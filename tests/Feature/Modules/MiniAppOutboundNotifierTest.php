<?php

namespace Tests\Feature\Modules;

use App\Modules\Channels\Jobs\MiniAppNotificationJob;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Services\MiniAppOutboundNotifier;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Models\WorkspaceSetting;
use App\Modules\Platform\Services\WorkspaceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Specs/15 § C4 — MiniAppOutboundNotifier chokepoint.
 *
 * Covers the four out-comes:
 *   1. happy path: contact has ZALO_OA identity + workspace has ZALO_OA
 *      channel account + template mapped → QUEUED + job dispatched.
 *   2. no ZALO_OA identity → FAILED audit row + no dispatch.
 *   3. no ZALO_OA channel account → FAILED audit row + no dispatch.
 *   4. no template mapping → FAILED audit row + no dispatch.
 *
 * QUEUED row carries the resolved oa_template_id inside `params` so the
 * job can pull it without re-resolving settings.
 */
class MiniAppOutboundNotifierTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        // Sync the queue connection so Queue::fake() intercepts without
        // touching real Redis.
        config(['queue.default' => 'sync']);
    }

    private function makeContactWithZaloOA(string $oaUserId): Contact
    {
        $contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Mini App User',
            'status' => 'ACTIVE',
            'source' => 'ZALO_OA',
        ]);

        $oaAccount = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'ZALO_OA',
            'name' => 'OA',
            'status' => 'ACTIVE',
            'credentials' => ['app_id' => 'a', 'app_secret' => 's', 'access_token' => 'tok'],
        ]);

        ExternalIdentity::create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'provider' => 'ZALO_OA',
            'provider_account_id' => $oaAccount->id,
            'provider_user_id' => $oaUserId,
            'display_name' => 'Mini App User',
        ]);

        return $contact;
    }

    private function setTemplateMapping(string $code, string $oaTemplateId): void
    {
        WorkspaceSetting::create([
            'workspace_id' => $this->workspace->id,
            'key' => 'miniapp.templates',
            // Encrypt through WorkspaceSettings so the read path matches.
            'value' => app(WorkspaceSettings::class)::class !== null
                ? ''
                : '',
        ]);
        // Use the service helper so the ciphertext path matches reads.
        app(WorkspaceSettings::class)
            ->set($this->workspace, 'miniapp.templates', [
                $code => ['oa_template_id' => $oaTemplateId],
            ]);
    }

    public function test_happy_path_queues_and_dispatches_job(): void
    {
        Queue::fake();
        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
        $contact = $this->makeContactWithZaloOA('oa-user-1');
        $this->setTemplateMapping('lead_won', 'zalo-template-id-123');

        $row = app(MiniAppOutboundNotifier::class)
            ->notifyContact($contact, 'lead_won', ['lead_title' => 'Big deal']);

        $this->assertNotNull($row);
        $this->assertSame('QUEUED', $row->status);
        $this->assertSame('lead_won', $row->template_code);
        $this->assertSame('oa-user-1', $row->oa_user_id);
        $this->assertSame('zalo-template-id-123', $row->params['oa_template_id']);
        $this->assertSame('Big deal', $row->params['lead_title']);

        Queue::assertPushed(MiniAppNotificationJob::class);
    }

    public function test_no_op_when_contact_has_no_zalo_oa_identity(): void
    {
        Queue::fake();
        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);

        // Contact without any identity.
        $contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'No Identity',
            'status' => 'ACTIVE',
            'source' => 'WEBSITE_FORM',
        ]);
        $this->setTemplateMapping('lead_won', 'zalo-template-id');

        $row = app(MiniAppOutboundNotifier::class)
            ->notifyContact($contact, 'lead_won');

        $this->assertNull($row);
        Queue::assertNothingPushed();

        // Audit row is written so ops can see the no-op trail.
        $this->assertDatabaseHas('outbound_miniapp_notifications', [
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'template_code' => 'lead_won',
            'status' => 'FAILED',
            'last_error' => 'no_zalo_oa_identity',
        ]);
    }

    public function test_no_op_when_workspace_has_no_zalo_oa_account(): void
    {
        Queue::fake();
        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
        // Build a contact + identity but no ZALO_OA channel account on the
        // workspace. The contact's identity still references the OA account,
        // but it's filtered out below.
        $contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'User',
            'status' => 'ACTIVE',
            'source' => 'ZALO_OA',
        ]);
        // Stub a channel account in a DIFFERENT workspace so the lookup
        // returns null.
        $otherWs = Workspace::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'ACTIVE']);
        $foreignAccount = ChannelAccount::create([
            'workspace_id' => $otherWs->id,
            'provider' => 'ZALO_OA',
            'name' => 'Foreign OA',
            'status' => 'ACTIVE',
        ]);
        ExternalIdentity::create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'provider' => 'ZALO_OA',
            'provider_account_id' => $foreignAccount->id,
            'provider_user_id' => 'u-1',
        ]);
        $this->setTemplateMapping('lead_won', 'zalo-template-id');

        $row = app(MiniAppOutboundNotifier::class)
            ->notifyContact($contact, 'lead_won');

        $this->assertNull($row);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('outbound_miniapp_notifications', [
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'status' => 'FAILED',
            'last_error' => 'no_zalo_oa_account',
        ]);
    }

    public function test_no_op_when_template_not_configured(): void
    {
        Queue::fake();
        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
        $contact = $this->makeContactWithZaloOA('oa-user-1');
        // No setTemplateMapping call — workspace has no miniapp.templates row.

        $row = app(MiniAppOutboundNotifier::class)
            ->notifyContact($contact, 'lead_won');

        $this->assertNull($row);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('outbound_miniapp_notifications', [
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'status' => 'FAILED',
            'last_error' => 'template_not_configured',
        ]);
    }
}
