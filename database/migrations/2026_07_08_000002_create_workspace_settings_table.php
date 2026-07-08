<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * workspace_settings — per-tenant configuration store.
 *
 * Decision (specs/12_SHOPEE_CHAT_VN_READINESS.md): JSONB-on-disk-via-app-layer-encryption.
 * The on-disk column is TEXT because every write is `Crypt::encryptString($value)` —
 * the DB stores opaque ciphertext. If a future feature needs to query sub-fields
 * without decrypting, switch to jsonb and stop encrypting (move encryption to a
 * column-level pgcrypto policy instead).
 *
 * Use this table for keys that are:
 *   - platform-level (not per channel_account)
 *   - per workspace (different tenants have different partner credentials)
 *   - not secret enough to warrant their own table
 *
 * Initial keys expected (spec 11):
 *   - shopee.partner_credentials     → {partner_id, partner_key} (encrypted)
 *   - tiktok_shop.partner_credentials → reserved for cut 2
 *
 * Per-shop credentials stay on channel_accounts.credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->string('key', 100);
            // Encrypted ciphertext (base64). Never query sub-fields.
            $table->text('value');
            $table->timestamps();

            $table->unique(['workspace_id', 'key'], 'workspace_settings_workspace_key_unique');
            $table->index(['workspace_id', 'key'], 'workspace_settings_workspace_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_settings');
    }
};