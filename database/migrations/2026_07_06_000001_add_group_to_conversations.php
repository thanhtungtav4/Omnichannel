<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group-chat support on conversations: mark group threads and store the Zalo
 * group thread id so outbound replies target the group (ThreadType.Group),
 * not an individual (spec 10 group-chat fix).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('is_group')->default(false)->after('contact_id');
            $table->string('provider_thread_id')->nullable()->after('is_group');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['is_group', 'provider_thread_id']);
        });
    }
};
