<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track when we last triggered a Zalo history pull per conversation so:
 *   1. We debounce — don't refire sidecar for the same thread within seconds.
 *   2. We have an audit trail — ops can see which threads are being synced and
 *      whether the sync found anything (sync timestamp + anchor message id).
 *
 * Generic `last_history_sync_*` so future Shopee / Facebook connectors can
 * reuse the same columns for their own pull strategies.
 *
 * Spec: specs/10 § "Gap when listener drops"; fix is auto-trigger sync on
 * thread open via ZaloThreadHistorySyncService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('last_history_sync_at')->nullable()->after('closed_at');
            // provider_message_id of the latest message known at sync-trigger
            // time. We don't actively use this for pagination right now
            // (sidecar requestOldMessages accepts a fresh cursor at trigger
            // time), but it's useful for forensics — "did we sync at all, and
            // where did we anchor?"
            $table->string('last_history_sync_msg_id')->nullable()->after('last_history_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['last_history_sync_at', 'last_history_sync_msg_id']);
        });
    }
};