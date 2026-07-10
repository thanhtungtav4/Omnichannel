<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * quick_replies — agent-editable canned responses ("tin nhắn nhanh").
 *
 * Was a static QUICK_TEMPLATES array in the inbox composer; moved here so
 * agents can add/edit their own without a code change. Workspace-scoped.
 * `shortcut` is the "/xxx" token typed in the composer to filter/insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('shortcut', 40);
            $table->string('label', 120);
            $table->text('text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'shortcut']);
            $table->index(['workspace_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
    }
};
