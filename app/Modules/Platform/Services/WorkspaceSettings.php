<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Workspace;
use App\Modules\Platform\Models\WorkspaceSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Typed accessor for per-workspace configuration. Encrypts the value at rest
 * via Laravel's Crypt facade (APP_KEY-based AES-256-CBC). Decryption is
 * transparent on read.
 *
 * Convention for keys: `<vendor>.<role>` — e.g.
 *   - shopee.partner_credentials
 *   - shopee.api_overrides        (cut 2+)
 *   - tiktok_shop.partner_credentials
 *
 * Always pass arrays through; primitives are allowed but discouraged.
 */
class WorkspaceSettings
{
    public function get(Workspace $workspace, string $key, mixed $default = null): mixed
    {
        $row = WorkspaceSetting::query()
            ->where('workspace_id', $workspace->id)
            ->where('key', $key)
            ->first();

        if ($row === null) {
            return $default;
        }

        $decoded = Crypt::decryptString($row->value);
        $value = json_decode($decoded, true);

        return is_array($value) ? $value : $default;
    }

    public function set(Workspace $workspace, string $key, mixed $value): WorkspaceSetting
    {
        $ciphertext = Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));

        // upsert on the (workspace_id, key) unique index so re-saves are atomic.
        return DB::transaction(function () use ($workspace, $key, $ciphertext) {
            return WorkspaceSetting::query()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'key' => $key],
                ['value' => $ciphertext],
            );
        });
    }

    public function forget(Workspace $workspace, string $key): bool
    {
        return WorkspaceSetting::query()
            ->where('workspace_id', $workspace->id)
            ->where('key', $key)
            ->delete() > 0;
    }

    public function has(Workspace $workspace, string $key): bool
    {
        return WorkspaceSetting::query()
            ->where('workspace_id', $workspace->id)
            ->where('key', $key)
            ->exists();
    }
}