<?php

namespace App\Modules\Channels\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\TelegramWebhookRegistrar;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ChannelAccountController extends Controller
{
    /** Credential fields required per provider (spec 05). */
    private const CREDENTIAL_FIELDS = [
        'TELEGRAM' => ['bot_token'],
        'ZALO_OA' => ['app_id', 'app_secret', 'access_token', 'refresh_token'],
        'ZALO_PERSONAL' => [], // logs in via QR through the sidecar, no stored creds at create time
        'FACEBOOK' => ['app_secret', 'page_access_token'],
    ];

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $workspaceId = $this->workspaceId($request);

        $data = $request->validate([
            'provider' => ['required', Rule::in(['TELEGRAM', 'ZALO_OA', 'ZALO_PERSONAL', 'FACEBOOK'])],
            'name' => ['required', 'string', 'max:120'],
            'credentials' => ['array'],
            'credentials.bot_token' => ['nullable', 'string', 'max:255'],
            'credentials.app_id' => ['nullable', 'string', 'max:255'],
            'credentials.app_secret' => ['nullable', 'string', 'max:255'],
            'credentials.access_token' => ['nullable', 'string', 'max:2000'],
            'credentials.refresh_token' => ['nullable', 'string', 'max:2000'],
            'credentials.page_access_token' => ['nullable', 'string', 'max:2000'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $creds = array_filter($data['credentials'] ?? [], fn ($v) => $v !== null && $v !== '');

        // ZALO_PERSONAL events come from the sidecar, which authenticates with the
        // shared ZALO_SIDECAR_TOKEN. The account's webhook_secret MUST equal that
        // token or the sidecar's pushes get 401'd. Other providers get a random one.
        $defaultSecret = $data['provider'] === 'ZALO_PERSONAL'
            ? (string) config('services.zalo_sidecar.token')
            : Str::random(48);

        ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'provider' => $data['provider'],
            'name' => $data['name'],
            'status' => 'DRAFT',
            'credentials' => $creds ?: null,
            'webhook_secret' => ($data['webhook_secret'] ?? null) ?: $defaultSecret,
        ]);

        return back()->with('success', 'Channel account created. Configure its webhook next.');
    }

    public function update(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        $this->authorizeManage($request);
        abort_unless($channelAccount->workspace_id === $this->workspaceId($request), 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['DRAFT', 'ACTIVE', 'DEGRADED', 'DISABLED'])],
            'credentials' => ['sometimes', 'array'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('credentials', $data)) {
            // Merge so blank fields don't wipe existing secrets.
            $incoming = array_filter($data['credentials'], fn ($v) => $v !== null && $v !== '');
            $data['credentials'] = array_merge($channelAccount->credentials ?? [], $incoming);
        }

        $channelAccount->fill($data)->save();

        return back()->with('success', 'Channel account updated.');
    }

    /**
     * Auto-register the webhook where the provider supports it (Telegram).
     * Other providers need the URL pasted into their dashboard; this action
     * just confirms the URL is ready and marks the account active.
     */
    public function registerWebhook(Request $request, ChannelAccount $channelAccount, TelegramWebhookRegistrar $telegram): RedirectResponse
    {
        $this->authorizeManage($request);
        abort_unless($channelAccount->workspace_id === $this->workspaceId($request), 403);

        if ($channelAccount->provider === 'TELEGRAM') {
            try {
                $telegram->register($channelAccount);
            } catch (Throwable $e) {
                return back()->with('error', 'Telegram webhook registration failed: '.$e->getMessage());
            }

            return back()->with('success', 'Telegram webhook registered. Send your bot a message to test.');
        }

        // Manual providers: store the public URL and flip DRAFT -> ACTIVE.
        $channelAccount->forceFill([
            'webhook_url' => route('webhooks.'.($channelAccount->provider === 'FACEBOOK' ? 'facebook' : 'zalo'), $channelAccount, absolute: true),
            'status' => $channelAccount->status === 'DRAFT' ? 'ACTIVE' : $channelAccount->status,
        ])->save();

        return back()->with('success', 'Webhook URL is ready. Paste it into the provider dashboard, then send a test message.');
    }

    /**
     * Hard-delete a channel account. DESTRUCTIVE: FK cascade removes its
     * conversations, messages, webhook_events and outbox rows. Owner-only.
     * Prefer update(status: DISABLED) to keep history.
     */
    public function destroy(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        abort_unless($request->user()->role === 'owner', 403);
        abort_unless($channelAccount->workspace_id === $this->workspaceId($request), 403);

        $channelAccount->delete();

        return back()->with('success', 'Channel account and its synced data were deleted.');
    }

    /** Ask the sidecar to start a Zalo-personal QR login. Returns the QR image. */
    public function zaloLoginQr(Request $request, ChannelAccount $channelAccount): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless($channelAccount->provider === 'ZALO_PERSONAL', 422);

        try {
            $res = $this->sidecar()->timeout(35)
                ->post($this->sidecarUrl("/accounts/{$channelAccount->id}/login-qr"));

            return response()->json($res->json() ?: ['error' => 'SIDECAR_ERROR']);
        } catch (Throwable $e) {
            return response()->json(['error' => 'SIDECAR_UNREACHABLE', 'message' => $e->getMessage()], 502);
        }
    }

    /** Poll Zalo-personal connection status (QR pending / connected). */
    public function zaloStatus(Request $request, ChannelAccount $channelAccount): JsonResponse
    {
        $this->authorizeManage($request);

        try {
            $res = $this->sidecar()->timeout(10)
                ->get($this->sidecarUrl("/accounts/{$channelAccount->id}/status"));
            $body = $res->json() ?: ['status' => 'UNKNOWN'];

            // Reflect a connected nick back onto the account status.
            if (($body['status'] ?? null) === 'CONNECTED' && $channelAccount->status !== 'ACTIVE') {
                $channelAccount->forceFill(['status' => 'ACTIVE'])->save();
            }

            return response()->json($body);
        } catch (Throwable $e) {
            return response()->json(['status' => 'SIDECAR_UNREACHABLE', 'message' => $e->getMessage()], 502);
        }
    }

    /** Ask the sidecar to replay recent messages (manual history sync). */
    public function zaloSync(Request $request, ChannelAccount $channelAccount): JsonResponse
    {
        $this->authorizeManage($request);
        abort_unless($channelAccount->provider === 'ZALO_PERSONAL', 422);

        try {
            $res = $this->sidecar()->timeout(15)
                ->post($this->sidecarUrl("/accounts/{$channelAccount->id}/sync"));

            return response()->json($res->json() ?: ['ok' => false]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 502);
        }
    }

    private function sidecar()
    {
        return Http::withHeaders(['x-sidecar-token' => (string) config('services.zalo_sidecar.token')]);
    }

    private function sidecarUrl(string $path): string
    {
        return rtrim((string) config('services.zalo_sidecar.url'), '/').$path;
    }

    private function authorizeManage(Request $request): void
    {
        // spec 08: only owner/admin manage channel accounts.
        abort_unless(in_array($request->user()->role, ['owner', 'admin'], true), 403);
    }

    private function workspaceId(Request $request): string
    {
        if (! $request->user()->workspace_id) {
            $workspace = Workspace::query()->firstOrCreate(
                ['slug' => 'default'],
                ['name' => 'CRM Demo Workspace', 'status' => 'ACTIVE'],
            );
            $request->user()->forceFill(['workspace_id' => $workspace->id, 'status' => 'ACTIVE'])->save();
        }

        return (string) $request->user()->workspace_id;
    }
}
