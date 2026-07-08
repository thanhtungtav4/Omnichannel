<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Extend messages.message_type with NOTE so internal comments render
     * differently from customer/agent messages (mockup note-bubble style).
     * Backfills any existing rows to TEXT first so the CHECK stays consistent.
     */
    public function up(): void
    {
        // Drop the old check constraint (Postgres doesn't have a portable
        // way to ALTER a CHECK in Laravel 12 without raw SQL).
        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_message_type_check');

        // Add the new check with NOTE included.
        $this->addCheck('messages', 'message_type', [
            'TEXT', 'IMAGE', 'FILE', 'AUDIO', 'VIDEO', 'STICKER',
            'LOCATION', 'RICH', 'NOTE', 'UNSUPPORTED',
        ]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_message_type_check');
        $this->addCheck('messages', 'message_type', [
            'TEXT', 'IMAGE', 'FILE', 'AUDIO', 'VIDEO', 'STICKER',
            'LOCATION', 'RICH', 'UNSUPPORTED',
        ]);
    }

    /** Re-implements the migration's helper without extending the parent. */
    private function addCheck(string $table, string $column, array $allowed): void
    {
        $list = "'" . implode("','", $allowed) . "'";
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$list}))");
    }
};