<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-form tags an agent sets on a customer (e.g. "VIP", "Nợ tiền", "Nha khoa").
 * ponytail: a JSON array on the contact, not a tags table + pivot — no tag
 * management screen is needed yet. Promote to a shared tags module if tags ever
 * need colours, rename-everywhere, or cross-entity reuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->jsonb('tags')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
