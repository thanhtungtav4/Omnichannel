<?php

namespace Tests\Feature\Modules;

use App\Models\User;
use App\Modules\Crm\Models\Contact;
use App\Modules\Crm\Models\ContactIngestEvent;
use App\Modules\Crm\Models\ContactIngestFailure;
use App\Modules\Crm\Models\WorkspaceIngestToken;
use App\Modules\Channels\Models\ChannelAccount;
use App\Modules\Platform\Models\AuditLog;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Cut 3 of specs/15_CONTACTS_INGESTION.md — public contact-ingest endpoint.
 *
 * The endpoint is OUT-OF-TENANT (no auth middleware). Workspace resolution
 * comes from X-Workspace-Key. CSRF is exempted for /api/public/* in
 * bootstrap/app.php so this works under the `web` group.
 *
 * Covers: happy 201, dedup 200, matched update 200, 409 collision,
 * 401 (missing / malformed / revoked / expired / bad bcrypt), 403
 * (source not allowed, origin not in whitelist), 422 (validation
 * failure writes a contact_ingest_failures row), HMAC verification
 * (valid / missing / stale / invalid / token-without-hmac), audit log
 * row on success.
 */
class PublicIngestControllerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private WorkspaceIngestToken $formToken;

    private WorkspaceIngestToken $miniappToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::create(['name' => 'W', 'slug' => 'w-'.uniqid(), 'status' => 'ACTIVE']);

        // Form token (no HMAC).
        $formPlaintext = 'whk_'.str_repeat('A', 32);
        $this->formToken = WorkspaceIngestToken::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Landing A',
            'token_prefix' => substr($formPlaintext, 0, 8),
            'token_hash' => password_hash($formPlaintext, PASSWORD_BCRYPT),
            'allowed_sources' => ['WEBSITE_FORM'],
            'rate_limit_per_minute' => 60,
        ]);

        // Mini App token (HMAC required, ZALO_MINIAPP only).
        $miniPlaintext = 'zmp_'.str_repeat('B', 32);
        $this->miniappToken = WorkspaceIngestToken::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Mini App',
            'token_prefix' => substr($miniPlaintext, 0, 8),
            'token_hash' => password_hash($miniPlaintext, PASSWORD_BCRYPT),
            'allowed_sources' => ['ZALO_MINIAPP'],
            'hmac_secret' => 'mini-app-shared-secret',
            'rate_limit_per_minute' => 60,
        ]);

        // Clear rate limiter buckets between tests.
        RateLimiter::clear('ingest:token:'.$this->formToken->id);
        RateLimiter::clear('ingest:token:'.$this->miniappToken->id);
        RateLimiter::clear('ingest:workspace:'.$this->workspace->id);
    }

    private function formPlaintext(): string
    {
        // The plaintext we hashed was whk_ + 32 As.
        return 'whk_'.str_repeat('A', 32);
    }

    private function miniappPlaintext(): string
    {
        return 'zmp_'.str_repeat('B', 32);
    }

    private function signMiniApp(string $body): array
    {
        $ts = time();
        $sig = hash_hmac('sha256', $ts.'.'.$body, 'mini-app-shared-secret');

        return [
            // Return the server-style key directly so the result merges cleanly
            // with transformHeaders() output.
            'HTTP_X_SIGNATURE' => "t={$ts},s={$sig}",
        ];
    }

    // ------------------------------------------------------------ happy path

    public function test_form_token_creates_contact_on_first_call(): void
    {
        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'Nguyen Van A',
            'phone' => '0912345678',
            'email' => 'a@example.test',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
            'X-Source-Event-Id' => 'evt-001',
        ]);

        $response->assertCreated()
            ->assertJson([
                'created' => true,
                'dedup_hit' => false,
                'ingest_event_id' => 'evt-001',
            ]);

        $contactId = $response->json('contact_id');
        $this->assertNotEmpty($contactId);

        // Contact row written.
        $contact = Contact::query()->findOrFail($contactId);
        $this->assertSame('Nguyen Van A', $contact->full_name);
        $this->assertSame('0912345678', $contact->phone);
        $this->assertSame('WEBSITE_FORM', $contact->source);
        $this->assertSame($this->workspace->id, $contact->workspace_id);
        // Server-side context attached to attributes.
        $this->assertSame('web_form', $contact->attributes['event_source'] ?? null);
        $this->assertNotEmpty($contact->attributes['received_at'] ?? null);

        // Ingest event recorded.
        $this->assertDatabaseHas('contact_ingest_events', [
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'source' => 'WEBSITE_FORM',
            'source_event_id' => 'evt-001',
        ]);

        // Token last_used_at updated.
        $this->assertNotNull($this->formToken->fresh()->last_used_at);

        // Audit log row.
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $this->workspace->id,
            'module' => 'crm',
            'action' => 'contact.ingested',
            'subject_id' => $contact->id,
        ]);
    }

    public function test_dedup_returns_same_contact_with_200(): void
    {
        // First call creates.
        $first = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'Nguyen Van A',
            'phone' => '0912345678',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
            'X-Source-Event-Id' => 'evt-dedup',
        ])->assertCreated()->json('contact_id');

        // Second call with same event id, same payload — returns 200, dedup_hit=true.
        $second = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'Nguyen Van A',
            'phone' => '0912345678',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
            'X-Source-Event-Id' => 'evt-dedup',
        ]);

        $second->assertOk()
            ->assertJson([
                'contact_id' => $first,
                'created' => false,
                'dedup_hit' => true,
                'ingest_event_id' => 'evt-dedup',
            ]);

        // Only one contact row.
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_event_id_collision_with_different_payload_returns_409_and_records_failure(): void
    {
        $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'Original',
            'phone' => '0912345678',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
            'X-Source-Event-Id' => 'evt-collide',
        ])->assertCreated();

        // Different payload under same event id — collision.
        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'Completely Different',
            'phone' => '0999999999',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
            'X-Source-Event-Id' => 'evt-collide',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'EVENT_ID_COLLISION');

        // Failure row written.
        $this->assertDatabaseHas('contact_ingest_failures', [
            'workspace_id' => $this->workspace->id,
            'token_id' => $this->formToken->id,
            'source' => 'WEBSITE_FORM',
        ]);
    }

    // ------------------------------------------------------------ auth

    public function test_missing_token_returns_401(): void
    {
        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], ['X-Source' => 'WEBSITE_FORM']);

        $response->assertUnauthorized()->assertJsonPath('error.code', 'MISSING_TOKEN');
    }

    public function test_invalid_token_returns_401(): void
    {
        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], [
            'X-Workspace-Key' => 'whk_'.str_repeat('Z', 32),
            'X-Source' => 'WEBSITE_FORM',
        ]);

        $response->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_revoked_token_returns_401(): void
    {
        $this->formToken->forceFill(['revoked_at' => now()])->save();

        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
        ]);

        $response->assertUnauthorized()->assertJsonPath('error.code', 'TOKEN_INACTIVE');
    }

    public function test_expired_token_returns_401(): void
    {
        $this->formToken->forceFill(['expires_at' => now()->subMinute()])->save();

        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
        ]);

        $response->assertUnauthorized()->assertJsonPath('error.code', 'TOKEN_INACTIVE');
    }

    // ------------------------------------------------------------ source scoping

    public function test_source_not_allowed_for_token_returns_403(): void
    {
        // Form token has only WEBSITE_FORM. Sending ZALO_MINIAPP must fail.
        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'ZALO_MINIAPP',
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'SOURCE_NOT_ALLOWED');
    }

    public function test_missing_source_header_returns_403(): void
    {
        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'SOURCE_NOT_ALLOWED');
    }

    // ------------------------------------------------------------ origin whitelist

    public function test_origin_whitelist_blocks_unmatched_origin(): void
    {
        $this->formToken->forceFill([
            'domain_whitelist' => 'https://allowed.example.com',
        ])->save();

        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
            'Origin' => 'https://evil.example.com',
        ]);

        $response->assertForbidden()->assertJsonPath('error.code', 'ORIGIN_NOT_ALLOWED');
        $this->assertDatabaseHas('contact_ingest_failures', [
            'workspace_id' => $this->workspace->id,
            'source' => 'WEBSITE_FORM',
        ]);
    }

    public function test_origin_whitelist_allows_matching_origin(): void
    {
        $this->formToken->forceFill([
            'domain_whitelist' => 'allowed.example.com',
        ])->save();

        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
            'Origin' => 'https://allowed.example.com/form',
        ]);

        $response->assertCreated();
    }

    // ------------------------------------------------------------ validation

    public function test_validation_failure_writes_failure_row_and_returns_422(): void
    {
        $response = $this->postJson(route('public.ingest.contact'), [
            'email' => 'not-an-email',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // No contact row written.
        $this->assertDatabaseCount('contacts', 0);

        // Failure row written.
        $this->assertDatabaseHas('contact_ingest_failures', [
            'workspace_id' => $this->workspace->id,
            'source' => 'WEBSITE_FORM',
            'token_id' => $this->formToken->id,
        ]);
    }

    public function test_miniapp_full_name_is_optional(): void
    {
        // external_identity.provider_account_id is a FK to channel_accounts.id,
        // so we have to seed a real ZALO_OA channel account for the test.
        $oaAccount = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'ZALO_OA',
            'name' => 'OA Test',
            'status' => 'ACTIVE',
        ]);

        $body = json_encode([
            'phone' => '0912345678',
            'external_identity' => [
                'provider' => 'ZALO_OA',
                'provider_account_id' => $oaAccount->id,
                'provider_user_id' => 'u-1',
                'display_name' => 'Zalo User',
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            route('public.ingest.contact'),
            [], [], [],
            $this->transformHeaders([
                'X-Workspace-Key' => $this->miniappPlaintext(),
                'X-Source' => 'ZALO_MINIAPP',
                'X-Source-Event-Id' => 'ma-001',
                'Content-Type' => 'application/json',
            ]) + $this->signMiniApp($body),
            $body,
        );

        $response->assertCreated();
        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $this->workspace->id,
            'source' => 'ZALO_MINIAPP',
            'phone' => '0912345678',
        ]);
    }

    // ------------------------------------------------------------ HMAC

    public function test_miniapp_with_valid_hmac_creates_contact(): void
    {
        $oaAccount = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'provider' => 'ZALO_OA',
            'name' => 'OA Test',
            'status' => 'ACTIVE',
        ]);

        $body = json_encode([
            'full_name' => 'Mini App User',
            'external_identity' => [
                'provider' => 'ZALO_OA',
                'provider_account_id' => $oaAccount->id,
                'provider_user_id' => 'u-1',
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            route('public.ingest.contact'),
            [], [], [],
            $this->transformHeaders([
                'X-Workspace-Key' => $this->miniappPlaintext(),
                'X-Source' => 'ZALO_MINIAPP',
                'X-Source-Event-Id' => 'ma-hmac-ok',
                'Content-Type' => 'application/json',
            ]) + $this->signMiniApp($body),
            $body,
        );

        fwrite(STDERR, "\n--- STATUS: " . $response->status() . "\n");
        fwrite(STDERR, "--- BODY: " . substr($response->getContent(), 0, 800) . "\n");

        $response->assertCreated();
    }

    public function test_miniapp_with_missing_signature_returns_401(): void
    {
        $body = json_encode(['full_name' => 'X'], JSON_THROW_ON_ERROR);

        $response = $this->call(
            'POST',
            route('public.ingest.contact'),
            [], [], [],
            $this->transformHeaders([
                'X-Workspace-Key' => $this->miniappPlaintext(),
                'X-Source' => 'ZALO_MINIAPP',
                'Content-Type' => 'application/json',
            ]),
            $body,
        );

        $response->assertUnauthorized()->assertJsonPath('error.code', 'MISSING_SIGNATURE');
    }

    public function test_miniapp_with_stale_signature_returns_401(): void
    {
        $body = json_encode(['full_name' => 'X'], JSON_THROW_ON_ERROR);
        $ts = time() - 600; // 10 min ago, outside 5-min window
        $sig = hash_hmac('sha256', $ts.'.'.$body, 'mini-app-shared-secret');

        $response = $this->call(
            'POST',
            route('public.ingest.contact'),
            [], [], [],
            $this->transformHeaders([
                'X-Workspace-Key' => $this->miniappPlaintext(),
                'X-Source' => 'ZALO_MINIAPP',
                'X-Signature' => "t={$ts},s={$sig}",
                'Content-Type' => 'application/json',
            ]),
            $body,
        );

        $response->assertUnauthorized()->assertJsonPath('error.code', 'STALE_SIGNATURE');
    }

    public function test_miniapp_with_invalid_signature_returns_401(): void
    {
        $body = json_encode(['full_name' => 'X'], JSON_THROW_ON_ERROR);
        $ts = time();

        $response = $this->call(
            'POST',
            route('public.ingest.contact'),
            [], [], [],
            $this->transformHeaders([
                'X-Workspace-Key' => $this->miniappPlaintext(),
                'X-Source' => 'ZALO_MINIAPP',
                'X-Signature' => "t={$ts},s=".str_repeat('0', 64),
                'Content-Type' => 'application/json',
            ]),
            $body,
        );

        $response->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_SIGNATURE');
    }

    public function test_miniapp_token_without_hmac_secret_returns_401_for_miniapp_source(): void
    {
        // Mint a new miniapp-style token but strip hmac_secret.
        $plain = 'zmp_'.str_repeat('C', 32);
        $token = WorkspaceIngestToken::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'No HMAC',
            'token_prefix' => substr($plain, 0, 8),
            'token_hash' => password_hash($plain, PASSWORD_BCRYPT),
            'allowed_sources' => ['ZALO_MINIAPP'],
            'hmac_secret' => null,
        ]);

        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], [
            'X-Workspace-Key' => $plain,
            'X-Source' => 'ZALO_MINIAPP',
            'X-Signature' => 't='.time().',s='.str_repeat('0', 64),
        ]);

        $response->assertUnauthorized()->assertJsonPath('error.code', 'HMAC_NOT_CONFIGURED');
    }

    public function test_form_token_skips_hmac_check_for_form_source(): void
    {
        // Form tokens don't require HMAC. The signature middleware should
        // pass-through when source != ZALO_MINIAPP.
        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'X',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
            // No X-Signature header — should still work.
        ]);

        $response->assertCreated();
    }

    // ------------------------------------------------------------ matched update

    public function test_matched_contact_phone_returns_existing_contact(): void
    {
        // Pre-existing contact with phone 0912345678 (Zalo-derived).
        Contact::create([
            'workspace_id' => $this->workspace->id,
            'full_name' => 'Already Known',
            'phone' => '0912345678',
            'phone_normalized' => '84912345678',
            'status' => 'ACTIVE',
            'source' => 'ZALO_PERSONAL',
        ]);

        // Web form submission with same phone should match, not create new.
        $response = $this->postJson(route('public.ingest.contact'), [
            'full_name' => 'Web Lead Same Person',
            'phone' => '0912345678',
        ], [
            'X-Workspace-Key' => $this->formPlaintext(),
            'X-Source' => 'WEBSITE_FORM',
            'X-Source-Event-Id' => 'evt-match',
        ]);

        $response->assertOk()
            ->assertJson(['created' => false, 'dedup_hit' => false]);

        $this->assertSame(1, Contact::query()->count());
    }

    // ------------------------------------------------------------ helpers

    /**
     * Transform a flat headers array into the SERVER-style array Laravel's
     * request bag expects for `call()` (which doesn't run TestCase's
     * postJson helpers).
     *
     * `Content-Type` is special — PHP's $_SERVER exposes it as CONTENT_TYPE
     * (no HTTP_ prefix), so we keep it under that key. Everything else gets
     * the standard HTTP_<UPPER_SNAKE_CASE> shape.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function transformHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Content-Type') === 0) {
                $server['CONTENT_TYPE'] = $value;
                continue;
            }
            $key = 'HTTP_'.str_replace('-', '_', strtoupper($name));
            $server[$key] = $value;
        }

        return $server;
    }
}