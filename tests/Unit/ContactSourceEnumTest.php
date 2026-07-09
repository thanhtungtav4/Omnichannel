<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Single source of truth for the canonical contact / lead `source` enum
 * values that the DB CHECK constraints enforce (specs/15 § Sources).
 *
 * Why this test exists:
 *  - Migrations that mutate CHECK constraints do it via raw SQL (Postgres
 *    has no portable enum-check builder). Without a runtime check, a typo
 *    in the SQL or a missed value would silently drop a provider from the
 *    accepted set and crash ingest in production.
 *  - The check constraint is on the DB but the canonical list lives in
 *    the spec. This test pins both sides together.
 */
class ContactSourceEnumTest extends TestCase
{
    public function test_db_check_constraint_matches_canonical_source_set(): void
    {
        // Pull contacts and leads constraints straight from Postgres so a
        // migration that drops/re-adds without these values is caught.
        $contacts = $this->extractCheck('contacts');
        $leads = $this->extractCheck('leads');

        $expected = [
            'MANUAL',
            'TELEGRAM',
            'ZALO_PERSONAL',
            'ZALO_OA',
            'FACEBOOK',
            'IMPORT',
            'API',
            'SHOPEE',
            'TIKTOK_SHOP',
            'WEBSITE_FORM',
            'ZALO_MINIAPP',
        ];

        $this->assertEqualsCanonicalizing($expected, $contacts, 'contacts.source CHECK mismatch');
        $this->assertEqualsCanonicalizing($expected, $leads, 'leads.source CHECK mismatch');
    }

    /**
     * @return string[]
     */
    private function extractCheck(string $table): array
    {
        $def = DB::selectOne(
            "SELECT pg_get_constraintdef(c.oid) AS def
             FROM pg_constraint c
             JOIN pg_class t ON t.oid = c.conrelid
             WHERE t.relname = ? AND c.conname = ?",
            [$table, "{$table}_source_check"],
        );

        $this->assertNotNull($def, "{$table}_source_check missing — did the migration run?");

        // Postgres form: CHECK (((source)::text = ANY ((ARRAY[...])::text[])))
        if (! preg_match("/ARRAY\[(.*?)\]\)/", $def->def, $m)) {
            $this->fail("Couldn't parse constraint: {$def->def}");
        }

        // Strip each value: 'MANUAL'::character varying → MANUAL
        $vals = array_map(
            fn ($v) => preg_replace("/^'|'(?:::.*)?$/", '', trim($v)),
            explode(',', $m[1]),
        );

        return array_values(array_filter($vals, fn ($v) => $v !== ''));
    }
}