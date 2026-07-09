<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Jobs\MiniAppNotificationJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboundMiniAppNotification;
use App\Modules\Crm\Models\Contact;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Specs/15 § C4 — MiniAppNotificationJob.
 *
 * Covers:
 *  - adapter ok=true → SENT + sent_at filled
 *  - adapter ok=false + retryable=true → RETRYING + attempts incremented
 *  - adapter ok=false + retryable=false → FAILED
 *  - account disappeared → FAILED no_zalo_oa_account
 *  - template_id missing in params → FAILED
 *
 * The job resolves the channel account at HANDLE time (not dispatch time)
 * so a reconnect between queue and worker still finds the right account.
 */
class MiniAppNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ChannelAccount $oaAccount;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);

        $this->oaAccount = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'ZALO_OA',
            'name' => 'OA',
            'status' => 'ACTIVE',
            'credentials' => ['access_token' => 'tok'],
        ]);

        $this->contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'User',
            'status' => 'ACTIVE',
            'source' => 'ZALO_OA',
        ]);
    }

    private function makeQueuedRow(array $paramsOverride = []): OutboundMiniAppNotification
    {
        return OutboundMiniAppNotification::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $this->contact->id,
            'oa_user_id' => 'oa-user-1',
            'template_code' => 'lead_won',
            'params' => [
                'oa_template_id' => 'tmpl-abc',
                'lead_title' => 'Big deal',
            ],
            'status' => 'QUEUED',
            'attempts' => 0,
            'queued_at' => now(),
        ], $paramsOverride));
    }

    public function test_marks_sent_on_adapter_success(): void
    {
        Http::fake([
            'openapi.zalo.me/*' => Http::response(['error' => 0, 'data' => ['message_id' => 'zmsg-1']]),
        ]);

        $row = $this->makeQueuedRow();
        (new MiniAppNotificationJob($row->id))->handle();

        $fresh = $row->fresh();
        $this->assertSame('SENT', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertNull($fresh->last_error);
        $this->assertSame(1, $fresh->attempts);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'message/template')
                && $request['recipient']['user_id'] === 'oa-user-1'
                && $request['template_id'] === 'tmpl-abc';
        });
    }

    public function test_marks_failed_with_retryable_status_when_adapter_says_so(): void
    {
        Http::fake([
            'openapi.zalo.me/*' => Http::response(['error' => -124, 'message' => 'Token invalid']),
        ]);

        $row = $this->makeQueuedRow();
        // Override release() so the test doesn't try to re-dispatch into the
        // queue. We can't add a $job property here — the parent declares none
        // (InteractsWithQueue provides it lazily) so any typed override is a
        // covariance violation.
        $job = new class($row->id) extends MiniAppNotificationJob
        {
            public function release($delay = 0): void
            {
                // No-op in tests.
            }
        };

        $job->handle();

        $fresh = $row->fresh();
        $this->assertSame('RETRYING', $fresh->status);
        $this->assertSame(1, $fresh->attempts);
        $this->assertStringContainsString('Token invalid', $fresh->last_error);
    }

    public function test_marks_failed_when_adapter_rejects_non_retryable(): void
    {
        // error=-201 is "user blocked OA" — non-retryable per the adapter.
        Http::fake([
            'openapi.zalo.me/*' => Http::response(['error' => -201, 'message' => 'User blocked OA']),
        ]);

        $row = $this->makeQueuedRow();
        (new MiniAppNotificationJob($row->id))->handle();

        $fresh = $row->fresh();
        $this->assertSame('FAILED', $fresh->status);
        $this->assertStringContainsString('User blocked OA', $fresh->last_error);
    }

    public function test_marks_failed_when_workspace_has_no_oa_account(): void
    {
        $row = $this->makeQueuedRow();
        // Disable the account — notifier/job treat DISABLED as missing.
        $this->oaAccount->forceFill(['status' => 'DISABLED'])->save();

        (new MiniAppNotificationJob($row->id))->handle();

        $fresh = $row->fresh();
        $this->assertSame('FAILED', $fresh->status);
        $this->assertSame('no_zalo_oa_account', $fresh->last_error);
    }

    public function test_marks_failed_when_template_id_missing(): void
    {
        $row = $this->makeQueuedRow(['params' => ['lead_title' => 'no template id']]);
        (new MiniAppNotificationJob($row->id))->handle();

        $fresh = $row->fresh();
        $this->assertSame('FAILED', $fresh->status);
        $this->assertSame('template_id_missing', $fresh->last_error);
    }

    public function test_no_op_when_row_already_sent(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $row = $this->makeQueuedRow(['status' => 'SENT']);
        (new MiniAppNotificationJob($row->id))->handle();

        // No HTTP request fired because we returned early.
        Http::assertNothingSent();
        $this->assertSame('SENT', $row->fresh()->status);
    }
}
