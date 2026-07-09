<?php

namespace App\Modules\Crm\Services\Ingest;

/**
 * Find an existing contact for an ingestion payload.
 *
 * Match priority (most specific first):
 *   1. external_identity  — provider + provider_user_id (deterministic)
 *   2. phone_normalized   — same human via a different channel (provider-agnostic)
 *   3. email              — fallback, case-insensitive
 *
 * Returns null when nothing matches; the caller creates a new contact.
 *
 * Implementation rules:
 *  - All queries scope by workspace_id; the global BelongsToWorkspace scope
 *    already handles this for Eloquent, but we spell it out for clarity.
 *  - Phone match uses the normalized form (84xxxxxxxxx for VN numbers) so a
 *    Zalo "0912..." message and a website form "84..." both resolve to the
 *    same person.
 *  - The match must include the dedup check (handled by ContactIngestor
 *    BEFORE this matcher runs) so we don't try to match across ingestion
 *    races — that would just create two contacts and the unique index on
 *    phone_normalized would abort the transaction.
 */
final class IdentityMatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function match(array $payload): ?\App\Modules\Crm\Models\Contact
    {
        $workspaceId = $payload['workspace_id'];

        // 1. Identity match (deterministic, provider-scoped).
        $identity = $payload['external_identity'] ?? null;
        if ($identity && ! empty($identity['provider']) && ! empty($identity['provider_account_id']) && ! empty($identity['provider_user_id'])) {
            $existing = \App\Modules\Crm\Models\ExternalIdentity::query()
                ->where('workspace_id', $workspaceId)
                ->where('provider', $identity['provider'])
                ->where('provider_account_id', $identity['provider_account_id'])
                ->where('provider_user_id', $identity['provider_user_id'])
                ->first();
            if ($existing) {
                return $existing->contact;
            }
        }

        // 2. Phone match (cross-channel).
        $phone = $payload['phone'] ?? null;
        $normalized = \App\Modules\Crm\Models\Contact::normalizePhone($phone);
        if ($normalized !== null) {
            $found = \App\Modules\Crm\Models\Contact::query()
                ->where('workspace_id', $workspaceId)
                ->where('phone_normalized', $normalized)
                ->first();
            if ($found) {
                return $found;
            }
        }

        // 3. Email match (case-insensitive). Lowercase comparison because
        // email is case-insensitive per RFC 5321 §2.4 (local-part has
        // implementation-defined casing, but the dominant convention is
        // case-insensitive matching).
        $email = $payload['email'] ?? null;
        if (is_string($email) && $email !== '') {
            $found = \App\Modules\Crm\Models\Contact::query()
                ->where('workspace_id', $workspaceId)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->first();
            if ($found) {
                return $found;
            }
        }

        return null;
    }
}