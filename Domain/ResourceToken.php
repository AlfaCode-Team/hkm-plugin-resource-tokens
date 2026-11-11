<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Domain;

/**
 * A minted token, as it is stored — WITHOUT the secret.
 *
 * The secret exists for exactly as long as one function call: it is generated,
 * digested, the digest is stored, and the raw value is handed back inside a
 * {@see MintedToken} and then forgotten. Nothing can reconstruct it, including
 * us. That is the difference between this and a webhook secret, which has to be
 * decryptable because we sign with it; here we only ever need to RECOGNISE a
 * presented value, so the one-way form is sufficient and therefore mandatory.
 */
final readonly class ResourceToken
{
    public function __construct(
        /** This row's identity, and what `restokens:revoke` takes. Not a secret. */
        public string $id,
        public ResourceRef $resource,
        public TokenAbilities $abilities,
        /** SHA-256 of the secret. See {@see TokenDigest} for why that is enough. */
        public string $digest,
        /** null = never expires. See the hazard note on the contract. */
        public ?\DateTimeImmutable $expiresAt,
        /** null = unlimited uses. */
        public ?int $maxUses,
        public int $uses,
        public ?\DateTimeImmutable $lastUsedAt,
        /** Set when the use that reached `maxUses` was recorded. */
        public ?\DateTimeImmutable $exhaustedAt,
        /** Set by a deliberate withdrawal, and never cleared. */
        public ?\DateTimeImmutable $revokedAt,
        public \DateTimeImmutable $createdAt,
        /** Free text for a human: "emailed to the claimant", "avatar CDN". */
        public string $label,
        /**
         * Who minted it, as the application understands identity. Audit only —
         * it grants nothing and is never checked.
         */
        public string $createdBy,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        // `<=`, not `<`. At the instant of expiry the token is gone: a token
        // minted with a one-second lifetime and used in the same second would
        // otherwise still work, and the boundary case is the one somebody
        // eventually tests.
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }

    public function isExhausted(): bool
    {
        return $this->maxUses !== null && $this->uses >= $this->maxUses;
    }

    /**
     * The token's own reason for refusing, or null when it is usable.
     *
     * This covers only the state of the ROW. It deliberately does not take a
     * {@see ResourceRef} or an ability: those belong to the request, not to the
     * token, and folding them in here would make it possible to call this and
     * believe the resource had been checked when the caller never passed one.
     *
     * Checked in the order the enum documents.
     */
    public function refusalAt(\DateTimeImmutable $now): ?TokenRefusal
    {
        if ($this->isRevoked()) {
            return TokenRefusal::Revoked;
        }

        if ($this->isExpired($now)) {
            return TokenRefusal::Expired;
        }

        if ($this->isExhausted()) {
            return TokenRefusal::Exhausted;
        }

        return null;
    }

    public function isUsableAt(\DateTimeImmutable $now): bool
    {
        return $this->refusalAt($now) === null;
    }

    /** Uses left, or null when unlimited. */
    public function usesRemaining(): ?int
    {
        return $this->maxUses === null ? null : max(0, $this->maxUses - $this->uses);
    }

    /**
     * Record one use. Returns a NEW instance; this class is immutable.
     *
     * This is the in-memory shape of the state change. The AUTHORITATIVE one is
     * a single conditional UPDATE in the repository — see
     * {@see \Plugins\ResourceTokens\Application\Ports\TokenStore::consume()} —
     * because deciding here and writing there leaves a window in which two
     * concurrent requests both read `uses = 0` and both pass a limit of one.
     */
    public function used(\DateTimeImmutable $now): self
    {
        $uses = $this->uses + 1;

        return new self(
            id:          $this->id,
            resource:    $this->resource,
            abilities:   $this->abilities,
            digest:      $this->digest,
            expiresAt:   $this->expiresAt,
            maxUses:     $this->maxUses,
            uses:        $uses,
            lastUsedAt:  $now,
            exhaustedAt: $this->maxUses !== null && $uses >= $this->maxUses
                ? ($this->exhaustedAt ?? $now)
                : $this->exhaustedAt,
            revokedAt:   $this->revokedAt,
            createdAt:   $this->createdAt,
            label:       $this->label,
            createdBy:   $this->createdBy,
        );
    }

    public function revoked(\DateTimeImmutable $now): self
    {
        return new self(
            id:          $this->id,
            resource:    $this->resource,
            abilities:   $this->abilities,
            digest:      $this->digest,
            expiresAt:   $this->expiresAt,
            maxUses:     $this->maxUses,
            uses:        $this->uses,
            lastUsedAt:  $this->lastUsedAt,
            exhaustedAt: $this->exhaustedAt,
            // Never overwritten. The first withdrawal is the one that matters,
            // and re-revoking would move the timestamp away from the moment
            // somebody actually decided.
            revokedAt:   $this->revokedAt ?? $now,
            createdAt:   $this->createdAt,
            label:       $this->label,
            createdBy:   $this->createdBy,
        );
    }

    /** One word for a CLI table and a status page. */
    public function state(\DateTimeImmutable $now): string
    {
        return match (true) {
            $this->isRevoked()      => 'revoked',
            $this->isExpired($now)  => 'expired',
            $this->isExhausted()    => 'spent',
            default                 => 'active',
        };
    }

    /**
     * Safe to log, print and return in an API.
     *
     * NO SECRET, because none is held. The `digest_prefix` is twelve characters
     * of the SHA-256, which is deliberately there and deliberately partial:
     *
     *   - it is NOT a credential. Presenting a digest cannot authenticate
     *     anything — verification hashes what it is given, so using the digest
     *     would require a preimage of it.
     *   - it is what makes an incident tractable. Given a token found in an
     *     access log or a support ticket, `shasum -a 256` of it and a glance at
     *     `restokens:list` identifies the row to revoke, without the token
     *     itself ever being pasted into our logs or our shell history.
     *   - the full digest is not shown because it would be the obvious thing to
     *     put in a URL by somebody who assumed it was the token, and twelve
     *     characters is enough to pick one row out of a table.
     */
    public function describe(\DateTimeImmutable $now): array
    {
        return [
            'id'            => $this->id,
            'resource'      => $this->resource->label(),
            'resource_type' => $this->resource->type,
            'resource_id'   => $this->resource->id,
            'abilities'     => $this->abilities->toArray(),
            'state'         => $this->state($now),
            'digest_prefix' => TokenDigest::prefix($this->digest),
            'expires_at'    => $this->expiresAt?->format(\DateTimeInterface::RFC3339),
            'max_uses'      => $this->maxUses,
            'uses'          => $this->uses,
            'uses_left'     => $this->usesRemaining(),
            'last_used_at'  => $this->lastUsedAt?->format(\DateTimeInterface::RFC3339),
            'revoked_at'    => $this->revokedAt?->format(\DateTimeInterface::RFC3339),
            'created_at'    => $this->createdAt->format(\DateTimeInterface::RFC3339),
            'label'         => $this->label,
            'created_by'    => $this->createdBy,
        ];
    }
}
