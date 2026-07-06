<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modular CRM schema. See specs/03_DATA_MODEL.md (amended by specs/10).
 *
 * Design rules enforced here (DB is the last line of defense):
 * - Every FK uses foreignUuid()->constrained()->nullOnDelete()/cascadeOnDelete().
 * - Every enum column has a CHECK constraint (addCheck helper below).
 * - Idempotency/dedup guarded by unique indexes.
 * Tables are created parent-before-child so FKs resolve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('ACTIVE')->index();
            $table->timestamps();
        });
        $this->check('workspaces', 'status', ['ACTIVE', 'SUSPENDED']);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained('workspaces')->nullOnDelete();
            $table->string('display_name')->nullable()->after('name');
            $table->string('role')->default('support_agent')->after('email')->index();
            $table->string('status')->default('ACTIVE')->after('role')->index();
            $table->timestamp('last_seen_at')->nullable()->after('status');
        });
        $this->check('users', 'status', ['ACTIVE', 'DISABLED']);
        // RBAC roles (spec 08). CHECK here because auth decisions depend on it.
        $this->check('users', 'role', ['owner', 'admin', 'support_lead', 'support_agent', 'sales', 'viewer']);

        // entity_links is polymorphic (contact<->lead/deal/patient/ticket), so
        // source_id/target_id CANNOT have DB foreign keys. Consequence: deleting a
        // linked row leaves an orphan link. A CRM-Core delete/merge service MUST
        // clean links at the app layer (spec 04 "Contact merge" edge case).
        Schema::create('entity_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('source_type');
            $table->uuid('source_id');
            $table->string('target_type');
            $table->uuid('target_id');
            $table->string('relation');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['workspace_id', 'source_type', 'source_id', 'target_type', 'target_id', 'relation'], 'entity_links_unique_relation');
            $table->index(['workspace_id', 'source_type', 'source_id']);
            $table->index(['workspace_id', 'target_type', 'target_id']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module')->index();
            $table->string('action')->index();
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('status')->default('ACTIVE')->index();
            $table->string('source')->default('MANUAL')->index();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable()->index();
            $table->timestamps();

            $table->index(['workspace_id', 'owner_id']);
            $table->index(['workspace_id', 'phone']);
            $table->index(['workspace_id', 'phone_normalized']);
            $table->index(['workspace_id', 'email']);
        });
        $this->check('contacts', 'status', ['ACTIVE', 'ARCHIVED', 'BLOCKED']);
        $this->check('contacts', 'source', ['MANUAL', 'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'IMPORT', 'API']);

        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('tax_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->default('ACTIVE')->index();
            $table->timestamps();
        });
        $this->check('companies', 'status', ['ACTIVE', 'ARCHIVED']);

        Schema::create('pipelines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->index();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        $this->check('pipelines', 'type', ['LEAD', 'DEAL']);

        Schema::create('stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $table->string('name');
            $table->string('status_group')->default('OPEN')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('color_token')->nullable();
            $table->timestamps();
        });
        $this->check('stages', 'status_group', ['OPEN', 'WON', 'LOST']);

        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('pipeline_id')->nullable()->constrained('pipelines')->nullOnDelete();
            $table->foreignUuid('stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $table->string('title');
            $table->string('status')->default('NEW')->index();
            $table->string('source')->default('MANUAL')->index();
            $table->decimal('value_amount', 12, 2)->nullable();
            $table->string('value_currency', 3)->default('VND');
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();

            $table->index(['workspace_id', 'owner_id', 'status']);
        });
        $this->check('leads', 'status', ['NEW', 'QUALIFYING', 'OPEN', 'WON', 'LOST', 'ARCHIVED']);
        $this->check('leads', 'source', ['TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'MANUAL', 'IMPORT', 'API']);

        Schema::create('deals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('pipeline_id')->nullable()->constrained('pipelines')->nullOnDelete();
            $table->foreignUuid('stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $table->string('title');
            $table->string('status')->default('OPEN')->index();
            $table->string('source')->default('MANUAL')->index();
            $table->decimal('value_amount', 12, 2)->nullable();
            $table->string('value_currency', 3)->default('VND');
            $table->date('expected_close_date')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();
        });
        $this->check('deals', 'status', ['NEW', 'QUALIFYING', 'OPEN', 'WON', 'LOST', 'ARCHIVED']);
        $this->check('deals', 'source', ['TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'MANUAL', 'IMPORT', 'API']);

        Schema::create('channel_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('name');
            $table->string('status')->default('DRAFT')->index();
            $table->text('credentials')->nullable(); // encrypted:array cast -> ciphertext string, NOT json
            $table->json('settings')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->string('webhook_url')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'provider', 'name'], 'channel_accounts_unique_name');
        });
        $this->check('channel_accounts', 'provider', ['TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK']);
        $this->check('channel_accounts', 'status', ['DRAFT', 'ACTIVE', 'DEGRADED', 'DISABLED']);

        Schema::create('external_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('provider')->index();
            $table->foreignUuid('provider_account_id')->constrained('channel_accounts')->cascadeOnDelete();
            $table->string('provider_user_id');
            $table->string('provider_chat_id')->nullable()->index();
            $table->string('display_name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->json('raw_profile')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'provider', 'provider_account_id', 'provider_user_id'], 'external_identity_unique_provider_user');
            $table->index(['workspace_id', 'contact_id']);
        });
        $this->check('external_identities', 'provider', ['TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK']);

        Schema::create('routing_queues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('ACTIVE')->index();
            $table->string('mode')->default('STICKY_THEN_EVEN')->index();
            $table->unsignedInteger('timeout_seconds')->default(300);
            $table->unsignedInteger('max_active_per_agent')->default(5);
            $table->boolean('requires_online')->default(true);
            $table->timestamps();
        });
        $this->check('routing_queues', 'status', ['ACTIVE', 'DISABLED']);
        $this->check('routing_queues', 'mode', ['STICKY_THEN_EVEN', 'EVEN', 'QUEUE_ORDER', 'BROADCAST']);

        Schema::create('routing_queue_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('routing_queue_id')->constrained('routing_queues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status')->default('ACTIVE')->index();
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['routing_queue_id', 'user_id']);
        });
        $this->check('routing_queue_members', 'status', ['ACTIVE', 'PAUSED']);

        Schema::create('agent_presence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('OFFLINE')->index();
            $table->unsignedInteger('active_conversation_count')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });
        $this->check('agent_presence', 'status', ['ONLINE', 'AWAY', 'BUSY', 'OFFLINE']);

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('channel_account_id')->constrained('channel_accounts')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('routing_queue_id')->nullable()->constrained('routing_queues')->nullOnDelete();
            $table->string('status')->default('OPEN')->index();
            $table->string('priority')->default('NORMAL')->index();
            $table->string('subject')->nullable();
            $table->uuid('last_message_id')->nullable(); // FK added after messages table exists
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('last_agent_message_at')->nullable();
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('next_response_due_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'last_message_at']);
            $table->index(['workspace_id', 'owner_id', 'status']);
            // Inbox nick-filter + recency sort (spec 03). Hottest inbox query.
            $table->index(['workspace_id', 'channel_account_id', 'last_message_at'], 'conversations_channel_recency');
        });
        $this->check('conversations', 'status', ['OPEN', 'ASSIGNED', 'WAITING_AGENT', 'WAITING_CUSTOMER', 'CLOSED', 'SPAM']);
        $this->check('conversations', 'priority', ['LOW', 'NORMAL', 'HIGH', 'URGENT']);

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('channel_account_id')->constrained('channel_accounts')->cascadeOnDelete();
            $table->string('provider_message_id')->nullable();
            $table->unsignedBigInteger('provider_message_seq')->nullable(); // Zalo msgIdNum Snowflake - thread sort key (spec 10)
            $table->string('direction')->index();
            $table->string('sender_type')->index();
            $table->string('sender_id')->nullable();
            $table->text('body_text')->nullable();
            $table->string('message_type')->default('TEXT')->index();
            $table->string('status')->default('RECEIVED')->index();
            $table->json('raw_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'channel_account_id', 'provider_message_id', 'direction'], 'messages_unique_provider_message');
            $table->index(['workspace_id', 'conversation_id', 'created_at']);
            $table->index(['workspace_id', 'conversation_id', 'provider_message_seq'], 'messages_thread_sort');
            $table->index(['workspace_id', 'status'], 'messages_ws_status_index'); // outbox retry/failed queries (spec 03)
        });
        $this->check('messages', 'direction', ['INBOUND', 'OUTBOUND']);
        $this->check('messages', 'sender_type', ['CUSTOMER', 'AGENT', 'SYSTEM']);
        $this->check('messages', 'message_type', ['TEXT', 'IMAGE', 'FILE', 'AUDIO', 'VIDEO', 'STICKER', 'LOCATION', 'RICH', 'UNSUPPORTED']);
        $this->check('messages', 'status', ['RECEIVED', 'QUEUED', 'SENDING', 'SENT', 'FAILED', 'DELIVERED', 'READ']);

        // conversations.last_message_id -> messages.id (deferred FK, now that messages exists)
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('last_message_id')->references('id')->on('messages')->nullOnDelete();
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->uuid('file_id')->nullable();
            $table->string('provider_file_id')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('internal_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('conversation_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('routing_queue_id')->nullable()->constrained('routing_queues')->nullOnDelete();
            $table->string('reason')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $this->check('conversation_assignments', 'reason', ['AUTO_STICKY_OWNER', 'AUTO_EVEN', 'AUTO_QUEUE_ORDER', 'MANUAL_TRANSFER', 'TIMEOUT_REASSIGN', 'ADMIN_OVERRIDE']);

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('channel_account_id')->constrained('channel_accounts')->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('provider_event_id')->nullable();
            $table->string('idempotency_key');
            $table->string('event_type')->nullable();
            $table->json('headers')->nullable();
            $table->json('payload');
            $table->string('status')->default('RECEIVED')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'channel_account_id', 'idempotency_key'], 'webhook_events_unique_idempotency');
            $table->index(['workspace_id', 'status', 'created_at']);
        });
        $this->check('webhook_events', 'status', ['RECEIVED', 'PROCESSING', 'PROCESSED', 'IGNORED', 'FAILED', 'REPLAYED']);

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('channel_account_id')->constrained('channel_accounts')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('recipient_external_id')->nullable();
            $table->json('payload');
            $table->string('status')->default('QUEUED')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('last_error_code')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();

            // One outbox per message: prevents double-send on retry (spec 10).
            $table->unique('message_id', 'outbox_messages_unique_message');
        });
        $this->check('outbox_messages', 'status', ['QUEUED', 'SENDING', 'SENT', 'FAILED', 'RETRYING', 'CANCELLED']);

        Schema::create('sdk_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('channel_account_id')->nullable()->constrained('channel_accounts')->cascadeOnDelete();
            $table->string('category')->index();
            $table->unsignedInteger('daily_limit');
            $table->unsignedInteger('burst_limit')->nullable();
            $table->unsignedInteger('burst_window_ms')->nullable();
            $table->timestamps();
        });
        $this->check('sdk_limits', 'category', ['MESSAGE', 'FRIEND_ADD', 'REACTION', 'CHAT_ACTION', 'STRANGER_MESSAGE']);
        // Partial-unique: one org-default row per category, and one override row per (account, category).
        DB::statement('CREATE UNIQUE INDEX sdk_limits_org_default_unique ON sdk_limits (workspace_id, category) WHERE channel_account_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX sdk_limits_account_override_unique ON sdk_limits (workspace_id, channel_account_id, category) WHERE channel_account_id IS NOT NULL');

        Schema::create('assignment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('routing_queue_id')->nullable()->constrained('routing_queues')->nullOnDelete();
            $table->foreignId('candidate_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result')->index();
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $this->check('assignment_attempts', 'result', ['ASSIGNED', 'SKIPPED_OFFLINE', 'SKIPPED_LIMIT', 'TIMEOUT', 'FAILED']);

        Schema::create('timeline_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('subject_type')->index();
            $table->uuid('subject_id')->index();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('module')->index();
            $table->string('type')->index();
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['last_message_id']);
        });

        Schema::dropIfExists('timeline_activities');
        Schema::dropIfExists('assignment_attempts');
        Schema::dropIfExists('sdk_limits');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('conversation_assignments');
        Schema::dropIfExists('internal_notes');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('agent_presence');
        Schema::dropIfExists('routing_queue_members');
        Schema::dropIfExists('routing_queues');
        Schema::dropIfExists('external_identities');
        Schema::dropIfExists('channel_accounts');
        Schema::dropIfExists('deals');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('stages');
        Schema::dropIfExists('pipelines');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('entity_links');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['display_name', 'role', 'status', 'last_seen_at']);
        });

        Schema::dropIfExists('workspaces');
    }

    /**
     * Add a CHECK constraint enforcing an enum column at the DB layer.
     * ponytail: raw SQL because Laravel has no portable enum-check builder;
     * Postgres only. Swap to a package if you add MySQL.
     */
    private function check(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$list}))");
    }
};
