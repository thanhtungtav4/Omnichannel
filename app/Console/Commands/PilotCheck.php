<?php

namespace App\Console\Commands;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Channels\Models\WebhookEvent;
use App\Modules\Inbox\Models\Conversation;
use App\Modules\Inbox\Models\Message;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pre-flight checks for a pilot channel account.
 *
 * Used by scripts/pilot_smoke.sh as the data-source for steps 3-6, 8. Also
 * usable standalone: `php artisan pilot:check --provider=SHOPEE --workspace=acme`.
 *
 * Output modes (mutually exclusive):
 *   (default)         human-readable table with ✓/✗ per check
 *   --json            single JSON object with all fields (used by smoke.sh)
 *   --resolve-account prints only the UUID of the most recent ACTIVE account
 *   --pending-outbox  prints only the pending count
 *   --last-error      prints only the last_error_code
 *   --token-ttl       prints only seconds until token expiry (<missing> if null)
 *   --send-test       creates an outbox row + dispatches SendChannelMessageJob,
 *                     then prints final status (SENT/QUEUED/RETRYING/FAILED).
 */
class PilotCheck extends Command
{
    protected $signature = 'pilot:check
        {--provider= : SHOPEE or TIKTOK_SHOP}
        {--workspace= : workspace slug}
        {--account= : channel account UUID (overrides workspace resolution)}
        {--json : output a single JSON object}
        {--resolve-account : print only the most-recent ACTIVE account UUID}
        {--pending-outbox : print only the pending outbox count}
        {--last-error : print only the last_error_code}
        {--token-ttl : print only seconds until access_token expiry}
        {--send-test : dispatch a synthetic outbound and print final outbox status}';

    protected $description = 'Pre-flight checks for a Shopee or TikTok Shop pilot channel account';

    public function handle(): int
    {
        $provider = $this->normalizeProvider($this->option('provider'));
        $workspaceSlug = $this->option('workspace');
        $accountId = $this->option('account');

        // --resolve-account mode: print the most-recent ACTIVE account UUID or fail.
        if ($this->option('resolve-account')) {
            return $this->resolveAccount($provider, $workspaceSlug);
        }

        // From here on we need a resolved account.
        if (! $accountId) {
            $accountId = $this->resolveAccountId($provider, $workspaceSlug);
            if (! $accountId) {
                $this->error("No ACTIVE channel account for provider={$provider} workspace=".($workspaceSlug ?? '<any>'));

                return self::FAILURE;
            }
        }

        $account = ChannelAccount::query()->find($accountId);
        if (! $account) {
            $this->error("Channel account not found: {$accountId}");

            return self::FAILURE;
        }

        // --pending-outbox mode.
        if ($this->option('pending-outbox')) {
            $count = OutboxMessage::query()
                ->where('channel_account_id', $accountId)
                ->whereIn('status', ['QUEUED', 'RETRYING'])
                ->count();
            $this->line((string) $count);

            return self::SUCCESS;
        }

        // --last-error mode.
        if ($this->option('last-error')) {
            $this->line((string) ($account->last_error_code ?? ''));

            return self::SUCCESS;
        }

        // --token-ttl mode.
        if ($this->option('token-ttl')) {
            $expiresAt = $account->credentials['access_token_expires_at'] ?? null;
            if (! $expiresAt) {
                $this->line('<missing>');

                return self::SUCCESS;
            }
            try {
                // now()->diffInSeconds($future, false) -> positive seconds-until-expiry.
                $remaining = (int) now()->diffInSeconds(Carbon::parse($expiresAt), false);
                $this->line((string) max(0, $remaining));

                return self::SUCCESS;
            } catch (\Throwable) {
                $this->line('<missing>');

                return self::SUCCESS;
            }
        }

        // --send-test mode: create an outbox row, dispatch the job, wait briefly,
        // and report final status. This does NOT hit the real Shopee/TikTok
        // servers — the job will fail at the HTTP step, but the outbox row
        // will exist and the status transition proves the queue pipeline works.
        if ($this->option('send-test')) {
            return $this->sendTest($account);
        }

        // Default mode: human-readable summary table OR --json.
        return $this->summary($account);
    }

    private function normalizeProvider(?string $raw): string
    {
        return match (strtoupper((string) $raw)) {
            'SHOPEE' => 'SHOPEE',
            'TIKTOK_SHOP', 'TIKTOK-SHOP', 'TIKTOK' => 'TIKTOK_SHOP',
            default => strtoupper((string) $raw),
        };
    }

    private function resolveAccountId(string $provider, ?string $slug): string
    {
        $workspaceId = $slug ? Workspace::query()->where('slug', $slug)->value('id') : null;

        return (string) ChannelAccount::query()
            ->where('provider', $provider)
            ->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->where('status', 'ACTIVE')
            ->latest()
            ->value('id');
    }

    private function resolveAccount(string $provider, ?string $slug): int
    {
        $id = $this->resolveAccountId($provider, $slug);
        if (! $id) {
            $this->line('No active');

            return self::FAILURE;
        }
        $this->line($id);

        return self::SUCCESS;
    }

    private function summary(ChannelAccount $account): int
    {
        $pendingOutbox = OutboxMessage::query()
            ->where('channel_account_id', $account->id)
            ->whereIn('status', ['QUEUED', 'RETRYING'])
            ->count();

        $lastInbound = WebhookEvent::query()
            ->where('channel_account_id', $account->id)
            ->where('status', 'PROCESSED')
            ->whereIn('event_type', ['message', 'message_edit'])
            ->max('processed_at');

        $expiresAt = $account->credentials['access_token_expires_at'] ?? null;
        $tokenTtl = null;
        if ($expiresAt) {
            try {
                // now()->diffInSeconds($future, false) gives POSITIVE seconds-until-expiry.
                // Inverting the args would give a negative number which we'd then clamp to 0,
                // which is wrong for tokens that expire far in the future.
                $tokenTtl = (int) now()->diffInSeconds(Carbon::parse($expiresAt), false);
            } catch (\Throwable) {
                $tokenTtl = null;
            }
        }

        $rows = [
            ['account.id', $account->id],
            ['account.provider', $account->provider],
            ['account.name', $account->name],
            ['account.status', $account->status],
            ['account.last_error_code', $account->last_error_code ?? '<none>'],
            ['pending_outbox', $pendingOutbox],
            ['last_inbound', $lastInbound ? Carbon::parse($lastInbound)->diffForHumans() : '<never>'],
            ['token_ttl_seconds', $tokenTtl ?? '<missing>'],
            ['webhook_secret_set', $account->webhook_secret ? 'yes' : 'no'],
        ];

        if ($this->option('json')) {
            $this->line(json_encode([
                'id' => $account->id,
                'provider' => $account->provider,
                'name' => $account->name,
                'status' => $account->status,
                'last_error_code' => $account->last_error_code,
                'webhook_secret' => $account->webhook_secret,
                'pending_outbox' => $pendingOutbox,
                'last_inbound_at' => $lastInbound,
                'token_ttl_seconds' => $tokenTtl,
            ]));

            return self::SUCCESS;
        }

        $this->table(['check', 'value'], $rows);

        // Pre-flight pass/fail summary at the bottom.
        $fails = [];
        if ($account->status !== 'ACTIVE') {
            $fails[] = "status is {$account->status} (need ACTIVE)";
        }
        if ($account->last_error_code === 'REAUTH_REQUIRED') {
            $fails[] = 'last_error_code is REAUTH_REQUIRED — re-run OAuth at /admin/channels';
        }
        if ($pendingOutbox > 0) {
            $fails[] = "pending_outbox = {$pendingOutbox} (drain or fix the underlying send failure)";
        }
        if ($tokenTtl !== null && $tokenTtl < 3600) {
            $fails[] = "token_ttl = {$tokenTtl}s (need >= 3600s; force-refresh)";
        }

        if ($fails) {
            $this->error('Pre-flight FAILED:');
            foreach ($fails as $f) {
                $this->line("  - {$f}");
            }

            return self::FAILURE;
        }

        $this->info('Pre-flight OK — ready for pilot smoke.');

        return self::SUCCESS;
    }

    private function sendTest(ChannelAccount $account): int
    {
        // Create a minimal conversation + outbound message + outbox row so the
        // job has something to process. We deliberately do NOT mock the
        // adapter — the smoke covers the real path so a misconfigured
        // partner credential surfaces here.
        $conversation = Conversation::query()->firstOrCreate(
            [
                'workspace_id' => $account->workspace_id,
                'channel_account_id' => $account->id,
                'subject' => 'pilot-smoke',
            ],
            [
                'status' => 'OPEN',
                'is_group' => false,
            ],
        );

        $message = Message::create([
            'workspace_id' => $account->workspace_id,
            'conversation_id' => $conversation->id,
            'channel_account_id' => $account->id,
            'direction' => 'OUTBOUND',
            'sender_type' => 'AGENT',
            'message_type' => 'TEXT',
            'body_text' => 'pilot smoke '.now()->toIso8601String(),
        ]);

        $outbox = OutboxMessage::create([
            'workspace_id' => $account->workspace_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel_account_id' => $account->id,
            'provider' => $account->provider,
            'recipient_external_id' => Str::random(16),
            'payload' => ['text' => 'pilot smoke'],
            'status' => 'QUEUED',
            'attempts' => 0,
        ]);

        \App\Modules\Channels\Jobs\SendChannelMessageJob::dispatch($outbox->id);

        // Block briefly for the queue worker to drain it. If no worker is
        // running locally, status stays QUEUED and the operator sees that.
        $deadline = now()->addSeconds(5);
        while (now()->lt($deadline)) {
            $outbox->refresh();
            if (in_array($outbox->status, ['SENT', 'FAILED', 'RETRYING'], true)) {
                break;
            }
            usleep(250_000);
        }

        $this->line($outbox->status);

        return in_array($outbox->status, ['SENT', 'RETRYING'], true) ? self::SUCCESS : self::FAILURE;
    }
}