<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Crm\Models\WorkspaceIngestToken;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Cut 3 of specs/15_CONTACTS_INGESTION.md — admin CRUD for
 * workspace_ingest_tokens.
 *
 * Covers: list, mint (plaintext returned once, never re-exposed),
 * rotate (new token issued + old revoked), revoke (soft delete),
 * RBAC (owner/admin only).
 */
class IngestTokenAdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset rate-limiter buckets so cross-test bleed doesn't happen.
        RateLimiter::clear('ingest:token:'.str_repeat('x', 36));
        RateLimiter::clear('ingest:workspace:'.str_repeat('x', 36));

        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);
        $this->owner = User::factory()->create([
            'workspace_id' => $this->workspace->id,
            'role' => 'owner',
            'status' => 'ACTIVE',
        ]);
    }

    private function mintDirect(array $overrides = []): WorkspaceIngestToken
    {
        $plaintext = 'whk_'.str_repeat('A', 32);

        return WorkspaceIngestToken::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'name' => 'Landing page',
            'token_prefix' => substr($plaintext, 0, 8),
            'token_hash' => password_hash($plaintext, PASSWORD_BCRYPT),
            'allowed_sources' => ['WEBSITE_FORM'],
            'rate_limit_per_minute' => 60,
        ], $overrides));
    }

    public function test_owner_can_list_tokens(): void
    {
        $tokenA = $this->mintDirect(['name' => 'Landing A']);
        $tokenB = $this->mintDirect(['name' => 'Landing B']);

        $response = $this->actingAs($this->owner)
            ->get(route('admin.ingest-tokens.index'));

        $response->assertOk();
        $body = $response->json('data');
        $this->assertCount(2, $body);
        // Plaintext is never returned by the list endpoint.
        foreach ($body as $row) {
            $this->assertArrayNotHasKey('plaintext', $row);
            $this->assertArrayHasKey('token_prefix', $row);
            $this->assertContains($row['name'] ?? null, ['Landing A', 'Landing B']);
        }

        // Per-row shape sanity (one row).
        $row = collect($body)->firstWhere('id', $tokenA->id);
        $this->assertSame($tokenA->token_prefix, $row['token_prefix']);
        $this->assertTrue($row['is_active']);
        $this->assertSame(['WEBSITE_FORM'], $row['allowed_sources']);
    }

    public function test_owner_can_mint_token_and_get_plaintext_once(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('admin.ingest-tokens.store'), [
                'name' => 'Summer Landing',
                'allowed_sources' => ['WEBSITE_FORM'],
                'rate_limit_per_minute' => 30,
            ]);

        $response->assertCreated();
        $payload = $response->json();
        $this->assertNotEmpty($payload['plaintext']);
        $this->assertStringStartsWith('whk_', $payload['plaintext']);
        $this->assertSame(36, strlen($payload['plaintext'])); // whk_ + 32 chars

        // Token is persisted; plaintext is gone from the row.
        $token = WorkspaceIngestToken::query()->firstOrFail();
        $this->assertSame(60, strlen($token->token_hash)); // bcrypt hash
        $this->assertSame(8, strlen($token->token_prefix));
        $this->assertSame('Summer Landing', $token->name);
        $this->assertSame(30, $token->rate_limit_per_minute);
        $this->assertNull($token->revoked_at);

        // List endpoint doesn't return plaintext either.
        $listResp = $this->actingAs($this->owner)
            ->getJson(route('admin.ingest-tokens.index'));
        $listResp->assertOk();
        foreach ($listResp->json('data') as $row) {
            $this->assertArrayNotHasKey('plaintext', $row);
        }
    }

    public function test_mini_app_token_mint_returns_hmac_secret(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('admin.ingest-tokens.store'), [
                'name' => 'Zalo Mini App',
                'allowed_sources' => ['ZALO_MINIAPP'],
                'with_hmac' => true,
            ]);

        $response->assertCreated();
        $payload = $response->json();
        $this->assertStringStartsWith('zmp_', $payload['plaintext']);
        $this->assertNotEmpty($payload['hmac_secret']);
        $this->assertSame(64, strlen($payload['hmac_secret']));

        // The HMAC secret is stored encrypted — read-back returns plaintext
        // (Laravel Crypt cast), so the controller can verify signatures.
        $token = WorkspaceIngestToken::query()->firstOrFail();
        $this->assertTrue($token->requiresHmac());
        $this->assertSame($payload['hmac_secret'], $token->hmac_secret);
    }

    public function test_support_agent_cannot_mint_token(): void
    {
        $agent = User::factory()->create([
            'workspace_id' => $this->workspace->id,
            'role' => 'support_agent',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($agent)
            ->post(route('admin.ingest-tokens.store'), [
                'name' => 'X',
                'allowed_sources' => ['WEBSITE_FORM'],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('workspace_ingest_tokens', 0);
    }

    public function test_owner_can_revoke_token(): void
    {
        $token = $this->mintDirect(['name' => 'X']);

        $this->actingAs($this->owner)
            ->deleteJson(route('admin.ingest-tokens.destroy', $token->id))
            ->assertOk();

        $fresh = $token->fresh();
        $this->assertNotNull($fresh->revoked_at);
        $this->assertFalse($fresh->isUsable());
    }

    public function test_owner_can_rotate_token_and_old_gets_revoked(): void
    {
        $old = $this->mintDirect(['name' => 'Original']);

        $response = $this->actingAs($this->owner)
            ->postJson(route('admin.ingest-tokens.rotate', $old->id));

        $response->assertCreated();
        $payload = $response->json();
        $this->assertStringStartsWith('whk_', $payload['plaintext']);

        // Two rows now: old (revoked) + new (active).
        $this->assertSame(2, WorkspaceIngestToken::query()->count());

        $oldFresh = $old->fresh();
        $this->assertNotNull($oldFresh->revoked_at);
        $this->assertFalse($oldFresh->isUsable());

        $newToken = WorkspaceIngestToken::query()
            ->where('id', '!=', $old->id)
            ->firstOrFail();
        $this->assertTrue($newToken->isUsable());
        // Same name with "(rotated)" suffix.
        $this->assertSame('Original (rotated)', $newToken->name);
    }

    public function test_mint_rejects_source_outside_allowlist(): void
    {
        // The API route under /api/admin/* returns JSON 422 for validation
        // failures (Laravel's default for /api/*); use the JSON variant of
        // the assertion instead of assertSessionHasErrors.
        $this->actingAs($this->owner)
            ->postJson(route('admin.ingest-tokens.store'), [
                'name' => 'Bad',
                'allowed_sources' => ['TELEGRAM'], // not in ALLOWED_PUBLIC_SOURCES
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('allowed_sources.0');

        $this->assertDatabaseCount('workspace_ingest_tokens', 0);
    }

    public function test_mint_requires_at_least_one_allowed_source(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('admin.ingest-tokens.store'), [
                'name' => 'No sources',
                'allowed_sources' => [],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('allowed_sources');
    }

    public function test_destroy_is_tenant_scoped(): void
    {
        // Token in another workspace — owner cannot delete it.
        $otherWs = Workspace::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'ACTIVE']);
        $stranger = WorkspaceIngestToken::create([
            'workspace_id' => $otherWs->id,
            'name' => 'X',
            'token_prefix' => 'whk_zzzz',
            'token_hash' => password_hash('whk_zzzz'.str_repeat('A', 32), PASSWORD_BCRYPT),
            'allowed_sources' => ['WEBSITE_FORM'],
        ]);

        $this->actingAs($this->owner)
            ->deleteJson(route('admin.ingest-tokens.destroy', $stranger->id))
            ->assertForbidden();
    }
}