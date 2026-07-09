<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cut 3 of specs/15_CONTACTS_INGESTION.md — public ingest API + tokens.
 *
 * Two new tables:
 *
 *   workspace_ingest_tokens
 *     Per-workspace API tokens issued to public callers (web forms, Zalo
 *     Mini App backend). Each token is scoped to a single source family
 *     via `allowed_sources` so a leaked web-form token cannot be used to
 *     forge a ZALO_MINIAPP event. The plaintext token is bcrypt-hashed;
 *     `token_prefix` keeps the first 8 chars for grep + UI display. The
 *     optional `hmac_secret` is encrypted via Laravel Crypt (same shape
 *     as ChannelAccount::webhook_secret) and used by ZALO_MINIAPP tokens
 *     to verify X-Signature HMAC payloads.
 *
 *   contact_ingest_failures
 *     Ops debugging surface. Every public ingest that fails BEFORE
 *     reaching ContactIngestor (validation error, unknown source for the
 *     token, bad HMAC, expired token) lands here with the raw payload
 *     and the error reason. Successful ingests do NOT write a row here;
 *     they go through `contact_ingest_events` + `audit_logs` instead.
 *
 * All additive — no existing rows touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ingest_tokens', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            $t->string('name', 120);                       // "Landing page mùa hè"
            // First 8 chars of the plaintext token (e.g. "whk_a1b2") — shown
            // in the UI so ops can tell tokens apart without exposing the
            // secret. The rest of the token is bcrypt-hashed and never shown
            // again after mint.
            $t->string('token_prefix', 12);
            // bcrypt hash of the full plaintext token. Length 60 fits bcrypt's
            // default output; we leave 12 chars of headroom for future hash
            // upgrades (argon2id is ~95 chars).
            $t->string('token_hash', 120);

            // JSONB array of source codes this token is allowed to ingest.
            // A web-form token has ["WEBSITE_FORM"]; a Mini App token has
            // ["ZALO_MINIAPP"]. The public endpoint rejects any X-Source
            // not in this list (403).
            $t->jsonb('allowed_sources');

            // For ZALO_MINIAPP tokens: the OA app secret used to verify
            // X-Signature HMAC. Encrypted at rest via Laravel Crypt cast
            // (same shape as ChannelAccount::webhook_secret). Nullable for
            // tokens that don't need HMAC.
            $t->text('hmac_secret')->nullable();

            // Per-token owner strategy override. When set, ContactIngestor's
            // OwnerResolver prefers this over the workspace default.
            $t->jsonb('default_owner_strategy')->nullable();

            // Default value for source_detail when the request omits it.
            // Lets ops pin a campaign tag ("summer-sale-2026") at the token
            // level so individual forms don't have to repeat it.
            $t->string('default_source_detail', 120)->nullable();

            // Comma-separated Origin allowlist. Empty = no check. The public
            // endpoint validates Origin / Referer against this list when
            // present (defense in depth on top of token auth).
            $t->string('domain_whitelist', 500)->nullable();

            $t->unsignedSmallInteger('rate_limit_per_minute')->default(60);

            $t->timestampTz('last_used_at')->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestamps();

            // Hot path lookup is "active tokens for this workspace".
            $t->index(['workspace_id', 'revoked_at']);
        });

        Schema::create('contact_ingest_failures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            // token_id is nullable: some failures happen before token auth
            // (e.g. malformed header) where we don't yet know which token
            // was being attempted.
            $t->uuid('token_id')->nullable();

            $t->string('source', 40);
            // Raw request body so ops can replay / inspect. JSONB keeps the
            // structure queryable later if needed.
            $t->jsonb('payload');
            // Truncated at 1000 chars; longer stack traces go to logs.
            $t->text('error', 1000);
            $t->ipAddress('ip')->nullable();

            $t->timestampTz('received_at');
            $t->timestampTz('resolved_at')->nullable();

            // Ops dashboard queries: "failures in last hour" + "open failures".
            $t->index(['workspace_id', 'received_at']);
            $t->index(['resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_ingest_failures');
        Schema::dropIfExists('workspace_ingest_tokens');
    }
};