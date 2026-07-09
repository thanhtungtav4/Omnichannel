<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cut 2 of specs/15_CONTACTS_INGESTION.md — single additive migration:
 *
 * 1. Extend `contacts` with columns every ingestion source needs but the
 *    webhook path didn't have a home for:
 *      - `attributes`        JSONB  free-form per-source payload (UTM, referrer,
 *                                    consent page text, Mini App event fields…)
 *      - `source_detail`     VARCHAR(120)  e.g. "summer-sale-2026"
 *      - `consent_*`         (informational; nullable — see C2 § 9)
 *
 * 2. Extend the `contacts.source` CHECK constraint with `WEBSITE_FORM` and
 *    `ZALO_MINIAPP`. Drop-and-recreate is the only way to mutate a CHECK in
 *    Postgres; the new constraint accepts the same old values so the change
 *    is backwards-compatible.
 *
 * 3. New table `contact_ingest_events` — dedup + audit per ingestion event.
 *    Spec calls this a C3 table, but it's a no-op until a public ingest path
 *    ships, so we land it here so the chokepoint can be tested end-to-end
 *    from day one. (`ingest_failures` ships in C3 with the public route.)
 *
 * All changes are additive. Existing rows get `attributes='{}'`, all consent
 * columns null, no source migration needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // JSONB default 'not null {}' — `attributes` access from Eloquent
            // casts always returns array, never null.
            $table->jsonb('attributes')->default(DB::raw("'{}'::jsonb"))->after('source');
            $table->string('source_detail', 120)->nullable()->after('source');

            // Consent is informational in C1 spec (no opt-in checkbox gating);
            // nullable so existing/legacy rows stay valid. Future PDPA-strict
            // flows can flip these to NOT NULL in a follow-up migration.
            $table->timestampTz('consent_given_at')->nullable();
            $table->string('consent_text', 500)->nullable();
            $table->ipAddress('consent_ip')->nullable();
            $table->text('consent_user_agent')->nullable();
        });

        // Rebuild the contacts.source CHECK constraint with the extended enum.
// Postgres syntax only; the helper from the base migration is inlined
// here because the original migration owns `check()` as private.
//
// ponytail: when extending this list, mirror the additions in BOTH this
// contacts check AND the leads check below, AND update specs/15 § Sources.
$values = ['MANUAL', 'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'IMPORT', 'API', 'SHOPEE', 'TIKTOK_SHOP', 'WEBSITE_FORM', 'ZALO_MINIAPP'];
DB::statement('ALTER TABLE contacts DROP CONSTRAINT contacts_source_check');
$list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
DB::statement("ALTER TABLE contacts ADD CONSTRAINT contacts_source_check CHECK (source IN ({$list}))");

// Same for leads.source — leads auto-created from a website form (in a
// future cut) will need WEBSITE_FORM here too.
$leadValues = ['TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'MANUAL', 'IMPORT', 'API', 'SHOPEE', 'TIKTOK_SHOP', 'WEBSITE_FORM', 'ZALO_MINIAPP'];
DB::statement('ALTER TABLE leads DROP CONSTRAINT leads_source_check');
$leadList = implode(', ', array_map(fn ($v) => "'".$v."'", $leadValues));
DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_source_check CHECK (source IN ({$leadList}))");

        Schema::create('contact_ingest_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->uuid('contact_id');
            $table->string('source', 40);
            // `source_event_id` is the dedup key: provider msg id, Mini App
            // event id, or web form Idempotency-Key. Long enough for GUIDs
            // and short hash digests alike.
            $table->string('source_event_id', 200);
            $table->string('payload_hash', 64);
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('received_at');
            $table->timestamps();

            // Unique per (workspace, source, source_event_id): a redelivered
            // webhook with the same provider msg id is one logical event.
            $table->unique(['workspace_id', 'source', 'source_event_id'], 'contact_ingest_events_unique');
            $table->index(['contact_id', 'received_at']);

            // FK to contacts is workspace-scoped via BelongsToWorkspace trait
            // on Contact; the global scope doesn't apply to raw SQL, so we
            // rely on the workspace_id index for tenant-pruned lookups. No
            // DB-level FK on contact_id because the global scope means
            // route-model binding skips foreign rows naturally.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_ingest_events');

        DB::statement('ALTER TABLE contacts DROP CONSTRAINT contacts_source_check');
        $values = ['MANUAL', 'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'IMPORT', 'API', 'SHOPEE', 'TIKTOK_SHOP'];
        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement("ALTER TABLE contacts ADD CONSTRAINT contacts_source_check CHECK (source IN ({$list}))");

        DB::statement('ALTER TABLE leads DROP CONSTRAINT leads_source_check');
        $leadValues = ['TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'MANUAL', 'IMPORT', 'API', 'SHOPEE', 'TIKTOK_SHOP'];
        $leadList = implode(', ', array_map(fn ($v) => "'".$v."'", $leadValues));
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_source_check CHECK (source IN ({$leadList}))");

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'attributes',
                'source_detail',
                'consent_given_at',
                'consent_text',
                'consent_ip',
                'consent_user_agent',
            ]);
        });
    }
};