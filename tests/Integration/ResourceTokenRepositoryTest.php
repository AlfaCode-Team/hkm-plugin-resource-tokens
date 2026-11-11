<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Integration;

use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\Ground\Fakes\MigratedSqlite;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;
use Plugins\ResourceTokens\Application\Services\ResourceTokenService;
use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\TokenAbilities;
use Plugins\ResourceTokens\Domain\TokenDigest;
use Plugins\ResourceTokens\Domain\TokenRefusal;
use Plugins\ResourceTokens\Infrastructure\Config\ResourceTokenConfig;
use Plugins\ResourceTokens\Infrastructure\Persistence\ResourceTokenRepository;
use Tests\Plugins\ResourceTokens\Support\TokenBuilder;

/**
 * The real SQL, against a database built by this plugin's own migration.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT ONLY THIS SUITE CAN SHOW
 * ────────────────────────────────────────────────────────────────────────────
 *
 * The unit suite drives policy against an array, where `consume()` is
 * sequential PHP and therefore satisfies the port's atomicity requirement by
 * virtue of there being no concurrency at all. Here there are two real
 * statements to interleave, so the guarantee the whole plugin rests on — that
 * the decision and the recording are ONE statement whose row count is the
 * authority — is demonstrable rather than asserted.
 *
 * It also catches what no in-memory double can: a column that does not exist,
 * a `NOT NULL` with no default, a unique index that is missing so a duplicate
 * silently inserts, and a conditional UPDATE whose affected-row count the code
 * forgot to check.
 */
#[CoversClass(ResourceTokenRepository::class)]
#[CoversClass(ResourceTokenService::class)]
final class ResourceTokenRepositoryTest extends TestCase
{
    private const NOW = '2026-06-01 12:00:00';

    private ?MigratedSqlite $db = null;

    private ResourceTokenRepository $repository;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->db = MigratedSqlite::migrated(
            \dirname(__DIR__, 2) . '/database/migrations',
            'restokens',
        );

        $this->repository = new ResourceTokenRepository($this->db);
        $this->clock      = new FrozenClock(self::NOW);
    }

    protected function tearDown(): void
    {
        // Guarded: tearDown runs even when setUp skipped or threw, and an
        // unguarded ->discard() there would replace the real failure with a
        // null-property error.
        $this->db?->discard();
        $this->db = null;
    }

    private function service(?ResourceTokenConfig $config = null): ResourceTokenService
    {
        return new ResourceTokenService(
            $this->repository,
            $config ?? ResourceTokenConfig::of(),
            $this->clock,
        );
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    private function file(string $id = 'file-1'): ResourceRef
    {
        return ResourceRef::of('file', $id);
    }

    /** @return array<string, mixed>|null */
    private function row(string $id): ?array
    {
        $statement = $this->db?->pdo()->prepare('SELECT * FROM resource_tokens WHERE id = :id');
        $statement?->execute(['id' => $id]);
        $row = $statement?->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    // ── The schema and the code agree ────────────────────────────────────────

    public function test_the_migration_produces_the_table_the_repository_queries(): void
    {
        // The cheapest and most valuable assertion in the file: a SELECT *
        // against a table the migration did not create, or a column the
        // repository invented, fails here rather than in production.
        self::assertNull($this->repository->findByDigest(TokenDigest::of('nothing')));
        self::assertSame(
            ['active' => 0, 'expired' => 0, 'spent' => 0, 'revoked' => 0],
            $this->repository->counts($this->now()),
        );
    }

    public function test_every_field_survives_a_round_trip(): void
    {
        $token = TokenBuilder::make(
            id:          'tok-round-trip',
            resource:    ResourceRef::of('invoice', 'INV-2026-001'),
            abilities:   TokenAbilities::of(['download', 'read']),
            digest:      TokenDigest::of('a-secret'),
            expiresAt:   new \DateTimeImmutable('2026-06-02 09:30:00'),
            maxUses:     5,
            uses:        2,
            lastUsedAt:  new \DateTimeImmutable('2026-06-01 11:00:00'),
            createdAt:   new \DateTimeImmutable('2026-05-30 08:00:00'),
            label:       'emailed to the claimant',
            createdBy:   'user-77',
        );

        $this->repository->save($token);

        $read = $this->repository->findByDigest(TokenDigest::of('a-secret'));

        self::assertNotNull($read);
        self::assertSame('tok-round-trip', $read->id);
        self::assertTrue($read->resource->equals(ResourceRef::of('invoice', 'INV-2026-001')));
        self::assertSame(['download', 'read'], $read->abilities->toArray());
        self::assertEquals($token->expiresAt, $read->expiresAt);
        self::assertSame(5, $read->maxUses);
        self::assertSame(2, $read->uses);
        self::assertEquals($token->lastUsedAt, $read->lastUsedAt);
        self::assertEquals($token->createdAt, $read->createdAt);
        self::assertSame('emailed to the claimant', $read->label);
        self::assertSame('user-77', $read->createdBy);
        self::assertNull($read->revokedAt);
        self::assertNull($read->exhaustedAt);
    }

    public function test_the_digest_column_is_unique(): void
    {
        // The index that makes a digest collision impossible to INSERT rather
        // than something that would hand one token's secret another token's
        // row. Without it, `save()` would succeed and `findByDigest()` would
        // return whichever row the storage felt like.
        $digest = TokenDigest::of('shared');

        $this->repository->save(TokenBuilder::make(id: 'first', digest: $digest));

        $this->expectException(RepositoryException::class);

        $this->repository->save(TokenBuilder::make(id: 'second', digest: $digest));
    }

    public function test_a_permanent_token_stores_a_null_expiry(): void
    {
        $this->repository->save(TokenBuilder::make(id: 'forever', expiresAt: null));

        $row = $this->row('forever');

        self::assertNotNull($row);
        // Asserted on the raw column, not through hydration: `?? 'not-null'`
        // here would have swallowed the very null being tested for, and the
        // column being an empty STRING instead of NULL would then read as
        // "never expires" to the hydrator while `expires_at IS NULL` in the
        // consume predicate disagreed.
        self::assertArrayHasKey('expires_at', $row);
        self::assertNull($row['expires_at']);
        self::assertNull($this->repository->get('forever')?->expiresAt);
    }

    // ── THE race ─────────────────────────────────────────────────────────────

    public function test_a_single_use_token_can_only_be_consumed_once(): void
    {
        $this->repository->save(TokenBuilder::make(id: 'once', maxUses: 1));

        self::assertTrue($this->repository->consume('once', $this->now()));
        self::assertFalse(
            $this->repository->consume('once', $this->now()),
            'the second consume finds uses < max_uses false and affects no row',
        );

        self::assertSame(1, $this->repository->get('once')?->uses, 'and did not increment');
    }

    public function test_the_consume_loses_when_another_request_took_the_last_use_first(): void
    {
        // THE interleaving, made explicit. A request reads the token and finds
        // it usable — one use left. Before its write lands, somebody else's
        // write takes that use. The conditional UPDATE then matches no row.
        //
        // Deciding in PHP and writing afterwards would have both requests pass,
        // and a single-use download link would serve the file twice. That
        // window is one line of `curl` wide.
        $this->repository->save(TokenBuilder::make(id: 'contested', maxUses: 1));

        $asRead = $this->repository->get('contested');
        self::assertNotNull($asRead);
        self::assertTrue($asRead->isUsableAt($this->now()), 'the pre-check passes');

        // The other request, in between.
        $this->db?->pdo()->exec("UPDATE resource_tokens SET uses = 1 WHERE id = 'contested'");

        self::assertFalse(
            $this->repository->consume('contested', $this->now()),
            'the write is the gate, not the read',
        );
    }

    public function test_the_consume_loses_when_the_token_was_revoked_in_between(): void
    {
        $this->repository->save(TokenBuilder::make(id: 'pulled'));

        $this->db?->pdo()->exec(
            "UPDATE resource_tokens SET revoked_at = '2026-06-01 11:59:00' WHERE id = 'pulled'",
        );

        self::assertFalse($this->repository->consume('pulled', $this->now()));
    }

    public function test_the_consume_refuses_an_expired_row(): void
    {
        $this->repository->save(TokenBuilder::make(
            id:        'stale',
            expiresAt: new \DateTimeImmutable('2026-06-01 11:00:00'),
        ));

        self::assertFalse($this->repository->consume('stale', $this->now()));
        self::assertSame(0, $this->repository->get('stale')?->uses);
    }

    public function test_the_consume_refuses_at_the_exact_instant_of_expiry(): void
    {
        // The SQL predicate is `expires_at > :now`, which must agree with the
        // domain's `expiresAt <= $now` meaning "expired". If one used `>=` and
        // the other `<`, a token would be refused by the pre-check and accepted
        // by the write, or the reverse — and the reverse is a token that works
        // one second past its stated life.
        $this->repository->save(TokenBuilder::make(
            id:        'boundary',
            expiresAt: new \DateTimeImmutable(self::NOW),
        ));

        self::assertFalse($this->repository->consume('boundary', $this->now()));
        self::assertTrue(
            $this->repository->get('boundary')?->isExpired($this->now()) ?? false,
            'and the domain agrees it is expired',
        );
    }

    public function test_the_consume_refuses_an_unknown_id(): void
    {
        self::assertFalse($this->repository->consume('no-such-token', $this->now()));
    }

    public function test_the_increment_is_computed_by_the_database(): void
    {
        // `uses = uses + 1`, never read into PHP and written back — so two
        // concurrent uses cannot lose an increment.
        $this->repository->save(TokenBuilder::make(id: 'counted', maxUses: 10));

        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($this->repository->consume('counted', $this->now()));
        }

        self::assertSame(4, $this->repository->get('counted')?->uses);
        self::assertEquals($this->now(), $this->repository->get('counted')?->lastUsedAt);
    }

    // ── exhausted_at ─────────────────────────────────────────────────────────

    public function test_the_use_that_spends_a_token_stamps_exhausted_at(): void
    {
        $this->repository->save(TokenBuilder::make(id: 'spending', maxUses: 2));

        $this->repository->consume('spending', $this->now());
        self::assertNull($this->repository->get('spending')?->exhaustedAt, 'one use left');

        $this->clock->travelSeconds(30);
        $this->repository->consume('spending', $this->clock->now());

        self::assertEquals(
            $this->clock->now(),
            $this->repository->get('spending')?->exhaustedAt,
            'the use that reached the limit stamped it',
        );
        self::assertSame('spent', $this->repository->get('spending')?->state($this->clock->now()));
    }

    public function test_an_unlimited_token_is_never_stamped_exhausted(): void
    {
        $this->repository->save(TokenBuilder::make(id: 'endless', maxUses: null));

        for ($i = 0; $i < 3; $i++) {
            $this->repository->consume('endless', $this->now());
        }

        self::assertNull($this->repository->get('endless')?->exhaustedAt);
    }

    public function test_a_refused_consume_does_not_restamp_the_exhausted_timestamp(): void
    {
        // Named for what it actually shows, which is NARROWER than it looks.
        //
        // What it shows: a refused consume returns before the second statement
        // runs, so the timestamp cannot move. That comes from the
        // affected-row-count check, not from the second statement's own guard.
        //
        // What it does NOT show, and a revert check is how that was
        // established: removing `AND exhausted_at IS NULL` from
        // `markExhaustedIfSpent()` passes this entire suite. That guard is
        // unreachable sequentially — the second statement only runs after a
        // SUCCESSFUL consume, which requires `uses < max_uses`, and it then
        // only writes when `uses >= max_uses`, so a row cannot arrive there
        // already stamped. Its one real scenario needs two processes
        // interleaving BETWEEN the two statements of one consume() call, where
        // it keeps the first exhaustion timestamp instead of a later one, and
        // this suite cannot create that. It is kept because it makes the
        // statement idempotent on its face, and it is recorded in the README as
        // unpinned rather than counted as covered.
        $this->repository->save(TokenBuilder::make(id: 'spent-twice', maxUses: 1));

        $this->repository->consume('spent-twice', $this->now());
        $first = $this->repository->get('spent-twice')?->exhaustedAt;

        self::assertNotNull($first);

        $this->clock->travel('+1 hour');
        self::assertFalse($this->repository->consume('spent-twice', $this->clock->now()));

        self::assertEquals($first, $this->repository->get('spent-twice')?->exhaustedAt);
    }

    // ── Revoking ─────────────────────────────────────────────────────────────

    public function test_revoking_is_idempotent_and_keeps_the_first_decision(): void
    {
        $this->repository->save(TokenBuilder::make(id: 'withdrawn'));

        self::assertTrue($this->repository->revoke('withdrawn', $this->now()));
        $first = $this->repository->get('withdrawn')?->revokedAt;

        $this->clock->travel('+1 day');

        self::assertFalse(
            $this->repository->revoke('withdrawn', $this->clock->now()),
            'already revoked',
        );
        self::assertEquals(
            $first,
            $this->repository->get('withdrawn')?->revokedAt,
            'the moment somebody actually decided is the one fact an audit is for',
        );
    }

    public function test_revoking_an_unknown_id_is_false(): void
    {
        self::assertFalse($this->repository->revoke('nope', $this->now()));
    }

    public function test_revoking_a_resource_touches_only_that_resource(): void
    {
        $this->repository->save(TokenBuilder::make(id: 'a', resource: $this->file('f-1')));
        $this->repository->save(TokenBuilder::make(id: 'b', resource: $this->file('f-1')));
        $this->repository->save(TokenBuilder::make(id: 'c', resource: $this->file('f-2')));
        $this->repository->save(TokenBuilder::make(
            id:        'd',
            resource:  $this->file('f-1'),
            revokedAt: new \DateTimeImmutable('2026-05-01 00:00:00'),
        ));

        self::assertSame(
            2,
            $this->repository->revokeForResource($this->file('f-1'), $this->now()),
            'the already-revoked row is not counted again',
        );

        self::assertNotNull($this->repository->get('a')?->revokedAt);
        self::assertNotNull($this->repository->get('b')?->revokedAt);
        self::assertNull($this->repository->get('c')?->revokedAt, 'a different resource');
        self::assertEquals(
            new \DateTimeImmutable('2026-05-01 00:00:00'),
            $this->repository->get('d')?->revokedAt,
            'and the earlier decision stands',
        );
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    public function test_tokens_for_a_resource_come_back_newest_first(): void
    {
        foreach (['old' => '2026-01-01', 'middle' => '2026-03-01', 'new' => '2026-05-01'] as $id => $day) {
            $this->repository->save(TokenBuilder::make(
                id:        $id,
                digest:    TokenDigest::of($id),
                createdAt: new \DateTimeImmutable($day . ' 00:00:00'),
            ));
        }

        self::assertSame(
            ['new', 'middle', 'old'],
            array_map(
                static fn ($t): string => $t->id,
                $this->repository->forResource($this->file()),
            ),
        );
    }

    public function test_tokens_minted_in_the_same_second_have_a_stable_order(): void
    {
        // `ORDER BY created_at DESC, id DESC`. Without the tiebreaker, two
        // tokens created in the same second come back in whatever order the
        // storage felt like, and a list page reorders itself between two reads
        // of the same data.
        $at = new \DateTimeImmutable('2026-04-01 00:00:00');

        foreach (['t-a', 't-b', 't-c'] as $id) {
            $this->repository->save(TokenBuilder::make(
                id: $id, digest: TokenDigest::of($id), createdAt: $at,
            ));
        }

        $first = array_map(static fn ($t): string => $t->id, $this->repository->forResource($this->file()));

        self::assertSame(['t-c', 't-b', 't-a'], $first);
        self::assertSame(
            $first,
            array_map(static fn ($t): string => $t->id, $this->repository->forResource($this->file())),
            'and the same order on a second read',
        );
    }

    public function test_counting_live_tokens_excludes_every_settled_state(): void
    {
        $resource = $this->file('capped');

        $this->repository->save(TokenBuilder::make(id: 'live', resource: $resource, digest: TokenDigest::of('1')));
        $this->repository->save(TokenBuilder::make(
            id: 'gone', resource: $resource, digest: TokenDigest::of('2'),
            revokedAt: new \DateTimeImmutable('2026-05-01 00:00:00'),
        ));
        $this->repository->save(TokenBuilder::make(
            id: 'stale', resource: $resource, digest: TokenDigest::of('3'),
            expiresAt: new \DateTimeImmutable('2026-05-01 00:00:00'),
        ));
        $this->repository->save(TokenBuilder::make(
            id: 'used-up', resource: $resource, digest: TokenDigest::of('4'),
            maxUses: 1, uses: 1,
        ));

        self::assertSame(1, $this->repository->countActiveFor($resource, $this->now()));
    }

    public function test_counts_partition_the_table(): void
    {
        // Mutually exclusive CASE arms in one statement, in the same precedence
        // the domain's state() uses. Four independent COUNT(*) queries would
        // double-count a token that is both expired and spent, so the totals
        // would not add up to the table.
        $this->repository->save(TokenBuilder::make(id: '1', digest: TokenDigest::of('1')));
        $this->repository->save(TokenBuilder::make(
            id: '2', digest: TokenDigest::of('2'),
            revokedAt: new \DateTimeImmutable('2026-05-01 00:00:00'),
        ));
        $this->repository->save(TokenBuilder::make(
            id: '3', digest: TokenDigest::of('3'),
            expiresAt: new \DateTimeImmutable('2026-05-01 00:00:00'),
        ));
        $this->repository->save(TokenBuilder::make(
            id: '4', digest: TokenDigest::of('4'), maxUses: 2, uses: 2,
        ));
        // Both expired AND spent: counted once, as expired.
        $this->repository->save(TokenBuilder::make(
            id: '5', digest: TokenDigest::of('5'), maxUses: 1, uses: 1,
            expiresAt: new \DateTimeImmutable('2026-05-01 00:00:00'),
        ));

        $counts = $this->repository->counts($this->now());

        self::assertSame(1, $counts['active']);
        self::assertSame(1, $counts['revoked']);
        self::assertSame(2, $counts['expired']);
        self::assertSame(1, $counts['spent']);
        self::assertSame(5, array_sum($counts), 'every row counted exactly once');
    }

    // ── Pruning ──────────────────────────────────────────────────────────────

    public function test_pruning_deletes_each_settled_kind_and_keeps_the_live_ones(): void
    {
        $old = new \DateTimeImmutable('2026-01-01 00:00:00');

        $this->repository->save(TokenBuilder::make(id: 'r', digest: TokenDigest::of('r'), revokedAt: $old));
        $this->repository->save(TokenBuilder::make(id: 'e', digest: TokenDigest::of('e'), expiresAt: $old));
        $this->repository->save(TokenBuilder::make(
            id: 'x', digest: TokenDigest::of('x'), maxUses: 1, uses: 1, exhaustedAt: $old,
        ));

        // Kept: live, and permanent.
        $this->repository->save(TokenBuilder::make(
            id: 'live', digest: TokenDigest::of('live'),
            expiresAt: new \DateTimeImmutable('2026-12-01 00:00:00'),
        ));
        $this->repository->save(TokenBuilder::make(
            id: 'forever', digest: TokenDigest::of('forever'), expiresAt: null, createdAt: $old,
        ));

        self::assertSame(3, $this->repository->prune(new \DateTimeImmutable('2026-05-01 00:00:00')));

        self::assertNull($this->repository->get('r'));
        self::assertNull($this->repository->get('e'));
        self::assertNull($this->repository->get('x'));
        self::assertNotNull($this->repository->get('live'));
        self::assertNotNull(
            $this->repository->get('forever'),
            'a deliberately permanent token is not stale, however old the row is',
        );
    }

    public function test_pruning_respects_the_cutoff(): void
    {
        $this->repository->save(TokenBuilder::make(
            id: 'recent', revokedAt: new \DateTimeImmutable('2026-05-30 00:00:00'),
        ));

        self::assertSame(0, $this->repository->prune(new \DateTimeImmutable('2026-05-01 00:00:00')));
        self::assertNotNull($this->repository->get('recent'));
    }

    public function test_pruning_an_empty_table_is_a_no_op(): void
    {
        self::assertSame(0, $this->repository->prune($this->now()));
    }

    public function test_pruning_batches_past_the_batch_size(): void
    {
        // 1200 rows exercises the loop: a first full batch of 1000, then a
        // short one that ends it. Without the batching, an unbounded DELETE on
        // a table that has not been pruned for a year is one enormous
        // transaction holding locks while it runs — and `DELETE ... LIMIT` is
        // MySQL-only, which is why this is select-then-delete-by-id.
        $old = '2026-01-01 00:00:00';
        $pdo = $this->db?->pdo();
        $pdo?->beginTransaction();

        $insert = $pdo?->prepare(
            'INSERT INTO resource_tokens
                (id, resource_type, resource_id, token_digest, abilities, revoked_at, created_at)
             VALUES (:id, \'file\', \'f\', :digest, \'["read"]\', :old, :old2)',
        );

        for ($i = 0; $i < 1200; $i++) {
            $insert?->execute([
                'id'     => 'bulk-' . $i,
                'digest' => hash('sha256', 'bulk-' . $i),
                'old'    => $old,
                'old2'   => $old,
            ]);
        }

        $pdo?->commit();

        self::assertSame(1200, $this->repository->prune(new \DateTimeImmutable('2026-05-01 00:00:00')));
        self::assertSame(0, array_sum($this->repository->counts($this->now())));
    }

    // ── Hydration fails CLOSED ───────────────────────────────────────────────

    public function test_a_row_with_an_unreadable_abilities_column_is_not_usable(): void
    {
        // FAIL CLOSED. The alternatives are worse: defaulting to ['read']
        // invents a permission nobody granted, and defaulting to ['*'] is not
        // worth discussing. Reaching this state needs the column to have been
        // written by something other than save(), which encodes a validated
        // list.
        $this->repository->save(TokenBuilder::make(id: 'corrupt', digest: TokenDigest::of('c')));

        $this->db?->pdo()->exec("UPDATE resource_tokens SET abilities = 'not json' WHERE id = 'corrupt'");

        self::assertNull($this->repository->findByDigest(TokenDigest::of('c')));
        self::assertNull($this->repository->get('corrupt'));
        self::assertSame([], $this->repository->forResource($this->file()));
    }

    public function test_a_skipped_row_still_shows_in_the_counts(): void
    {
        // The one signal that it has happened: counts() is computed in SQL and
        // hydrates nothing, so a total that exceeds the list is the discrepancy
        // to notice. Said out loud because it is otherwise a silence.
        $this->repository->save(TokenBuilder::make(id: 'corrupt', digest: TokenDigest::of('c')));
        $this->db?->pdo()->exec("UPDATE resource_tokens SET abilities = '{}' WHERE id = 'corrupt'");

        self::assertSame(1, array_sum($this->repository->counts($this->now())));
        self::assertSame([], $this->repository->forResource($this->file()));
    }

    public function test_an_unreadable_expiry_makes_the_row_unusable_rather_than_permanent(): void
    {
        // The fail-OPEN that had to be designed out. A null `expires_at` means
        // NEVER EXPIRES, so a date the parser cannot read must not be allowed
        // to collapse into "absent" — that would quietly promote a dead token
        // to a permanent one. There is no instant that means "refuse", so the
        // only fail-closed option is to drop the row.
        $this->repository->save(TokenBuilder::make(
            id: 'bad-date', digest: TokenDigest::of('d'),
            expiresAt: new \DateTimeImmutable('2026-06-02 00:00:00'),
        ));

        $this->db?->pdo()->exec(
            "UPDATE resource_tokens SET expires_at = 'not a date at all' WHERE id = 'bad-date'",
        );

        self::assertNull($this->repository->get('bad-date'));
        self::assertNull($this->repository->findByDigest(TokenDigest::of('d')));
    }

    public function test_an_unreadable_last_used_at_does_not_hide_the_row(): void
    {
        // The lenient half of the same decision: `last_used_at` is reporting
        // and cannot refuse or grant anything, so an unreadable value there
        // must not take a live token out of circulation.
        $this->repository->save(TokenBuilder::make(id: 'odd-date', digest: TokenDigest::of('o')));

        $this->db?->pdo()->exec(
            "UPDATE resource_tokens SET last_used_at = 'gibberish' WHERE id = 'odd-date'",
        );

        $token = $this->repository->get('odd-date');

        self::assertNotNull($token);
        self::assertNull($token->lastUsedAt);
        self::assertTrue($token->isUsableAt($this->now()));
    }

    public function test_describe_names_the_table_and_the_dialect_and_no_credential(): void
    {
        $described = $this->repository->describe();

        self::assertSame('resource_tokens', $described['table']);
        // Through the OPTIONAL DriverAware interface — DatabasePort has no
        // driver(), so this is an instanceof check and not a method call.
        self::assertSame('sqlite', $described['driver']);
        self::assertSame(['table', 'driver'], array_keys($described));
    }

    // ── End to end, through the service ──────────────────────────────────────

    public function test_a_minted_token_verifies_against_the_real_database(): void
    {
        $service = $this->service();
        $minted  = $service->mint(
            $this->file('f-9'),
            ttlSeconds: 3600,
            abilities:  TokenAbilities::of(['download']),
            maxUses:    2,
            label:      'end to end',
        );

        self::assertTrue($service->verify($minted->secret(), $this->file('f-9'), 'download')->granted);
        self::assertTrue($service->verify($minted->secret(), $this->file('f-9'), 'download')->granted);

        $third = $service->verify($minted->secret(), $this->file('f-9'), 'download');
        self::assertFalse($third->granted);
        self::assertSame(TokenRefusal::Exhausted, $third->refusal);
    }

    public function test_a_minted_token_does_not_open_a_sibling_resource_through_the_real_database(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file('mine'));

        self::assertSame(
            TokenRefusal::WrongResource,
            $service->verify($minted->secret(), $this->file('yours'))->refusal,
        );
        self::assertSame(0, $this->repository->get($minted->id())?->uses, 'and spent nothing');
    }

    public function test_the_per_resource_cap_counts_rows_in_the_database(): void
    {
        $service = $this->service(ResourceTokenConfig::of(maxPerResource: 2));

        $service->mint($this->file('capped'));
        $service->mint($this->file('capped'));

        $this->expectExceptionMessageMatches('/already has 2 live tokens/');

        $service->mint($this->file('capped'));
    }

    public function test_a_permanent_token_round_trips_through_the_service(): void
    {
        $service = $this->service();
        $minted  = $service->mint(
            $this->file('avatar'),
            ttlSeconds: ResourceTokenContract::NEVER_EXPIRES,
        );

        $this->clock->travel('+10 years');

        self::assertTrue($service->verify($minted->secret(), $this->file('avatar'))->granted);
    }

    public function test_a_tracked_unlimited_token_writes_and_an_untracked_one_does_not(): void
    {
        $tracked = $this->service(ResourceTokenConfig::of(trackUses: true));
        $a       = $tracked->mint($this->file('hot-a'));
        $tracked->verify($a->secret(), $this->file('hot-a'));
        self::assertSame(1, $this->repository->get($a->id())?->uses);

        $untracked = $this->service(ResourceTokenConfig::of(trackUses: false));
        $b         = $untracked->mint($this->file('hot-b'));
        $untracked->verify($b->secret(), $this->file('hot-b'));
        self::assertSame(
            0,
            $this->repository->get($b->id())?->uses,
            'no write on the hot path for an unlimited token',
        );
    }
}
