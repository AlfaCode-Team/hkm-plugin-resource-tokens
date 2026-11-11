<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Application\Ports;

use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\ResourceToken;

/**
 * Where tokens live.
 *
 * An interface so the service can be driven against an in-memory store in a
 * unit test and against real SQL in the integration suite — the two suites
 * answer different questions, and neither can answer the other's.
 *
 * Timestamps cross this boundary as `\DateTimeImmutable`, never as strings or
 * ints: the format belongs to the implementation, and a port that passed
 * 'Y-m-d H:i:s' would make the service's correctness depend on the storage's
 * timezone handling.
 */
interface TokenStore
{
    public function save(ResourceToken $token): void;

    /**
     * The one hot-path read, by the digest of the presented value.
     *
     * Returns the row in WHATEVER state it is in — revoked, expired, spent.
     * Filtering those out in SQL would be the obvious thing to do and is wrong:
     * the service needs the row to say WHICH of them happened, and a query that
     * returned null for all of them would make every refusal indistinguishable
     * from "no such token".
     */
    public function findByDigest(string $digest): ?ResourceToken;

    public function get(string $id): ?ResourceToken;

    /**
     * Every token minted for one resource, newest first.
     *
     * @return list<ResourceToken>
     */
    public function forResource(ResourceRef $resource): array;

    /** How many tokens for this resource are usable right now. */
    public function countActiveFor(ResourceRef $resource, \DateTimeImmutable $now): int;

    /**
     * Record one use, atomically, and say whether it was ALLOWED.
     *
     * ──────────────────────────────────────────────────────────────────────
     * THE DECISION AND THE RECORDING ARE ONE STATEMENT. THAT IS THE POINT.
     * ──────────────────────────────────────────────────────────────────────
     *
     * An implementation MUST do this in a single conditional write whose
     * affected-row count is the answer — the shape the outbox and the webhook
     * deliverer both use:
     *
     *   UPDATE resource_tokens
     *      SET uses = uses + 1, last_used_at = :now
     *    WHERE id = :id
     *      AND revoked_at IS NULL
     *      AND (expires_at IS NULL OR expires_at > :now)
     *      AND (max_uses IS NULL OR uses < max_uses)
     *
     * One affected row means this use was legal AND is now recorded. Zero means
     * somebody else got there first, or the row changed under us.
     *
     * Reading the row, deciding in PHP, then writing, leaves a window in which
     * two concurrent requests both read `uses = 0`, both find it below a limit
     * of one, and both proceed — a single-use download link that serves the file
     * twice. That window is small and it is reachable on purpose by anybody who
     * fires two requests at once, which is one line of `curl`.
     *
     * So the service's own pre-checks exist ONLY to produce a precise refusal
     * reason for the log. This row count is the authority, and when the two
     * disagree this one wins.
     *
     * `uses = uses + 1` is computed by the DATABASE, not read into PHP and
     * written back, so concurrent uses cannot lose an increment either.
     */
    public function consume(string $id, \DateTimeImmutable $now): bool;

    /**
     * Withdraw a token. Returns false when there was no such token, or it was
     * already revoked.
     *
     * Idempotent, and it must not move an existing `revoked_at`: the moment
     * somebody first decided is the one worth keeping.
     */
    public function revoke(string $id, \DateTimeImmutable $now): bool;

    /** Withdraw every token for a resource. Returns how many were revoked. */
    public function revokeForResource(ResourceRef $resource, \DateTimeImmutable $now): int;

    /**
     * Delete settled rows older than the cutoff. Returns how many went.
     *
     * Settled means revoked, expired or spent. An ACTIVE token is never pruned
     * regardless of age — a deliberately permanent token is not stale.
     */
    public function prune(\DateTimeImmutable $before): int;

    /**
     * Totals by state, for a status command.
     *
     * @return array<string, int> keys: active, expired, revoked, spent
     */
    public function counts(\DateTimeImmutable $now): array;

    /**
     * What this store is, for a status line.
     *
     * NEVER a credential, a DSN with a password, or key material.
     *
     * @return array<string, scalar>
     */
    public function describe(): array;
}
