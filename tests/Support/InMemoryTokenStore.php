<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Support;

use Plugins\ResourceTokens\Application\Ports\TokenStore;
use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\ResourceToken;

/**
 * The store, in an array.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT THIS CAN AND CANNOT PROVE
 * ────────────────────────────────────────────────────────────────────────────
 *
 * It drives the service's POLICY — which refusal a given state produces, how a
 * lifetime argument is resolved, what the per-resource cap does — fast and
 * without a database.
 *
 * It CANNOT prove the thing the service most depends on. `consume()` here is
 * sequential PHP, so it trivially satisfies the port's atomicity requirement by
 * virtue of there being no concurrency. The real guarantee is a single
 * conditional UPDATE and its affected-row count, and only the integration
 * suite can demonstrate that — which is why the use-limit race is tested there
 * and not here.
 *
 * `failConsume` and `mutateBeforeConsume` exist to simulate what the real store
 * does when it loses that race, because the service has a branch for it that no
 * sequential test would ever reach.
 */
class InMemoryTokenStore implements TokenStore
{
    // NOT final, deliberately: ResourceTokenServiceTest extends it with an
    // anonymous subclass whose findByDigest() throws, which is the only way to
    // take a stack trace while verify()'s frame is still live.

    /** @var array<string, ResourceToken> keyed by token id */
    private array $tokens = [];

    /** Make the next consume() lose, as the conditional UPDATE would. */
    public bool $failConsume = false;

    /**
     * Change the row between the service's pre-checks and its consume().
     *
     * The window the conditional UPDATE exists to close, made reachable: the
     * service checks, something else revokes or spends the token, and then the
     * write finds nothing to update.
     *
     * @var null|\Closure(ResourceToken): ResourceToken
     */
    public ?\Closure $mutateBeforeConsume = null;

    public int $consumeCalls = 0;

    public function save(ResourceToken $token): void
    {
        $this->tokens[$token->id] = $token;
    }

    public function findByDigest(string $digest): ?ResourceToken
    {
        foreach ($this->tokens as $token) {
            // Not hash_equals: this is an in-memory index lookup standing in
            // for a WHERE clause, and the real store compares nothing at all.
            if ($token->digest === $digest) {
                return $token;
            }
        }

        return null;
    }

    public function get(string $id): ?ResourceToken
    {
        return $this->tokens[$id] ?? null;
    }

    /** @return list<ResourceToken> */
    public function forResource(ResourceRef $resource): array
    {
        $found = array_values(array_filter(
            $this->tokens,
            static fn (ResourceToken $t): bool => $t->resource->equals($resource),
        ));

        // Newest first, like the SQL's ORDER BY created_at DESC, id DESC.
        usort($found, static fn (ResourceToken $a, ResourceToken $b): int
            => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $found;
    }

    public function countActiveFor(ResourceRef $resource, \DateTimeImmutable $now): int
    {
        return \count(array_filter(
            $this->forResource($resource),
            static fn (ResourceToken $t): bool => $t->isUsableAt($now),
        ));
    }

    public function consume(string $id, \DateTimeImmutable $now): bool
    {
        $this->consumeCalls++;

        $token = $this->tokens[$id] ?? null;

        if ($token === null) {
            return false;
        }

        if ($this->mutateBeforeConsume !== null) {
            $token = ($this->mutateBeforeConsume)($token);
            $this->tokens[$id] = $token;
        }

        if ($this->failConsume) {
            return false;
        }

        // The same predicate the real UPDATE's WHERE clause carries. Written
        // out rather than calling isUsableAt() so that a change to one is not
        // silently mirrored in the other — these two must be compared by hand.
        if (!$token->isUsableAt($now)) {
            return false;
        }

        $this->tokens[$id] = $token->used($now);

        return true;
    }

    public function revoke(string $id, \DateTimeImmutable $now): bool
    {
        $token = $this->tokens[$id] ?? null;

        if ($token === null || $token->isRevoked()) {
            return false;
        }

        $this->tokens[$id] = $token->revoked($now);

        return true;
    }

    public function revokeForResource(ResourceRef $resource, \DateTimeImmutable $now): int
    {
        $revoked = 0;

        foreach ($this->forResource($resource) as $token) {
            if ($this->revoke($token->id, $now)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    public function prune(\DateTimeImmutable $before): int
    {
        $deleted = 0;

        foreach ($this->tokens as $id => $token) {
            $settledAt = $token->revokedAt ?? $token->expiresAt ?? $token->exhaustedAt;

            if ($settledAt !== null && $settledAt <= $before) {
                unset($this->tokens[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @return array<string, int> */
    public function counts(\DateTimeImmutable $now): array
    {
        $counts = ['active' => 0, 'expired' => 0, 'spent' => 0, 'revoked' => 0];

        foreach ($this->tokens as $token) {
            // state() uses the same precedence the SQL's mutually-exclusive
            // CASE arms do — revoked, then expired, then spent, then active —
            // so the totals add up to the table on both sides.
            $counts[$token->state($now)]++;
        }

        return $counts;
    }

    /** @return array<string, scalar> */
    public function describe(): array
    {
        return ['table' => 'memory', 'driver' => 'array'];
    }

    // ── Test helpers ─────────────────────────────────────────────────────────

    public function replace(ResourceToken $token): void
    {
        $this->tokens[$token->id] = $token;
    }

    /** @return list<ResourceToken> */
    public function all(): array
    {
        return array_values($this->tokens);
    }

    public function count(): int
    {
        return \count($this->tokens);
    }
}
