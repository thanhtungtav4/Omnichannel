<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA breach marker on conversations. The response-due timestamps already
 * exist; this records WHEN a conversation actually breached so the sweep is
 * idempotent (flag once, don't re-notify every minute) and the admin cockpit
 * can surface an on-screen "SLA breached" badge per spec / AGENTS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('sla_breached_at')->nullable()->after('next_response_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('sla_breached_at');
        });
    }
};
