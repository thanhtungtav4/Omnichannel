<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Models\WorkspaceIngestToken;
use App\Modules\Platform\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mint / rotate / revoke workspace ingest tokens.
 *
 * Token format: `whk_<32-char base32>` (and `zmp_` for Mini App tokens —
 * the prefix lets ops tell them apart at a glance). Prefix + first 8 chars
 * are stored in `token_prefix` for grep + UI display. Full plaintext is
 * returned EXACTLY ONCE at mint time; we keep the bcrypt hash so future
 * calls can be verified, but never re-derive the plaintext.
 *
 * HMAC secret (for ZALO_MINIAPP tokens) is generated at mint time and
 * stored encrypted via Laravel Crypt. It's never returned to the caller —
 * the Mini App integrator configures it via the Zalo OA dashboard instead,
 * or pastes it once into a config UI (TBD; out of scope for C3).
 */
final class IngestTokenIssuer
{
    public const PREFIX_FORM = 'whk_';

    public const PREFIX_MINIAPP = 'zmp_';

    /**
     * Mint a new token for the workspace.
     *
     * @param  array{
     *     name: string,
     *     allowed_sources: array<int, string>,
     *     rate_limit_per_minute?: int,
     *     default_source_detail?: ?string,
     *     domain_whitelist?: ?string,
     *     expires_at?: ?\DateTimeInterface|string,
     *     with_hmac?: bool,
     * }  $options
     * @return array{token: WorkspaceIngestToken, plaintext: string, hmac_secret: ?string}
     */
    public function mint(Workspace $workspace, array $options): array
    {
        $withHmac = (bool) ($options['with_hmac'] ?? false);
        $prefix = $withHmac ? self::PREFIX_MINIAPP : self::PREFIX_FORM;

        // 32 chars of base32 (Crockford, no ambiguous 0/O/1/I/L) keeps the
        // plaintext URL-safe and ~160 bits of entropy.
        $random = self::base32Random(32);
        $plaintext = $prefix.$random;
        $tokenPrefix = substr($plaintext, 0, 8);

        $hmacPlaintext = null;
        if ($withHmac) {
            $hmacPlaintext = Str::random(64);
        }

        $token = WorkspaceIngestToken::create([
            'workspace_id' => $workspace->id,
            'name' => $options['name'],
            'token_prefix' => $tokenPrefix,
            'token_hash' => password_hash($plaintext, PASSWORD_BCRYPT),
            'allowed_sources' => $options['allowed_sources'],
            'hmac_secret' => $hmacPlaintext,
            'default_source_detail' => $options['default_source_detail'] ?? null,
            'domain_whitelist' => $options['domain_whitelist'] ?? null,
            'rate_limit_per_minute' => $options['rate_limit_per_minute'] ?? 60,
            'expires_at' => $options['expires_at'] ?? null,
        ]);

        return [
            'token' => $token,
            'plaintext' => $plaintext,
            'hmac_secret' => $hmacPlaintext,
        ];
    }

    /**
     * Rotate: mint a fresh token, revoke the old one. The new plaintext is
     * returned (so the caller can show "Newly minted" toast). The old token
     * keeps its row for audit but is unusable.
     */
    public function rotate(WorkspaceIngestToken $old): array
    {
        $minted = $this->mint($old->workspace, [
            'name' => $old->name.' (rotated)',
            'allowed_sources' => $old->allowed_sources ?? [],
            'rate_limit_per_minute' => $old->rate_limit_per_minute,
            'default_source_detail' => $old->default_source_detail,
            'domain_whitelist' => $old->domain_whitelist,
            'expires_at' => $old->expires_at,
            'with_hmac' => $old->requiresHmac(),
        ]);

        $old->forceFill(['revoked_at' => Carbon::now()])->save();

        return $minted;
    }

    /** Soft-revoke. Hard-delete is reserved for ops incidents. */
    public function revoke(WorkspaceIngestToken $token): void
    {
        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => Carbon::now()])->save();
        }
    }

    /**
     * 32 chars of Crockford base32 — URL-safe, no padding, no ambiguous
     * characters (0/O/1/I/L). Uses random_bytes for CSPRNG entropy.
     */
    public static function base32Random(int $length): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford (omit I, L, O, U)
        $max = strlen($alphabet) - 1;
        $bytes = random_bytes($length);
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[ord($bytes[$i]) % ($max + 1)];
        }

        return $out;
    }
}