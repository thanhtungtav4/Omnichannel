<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the provider enum to include SHOPEE (cut 1) and TIKTOK_SHOP (reserved
 * for cut 2). Doing both in one migration means we only touch the CHECK
 * constraints once.
 *
 * Spec: specs/11_SHOPEE_CHAT_VN.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // channel_accounts.provider
        DB::statement('ALTER TABLE channel_accounts DROP CONSTRAINT IF EXISTS channel_accounts_provider_check');
        $this->addCheck('channel_accounts', 'provider', [
            'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'SHOPEE', 'TIKTOK_SHOP',
        ]);

        // external_identities.provider
        DB::statement('ALTER TABLE external_identities DROP CONSTRAINT IF EXISTS external_identities_provider_check');
        $this->addCheck('external_identities', 'provider', [
            'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK', 'SHOPEE', 'TIKTOK_SHOP',
        ]);
    }

    public function down(): void
    {
        // Roll back only if no row currently uses the new values; otherwise
        // the DROP will fail. Surface to the operator if any SHOPEE row exists
        // in either table (channel_accounts OR external_identities — both
        // carry the provider column).
        $accountCount = (int) DB::table('channel_accounts')
            ->whereIn('provider', ['SHOPEE', 'TIKTOK_SHOP'])
            ->count();
        $identityCount = (int) DB::table('external_identities')
            ->whereIn('provider', ['SHOPEE', 'TIKTOK_SHOP'])
            ->count();

        if ($accountCount > 0 || $identityCount > 0) {
            throw new \RuntimeException(sprintf(
                'Refusing to roll back: %d channel account(s) and %d external identit%s use SHOPEE/TIKTOK_SHOP. '.
                'Reassign or delete them before rolling back this migration.',
                $accountCount,
                $identityCount,
                $identityCount === 1 ? 'y' : 'ies',
            ));
        }

        DB::statement('ALTER TABLE channel_accounts DROP CONSTRAINT IF EXISTS channel_accounts_provider_check');
        $this->addCheck('channel_accounts', 'provider', [
            'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK',
        ]);

        DB::statement('ALTER TABLE external_identities DROP CONSTRAINT IF EXISTS external_identities_provider_check');
        $this->addCheck('external_identities', 'provider', [
            'TELEGRAM', 'ZALO_PERSONAL', 'ZALO_OA', 'FACEBOOK',
        ]);
    }

    /**
     * ponytail: raw SQL because Laravel has no portable enum-check builder;
     * Postgres only. Mirrors the helper in 2026_07_04_000001_create_modular_crm_tables.
     */
    private function addCheck(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn ($v) => "'".$v."'", $values));
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$list}))");
    }
};