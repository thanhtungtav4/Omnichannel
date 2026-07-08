<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend contacts/leads/deals source enum with SHOPEE + TIKTOK_SHOP.
 * Same approach as 2026_07_08_000001 — drop CHECK + re-add with extended list.
 *
 * Spec: specs/11_SHOPEE_CHAT_VN.md
 */
return new class extends Migration
{
    private const EXTENDED = [
        'MANUAL', 'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'IMPORT', 'API', 'SHOPEE', 'TIKTOK_SHOP',
    ];

    private const ORIGINAL = [
        'MANUAL', 'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'IMPORT', 'API',
    ];

    public function up(): void
    {
        // contacts.source
        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_source_check');
        $this->addCheck('contacts', 'source', self::EXTENDED);

        // leads.source
        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_source_check');
        $this->addCheck('leads', 'source', self::EXTENDED);

        // deals.source (cut 2 — included now so we don't re-touch migrations)
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_source_check');
        $this->addCheck('deals', 'source', self::EXTENDED);
    }

    public function down(): void
    {
        // Block rollback if any row already uses SHOPEE/TIKTOK_SHOP — same
        // pattern as 2026_07_08_000001.
        $newValues = ['SHOPEE', 'TIKTOK_SHOP'];

        foreach (['contacts', 'leads', 'deals'] as $table) {
            $count = (int) DB::table($table)->whereIn('source', $newValues)->count();
            if ($count > 0) {
                throw new \RuntimeException(sprintf(
                    'Refusing to roll back: %d row(s) in %s use SHOPEE/TIKTOK_SHOP. '.
                    'Reassign before rolling back this migration.',
                    $count,
                    $table,
                ));
            }
        }

        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_source_check');
        $this->addCheck('contacts', 'source', self::ORIGINAL);

        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_source_check');
        $this->addCheck('leads', 'source', self::ORIGINAL);

        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_source_check');
        $this->addCheck('deals', 'source', self::ORIGINAL);
    }

    private function addCheck(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$list}))");
    }
};