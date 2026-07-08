<?php

namespace App\Modules\Channels\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\TikTok\InvalidTikTokStateException;
use App\Modules\Channels\Services\TikTok\TikTokOAuthState;
use App\Modules\Channels\Services\TikTok\TikTokTokenExchanger;
use App\Modules\Channels\Services\TikTok\TikTokTokenException;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * TikTok Shop Chat VN OAuth round-trip (specs/13_TIKTOK_SHOP_VN.md, W2 G1.1).
 *
 * Routes (registered by routes/web.php):
 *   GET  /admin/channels/tiktok/connect    → connect()
 *   GET  /admin/channels/tiktok/callback   → callback()
 *
 * VERIFIED against TikTok Shop Partner API docs:
 *   - Authorize URL: https://auth.tiktok-shops.com/api/v2/token/authorize
 *   - Token URL:     https://auth.tiktok-shops.com/api/v2/token/get
 *   - Public app identifier parameter: app_key (NOT client_id)
 *   - Callback returns `auth_code` query param (NOT `code`)
 *   - Token exchange grant_type: `authorized_code` (note: NOT `authorization_code`)
 *   - Response includes: access_token, refresh_token, open_id, seller_base_region,
 *     refresh_token_expire_in
 *
 * Sandbox vs production: same URLs (no separate sandbox host documented).
 * Use TikTok's "Generate a test access token" tool in Partner Center for testing.
 */
class TikTokOAuthController extends Controller
{
    public function __construct(
        private readonly TikTokOAuthState $state,
        private readonly TikTokTokenExchanger $exchanger,
        private readonly WorkspaceSettings $settings,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * Build the TikTok Shop OAuth consent URL and redirect the browser there.
     */
    public function connect(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspaceOrFail();

        $appKey = $this->appKeyOrFail($workspace);

        $stateToken = $this->state->issue($workspace);

        $params = [
            'app_key' => $appKey,
            'state' => $stateToken,
        ];

        $authBase = rtrim((string) config('services.tiktok_shop.auth_base'), '/');
        $url = $authBase.'/token/authorize?'.http_build_query($params);

        return redirect()->away($url);
    }

    /**
     * Handle TikTok's redirect back. Persist tokens, flip the channel account
     * to ACTIVE, redirect to admin.
     */
    public function callback(Request $request): RedirectResponse
    {
        // Surface TikTok-side errors before doing anything else. TikTok
        // passes these as query string params when the user denies or the
        // request is invalid.
        if ($error = $request->query('error')) {
            [$code, $message] = $this->mapTikTokError((string) $error);

            return $this->redirectWithError($code, $message);
        }

        if (! $request->has('auth_code')) {
            return $this->redirectWithError(
                'MISSING_CODE',
                'TikTok did not return an authorization code. The flow was cancelled or interrupted.',
            );
        }

        // Consume state FIRST so a replay attack fails before we touch TikTok.
        try {
            $payload = $this->state->consume((string) $request->query('state'));
        } catch (InvalidTikTokStateException $e) {
            return $this->redirectWithError(
                'INVALID_STATE',
                'Your TikTok connection request expired or has already been used. Please try again.',
            );
        }

        $workspace = Workspace::query()->whereKey($payload['workspace_id'])->first();
        if ($workspace === null) {
            return $this->redirectWithError(
                'WORKSPACE_NOT_FOUND',
                'Workspace no longer exists.',
            );
        }

        try {
            $tokens = $this->exchanger->exchangeCodeForTokens(
                workspace: $workspace,
                authCode: (string) $request->query('auth_code'),
            );
        } catch (TikTokTokenException $e) {
            Log::warning('TikTok OAuth code exchange failed', [
                'workspace_id' => $workspace->id,
                'error_code' => $e->getCode(),
            ]);
            return $this->redirectWithError(
                'TOKEN_EXCHANGE_FAILED',
                'Could not exchange the authorization code. Please try again or contact support.',
            );
        }

        // Persist the new channel account. One channel_account per shop.
        $account = ChannelAccount::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'TIKTOK_SHOP')
            ->where('name', 'TikTok shop '.$tokens['shop_id'])
            ->first();

        if ($account === null) {
            $account = new ChannelAccount([
                'workspace_id' => $workspace->id,
                'provider' => 'TIKTOK_SHOP',
                'name' => 'TikTok shop '.$tokens['shop_id'],
                'status' => 'ACTIVE',
            ]);
        }

        $account->credentials = array_merge(
            $account->credentials ?? [],
            [
                'shop_id' => $tokens['shop_id'],
                'open_id' => $tokens['open_id'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'access_token_expires_at' => $tokens['access_token_expires_at']->toIso8601String(),
                // TikTok Shop Partner also returns refresh_token_expire_in — store for
                // visibility in admin health card. Long-lived (~30+ days typically).
                'refresh_token_expires_at' => $tokens['refresh_token_expires_at']->toIso8601String(),
            ],
        );
        $account->status = 'ACTIVE';
        $account->last_error_code = null;
        $account->last_error_message = null;
        $account->save();

        return redirect()
            ->route('admin.channels', ['tenant' => $workspace->slug])
            ->with('success', 'TikTok shop connected.');
    }

    private function currentWorkspaceOrFail(): Workspace
    {
        $workspace = $this->current->get();
        abort_unless($workspace !== null, 404, 'No workspace resolved.');
        abort_unless(
            $this->settings->has($workspace, 'tiktok.partner_credentials'),
            412,
            'TikTok partner credentials are not configured for this workspace. '.
            'Set them in Settings → Integrations first.',
        );

        return $workspace;
    }

    private function appKeyOrFail(Workspace $workspace): string
    {
        $creds = $this->settings->get($workspace, 'tiktok.partner_credentials');
        $appKey = $creds['app_key'] ?? null;
        abort_unless(is_string($appKey) && $appKey !== '', 412, 'app_key missing.');

        return $appKey;
    }

    /**
     * Translate TikTok's error code into an admin-friendly message.
     */
    private function mapTikTokError(string $error): array
    {
        return match ($error) {
            'invalid_redirect_uri', 'redirect_uri_mismatch' => [
                'INVALID_REDIRECT_URI',
                'TikTok rejected the redirect URI. The CRM must register the callback URL '.
                'in the TikTok Open Platform app dashboard.',
            ],
            'access_denied' => [
                'ACCESS_DENIED',
                'You declined the TikTok authorization. Click "Connect TikTok" to try again.',
            ],
            'invalid_request' => [
                'INVALID_REQUEST',
                'TikTok reported an invalid request. Please try connecting again.',
            ],
            default => [
                'TIKTOK_ERROR',
                "TikTok returned an error: {$error}.",
            ],
        };
    }

    /**
     * @return RedirectResponse
     */
    private function redirectWithError(string $code, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.channels')
            ->withErrors(['tiktok_oauth' => $message])
            ->with('tiktok_oauth_error_code', $code);
    }
}