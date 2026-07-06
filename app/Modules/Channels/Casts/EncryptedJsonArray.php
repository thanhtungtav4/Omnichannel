<?php

namespace App\Modules\Channels\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Stores encrypted arrays inside a JSON column as {"payload": "..."}.
 *
 * Laravel's built-in encrypted:array cast writes a raw ciphertext string, which
 * is correct for TEXT columns but invalid for PostgreSQL JSON columns.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<string, mixed>|null>
 */
class EncryptedJsonArray implements CastsAttributes
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);
        if (! is_array($decoded)) {
            return null;
        }

        if (! isset($decoded['payload'])) {
            return $decoded;
        }

        $decrypted = Crypt::decryptString((string) $decoded['payload']);
        $data = json_decode($decrypted, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode([
            'payload' => Crypt::encryptString(json_encode($value)),
        ], JSON_THROW_ON_ERROR);
    }
}
