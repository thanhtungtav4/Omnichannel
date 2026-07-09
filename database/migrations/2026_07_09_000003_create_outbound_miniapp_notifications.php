<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cut 4 of specs/15_CONTACTS_INGESTION.md — Mini App re-engagement.
 *
 * `outbound_miniapp_notifications` is the audit + retry surface for
 * template messages the CRM sends TO a Zalo Mini App (re-engagement
 * notifications: lead status changed, contact archived, ...).
 *
 * Each row is one attempt: one contact + one template_code + one
 * trigger context. Status QUEUED → SENT → FAILED walks through the
 * MiniAppNotificationJob's lifecycle. `attempts` counts retries; the
 * job runs at most 3 times on transient failure (spec).
 *
 * `oa_user_id` is the OA recipient ID (Zalo-side user_id, not our
 * internal user id). Stored as plain string so we don't depend on a
 * FK that might not exist (Zalo users aren't mirrored in our `users`
 * table).
 *
 * Token templates (template_code → OA template id) live in
 * workspace_settings.miniapp.templates — same shape as
 * spec 15 § C4 cross-cutting. Not declared here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_miniapp_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $t->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            // OA recipient — Zalo-side user_id. Free-form string, no FK.
            $t->string('oa_user_id', 100);
            $t->string('template_code', 80);
            $t->jsonb('params');
            // QUEUED → SENT | FAILED. The MiniAppNotificationJob walks this.
            $t->string('status', 20);
            $t->text('last_error', 500)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestampTz('queued_at');
            $t->timestampTz('sent_at')->nullable();

            // Ops dashboard: "what's pending in this workspace".
            $t->index(['workspace_id', 'status', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_miniapp_notifications');
    }
};
