<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unify notes: contact_notes is now the single note store. A note created from
 * a conversation keeps a nullable conversation_id so the inbox thread can show
 * the notes typed in that conversation inline, while the contact record shows
 * all of a customer's notes. Replaces the separate internal_notes table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_notes', function (Blueprint $table) {
            $table->foreignUuid('conversation_id')->nullable()->after('contact_id')
                ->constrained('conversations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
        });
    }
};
