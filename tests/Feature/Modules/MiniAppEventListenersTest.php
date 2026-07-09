<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Channels\Models\OutboundMiniAppNotification;
use App\Modules\Channels\Services\MiniAppOutboundNotifier;
use App\Modules\Crm\Events\ContactArchived;
use App\Modules\Crm\Events\LeadStatusChanged;
use App\Modules\Crm\Listeners\NotifyContactOnContactArchived;
use App\Modules\Crm\Listeners\NotifyContactOnLeadStatusChanged;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ExternalIdentity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Pipeline;
use App\Modules\Crm\Models\Stage;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Specs/15 § C4 — domain-event → Mini App notification mapping.
 *
 * Two listeners:
 *  - NotifyContactOnLeadStatusChanged: only fires for WON/LOST transitions
 *    AND only when notify_user=true.
 *  - NotifyContactOnContactArchived: always fires.
 *
 * Tests verify the dispatch shape (template code + params) and the gate
 * conditions. The MiniAppOutboundNotifier itself has its own test file.
 */
class MiniAppEventListenersTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);

        // Hook a stub notifier so we can count calls without exercising the
        // real template-mapping path (covered in MiniAppOutboundNotifierTest).
        $this->notifierCalls = [];
        $this->app->instance(MiniAppOutboundNotifier::class, new class extends MiniAppOutboundNotifier
        {
            public array $calls = [];

            public function notifyContact(Contact $contact, string $templateCode, array $params = []): ?OutboundMiniAppNotification
            {
                $this->calls[] = compact('contact', 'templateCode', 'params');

                return null;
            }
        });
    }

    private array $notifierCalls = [];

    private function contactWithZaloOA(string $oaUserId): Contact
    {
        $contact = Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'User',
            'status' => 'ACTIVE',
            'source' => 'ZALO_OA',
        ]);
        $oa = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'ZALO_OA',
            'name' => 'OA',
            'status' => 'ACTIVE',
        ]);
        ExternalIdentity::create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'provider' => 'ZALO_OA',
            'provider_account_id' => $oa->id,
            'provider_user_id' => $oaUserId,
        ]);

        return $contact;
    }

    private function leadForContact(Contact $contact): Lead
    {
        $pipeline = Pipeline::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Sales',
            'type' => 'LEAD',
            'is_default' => true,
        ]);
        $stage = Stage::create([
            'workspace_id' => $this->workspace->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'New',
            'sort_order' => 1,
            'status_group' => 'OPEN',
        ]);

        return Lead::create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'owner_id' => User::factory()->create(['workspace_id' => $this->workspace->id, 'role' => 'sales'])->id,
            'title' => 'Big deal',
            'status' => 'NEW',
            'source' => 'MANUAL',
            'last_activity_at' => now(),
        ]);
    }

    // -------------------------------------------------------------- LeadStatusChanged

    public function test_won_with_notify_user_true_dispatches_lead_won(): void
    {
        $contact = $this->contactWithZaloOA('oa-1');
        $lead = $this->leadForContact($contact);
        $lead->forceFill(['status' => 'QUALIFYING'])->save();

        LeadStatusChanged::dispatch($lead->id, 'QUALIFYING', 'WON', true);

        $notifier = $this->app->make(MiniAppOutboundNotifier::class);
        $this->assertCount(1, $notifier->calls);
        $this->assertSame('lead_won', $notifier->calls[0]['templateCode']);
        $this->assertSame('WON', $notifier->calls[0]['params']['new_status']);
        $this->assertSame('QUALIFYING', $notifier->calls[0]['params']['previous_status']);
        $this->assertSame('Big deal', $notifier->calls[0]['params']['lead_title']);
    }

    public function test_lost_with_notify_user_true_dispatches_lead_lost(): void
    {
        $contact = $this->contactWithZaloOA('oa-1');
        $lead = $this->leadForContact($contact);
        $lead->forceFill(['status' => 'OPEN'])->save();

        LeadStatusChanged::dispatch($lead->id, 'OPEN', 'LOST', true);

        $notifier = $this->app->make(MiniAppOutboundNotifier::class);
        $this->assertCount(1, $notifier->calls);
        $this->assertSame('lead_lost', $notifier->calls[0]['templateCode']);
    }

    public function test_notify_user_false_skips_even_on_won(): void
    {
        $contact = $this->contactWithZaloOA('oa-1');
        $lead = $this->leadForContact($contact);
        $lead->forceFill(['status' => 'QUALIFYING'])->save();

        LeadStatusChanged::dispatch($lead->id, 'QUALIFYING', 'WON', false);

        $notifier = $this->app->make(MiniAppOutboundNotifier::class);
        $this->assertCount(0, $notifier->calls);
    }

    public function test_non_terminal_status_with_notify_user_true_does_not_dispatch(): void
    {
        $contact = $this->contactWithZaloOA('oa-1');
        $lead = $this->leadForContact($contact);
        $lead->forceFill(['status' => 'NEW'])->save();

        // NEW → QUALIFYING isn't a terminal transition; no template applies.
        LeadStatusChanged::dispatch($lead->id, 'NEW', 'QUALIFYING', true);

        $notifier = $this->app->make(MiniAppOutboundNotifier::class);
        $this->assertCount(0, $notifier->calls);
    }

    // -------------------------------------------------------------- ContactArchived

    public function test_archived_contact_dispatches_contact_archived_template(): void
    {
        $contact = $this->contactWithZaloOA('oa-1');

        ContactArchived::dispatch($contact->id, $this->workspace->id);

        $notifier = $this->app->make(MiniAppOutboundNotifier::class);
        $this->assertCount(1, $notifier->calls);
        $this->assertSame('contact_archived', $notifier->calls[0]['templateCode']);
        $this->assertArrayHasKey('archived_at', $notifier->calls[0]['params']);
    }
}
