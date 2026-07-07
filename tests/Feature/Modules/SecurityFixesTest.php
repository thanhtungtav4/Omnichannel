<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Crm\Models\Lead;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityFixesTest extends TestCase
{
    use RefreshDatabase;

    private function ws(): Workspace
    {
        return Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
    }

    private function oaAccount(Workspace $ws): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $ws->id, 'provider' => 'ZALO_OA', 'name' => 'OA', 'status' => 'ACTIVE',
            'credentials' => ['app_id' => 'a', 'app_secret' => 'oa-secret'],
        ]);
    }

    /** #1 ZALO_OA webhook rejects a request with no / wrong HMAC signature. */
    public function test_zalo_oa_webhook_rejects_bad_signature(): void
    {
        $account = $this->oaAccount($this->ws());

        $this->postJson(route('webhooks.zalo', $account), ['foo' => 'bar'])
            ->assertStatus(401);

        $this->withHeader('X-Zalo-Signature', 'sha256=deadbeef')
            ->postJson(route('webhooks.zalo', $account), ['foo' => 'bar'])
            ->assertStatus(401);
    }

    /** #1 ZALO_OA webhook accepts a request signed with the OA app_secret. */
    public function test_zalo_oa_webhook_accepts_valid_signature(): void
    {
        $account = $this->oaAccount($this->ws());
        $body = json_encode(['foo' => 'bar']);
        $sig = 'sha256='.hash_hmac('sha256', $body, 'oa-secret');

        $this->call(
            'POST',
            route('webhooks.zalo', $account),
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_ZALO_SIGNATURE' => $sig],
            $body,
        )->assertOk();
    }

    /** #5 webhook_secret is stored encrypted at rest but reads back plaintext. */
    public function test_webhook_secret_encrypted_at_rest(): void
    {
        $account = ChannelAccount::create([
            'workspace_id' => $this->ws()->id, 'provider' => 'TELEGRAM', 'name' => 'B',
            'status' => 'ACTIVE', 'webhook_secret' => 'my-secret',
        ]);

        $this->assertSame('my-secret', $account->fresh()->webhook_secret);
        $raw = \DB::table('channel_accounts')->where('id', $account->id)->value('webhook_secret');
        $this->assertNotSame('my-secret', $raw); // ciphertext in the column
    }

    /** #3 a non-sales role cannot move a lead's status. */
    public function test_viewer_cannot_update_lead_status(): void
    {
        $ws = $this->ws();
        $viewer = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'support_agent', 'status' => 'ACTIVE']);
        $lead = Lead::create([
            'workspace_id' => $ws->id, 'title' => 'L', 'status' => 'NEW', 'source' => 'TELEGRAM',
        ]);

        // Denied via a flash-toast redirect (not a raw 403), and the status
        // must not have changed.
        $this->actingAs($viewer)
            ->put(route('admin.leads.status', $lead), ['status' => 'WON'])
            ->assertRedirect();

        $this->assertSame('NEW', $lead->fresh()->status);
    }

    /** #3 a sales role can move a lead's status. */
    public function test_sales_can_update_lead_status(): void
    {
        $ws = $this->ws();
        $sales = User::factory()->create(['workspace_id' => $ws->id, 'role' => 'sales', 'status' => 'ACTIVE']);
        $lead = Lead::create([
            'workspace_id' => $ws->id, 'title' => 'L', 'status' => 'NEW', 'source' => 'TELEGRAM',
        ]);

        $this->actingAs($sales)
            ->put(route('admin.leads.status', $lead), ['status' => 'WON'])
            ->assertRedirect();

        $this->assertSame('WON', $lead->fresh()->status);
    }
}
