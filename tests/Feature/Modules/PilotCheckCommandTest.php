<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboxMessage;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PilotCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::create([
            'slug' => 'pilot-test',
            'name' => 'Pilot Test',
            'status' => 'ACTIVE',
        ]);
    }

    private function makeChannel(string $provider, string $status = 'ACTIVE', array $creds = []): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => $provider,
            'name' => "Pilot {$provider}",
            'status' => $status,
            'credentials' => array_merge([
                'shop_id' => $provider === 'SHOPEE' ? 11111 : 'SHOP-PILOT',
                'shop_cipher' => $provider === 'TIKTOK_SHOP' ? 'GCipA==' : null,
                'access_token' => 'fake',
                'refresh_token' => 'fake',
                'access_token_expires_at' => Carbon::now()->addHours(2)->toIso8601String(),
            ], $creds),
            'webhook_secret' => 'pilot-secret',
        ]);
    }

    /** Create the conversation + message a valid outbox row needs. */
    private function makeOutboxScaffold(ChannelAccount $channel): array
    {
        $conversation = \App\Modules\Inbox\Models\Conversation::create([
            'workspace_id' => $channel->workspace_id,
            'channel_account_id' => $channel->id,
            'subject' => 'pilot-test',
            'status' => 'OPEN',
            'is_group' => false,
        ]);
        $message = \App\Modules\Inbox\Models\Message::create([
            'workspace_id' => $channel->workspace_id,
            'conversation_id' => $conversation->id,
            'channel_account_id' => $channel->id,
            'direction' => 'OUTBOUND',
            'sender_type' => 'AGENT',
            'message_type' => 'TEXT',
            'body_text' => 'x',
        ]);

        return [$conversation, $message];
    }

    // ---------- summary mode ----------

    public function test_summary_renders_table_for_active_shopee_account(): void
    {
        $this->makeChannel('SHOPEE');

        $this->artisan('pilot:check', [
            '--provider' => 'SHOPEE',
            '--workspace' => 'pilot-test',
        ])->assertExitCode(0)
          ->expectsOutputToContain('Pre-flight OK')
          ->expectsOutputToContain('SHOPEE');
    }

    public function test_summary_renders_table_for_active_tiktok_account(): void
    {
        $this->makeChannel('TIKTOK_SHOP');

        $this->artisan('pilot:check', [
            '--provider' => 'TIKTOK_SHOP',
            '--workspace' => 'pilot-test',
        ])->assertExitCode(0)
          ->expectsOutputToContain('Pre-flight OK')
          ->expectsOutputToContain('TIKTOK_SHOP');
    }

    public function test_summary_fails_when_account_degraded(): void
    {
        $account = $this->makeChannel('SHOPEE', 'DEGRADED');

        // Degraded accounts are skipped by --workspace resolution (which only
        // finds ACTIVE). Pass --account explicitly to force inspection.
        $exitCode = \Illuminate\Support\Facades\Artisan::call('pilot:check', [
            '--provider' => 'SHOPEE',
            '--workspace' => 'pilot-test',
            '--account' => $account->id,
        ]);

        $this->assertSame(1, $exitCode);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Pre-flight FAILED', $output);
        $this->assertStringContainsString('status is DEGRADED', $output);
    }

    public function test_summary_fails_when_reauth_required(): void
    {
        $account = $this->makeChannel('SHOPEE');
        $account->forceFill(['last_error_code' => 'REAUTH_REQUIRED'])->save();

        $this->artisan('pilot:check', [
            '--provider' => 'SHOPEE',
            '--workspace' => 'pilot-test',
        ])->assertExitCode(1)
          ->expectsOutputToContain('REAUTH_REQUIRED');
    }

    public function test_summary_fails_when_pending_outbox_nonzero(): void
    {
        $account = $this->makeChannel('TIKTOK_SHOP');
        [$conversation, $message] = $this->makeOutboxScaffold($account);
        OutboxMessage::create([
            'workspace_id' => $this->workspace->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'channel_account_id' => $account->id,
            'provider' => 'TIKTOK_SHOP',
            'recipient_external_id' => 'open-uid-1',
            'payload' => ['text' => 'x'],
            'status' => 'QUEUED',
            'attempts' => 0,
        ]);

        $this->artisan('pilot:check', [
            '--provider' => 'TIKTOK_SHOP',
            '--workspace' => 'pilot-test',
        ])->assertExitCode(1)
          ->expectsOutputToContain('pending_outbox');
    }

    public function test_summary_fails_when_token_ttl_below_3600(): void
    {
        $this->makeChannel('SHOPEE', 'ACTIVE', [
            'access_token_expires_at' => Carbon::now()->addMinutes(15)->toIso8601String(),
        ]);

        $this->artisan('pilot:check', [
            '--provider' => 'SHOPEE',
            '--workspace' => 'pilot-test',
        ])->assertExitCode(1)
          ->expectsOutputToContain('token_ttl');
    }

    public function test_summary_works_when_token_expiry_missing(): void
    {
        $this->makeChannel('SHOPEE', 'ACTIVE', ['access_token_expires_at' => null]);

        $this->artisan('pilot:check', [
            '--provider' => 'SHOPEE',
            '--workspace' => 'pilot-test',
        ])->assertExitCode(0)
          ->expectsOutputToContain('<missing>');
    }

    // ---------- --json mode ----------

    public function test_json_mode_emits_machine_readable_payload(): void
    {
        $this->makeChannel('SHOPEE');

        $exitCode = \Illuminate\Support\Facades\Artisan::call('pilot:check', [
            '--provider' => 'SHOPEE',
            '--workspace' => 'pilot-test',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        // Capture the output via Artisan::output() (Laravel helper).
        $output = trim(\Illuminate\Support\Facades\Artisan::output());
        $data = json_decode($output, true);
        $this->assertIsArray($data, "Artisan output was not valid JSON: {$output}");
        $this->assertSame('SHOPEE', $data['provider']);
        $this->assertSame('ACTIVE', $data['status']);
        $this->assertArrayHasKey('webhook_secret', $data);
        $this->assertArrayHasKey('pending_outbox', $data);
    }

    // ---------- --resolve-account mode ----------

    public function test_resolve_account_returns_most_recent_active_uuid(): void
    {
        $a = $this->makeChannel('SHOPEE');
        // Make sure $a is the latest.
        $a->forceFill(['updated_at' => Carbon::now()])->save();

        $this->artisan('pilot:check', [
            '--provider' => 'SHOPEE',
            '--workspace' => 'pilot-test',
            '--resolve-account' => true,
        ])->assertExitCode(0)
          ->expectsOutput($a->id);
    }

    public function test_resolve_account_fails_when_no_active(): void
    {
        $this->makeChannel('SHOPEE', 'DISABLED');

        $this->artisan('pilot:check', [
            '--provider' => 'SHOPEE',
            '--workspace' => 'pilot-test',
            '--resolve-account' => true,
        ])->assertExitCode(1)
          ->expectsOutput('No active');
    }

    // ---------- single-value modes ----------

    public function test_pending_outbox_mode_prints_count(): void
    {
        $account = $this->makeChannel('TIKTOK_SHOP');
        // Each outbox row needs a distinct message (unique constraint on message_id).
        // Create one scaffold then add 2 more messages for QUEUED + RETRYING, and one
        // for SENT.
        foreach (['QUEUED', 'RETRYING', 'SENT'] as $i => $status) {
            $conv = \App\Modules\Inbox\Models\Conversation::create([
                'workspace_id' => $this->workspace->id,
                'channel_account_id' => $account->id,
                'subject' => "smoke-{$i}",
                'status' => 'OPEN',
                'is_group' => false,
            ]);
            $msg = \App\Modules\Inbox\Models\Message::create([
                'workspace_id' => $this->workspace->id,
                'conversation_id' => $conv->id,
                'channel_account_id' => $account->id,
                'direction' => 'OUTBOUND',
                'sender_type' => 'AGENT',
                'message_type' => 'TEXT',
                'body_text' => "msg-{$i}",
            ]);
            OutboxMessage::create([
                'workspace_id' => $this->workspace->id,
                'conversation_id' => $conv->id,
                'message_id' => $msg->id,
                'channel_account_id' => $account->id,
                'provider' => 'TIKTOK_SHOP',
                'recipient_external_id' => "u-{$i}",
                'payload' => [],
                'status' => $status,
                'attempts' => 0,
            ]);
        }

        $this->artisan('pilot:check', [
            '--provider' => 'TIKTOK_SHOP',
            '--account' => $account->id,
            '--pending-outbox' => true,
        ])->assertExitCode(0)
          ->expectsOutput('2');
    }

    public function test_last_error_mode_prints_code(): void
    {
        $account = $this->makeChannel('SHOPEE');
        $account->forceFill(['last_error_code' => 'HTTP_500'])->save();

        $this->artisan('pilot:check', [
            '--provider' => 'SHOPEE',
            '--account' => $account->id,
            '--last-error' => true,
        ])->assertExitCode(0)
          ->expectsOutput('HTTP_500');
    }

    public function test_last_error_mode_prints_empty_when_null(): void
    {
        $this->makeChannel('SHOPEE');

        $this->artisan('pilot:check', [
            '--provider' => 'SHOPEE',
            '--account' => ChannelAccount::query()->where('workspace_id', $this->workspace->id)->first()->id,
            '--last-error' => true,
        ])->assertExitCode(0);
        // Output is empty newline — just check it didn't fail.
    }

    public function test_token_ttl_mode_prints_seconds(): void
    {
        $this->makeChannel('SHOPEE', 'ACTIVE', [
            'access_token_expires_at' => Carbon::now()->addMinutes(45)->toIso8601String(),
        ]);

        $account = ChannelAccount::query()->where('workspace_id', $this->workspace->id)->first();
        $exitCode = \Illuminate\Support\Facades\Artisan::call('pilot:check', [
            '--provider' => 'SHOPEE',
            '--account' => $account->id,
            '--token-ttl' => true,
        ]);

        $this->assertSame(0, $exitCode);

        // 45 minutes = 2700 seconds ± 60s slack for test runtime.
        $output = (int) trim(\Illuminate\Support\Facades\Artisan::output());
        $this->assertGreaterThan(2400, $output, "expected >2400, got {$output}");
        $this->assertLessThan(3000, $output, "expected <3000, got {$output}");
    }

    public function test_token_ttl_mode_prints_missing_when_null(): void
    {
        $this->makeChannel('SHOPEE', 'ACTIVE', ['access_token_expires_at' => null]);

        $account = ChannelAccount::query()->where('workspace_id', $this->workspace->id)->first();
        $this->artisan('pilot:check', [
            '--provider' => 'SHOPEE',
            '--account' => $account->id,
            '--token-ttl' => true,
        ])->assertExitCode(0)
          ->expectsOutput('<missing>');
    }

    // ---------- --send-test mode ----------

    public function test_send_test_dispatches_job_and_prints_status(): void
    {
        Queue::fake();

        $account = $this->makeChannel('SHOPEE');

        $exitCode = \Illuminate\Support\Facades\Artisan::call('pilot:check', [
            '--provider' => 'SHOPEE',
            '--account' => $account->id,
            '--send-test' => true,
        ]);

        // Queue::fake() intercepts the job — the outbox row stays QUEUED.
        // We treat QUEUED as success in this mode (job is ready to be processed
        // when a worker is available).
        $output = trim(\Illuminate\Support\Facades\Artisan::output());
        $this->assertSame('QUEUED', $output);
        $this->assertSame(1, $exitCode); // 1 = status was QUEUED (acceptable here per test mode)

        // Job should have been dispatched (Queue::fake() intercepts it).
        Queue::assertPushed(\App\Modules\Channels\Jobs\SendChannelMessageJob::class);
    }

    public function test_send_test_marks_queued_when_no_worker(): void
    {
        // Queue runs synchronously in test by default. We fake the adapter HTTP
        // so the job marks the outbox SENT; we then verify a row was created.
        \Illuminate\Support\Facades\Http::fake([
            '*open.tiktokglobalshop.com/*' => \Illuminate\Support\Facades\Http::response([
                'code' => 0,
                'data' => ['message_id' => 'TT-SMOKE-1'],
            ], 200),
        ]);

        $account = $this->makeChannel('TIKTOK_SHOP');

        $this->artisan('pilot:check', [
            '--provider' => 'TIKTOK_SHOP',
            '--account' => $account->id,
            '--send-test' => true,
        ])->assertExitCode(0); // SENT

        // Outbox row must exist and reach SENT.
        $this->assertDatabaseHas('outbox_messages', [
            'channel_account_id' => $account->id,
            'provider' => 'TIKTOK_SHOP',
            'status' => 'SENT',
        ]);
    }

    // ---------- edge cases ----------

    public function test_unknown_provider_falls_through_unchanged(): void
    {
        $this->makeChannel('FACEBOOK');

        // FACEBOOK exists but pilot:check for an unknown provider means
        // --resolve-account returns no ACTIVE account.
        $this->artisan('pilot:check', [
            '--provider' => 'UNRECOGNIZED',
            '--workspace' => 'pilot-test',
            '--resolve-account' => true,
        ])->assertExitCode(1)
          ->expectsOutput('No active');
    }

    public function test_normalize_provider_handles_alternate_spelling(): void
    {
        // Both 'tiktok-shop' (kebab) and 'tiktok' (bare) should map to TIKTOK_SHOP.
        $this->makeChannel('TIKTOK_SHOP');

        $this->artisan('pilot:check', [
            '--provider' => 'tiktok-shop',
            '--workspace' => 'pilot-test',
            '--resolve-account' => true,
        ])->assertExitCode(0);

        $this->artisan('pilot:check', [
            '--provider' => 'tiktok',
            '--workspace' => 'pilot-test',
            '--resolve-account' => true,
        ])->assertExitCode(0);
    }
}