<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;
use Plugins\ResourceTokens\Application\Ports\TokenStore;
use Plugins\ResourceTokens\Domain\MintedToken;
use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\ResourceToken;
use Plugins\ResourceTokens\Domain\TokenAbilities;
use Plugins\ResourceTokens\Domain\TokenDigest;
use Plugins\ResourceTokens\Domain\TokenRefusal;
use Plugins\ResourceTokens\Domain\TokenVerdict;
use Plugins\ResourceTokens\Infrastructure\Config\ResourceTokenConfig;
use Plugins\ResourceTokens\Infrastructure\Exceptions\ResourceTokenException;

/**
 * Minting, verifying and withdrawing resource tokens.
 *
 * The policy lives here; the atomic guarantee lives in the store's
 * {@see TokenStore::consume()}. Both halves are necessary and neither is
 * sufficient, which is worth being clear about because the division looks
 * redundant until the race matters:
 *
 *   The checks in {@see verify()} produce a PRECISE REASON for the log. They
 *   are not the gate.
 *
 *   `consume()` is the gate. One conditional UPDATE decides and records in the
 *   same statement, and its affected-row count is the authority. When the two
 *   disagree — which happens when another request got there in between — the
 *   row count wins and the token is refused.
 */
final class ResourceTokenService implements ResourceTokenContract
{
    public function __construct(
        private readonly TokenStore $store,
        private readonly ResourceTokenConfig $config,
        private readonly ClockPort $clock,
    ) {}

    // ── Minting ──────────────────────────────────────────────────────────────

    public function mint(
        ResourceRef $resource,
        ?int $ttlSeconds = null,
        ?TokenAbilities $abilities = null,
        ?int $maxUses = null,
        string $label = '',
        string $createdBy = '',
    ): MintedToken {
        $now = $this->clock->now();

        $expiresAt = $this->expiryFor($ttlSeconds, $now);

        if ($maxUses !== null && $maxUses < 1) {
            throw ResourceTokenException::invalidMaxUses($maxUses);
        }

        $this->assertResourceHasRoom($resource, $now);

        $secret = TokenDigest::mint();

        $token = new ResourceToken(
            id:          $this->newId(),
            resource:    $resource,
            abilities:   $abilities ?? TokenAbilities::readOnly(),
            digest:      TokenDigest::of($secret),
            expiresAt:   $expiresAt,
            maxUses:     $maxUses,
            uses:        0,
            lastUsedAt:  null,
            exhaustedAt: null,
            revokedAt:   null,
            createdAt:   $now,
            // Bounded here rather than refused: a label is a convenience for
            // whoever reads the list later, and failing a mint over a long
            // note would be the wrong trade. The resource and the lifetime are
            // the things worth refusing over.
            label:       mb_substr($label, 0, 255),
            createdBy:   mb_substr($createdBy, 0, 191),
        );

        try {
            $this->store->save($token);
        } catch (ResourceTokenException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ResourceTokenException::saveFailed($resource->label(), $e);
        }

        return new MintedToken($token, $secret);
    }

    /**
     * Resolve the three-way lifetime argument into an absolute instant.
     *
     * null  → the configured default
     * > 0   → that many seconds from now
     * -1    → no expiry  ({@see ResourceTokenContract::NEVER_EXPIRES})
     * 0     → refused, and {@see ResourceTokenException::zeroLifetime()} says why
     */
    private function expiryFor(?int $ttlSeconds, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        if ($ttlSeconds === self::NEVER_EXPIRES) {
            if (!$this->config->allowPermanent) {
                throw ResourceTokenException::permanentRefused();
            }

            return null;
        }

        if ($ttlSeconds === 0) {
            throw ResourceTokenException::zeroLifetime();
        }

        if ($ttlSeconds !== null && $ttlSeconds < 0) {
            throw ResourceTokenException::negativeLifetime($ttlSeconds);
        }

        $ttl = $ttlSeconds ?? $this->config->defaultTtl;

        // The ceiling is checked against what the CALLER asked for, including
        // when the caller asked for nothing: a default longer than the ceiling
        // is a misconfiguration, and silently clamping it would leave the
        // operator believing the default was in force.
        if ($this->config->maxTtl > 0 && $ttl > $this->config->maxTtl) {
            throw ResourceTokenException::lifetimeTooLong($ttl, $this->config->maxTtl);
        }

        return $now->add(new \DateInterval('PT' . $ttl . 'S'));
    }

    private function assertResourceHasRoom(ResourceRef $resource, \DateTimeImmutable $now): void
    {
        if ($this->config->maxPerResource === 0) {
            return;
        }

        $live = $this->store->countActiveFor($resource, $now);

        if ($live >= $this->config->maxPerResource) {
            throw ResourceTokenException::tooManyForResource(
                $resource->label(),
                $this->config->maxPerResource,
            );
        }
    }

    // ── Verifying ────────────────────────────────────────────────────────────

    /**
     * The hot path. Everything about its shape is about refusing cheaply and
     * recording atomically.
     */
    public function verify(
        #[\SensitiveParameter] string $presented,
        ResourceRef $expected,
        string $ability = TokenAbilities::READ,
    ): TokenVerdict {
        // Before the database. An endpoint being sprayed with junk costs a
        // strlen and a regex, not an indexed query per request.
        if (!TokenDigest::isPlausible($presented)) {
            return TokenVerdict::refused(TokenRefusal::Malformed);
        }

        $token = $this->store->findByDigest(TokenDigest::of($presented));

        if ($token === null) {
            return TokenVerdict::refused(TokenRefusal::Unknown);
        }

        $now = $this->clock->now();

        // THE CHECK THIS PLUGIN EXISTS FOR. A valid token aimed at a resource
        // it was not minted for is refused, and is reported as the one refusal
        // that means somebody is trying things rather than holding a link that
        // ran out.
        if (!$token->resource->equals($expected)) {
            return TokenVerdict::refused(TokenRefusal::WrongResource, $token);
        }

        if (!$token->abilities->permits($ability)) {
            return TokenVerdict::refused(TokenRefusal::MissingAbility, $token);
        }

        $refusal = $token->refusalAt($now);

        if ($refusal !== null) {
            return TokenVerdict::refused($refusal, $token);
        }

        // Unlimited AND use-tracking turned off: there is nothing to enforce
        // and nothing to record, so the write is skipped entirely. This is the
        // `<img src>` case — a token on a hot page would otherwise turn every
        // image request into a write, and an UPDATE per image is how a read
        // path becomes a replication bottleneck.
        //
        // A LIMITED token always writes, whatever this setting says: the write
        // IS the enforcement.
        if ($token->maxUses === null && !$this->config->trackUses) {
            return TokenVerdict::granted($token);
        }

        if (!$this->store->consume($token->id, $now)) {
            // The pre-checks above passed and the conditional write did not, so
            // something changed in between: a concurrent use took the last
            // allowance, or the token was revoked mid-request. The row count is
            // the authority; this re-read exists only to name the reason, and
            // it is best-effort — by the time it runs the row may have changed
            // again, or been pruned.
            return TokenVerdict::refused(
                $this->store->get($token->id)?->refusalAt($now) ?? TokenRefusal::Exhausted,
                $token,
            );
        }

        // The granted verdict carries the token as it was READ, not as it now
        // is: one more use has been recorded since. The caller is being told
        // what it may do, and `uses` is not part of that answer — a caller that
        // needs the current count has to read it back, because any value
        // returned here is already stale.
        return TokenVerdict::granted($token);
    }

    // ── Withdrawing, listing, housekeeping ───────────────────────────────────

    public function revoke(string $tokenId): bool
    {
        return $this->store->revoke($tokenId, $this->clock->now());
    }

    public function revokeFor(ResourceRef $resource): int
    {
        return $this->store->revokeForResource($resource, $this->clock->now());
    }

    /** @return list<ResourceToken> */
    public function tokensFor(ResourceRef $resource): array
    {
        return $this->store->forResource($resource);
    }

    public function prune(): int
    {
        return $this->store->prune(
            $this->clock->now()->sub(
                new \DateInterval('PT' . $this->config->pruneAfterSeconds() . 'S'),
            ),
        );
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->store->counts($this->clock->now());
    }

    public function describe(): string
    {
        $parts = [];

        foreach ([...$this->store->describe(), ...$this->config->describe()] as $key => $value) {
            $parts[] = $key . '=' . (\is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
        }

        return implode(' ', $parts);
    }

    /**
     * A UUID v4 for the row.
     *
     * Hand-rolled rather than pulled from a dependency: it is nine lines, and a
     * plugin whose whole job is one table should not add a composer requirement
     * for them. `random_bytes()` and not `mt_rand()` — this id is not a secret,
     * but a predictable id would let somebody enumerate `restokens:revoke`
     * targets if an application ever exposed revocation by id to a caller.
     */
    private function newId(): string
    {
        $bytes = random_bytes(16);

        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80); // variant 1

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
