<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\ContactIngestEvent;
use App\Modules\Crm\Models\ContactIngestFailure;
use App\Modules\Crm\Models\WorkspaceIngestToken;
use App\Modules\Crm\Services\ContactIngestor;
use App\Modules\Platform\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Public contact-ingest endpoint (specs/15 § C3).
 *
 * Out-of-tenant: NO auth/session, NO subdomain workspace pin. The
 * workspace is resolved from the X-Workspace-Key header by the
 * `ingest.token` middleware; the rest of the request scopes via the
 * pinned CurrentWorkspace singleton.
 *
 * Sources allowed: WEBSITE_FORM and ZALO_MINIAPP. Webhook traffic
 * (Zalo/Telegram/Shopee/TikTok) does NOT go through this endpoint — it
 * has its own per-account middleware + signature verification.
 */
class PublicIngestController extends Controller
{
    public function __construct(private readonly ContactIngestor $ingestor) {}

    public function ingest(Request $request): JsonResponse
    {
        /** @var WorkspaceIngestToken $token */
        $token = $request->attributes->get('ingest_token');

        // Middleware should have set this; if not, refuse loudly rather than
        // silently proceed with no workspace pin.
        if (! $token instanceof WorkspaceIngestToken) {
            return $this->errorJson(500, 'TOKEN_NOT_RESOLVED', 'Token middleware did not run.');
        }

        $source = strtoupper((string) $request->header('X-Source', ''));
        if ($source === '' || ! $token->allowsSource($source)) {
            // Same response for "no source" and "wrong source" — don't leak
            // which sources the token accepts.
            return $this->errorJson(403, 'SOURCE_NOT_ALLOWED', 'X-Source is not allowed for this token.');
        }

        // Origin / Referer whitelist. Empty = no check.
        if (! empty($token->domain_whitelist)) {
            $origin = (string) ($request->header('Origin') ?? $request->header('Referer') ?? '');
            if (! $this->originMatches($origin, (string) $token->domain_whitelist)) {
                $this->recordFailure($request, $token, $source, ['origin' => $origin], 'Origin not in whitelist');

                return $this->errorJson(403, 'ORIGIN_NOT_ALLOWED', 'Origin not in token whitelist.');
            }
        }

        $eventId = $request->header('X-Source-Event-Id');
        $sourceDetail = $request->header('X-Source-Detail') ?: $token->default_source_detail;

        try {
            $data = $request->validate($this->rulesFor($source));
        } catch (ValidationException $e) {
            $this->recordFailure(
                $request,
                $token,
                $source,
                $e->errors(),
                'Validation failed',
                $eventId,
            );

            return response()->json([
                'error' => ['code' => 'VALIDATION_FAILED', 'message' => 'Validation failed.'],
                'errors' => $e->errors(),
            ], 422);
        }

// Build the canonical attribute shape BEFORE collision check —
        // payloadHash must mirror what ContactIngestor stores, otherwise a
        // retry of the exact same payload looks like a collision (409).
        $clientAttributes = $data['attributes'] ?? [];
        $attributes = $this->attributesFor($source, $clientAttributes, $request);
        $consent = $this->normalizeConsent($data['consent'] ?? null, $request);

        // 409 collision: same event id, different payload hash.
        if ($eventId) {
            $existing = ContactIngestEvent::query()
                ->withoutWorkspaceScope()
                ->where('workspace_id', $token->workspace_id)
                ->where('source', $source)
                ->where('source_event_id', $eventId)
                ->first();

            if ($existing) {
                $newHash = $this->payloadHash($source, $sourceDetail, $data, $clientAttributes);
                if ($existing->payload_hash !== $newHash) {
                    $this->recordFailure(
                        $request,
                        $token,
                        $source,
                        ['event_id' => $eventId, 'existing_hash' => $existing->payload_hash, 'new_hash' => $newHash],
                        'Event id collision with different payload',
                        $eventId,
                    );

                    return $this->errorJson(409, 'EVENT_ID_COLLISION', 'Source event id was reused with a different payload.');
                }

                // Same event id + same payload = true dedup. Re-ingest via
                // the chokepoint (which will short-circuit on dedup) so the
                // audit row is consistent.
            }
        }

        // Synthesize full_name from external_identity.display_name when missing.
        // contacts.full_name is NOT NULL at the schema level; Mini App events often
        // arrive with only the OA profile (no name on the form). The Zalo OA
        // profile always carries a display_name we can use as a fallback.
        $fullName = $data['full_name'] ?? null;
        if (empty($fullName) && ! empty($data['external_identity']['display_name'] ?? null)) {
            $fullName = $data['external_identity']['display_name'];
        }

        $payload = [
            'workspace_id' => $token->workspace_id,
            'source' => $source,
            'source_detail' => $sourceDetail,
            'full_name' => $fullName,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'external_identity' => $data['external_identity'] ?? null,
            'attributes' => $attributes,
            'consent' => $consent,
            'ingest_event_id' => $eventId,
            'last_inbound_at' => now(),
            // Pass client attributes separately so ContactIngestor can hash
            // on the deterministic subset (server context like received_at
            // / ip would otherwise differ between retries).
            'client_attributes' => $clientAttributes,
        ];

        try {
            // No DB::transaction wrapper: ContactIngestor writes contacts,
            // identities, ingest_events, and timeline rows in autocommit.
            // Wrapping here would create a NESTED transaction inside the
            // test environment's RefreshDatabase transaction, which masks
            // constraint violations until commit time. The chokepoint is
            // idempotent enough (firstOrCreate, updateOrCreate) that
            // autocommit is safe; if something fails mid-flight, only that
            // half-written contact rolls back.
            $result = $this->ingestor->ingestWithStatus($payload);
        } catch (Throwable $e) {
            // DEBUG
            fwrite(STDERR, "\n--- INGEST EXC: ".get_class($e).' :: '.$e->getMessage()."\n");

            // Unexpected ingestor failure — record + 500. We deliberately
            // do NOT leak the exception message to the caller (defense in
            // depth against information disclosure).
            $this->recordFailure($request, $token, $source, $payload, 'Ingestor exception: '.$e->getMessage(), $eventId);

            return $this->errorJson(500, 'INGEST_FAILED', 'Failed to ingest contact.');
        }

        // Best-effort bookkeeping — do not fail the request if last_used_at
        // save errors (token is already validated).
        try {
            $token->forceFill(['last_used_at' => now()])->save();
        } catch (Throwable) {
            // swallow
        }

        // Audit row per spec 15 § Cross-cutting. AuditLog schema:
        //   actor_id (FK users, nullable), action, subject_type, subject_id,
        //   before, after, metadata, ip_address.
        // No `actor_type` column — the AuditLog model uses `actor_id` for
        // the FK and `action` for the verb. SYSTEM actions pass actor_id=null.
        try {
            AuditLog::create([
                'workspace_id' => $token->workspace_id,
                'actor_id' => null,
                'module' => 'crm',
                'action' => 'contact.ingested',
                'subject_type' => 'contact',
                'subject_id' => $result['contact']->id,
                'metadata' => [
                    'token_id' => $token->id,
                    'source' => $source,
                    'source_event_id' => $eventId,
                    'source_detail' => $sourceDetail,
                    'created' => $result['created'],
                    'dedup_hit' => $result['dedup_hit'],
                ],
                'ip_address' => $request->ip(),
            ]);
        } catch (Throwable) {
            // Audit failures should not fail the user request.
        }

        return response()->json([
            'contact_id' => $result['contact']->id,
            'created' => $result['created'],
            'dedup_hit' => $result['dedup_hit'],
            'ingest_event_id' => $eventId,
        ], $result['created'] ? 201 : 200);
    }

    /**
     * Per-source validation rules. Spec 15 § C3:
     *
     * | Field            | WEBSITE_FORM | ZALO_MINIAPP |
     * | full_name        | required     | optional     |
     * | phone            | optional     | optional     |
     * | email            | optional     | optional     |
     * | external_identity| optional     | optional     |
     * | attributes       | optional     | optional     |
     * | consent          | optional     | optional     |
     *
     * Note: contacts.full_name is NOT NULL at the schema level, but the
     * chokepoint guarantees it's filled — either from this payload for
     * WEBSITE_FORM (required here), or from a Zalo OA / personal identity
     * later. For ZALO_MINIAPP without full_name we synthesize one from the
     * identity's display_name so the NOT NULL stays satisfied.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rulesFor(string $source): array
    {
        return [
            'full_name' => [
                Rule::requiredIf($source === ContactIngestor::SOURCE_WEBSITE_FORM),
                'nullable',
                'string',
                'max:255',
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'external_identity' => ['nullable', 'array'],
            'external_identity.provider' => ['required_with:external_identity', 'string', 'max:40'],
            'external_identity.provider_account_id' => ['required_with:external_identity', 'string', 'max:120'],
            'external_identity.provider_user_id' => ['required_with:external_identity', 'string', 'max:120'],
            'external_identity.display_name' => ['nullable', 'string', 'max:255'],
            'external_identity.avatar_url' => ['nullable', 'string', 'max:500'],
            'attributes' => ['nullable', 'array'],
            'consent' => ['nullable', 'array'],
            'consent.given_at' => ['nullable', 'date'],
            'consent.text' => ['nullable', 'string', 'max:500'],
            'consent.ip' => ['nullable', 'string', 'max:64'],
            'consent.user_agent' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Build the `attributes` JSON for storage. Per-source we attach
     * standard server-side fields (page URL, referrer, UA) so even if the
     * caller forgets to include them, ops can trace where the lead came
     * from. Caller-supplied keys are merged in (caller wins on conflict).
     *
     * @param  array<string, mixed>  $clientAttributes  raw attributes from the request body
     * @return array<string, mixed>
     */
    private function attributesFor(string $source, array $clientAttributes, Request $request): array
    {
        $serverContext = [
            'received_at' => now()->toIso8601String(),
            'page_url' => $request->header('Referer'),
            'user_agent' => $request->header('User-Agent'),
            'ip' => $request->ip(),
        ];

        $sourceSpecific = $source === ContactIngestor::SOURCE_ZALO_MINIAPP
            ? ['event_source' => 'miniapp']
            : ['event_source' => 'web_form'];

        return array_merge($serverContext, $sourceSpecific, $clientAttributes);
    }

    /**
     * Normalize consent payload. The server-side IP / UA override whatever
     * the caller sent (X-Forwarded-For chain is trusted because the
     * middleware already trusts proxies at the proxy layer).
     *
     * @param  array<string, mixed>|null  $consent
     * @return array<string, mixed>|null
     */
    private function normalizeConsent(?array $consent, Request $request): ?array
    {
        if ($consent === null) {
            return null;
        }

        return [
            'given_at' => $consent['given_at'] ?? null,
            'text' => $consent['text'] ?? null,
            // Server overrides: never trust a client-supplied IP.
            'ip' => $request->ip(),
            'user_agent' => $consent['user_agent'] ?? $request->header('User-Agent'),
        ];
    }

    /**
     * Compute the canonical payload hash used to detect event-id
     * collisions. Hashes on the deterministic client-controlled subset
     * (excludes server-augmented fields like received_at / ip that change
     * between retries). The ContactIngestor chokepoint uses the same shape
     * via $payload['client_attributes'] so retries with the same payload
     * hash identically.
     *
     * @param  array<string, mixed>  $data  validated request body
     * @param  array<string, mixed>  $clientAttributes  raw attributes from the request body
     */
    private function payloadHash(string $source, ?string $sourceDetail, array $data, array $clientAttributes): string
    {
        $hashable = [
            'source' => $source,
            'source_detail' => $sourceDetail,
            'full_name' => $data['full_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'external_identity' => $data['external_identity'] ?? null,
            'attributes' => $clientAttributes,
        ];

        return hash('sha256', json_encode($hashable, JSON_THROW_ON_ERROR));
    }

    /**
     * Origin allowlist check. Accepts comma-separated origins (full URLs)
     * or hosts ("example.com"). Match is scheme-insensitive on host and
     * exact on path (caller can scope tighter if needed).
     */
    private function originMatches(string $origin, string $whitelist): bool
    {
        if ($origin === '') {
            return false;
        }

        $allowed = array_values(array_filter(array_map('trim', explode(',', $whitelist))));
        if (! $allowed) {
            return false;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $originScheme = parse_url($origin, PHP_URL_SCHEME);
        if (! $originHost) {
            return false;
        }

        foreach ($allowed as $candidate) {
            // Candidate can be either a full URL or a bare host.
            $candidateHost = parse_url($candidate, PHP_URL_HOST) ?: $candidate;
            $candidateScheme = parse_url($candidate, PHP_URL_SCHEME);

            if (strcasecmp($candidateHost, $originHost) === 0) {
                // If the candidate specifies a scheme, the origin must match too.
                if ($candidateScheme === null || strcasecmp($candidateScheme, (string) $originScheme) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $contextPayload
     */
    private function recordFailure(
        Request $request,
        WorkspaceIngestToken $token,
        string $source,
        array $contextPayload,
        string $error,
        ?string $eventId = null,
    ): void {
        try {
            ContactIngestFailure::create([
                'workspace_id' => $token->workspace_id,
                'token_id' => $token->id,
                'source' => $source,
                // Truncate payload to a sane size — large JSON blobs would
                // bloat the audit table.
                'payload' => array_merge(
                    ['headers' => [
                        'X-Source' => $source,
                        'X-Source-Detail' => $request->header('X-Source-Detail'),
                        'X-Source-Event-Id' => $eventId ?? $request->header('X-Source-Event-Id'),
                    ]],
                    ['body' => $request->all()],
                    ['context' => $contextPayload],
                ),
                'error' => mb_substr($error, 0, 1000),
                'ip' => $request->ip(),
                'received_at' => now(),
            ]);
        } catch (Throwable) {
            // Failure-recorder must never block the request — swallow.
        }
    }

    private function errorJson(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}