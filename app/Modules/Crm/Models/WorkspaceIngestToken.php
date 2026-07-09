<?php

namespace App\Modules\Crm\Models;

use App\Modules\Platform\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-workspace public API token for the contact ingest endpoint.
 *
 * Two auth surfaces:
 *  - `token_hash` (bcrypt) verifies the X-Workspace-Key plaintext on every call.
 *  - `hmac_secret` (encrypted) verifies the X-Signature header for ZALO_MINIAPP
 *    tokens. We never log or return either secret in plaintext.
 *
 * Scope is per-source via `allowed_sources`. A leaked web-form token cannot
 * be reused against the Mini App endpoint — the controller checks this list
 * before doing anything else.
 */
class WorkspaceIngestToken extends Model
{
    use BelongsToWorkspace, HasUuids;

    protected $guarded = [];

    protected $hidden = ['token_hash', 'hmac_secret'];

    protected function casts(): array
    {
        return [
            'allowed_sources' => 'array',
            'default_owner_strategy' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'rate_limit_per_minute' => 'integer',
            // hmac_secret uses Laravel's encrypted cast so reads decrypt on
            // the fly. Never expose this attribute via JSON responses.
            'hmac_secret' => 'encrypted',
        ];
    }

    /** True if the token can still be used for authentication. */
    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /** True if this token accepts the given source code. */
    public function allowsSource(string $source): bool
    {
        $allowed = $this->allowed_sources ?? [];

        return is_array($allowed) && in_array($source, $allowed, true);
    }

    /** True if this token requires HMAC signature verification. */
    public function requiresHmac(): bool
    {
        return ! empty($this->hmac_secret);
    }
}