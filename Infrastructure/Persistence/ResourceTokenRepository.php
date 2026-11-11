<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DriverAware;
use Plugins\ResourceTokens\Application\Ports\TokenStore;
use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\ResourceToken;
use Plugins\ResourceTokens\Domain\TokenAbilities;

/**
 * One table, and one statement that matters.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * EVERY TIMESTAMP COMPARED HERE IS WRITTEN BY PHP
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Not one of them comes from a SQL `CURRENT_TIMESTAMP` default, and that is
 * deliberate rather than incidental. Timestamps cross as `'Y-m-d H:i:s'`, which
 * carries no zone, so a value written by the DATABASE in the server's session
 * zone and a value written by PHP in PHP's zone would be compared as if they
 * were the same clock. On a MySQL server in one zone and an application in
 * another, `expires_at > :now` is then wrong by the offset between them —
 * either refusing live tokens or honouring dead ones, depending on the sign.
 *
 * With both sides written by PHP, the only requirement is that PHP's own
 * timezone is consistent across processes, which it is: the comparison is
 * between two values this code produced.
 *
 * The migration does declare a `CURRENT_TIMESTAMP` default on `created_at`, as
 * a safety net for a row inserted by hand. Nothing in this plugin compares
 * `created_at`, so that net cannot pull a decision off.
 */
final class ResourceTokenRepository implements TokenStore
{
    private const TABLE = 'resource_tokens';

    /** Rows deleted per statement, so a prune cannot build one enormous DELETE. */
    private const PRUNE_BATCH = 1000;

    /**
     * A ceiling on the prune loop.
     *
     * Without it, a bug in the cutoff that makes `idsToPrune()` keep returning
     * rows turns a nightly command into an infinite loop holding a connection.
     * 1000 × 100 = 100,000 rows per run, and the next run takes the rest.
     */
    private const PRUNE_MAX_RUNS = 100;

    public function __construct(private readonly DatabasePort $db) {}

    // ── Writes ───────────────────────────────────────────────────────────────

    public function save(ResourceToken $token): void
    {
        try {
            $this->db->execute(
                'INSERT INTO ' . self::TABLE . ' (
                    id, resource_type, resource_id, token_digest, abilities,
                    label, created_by, expires_at, max_uses, uses,
                    last_used_at, exhausted_at, revoked_at, created_at
                 ) VALUES (
                    :id, :resource_type, :resource_id, :token_digest, :abilities,
                    :label, :created_by, :expires_at, :max_uses, :uses,
                    :last_used_at, :exhausted_at, :revoked_at, :created_at
                 )',
                [
                    'id'            => $token->id,
                    'resource_type' => $token->resource->type,
                    'resource_id'   => $token->resource->id,
                    'token_digest'  => $token->digest,
                    'abilities'     => json_encode($token->abilities->toArray(), \JSON_THROW_ON_ERROR),
                    'label'         => $token->label,
                    'created_by'    => $token->createdBy,
                    'expires_at'    => self::stamp($token->expiresAt),
                    'max_uses'      => $token->maxUses,
                    'uses'          => $token->uses,
                    'last_used_at'  => self::stamp($token->lastUsedAt),
                    'exhausted_at'  => self::stamp($token->exhaustedAt),
                    'revoked_at'    => self::stamp($token->revokedAt),
                    'created_at'    => self::stamp($token->createdAt),
                ],
            );
        } catch (\Throwable $e) {
            // A plain INSERT, not an upsert. The digest column is unique, so a
            // collision would raise here — and a collision on a SHA-256 of 32
            // random bytes is not something to paper over with
            // `ON CONFLICT DO NOTHING`: that would silently return a MintedToken
            // whose secret opens somebody else's row.
            throw new RepositoryException(
                'Could not store the resource token.',
                layer: 'repository.resource-tokens.save',
                context: ['resource' => $token->resource->label()],
                previous: $e,
            );
        }
    }

    /**
     * ONE statement that decides and records. See
     * {@see TokenStore::consume()} for why this cannot be two.
     */
    public function consume(string $id, \DateTimeImmutable $now): bool
    {
        try {
            $affected = $this->db->execute(
                'UPDATE ' . self::TABLE . '
                    SET uses = uses + 1, last_used_at = :now
                  WHERE id = :id
                    AND revoked_at IS NULL
                    AND (expires_at IS NULL OR expires_at > :expiry_now)
                    AND (max_uses IS NULL OR uses < max_uses)',
                [
                    // Two bind names for one value. `:now` appears in the SET
                    // and `:expiry_now` in the WHERE, and PDO with emulated
                    // prepares OFF rejects a named placeholder reused in two
                    // places. A plugin cannot know how the application
                    // configured its PDO, so it never reuses one.
                    'now'        => self::stamp($now),
                    'expiry_now' => self::stamp($now),
                    'id'         => $id,
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not record a resource token use.',
                layer: 'repository.resource-tokens.consume',
                context: ['token' => $id],
                previous: $e,
            );
        }

        if ($affected !== 1) {
            return false;
        }

        $this->markExhaustedIfSpent($id, $now);

        return true;
    }

    /**
     * Stamp a token whose last allowed use has just been recorded.
     *
     * A SECOND statement on purpose, and this is the interesting part. Folding
     * it into the UPDATE above would need `uses + 1` inside a CASE, and MySQL
     * evaluates a SET list LEFT TO RIGHT with later assignments seeing earlier
     * ones, while PostgreSQL and SQLite evaluate every right-hand side against
     * the OLD row. The same statement would therefore be off by one between
     * MySQL and the other two, and the integration suite runs on SQLite — so
     * the one driver that could show the bug is the one that never would.
     *
     * Split out, it references no arithmetic at all: it is idempotent, ordering
     * is irrelevant, and it reads the same on every driver.
     *
     * It is also NOT part of the decision. `consume()` has already decided and
     * recorded; this only sets a flag that makes `restokens:list` say "spent"
     * instead of "active" and gives `prune()` an indexable column to work
     * from. If it failed, nothing would be mis-granted.
     *
     * `AND exhausted_at IS NULL` is UNREACHABLE by any sequential path, and a
     * revert check is what established that — removing it passes the whole
     * suite. This statement runs only after a successful consume, which
     * requires `uses < max_uses`, and it writes only when `uses >= max_uses`,
     * so a row cannot arrive here already stamped. Its one real scenario needs
     * two processes interleaving between the two statements of one `consume()`
     * call, where it keeps the FIRST exhaustion timestamp rather than a later
     * one. It stays because it makes the statement idempotent on its face, and
     * the README records it as unpinned rather than as covered.
     */
    private function markExhaustedIfSpent(string $id, \DateTimeImmutable $now): void
    {
        try {
            $this->db->execute(
                'UPDATE ' . self::TABLE . '
                    SET exhausted_at = :now
                  WHERE id = :id
                    AND exhausted_at IS NULL
                    AND max_uses IS NOT NULL
                    AND uses >= max_uses',
                ['now' => self::stamp($now), 'id' => $id],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not mark a resource token as spent.',
                layer: 'repository.resource-tokens.exhaust',
                context: ['token' => $id],
                previous: $e,
            );
        }
    }

    public function revoke(string $id, \DateTimeImmutable $now): bool
    {
        try {
            // `AND revoked_at IS NULL` makes this idempotent AND keeps the
            // first decision's timestamp: re-revoking would otherwise move
            // `revoked_at` away from the moment somebody actually decided,
            // which is the one fact an audit of this row is for.
            return $this->db->execute(
                'UPDATE ' . self::TABLE . '
                    SET revoked_at = :now
                  WHERE id = :id AND revoked_at IS NULL',
                ['now' => self::stamp($now), 'id' => $id],
            ) === 1;
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not revoke the resource token.',
                layer: 'repository.resource-tokens.revoke',
                context: ['token' => $id],
                previous: $e,
            );
        }
    }

    public function revokeForResource(ResourceRef $resource, \DateTimeImmutable $now): int
    {
        try {
            return $this->db->execute(
                'UPDATE ' . self::TABLE . '
                    SET revoked_at = :now
                  WHERE resource_type = :type AND resource_id = :id AND revoked_at IS NULL',
                ['now' => self::stamp($now), 'type' => $resource->type, 'id' => $resource->id],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not revoke the resource\'s tokens.',
                layer: 'repository.resource-tokens.revoke-all',
                context: ['resource' => $resource->label()],
                previous: $e,
            );
        }
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    public function findByDigest(string $digest): ?ResourceToken
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM ' . self::TABLE . ' WHERE token_digest = :digest',
                ['digest' => $digest],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not look up the resource token.',
                layer: 'repository.resource-tokens.find',
                // NOT the digest. It is not a credential, but it is the lookup
                // key for one row, and a context array ends up in an error
                // report that is mailed and stored — there is no reason for it
                // to travel there.
                previous: $e,
            );
        }

        return $row === null ? null : $this->hydrate($row);
    }

    public function get(string $id): ?ResourceToken
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
                ['id' => $id],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not read the resource token.',
                layer: 'repository.resource-tokens.get',
                context: ['token' => $id],
                previous: $e,
            );
        }

        return $row === null ? null : $this->hydrate($row);
    }

    /** @return list<ResourceToken> */
    public function forResource(ResourceRef $resource): array
    {
        try {
            $rows = $this->db->query(
                'SELECT * FROM ' . self::TABLE . '
                  WHERE resource_type = :type AND resource_id = :id
                  ORDER BY created_at DESC, id DESC',
                ['type' => $resource->type, 'id' => $resource->id],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not list the resource\'s tokens.',
                layer: 'repository.resource-tokens.list',
                context: ['resource' => $resource->label()],
                previous: $e,
            );
        }

        // `id DESC` as a tiebreaker on `created_at`: two tokens minted in the
        // same second would otherwise come back in whatever order the storage
        // felt like, so a list page would reorder itself between two reads of
        // the same data.
        $tokens = [];

        foreach ($rows as $row) {
            $token = $this->hydrate($row);

            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    public function countActiveFor(ResourceRef $resource, \DateTimeImmutable $now): int
    {
        try {
            $row = $this->db->queryOne(
                'SELECT COUNT(*) AS n FROM ' . self::TABLE . '
                  WHERE resource_type = :type
                    AND resource_id = :id
                    AND revoked_at IS NULL
                    AND (expires_at IS NULL OR expires_at > :now)
                    AND (max_uses IS NULL OR uses < max_uses)',
                ['type' => $resource->type, 'id' => $resource->id, 'now' => self::stamp($now)],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not count the resource\'s live tokens.',
                layer: 'repository.resource-tokens.count',
                context: ['resource' => $resource->label()],
                previous: $e,
            );
        }

        return (int) ($row['n'] ?? 0);
    }

    /** @return array<string, int> */
    public function counts(\DateTimeImmutable $now): array
    {
        // One round trip, and the four predicates are MUTUALLY EXCLUSIVE in the
        // same precedence ResourceToken::state() uses — revoked, then expired,
        // then spent, then active. Four independent COUNT(*) queries would
        // each be simpler and would double-count a token that is both expired
        // and spent, so the totals would not add up to the table.
        //
        // SUM over an empty table is NULL, hence the casts.
        $sql = 'SELECT
                  SUM(CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revoked,
                  SUM(CASE WHEN revoked_at IS NULL
                            AND expires_at IS NOT NULL AND expires_at <= :now_a
                           THEN 1 ELSE 0 END) AS expired,
                  SUM(CASE WHEN revoked_at IS NULL
                            AND (expires_at IS NULL OR expires_at > :now_b)
                            AND max_uses IS NOT NULL AND uses >= max_uses
                           THEN 1 ELSE 0 END) AS spent,
                  SUM(CASE WHEN revoked_at IS NULL
                            AND (expires_at IS NULL OR expires_at > :now_c)
                            AND (max_uses IS NULL OR uses < max_uses)
                           THEN 1 ELSE 0 END) AS active
                FROM ' . self::TABLE;

        try {
            $row = $this->db->queryOne($sql, [
                'now_a' => self::stamp($now),
                'now_b' => self::stamp($now),
                'now_c' => self::stamp($now),
            ]);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not count resource tokens.',
                layer: 'repository.resource-tokens.counts',
                previous: $e,
            );
        }

        return [
            'active'  => (int) ($row['active'] ?? 0),
            'expired' => (int) ($row['expired'] ?? 0),
            'spent'   => (int) ($row['spent'] ?? 0),
            'revoked' => (int) ($row['revoked'] ?? 0),
        ];
    }

    // ── Housekeeping ─────────────────────────────────────────────────────────

    public function prune(\DateTimeImmutable $before): int
    {
        $deleted = 0;

        for ($run = 0; $run < self::PRUNE_MAX_RUNS; $run++) {
            $ids = $this->idsToPrune($before);

            if ($ids === []) {
                break;
            }

            $placeholders = [];
            $params       = [];

            foreach (array_values($ids) as $index => $id) {
                $placeholders[]       = ':id' . $index;
                $params['id' . $index] = $id;
            }

            try {
                $deleted += $this->db->execute(
                    'DELETE FROM ' . self::TABLE . ' WHERE id IN (' . implode(', ', $placeholders) . ')',
                    $params,
                );
            } catch (\Throwable $e) {
                throw new RepositoryException(
                    'Could not prune resource tokens.',
                    layer: 'repository.resource-tokens.prune',
                    previous: $e,
                );
            }

            // A short batch means that was the last of them. Checking this
            // rather than looping until the select is empty saves one query
            // per run on the common case of a table with nothing to prune.
            if (\count($ids) < self::PRUNE_BATCH) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * Ids of SETTLED rows that settled before the cutoff.
     *
     * ──────────────────────────────────────────────────────────────────────
     * SELECT-THEN-DELETE-BY-ID, BECAUSE `DELETE ... LIMIT` IS NOT PORTABLE
     * ──────────────────────────────────────────────────────────────────────
     *
     * MySQL takes `DELETE ... LIMIT n`; PostgreSQL does not. An unbounded
     * DELETE on a table that has not been pruned for a year is one enormous
     * transaction holding locks while it runs, so the batching is not optional
     * — and this is the portable way to bound it.
     *
     * Each arm of the OR has its OWN indexable column, which is why there are
     * three timestamps on this table rather than one `settled_at`:
     *
     *   revoked_at    — withdrawn deliberately
     *   expires_at    — ran out of time
     *   exhausted_at  — ran out of uses
     *
     * An ACTIVE token is never selected here regardless of age. A deliberately
     * permanent token is not stale, and deleting it would delete the live link
     * somebody is relying on — the one deletion in this plugin that cannot be
     * undone, since the secret cannot be recovered to re-create the row.
     *
     * @return list<string>
     */
    private function idsToPrune(\DateTimeImmutable $before): array
    {
        $cutoff = self::stamp($before);

        try {
            $rows = $this->db->query(
                'SELECT id FROM ' . self::TABLE . '
                  WHERE (revoked_at   IS NOT NULL AND revoked_at   <= :cutoff_a)
                     OR (expires_at   IS NOT NULL AND expires_at   <= :cutoff_b)
                     OR (exhausted_at IS NOT NULL AND exhausted_at <= :cutoff_c)
                  ORDER BY id
                  LIMIT ' . self::PRUNE_BATCH,
                ['cutoff_a' => $cutoff, 'cutoff_b' => $cutoff, 'cutoff_c' => $cutoff],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Could not select resource tokens to prune.',
                layer: 'repository.resource-tokens.prune',
                previous: $e,
            );
        }

        $ids = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;

            if (\is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return array<string, scalar> */
    public function describe(): array
    {
        return [
            'table'  => self::TABLE,
            // The dialect, through the OPTIONAL DriverAware interface —
            // DatabasePort has no driver(), so this is an instanceof check and
            // not a method call. Unknown is a fine answer; guessing is not.
            'driver' => $this->db instanceof DriverAware ? $this->db->driver() : 'unknown',
        ];
    }

    // ── Hydration ────────────────────────────────────────────────────────────

    /**
     * A row into a token, or null when the row cannot be trusted.
     *
     * FAIL CLOSED. A row whose `abilities` column is not a JSON list of strings
     * cannot be turned into a grant, and the two alternatives are both worse
     * than refusing it: defaulting to `['read']` would INVENT a permission
     * nobody granted, and defaulting to `['*']` is not worth discussing. So the
     * row is skipped, which makes the token unusable.
     *
     * The consequence is worth stating because it is a silence: such a token
     * verifies as `Unknown`, exactly as if it had never been minted, and it
     * does not appear in `restokens:list`. It DOES still appear in `counts()`,
     * which is computed in SQL and hydrates nothing — so a total that exceeds
     * the list is the one signal that this has happened. Reaching this state
     * requires the column to have been written by something other than this
     * plugin, since `save()` encodes a validated list.
     *
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?ResourceToken
    {
        try {
            $resource = ResourceRef::of(
                (string) ($row['resource_type'] ?? ''),
                (string) ($row['resource_id'] ?? ''),
            );

            $decoded = json_decode((string) ($row['abilities'] ?? ''), true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($decoded)) {
                return null;
            }

            $abilities = TokenAbilities::of(array_values(array_filter($decoded, 'is_string')));

            // THE TWO COLUMNS THAT CAN REFUSE A TOKEN are parsed in here, so
            // that an unreadable one skips the row. Outside this block they
            // would fall back to null, and null in `expires_at` means NEVER
            // EXPIRES — a date the parser cannot read would quietly promote a
            // dead token to a permanent one. There is no instant that means
            // "refuse", so the only fail-closed option is to drop the row.
            $expiresAt = self::moment($row['expires_at'] ?? null);
            $revokedAt = self::moment($row['revoked_at'] ?? null);
        } catch (\Throwable) {
            return null;
        }

        return new ResourceToken(
            id:          (string) ($row['id'] ?? ''),
            resource:    $resource,
            abilities:   $abilities,
            digest:      (string) ($row['token_digest'] ?? ''),
            expiresAt:   $expiresAt,
            // A stored 0 would mean "unlimited" if it were cast blindly, which
            // is the opposite of what a 0 in that column looks like it says.
            // The service refuses 0 at mint, so a 0 here came from elsewhere.
            maxUses:     isset($row['max_uses']) && $row['max_uses'] !== null && (int) $row['max_uses'] > 0
                ? (int) $row['max_uses']
                : null,
            uses:        (int) ($row['uses'] ?? 0),
            // Cosmetic columns, read leniently. None of them can refuse or
            // grant anything: `last_used_at` is reporting, and `exhausted_at`
            // is a prune hint whose authority is `uses >= max_uses` anyway —
            // which is computed from two integers, not from this date.
            lastUsedAt:  self::momentOrNull($row['last_used_at'] ?? null),
            exhaustedAt: self::momentOrNull($row['exhausted_at'] ?? null),
            revokedAt:   $revokedAt,
            // `created_at` has a SQL default, so a hand-inserted row can carry
            // a value this code did not write. Falling back to the epoch rather
            // than to `now` keeps a row with an unreadable date looking OLD,
            // which is what a list sorted by age should show, instead of
            // jumping to the top on every read.
            createdAt:   self::momentOrNull($row['created_at'] ?? null) ?? new \DateTimeImmutable('@0'),
            label:       (string) ($row['label'] ?? ''),
            createdBy:   (string) ($row['created_by'] ?? ''),
        );
    }

    private static function stamp(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->format('Y-m-d H:i:s');
    }

    /**
     * A stored timestamp, or null when the column is NULL or empty.
     *
     * THROWS on a non-empty value it cannot parse, and that is the whole point
     * of it existing separately from {@see momentOrNull()}: for a column whose
     * absence is permissive, "unreadable" must not be allowed to collapse into
     * "absent".
     *
     * @throws \Exception
     */
    private static function moment(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException('A timestamp column held a non-string value.');
        }

        return new \DateTimeImmutable($value);
    }

    /** The lenient form, for columns that cannot refuse or grant anything. */
    private static function momentOrNull(mixed $value): ?\DateTimeImmutable
    {
        try {
            return self::moment($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
