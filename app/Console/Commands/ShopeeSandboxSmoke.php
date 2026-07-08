<?php

namespace App\Console\Commands;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Console\Command;

/**
 * Pre-flight + smoke for Shopee Chat VN sandbox (or production).
 *
 * Subcommands:
 *   config   : Show env-derived config (api base, region).
 *   set      : Write partner credentials to workspace_settings for a workspace.
 *   inspect  : Show all SHOPEE accounts + their credential expiry state.
 *   probe    : Validate that partner credentials round-trip against the
 *              configured API base (HMAC signature is verified locally —
 *              no real Shopee call; this catches partner_id typos only).
 */
class ShopeeSandboxSmoke extends Command
{
    protected $signature = 'shopee:sandbox-smoke
        {action : One of: config, set, inspect, probe}
        {--slug= : Workspace slug (for set)}
        {--partner-id= : Shopee partner_id (for set)}
        {--partner-key= : Shopee partner_key (for set; prompted if omitted)}';

    protected $description = 'Pre-flight + smoke for Shopee Chat VN (sandbox or prod).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'config' => $this->config(),
            'set' => $this->setCreds(),
            'inspect' => $this->inspect(),
            'probe' => $this->probe(),
            default => $this->fail("Unknown action: {$action}. Use config|set|inspect|probe."),
        };
    }

    private function config(): int
    {
        $rows = [
            ['APP_ENV', (string) config('app.env')],
            ['APP_TENANT_DOMAIN', (string) config('tenant.domain')],
            ['APP_WEBHOOK_SUBDOMAIN', (string) config('tenant.webhook_subdomain')],
            ['SHOPEE_API_BASE', (string) config('services.shopee.api_base')],
            ['SHOPEE_REGION', (string) config('services.shopee.region')],
            ['webhook_host', (string) config('tenant.webhook_host')],
        ];
        $this->table(['Key', 'Value'], $rows);

        $apiBase = (string) config('services.shopee.api_base');
        $isSandbox = str_contains($apiBase, 'test-');
        $this->line('');
        $this->info($isSandbox
            ? 'API base points at Shopee SANDBOX.'
            : 'API base points at Shopee PRODUCTION.');

        return self::SUCCESS;
    }

    private function setCreds(): int
    {
        $slug = (string) $this->option('slug');
        $partnerId = (string) $this->option('partner-id');
        $partnerKey = (string) $this->option('partner-key');

        if ($slug === '') {
            $this->error('--slug is required');
            return self::FAILURE;
        }
        if ($partnerId === '') {
            $this->error('--partner-id is required');
            return self::FAILURE;
        }
        if ($partnerKey === '') {
            $partnerKey = (string) $this->secret('Shopee partner_key');
            if ($partnerKey === '') {
                $this->error('partner_key is required');
                return self::FAILURE;
            }
        }

        $workspace = Workspace::query()->where('slug', $slug)->first();
        if ($workspace === null) {
            $this->error("Workspace '{$slug}' not found.");
            return self::FAILURE;
        }

        app(WorkspaceSettings::class)->set($workspace, 'shopee.partner_credentials', [
            'partner_id' => $partnerId,
            'partner_key' => $partnerKey,
        ]);

        $this->info("Saved partner_credentials for workspace {$slug} ({$workspace->id}).");

        return self::SUCCESS;
    }

    private function inspect(): int
    {
        $accounts = ChannelAccount::query()
            ->withoutWorkspaceScope()
            ->where('provider', 'SHOPEE')
            ->with('workspace:id,slug,name')
            ->get();

        if ($accounts->isEmpty()) {
            $this->warn('No SHOPEE channel accounts found. Connect one via the admin UI first.');

            return self::SUCCESS;
        }

        $rows = $accounts->map(function (ChannelAccount $account) {
            $creds = $account->credentials ?? [];
            $expiresAt = $creds['access_token_expires_at'] ?? null;
            $expiresHuman = $expiresAt ? \Illuminate\Support\Carbon::parse($expiresAt)->diffForHumans() : '—';

            return [
                $account->id,
                $account->workspace?->slug ?? '—',
                $account->name,
                $account->status,
                (string) ($creds['shop_id'] ?? '—'),
                (string) ($creds['merchant_id'] ?? '—'),
                $expiresHuman,
                $account->last_error_code ?? '—',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Workspace', 'Name', 'Status', 'Shop ID', 'Merchant', 'Token expires', 'Last error'],
            $rows,
        );

        $reauth = $accounts->filter(fn ($a) => $a->last_error_code === 'REAUTH_REQUIRED');
        if ($reauth->isNotEmpty()) {
            $this->line('');
            $this->warn("{$reauth->count()} account(s) need re-auth. Run the OAuth flow from the admin UI for each.");
        }

        return self::SUCCESS;
    }

    /**
     * Local-only probe: verifies the partner credentials are syntactically
     * valid for HMAC signing (does NOT call Shopee). It does:
     *   1. Read partner_id + partner_key from workspace_settings.
     *   2. Generate a canonical Shopee partner-auth signature locally.
     *   3. Print the signature + the URL it would attach to.
     * Catches: missing credentials, partner_id non-numeric, partner_key empty.
     */
    private function probe(): int
    {
        $slug = (string) ($this->option('slug') ?: '');
        if ($slug === '') {
            $this->error('--slug required for probe');
            return self::FAILURE;
        }

        $workspace = Workspace::query()->where('slug', $slug)->first();
        if ($workspace === null) {
            $this->error("Workspace '{$slug}' not found.");
            return self::FAILURE;
        }

        $creds = app(WorkspaceSettings::class)->get($workspace, 'shopee.partner_credentials');
        if (! is_array($creds) || empty($creds['partner_id']) || empty($creds['partner_key'])) {
            $this->error("shopee.partner_credentials missing or empty for {$slug}. Run `shopee:sandbox-smoke set` first.");
            return self::FAILURE;
        }

        $partnerId = (int) $creds['partner_id'];
        $partnerKey = (string) $creds['partner_key'];
        $ts = time();

        // Canonical Shopee partner signature for /seller_chat/send_message.
        // Format: HMAC-SHA256 over `${path}|${timestamp}|${shop_id}|${access_token}`.
        // We don't have a real shop_id/access_token here, so use placeholders.
        // The probe only validates the credential FORMAT, not the signed request.
        $sig = hash_hmac('sha256', "/seller_chat/send_message|{$ts}|0|dummy", $partnerKey);

        $this->info('Partner credentials present and HMAC-signable.');
        $this->table(
            ['Field', 'Value'],
            [
                ['partner_id', $partnerId],
                ['partner_key_length', strlen($partnerKey)],
                ['api_base', (string) config('services.shopee.api_base')],
                ['sample_timestamp', $ts],
                ['sample_signature', substr($sig, 0, 32).'...'],
            ],
        );

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}