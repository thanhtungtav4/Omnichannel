<?php

namespace App\Modules\Channels\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\Shopee\InvalidShopeeStateException;
use App\Modules\Channels\Services\Shopee\ShopeeOAuthState;
use App\Modules\Channels\Services\Shopee\ShopeeTokenExchanger;
use App\Modules\Channels\Services\Shopee\ShopeeTokenException;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use App\Modules\Platform\Tenancy\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Shopee Chat VN OAuth round-trip (specs/11_SHOPEE_CHAT_VN.md, W2 G1.1).
 *
 * Routes (registered by the channels service provider):
 *   GET  /admin/channels/shopee/connect    → connect()
 *   GET  /admin/channels/shopee/callback   → callback()
 *
 * Both are out-of-tenant routes? No — they're per-tenant because the
 * callback must attach the new tokens to the workspace that initiated the
 * flow. They live under the workspace-scoped admin middleware.
 *
 * The redirect URI sent to Shopee is exactly the URL of this controller's
 * callback action. It must be pre-registered in the Shopee Open Platform
 * partner dashboard at https://open.shopee.vn/developer (spec 11 § OAuth
 * pre-registration).
 */
class ShopeeOAuthController extends Controller
{
    public function __construct(
        private readonly ShopeeOAuthState $state,
        private readonly ShopeeTokenExchanger $exchanger,
        private readonly WorkspaceSettings $settings,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * Build the Shopee OAuth consent URL and redirect the browser there.
     */
    public function connect(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspaceOrFail();

        $partnerId = $this->partnerIdOrFail($workspace);

        $stateToken = $this->state->issue($workspace);

        $params = [
            'partner_id' => $partnerId,
            'redirect' => route('admin.channels.shopee.callback'),
            'state' => $stateToken,
            'scope' => implode(',', config('services.shopee.oauth_scopes', [])),
            'token_type' => 'main_account',
        ];

        $url = 'https://partner.shopeemobile.com/api/v2/shop/auth_partner'
            .'?'.http_build_query($params);

        return redirect()->away($url);
    }

    /**
     * Handle Shopee's redirect back. Persist tokens, flip the channel account
     * to ACTIVE, redirect to admin.
     */
    public function callback(Request $request): RedirectResponse
    {
        // Surface Shopee-side errors before doing anything else. Shopee
        // passes these as query string params when the user denies or the
        // request is invalid.
        if ($error = $request->query('error')) {
            [$code, $message] = $this->mapShopeeError((string) $error);

            return $this->redirectWithError($code, $message);
        }

        if (! $request->has('code')) {
            return $this->redirectWithError(
                'MISSING_CODE',
                'Shopee did not return an authorization code. The flow was cancelled or interrupted.',
            );
        }

        // Consume state FIRST so a replay attack fails before we touch Shopee.
        try {
            $payload = $this->state->consume((string) $request->query('state'));
        } catch (InvalidShopeeStateException $e) {
            return $this->redirectWithError(
                'INVALID_STATE',
                'Your Shopee connection request expired or has already been used. Please try again.',
            );
        }

        $workspace = Workspace::query()->whereKey($payload['workspace_id'])->first();
        if ($workspace === null) {
            // Workspace was deleted between connect and callback — extremely
            // unlikely but possible (admin suspended + purged tenant).
            return $this->redirectWithError(
                'WORKSPACE_NOT_FOUND',
                'Workspace no longer exists.',
            );
        }

        try {
            $tokens = $this->exchanger->exchangeCodeForTokens(
                workspace: $workspace,
                code: (string) $request->query('code'),
                redirectUri: route('admin.channels.shopee.callback'),
            );
        } catch (ShopeeTokenException $e) {
            Log::warning('Shopee OAuth code exchange failed', [
                'workspace_id' => $workspace->id,
                'error_code' => $e->getCode(),
            ]);
            return $this->redirectWithError(
                'TOKEN_EXCHANGE_FAILED',
                'Could not exchange the authorization code. Please try again or contact support.',
            );
        }

        // Persist the new channel account. One channel_account per shop.
        // For cut 1 we don't pre-create the row — connect-from-scratch is the
        // only onboarding flow. Cut 2 may add a "select shop" picker if a
        // single partner owns multiple shops.
        $account = ChannelAccount::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'SHOPEE')
            ->where('name', 'Shopee shop '.$tokens['shop_id'])
            ->first();

        if ($account === null) {
            $account = new ChannelAccount([
                'workspace_id' => $workspace->id,
                'provider' => 'SHOPEE',
                'name' => 'Shopee shop '.$tokens['shop_id'],
                'status' => 'ACTIVE',
            ]);
        }

        $account->credentials = array_merge(
            $account->credentials ?? [],
            [
                'shop_id' => $tokens['shop_id'],
                'merchant_id' => $tokens['merchant_id'] ?? null,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'access_token_expires_at' => $tokens['access_token_expires_at']->toIso8601String(),
            ],
        );
        $account->status = 'ACTIVE';
        $account->last_error_code = null;
        $account->last_error_message = null;
        $account->save();

        return redirect()
            ->route('admin.channels', ['tenant' => $workspace->slug])
            ->with('success', 'Shopee shop connected.');
    }

    private function currentWorkspaceOrFail(): Workspace
    {
        $workspace = $this->current->get();
        abort_unless($workspace !== null, 404, 'No workspace resolved.');
        abort_unless(
            $this->settings->has($workspace, 'shopee.partner_credentials'),
            412,
            'Shopee partner credentials are not configured for this workspace. '.
            'Set them in Settings → Integrations first.',
        );

        return $workspace;
    }

    private function partnerIdOrFail(Workspace $workspace): string
    {
        $creds = $this->settings->get($workspace, 'shopee.partner_credentials');
        $partnerId = $creds['partner_id'] ?? null;
        abort_unless(is_string($partnerId) && $partnerId !== '', 412, 'partner_id missing.');

        return $partnerId;
    }

    /**
     * Translate Shopee's error code into an admin-friendly message.
     */
    private function mapShopeeError(string $error): array
    {
        return match ($error) {
            'invalid_redirect_uri' => [
                'INVALID_REDIRECT_URI',
                'Shopee rejected the redirect URI. The CRM must register the callback URL '.
                'in the Shopee partner dashboard at https://open.shopee.vn/developer.',
            ],
            'access_denied' => [
                'ACCESS_DENIED',
                'You declined the Shopee authorization. Click "Connect Shopee" to try again.',
            ],
            'invalid_request' => [
                'INVALID_REQUEST',
                'Shopee reported an invalid request. Please try connecting again.',
            ],
            default => [
                'SHOPEE_ERROR',
                "Shopee returned an error: {$error}.",
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
            ->withErrors(['shopee_oauth' => $message])
            ->with('shopee_oauth_error_code', $code);
    }
}