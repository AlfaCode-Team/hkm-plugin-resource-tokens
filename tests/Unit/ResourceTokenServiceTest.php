<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Unit;

use AlfacodeTeam\Ground\Fakes\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;
use Plugins\ResourceTokens\Application\Services\ResourceTokenService;
use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\TokenAbilities;
use Plugins\ResourceTokens\Domain\TokenDigest;
use Plugins\ResourceTokens\Domain\TokenRefusal;
use Plugins\ResourceTokens\Domain\TokenVerdict;
use Plugins\ResourceTokens\Infrastructure\Config\ResourceTokenConfig;
use Plugins\ResourceTokens\Infrastructure\Exceptions\ResourceTokenException;
use Tests\Plugins\ResourceTokens\Support\InMemoryTokenStore;

/**
 * Minting and verification policy, against an in-memory store and a frozen
 * clock.
 *
 * What this suite CANNOT show is the use-limit race: `consume()` here is
 * sequential PHP and satisfies the port's atomicity requirement by virtue of
 * there being no concurrency at all. That belongs in the integration suite,
 * where there are two real statements to interleave.
 */
#[CoversClass(ResourceTokenService::class)]
#[CoversClass(TokenVerdict::class)]
final class ResourceTokenServiceTest extends TestCase
{
    private InMemoryTokenStore $store;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->store = new InMemoryTokenStore();
        $this->clock = new FrozenClock('2026-06-01 12:00:00');
    }

    private function service(?ResourceTokenConfig $config = null): ResourceTokenService
    {
        return new ResourceTokenService(
            $this->store,
            $config ?? ResourceTokenConfig::of(),
            $this->clock,
        );
    }

    private function file(string $id = 'file-1'): ResourceRef
    {
        return ResourceRef::of('file', $id);
    }

    // ── Minting ──────────────────────────────────────────────────────────────

    public function test_minting_stores_a_row_and_returns_the_secret_once(): void
    {
        $minted = $this->service()->mint($this->file());

        self::assertSame(64, \strlen($minted->secret()));
        self::assertSame(1, $this->store->count());

        $stored = $this->store->get($minted->id());
        self::assertNotNull($stored);

        // The secret is never stored in any form — only its digest. Nothing,
        // including this plugin, can get it back.
        self::assertSame(TokenDigest::of($minted->secret()), $stored->digest);
    }

    public function test_two_mints_produce_different_secrets_and_ids(): void
    {
        $service = $this->service();

        $first  = $service->mint($this->file());
        $second = $service->mint($this->file());

        self::assertNotSame($first->secret(), $second->secret());
        self::assertNotSame($first->id(), $second->id());
    }

    public function test_the_id_is_a_uuid_v4(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $this->service()->mint($this->file())->id(),
        );
    }

    public function test_the_default_grant_is_read_only(): void
    {
        $minted = $this->service()->mint($this->file());

        self::assertSame(['read'], $minted->token->abilities->toArray());
    }

    public function test_the_default_lifetime_comes_from_the_config(): void
    {
        $minted = $this->service(ResourceTokenConfig::of(defaultTtl: 600))->mint($this->file());

        self::assertEquals(
            new \DateTimeImmutable('2026-06-01 12:10:00'),
            $minted->token->expiresAt,
        );
    }

    public function test_an_explicit_lifetime_wins(): void
    {
        $minted = $this->service(ResourceTokenConfig::of(defaultTtl: 600))
            ->mint($this->file(), ttlSeconds: 60);

        self::assertEquals(
            new \DateTimeImmutable('2026-06-01 12:01:00'),
            $minted->token->expiresAt,
        );
    }

    // ── The three-way lifetime argument ──────────────────────────────────────

    public function test_never_expires_is_a_named_constant_and_not_zero(): void
    {
        // THE decision worth pinning. If 0 meant "never", somebody writing
        // `ttlSeconds: 0` and meaning "expire immediately" would mint the most
        // dangerous token this API can make. So 0 is refused and permanence
        // has to be asked for by name.
        self::assertSame(-1, ResourceTokenContract::NEVER_EXPIRES);

        $minted = $this->service()->mint(
            $this->file(),
            ttlSeconds: ResourceTokenContract::NEVER_EXPIRES,
        );

        self::assertNull($minted->token->expiresAt);
    }

    public function test_a_zero_lifetime_is_refused_with_a_message_naming_the_constant(): void
    {
        try {
            $this->service()->mint($this->file(), ttlSeconds: 0);
            self::fail('a zero lifetime must be refused');
        } catch (ResourceTokenException $e) {
            self::assertStringContainsString('NEVER_EXPIRES', $e->getMessage());
            self::assertStringContainsString('expires immediately', $e->getMessage());
        }
    }

    public function test_a_negative_lifetime_other_than_the_constant_is_refused(): void
    {
        $this->expectException(ResourceTokenException::class);

        $this->service()->mint($this->file(), ttlSeconds: -600);
    }

    public function test_a_lifetime_past_the_ceiling_is_refused_rather_than_clamped(): void
    {
        // Refused, not clamped. A clamp would hand back a token that expires
        // sooner than the caller asked for, and the caller would tell its user
        // the wrong thing about how long the link lasts.
        try {
            $this->service(ResourceTokenConfig::of(maxTtl: 3600))
                ->mint($this->file(), ttlSeconds: 7200);
            self::fail('a lifetime past the ceiling must be refused');
        } catch (ResourceTokenException $e) {
            self::assertStringContainsString('ceiling', $e->getMessage());
            self::assertStringContainsString('RESOURCE_TOKENS_MAX_TTL', $e->getMessage());
        }
    }

    public function test_a_default_longer_than_the_ceiling_is_also_refused(): void
    {
        // The misconfiguration case. Silently clamping would leave an operator
        // believing the default they configured was in force.
        $this->expectException(ResourceTokenException::class);

        $this->service(ResourceTokenConfig::of(defaultTtl: 7200, maxTtl: 3600))
            ->mint($this->file());
    }

    public function test_the_ceiling_does_not_apply_to_a_permanent_token(): void
    {
        // Deliberate: the ceiling bounds a LIFETIME, and a permanent token has
        // none to bound. The switch for permanence is its own setting, because
        // "no token may last more than an hour" and "no token may be permanent"
        // are two different decisions and an operator may want either.
        $minted = $this->service(ResourceTokenConfig::of(maxTtl: 60))->mint(
            $this->file(),
            ttlSeconds: ResourceTokenContract::NEVER_EXPIRES,
        );

        self::assertNull($minted->token->expiresAt);
    }

    public function test_a_permanent_token_can_be_forbidden_outright(): void
    {
        try {
            $this->service(ResourceTokenConfig::of(allowPermanent: false))->mint(
                $this->file(),
                ttlSeconds: ResourceTokenContract::NEVER_EXPIRES,
            );
            self::fail('a permanent token must be refused when the deployment forbids it');
        } catch (ResourceTokenException $e) {
            self::assertStringContainsString('RESOURCE_TOKENS_ALLOW_PERMANENT', $e->getMessage());
        }
    }

    // ── Use limits and the per-resource cap ──────────────────────────────────

    public function test_a_use_limit_of_zero_is_refused(): void
    {
        // It would mint a token that cannot be used once, which looks exactly
        // like the plugin being broken.
        $this->expectException(ResourceTokenException::class);

        $this->service()->mint($this->file(), maxUses: 0);
    }

    public function test_the_per_resource_cap_refuses_a_further_mint(): void
    {
        $service = $this->service(ResourceTokenConfig::of(maxPerResource: 2));

        $service->mint($this->file());
        $service->mint($this->file());

        try {
            $service->mint($this->file());
            self::fail('past the cap, a mint must be refused');
        } catch (ResourceTokenException $e) {
            // The message says what this is actually for: it catches code that
            // mints a token per request where one should be reused.
            self::assertStringContainsString('minted per request', $e->getMessage());
        }
    }

    public function test_the_cap_counts_only_live_tokens(): void
    {
        // A revoked or expired token occupies no room. Otherwise a resource
        // that had been shared and un-shared a hundred times could never be
        // shared again until somebody ran a prune.
        $service = $this->service(ResourceTokenConfig::of(maxPerResource: 1));

        $first = $service->mint($this->file());
        $service->revoke($first->id());

        $second = $service->mint($this->file());

        self::assertNotSame($first->id(), $second->id());
    }

    public function test_the_cap_is_per_resource_and_not_global(): void
    {
        $service = $this->service(ResourceTokenConfig::of(maxPerResource: 1));

        $service->mint($this->file('a'));
        $service->mint($this->file('b'));

        self::assertSame(2, $this->store->count());
    }

    public function test_a_cap_of_zero_means_unlimited(): void
    {
        $service = $this->service(ResourceTokenConfig::of(maxPerResource: 0));

        for ($i = 0; $i < 5; $i++) {
            $service->mint($this->file());
        }

        self::assertSame(5, $this->store->count());
    }

    // ── Verification: the resource is the boundary ───────────────────────────

    public function test_a_valid_token_opens_its_own_resource(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file('f-1'));

        $verdict = $service->verify($minted->secret(), $this->file('f-1'));

        self::assertTrue($verdict->granted);
        self::assertNull($verdict->refusal);
        self::assertSame($minted->id(), $verdict->tokenId());
    }

    public function test_a_valid_token_does_not_open_a_different_resource(): void
    {
        // THE test this whole plugin exists for. The naive implementation —
        // look the token up, find it valid, serve whatever the URL asked for —
        // passes every other test in this file and fails this one, and the
        // consequence is that a token for the cheapest file in the system opens
        // every file in the system.
        $service = $this->service();
        $minted  = $service->mint($this->file('f-1'));

        $verdict = $service->verify($minted->secret(), $this->file('f-2'));

        self::assertFalse($verdict->granted);
        self::assertSame(TokenRefusal::WrongResource, $verdict->refusal);
    }

    public function test_a_valid_token_does_not_open_a_different_resource_type_with_the_same_id(): void
    {
        // The subtler half: two resource types in one application very often
        // share an id space — a file and an invoice both keyed by the same
        // UUID, or both numbered from 1.
        $service = $this->service();
        $minted  = $service->mint(ResourceRef::of('file', '7'));

        $verdict = $service->verify($minted->secret(), ResourceRef::of('invoice', '7'));

        self::assertFalse($verdict->granted);
        self::assertSame(TokenRefusal::WrongResource, $verdict->refusal);
    }

    public function test_the_wrong_resource_verdict_still_names_the_token(): void
    {
        // Refused, but a REAL token — so an operator investigating needs to
        // know which one it was and what it was actually minted for. This is
        // the refusal that means somebody is trying things.
        $service = $this->service();
        $minted  = $service->mint($this->file('f-1'));

        $verdict = $service->verify($minted->secret(), $this->file('f-2'));

        self::assertSame($minted->id(), $verdict->tokenId());
        self::assertSame('file:f-1', $verdict->resourceLabel());
        self::assertTrue($verdict->refusal?->suggestsProbing());
    }

    // ── Verification: abilities ──────────────────────────────────────────────

    public function test_a_read_token_cannot_write(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file(), abilities: TokenAbilities::readOnly());

        self::assertTrue($service->verify($minted->secret(), $this->file(), 'read')->granted);
        self::assertSame(
            TokenRefusal::MissingAbility,
            $service->verify($minted->secret(), $this->file(), 'write')->refusal,
        );
    }

    public function test_a_wildcard_token_passes_any_ability_on_its_own_resource(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file('f-1'), abilities: TokenAbilities::all());

        self::assertTrue($service->verify($minted->secret(), $this->file('f-1'), 'delete')->granted);

        // And still cannot reach another resource. `*` is bounded by the
        // resource; there is no ability value that widens it.
        self::assertSame(
            TokenRefusal::WrongResource,
            $service->verify($minted->secret(), $this->file('f-2'), 'read')->refusal,
        );
    }

    public function test_the_resource_is_checked_before_the_ability(): void
    {
        // Both refuse, so this is about what the log says: a token aimed at
        // the wrong resource is the alarming reading and must not be masked by
        // the narrower "it lacked that ability".
        $service = $this->service();
        $minted  = $service->mint($this->file('f-1'), abilities: TokenAbilities::readOnly());

        self::assertSame(
            TokenRefusal::WrongResource,
            $service->verify($minted->secret(), $this->file('f-2'), 'write')->refusal,
        );
    }

    // ── Verification: state ──────────────────────────────────────────────────

    public function test_an_expired_token_is_refused(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file(), ttlSeconds: 60);

        self::assertTrue($service->verify($minted->secret(), $this->file())->granted);

        $this->clock->travelSeconds(61);

        self::assertSame(
            TokenRefusal::Expired,
            $service->verify($minted->secret(), $this->file())->refusal,
        );
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file());

        self::assertTrue($service->revoke($minted->id()));

        self::assertSame(
            TokenRefusal::Revoked,
            $service->verify($minted->secret(), $this->file())->refusal,
        );
    }

    public function test_a_single_use_token_works_once(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file(), maxUses: 1);

        self::assertTrue($service->verify($minted->secret(), $this->file())->granted);
        self::assertSame(
            TokenRefusal::Exhausted,
            $service->verify($minted->secret(), $this->file())->refusal,
        );
    }

    public function test_a_limited_token_counts_down(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file(), maxUses: 3);

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($service->verify($minted->secret(), $this->file())->granted, "use {$i}");
        }

        self::assertFalse($service->verify($minted->secret(), $this->file())->granted);
        self::assertSame(3, $this->store->get($minted->id())?->uses);
    }

    public function test_a_refused_verification_does_not_spend_a_use(): void
    {
        // An expired or wrong-resource attempt must not consume the allowance
        // of a token that is otherwise fine — otherwise anybody who can make a
        // failing request can burn somebody else's single-use link.
        $service = $this->service();
        $minted  = $service->mint($this->file('f-1'), maxUses: 1);

        $service->verify($minted->secret(), $this->file('f-2'));          // wrong resource
        $service->verify($minted->secret(), $this->file('f-1'), 'write'); // wrong ability

        self::assertSame(0, $this->store->get($minted->id())?->uses);
        self::assertTrue($service->verify($minted->secret(), $this->file('f-1'))->granted);
    }

    // ── Verification: unknown and malformed ──────────────────────────────────

    public function test_an_unknown_token_is_refused_without_naming_a_row(): void
    {
        $verdict = $this->service()->verify(TokenDigest::mint(), $this->file());

        self::assertSame(TokenRefusal::Unknown, $verdict->refusal);
        self::assertNull($verdict->token);
        self::assertSame('', $verdict->tokenId(), 'not nullable — it goes into a log line');
    }

    public function test_a_malformed_value_is_refused_without_touching_the_store(): void
    {
        $service = $this->service();
        $service->mint($this->file());

        $before = $this->store->consumeCalls;

        foreach (['', 'nope', str_repeat('z', 64)] as $junk) {
            self::assertSame(
                TokenRefusal::Malformed,
                $service->verify($junk, $this->file())->refusal,
                $junk,
            );
        }

        self::assertSame($before, $this->store->consumeCalls, 'no write was attempted');
    }

    public function test_an_empty_token_takes_the_same_path_as_a_wrong_one(): void
    {
        // A missing token and a wrong token produce the same verdict shape, so
        // there is one fewer place for the two to diverge. The route filter
        // relies on this: it passes '' straight through rather than
        // short-circuiting.
        $verdict = $this->service()->verify('', $this->file());

        self::assertFalse($verdict->granted);
        self::assertNotNull($verdict->refusal);
    }

    // ── The race the conditional write exists to close ───────────────────────

    public function test_a_lost_consume_refuses_even_though_the_pre_checks_passed(): void
    {
        // The branch no sequential test would reach on its own. The service
        // checked and found the token usable; the conditional write then found
        // nothing to update, because somebody else got there first. The ROW
        // COUNT is the authority, so the answer is refused.
        $service = $this->service();
        $minted  = $service->mint($this->file(), maxUses: 1);

        $this->store->failConsume = true;

        $verdict = $service->verify($minted->secret(), $this->file());

        self::assertFalse($verdict->granted);
        self::assertSame(TokenRefusal::Exhausted, $verdict->refusal);
    }

    public function test_a_token_revoked_mid_request_is_refused_with_the_right_reason(): void
    {
        // The same window, with a different cause: the pre-checks passed, then
        // the token was revoked, and the write found a revoked row. The reason
        // is re-read afterwards so the log says "revoked" rather than guessing.
        $service = $this->service();
        $minted  = $service->mint($this->file());
        $clock   = $this->clock;

        $this->store->mutateBeforeConsume =
            static fn ($token) => $token->revoked($clock->now());

        $verdict = $service->verify($minted->secret(), $this->file());

        self::assertFalse($verdict->granted);
        self::assertSame(TokenRefusal::Revoked, $verdict->refusal);
    }

    // ── Tracking uses ────────────────────────────────────────────────────────

    public function test_an_unlimited_token_skips_the_write_when_tracking_is_off(): void
    {
        // The `<img src>` case. A token on a hot page would otherwise turn
        // every image request into an UPDATE, which is how a read path becomes
        // a replication bottleneck.
        $service = $this->service(ResourceTokenConfig::of(trackUses: false));
        $minted  = $service->mint($this->file(), maxUses: null);

        self::assertTrue($service->verify($minted->secret(), $this->file())->granted);
        self::assertSame(0, $this->store->consumeCalls);
    }

    public function test_a_limited_token_always_writes_even_when_tracking_is_off(): void
    {
        // Because the write IS the enforcement. If this setting could switch it
        // off, RESOURCE_TOKENS_TRACK_USES=false would silently turn every
        // single-use link into an unlimited one.
        $service = $this->service(ResourceTokenConfig::of(trackUses: false));
        $minted  = $service->mint($this->file(), maxUses: 1);

        self::assertTrue($service->verify($minted->secret(), $this->file())->granted);
        self::assertSame(1, $this->store->consumeCalls);
        self::assertFalse($service->verify($minted->secret(), $this->file())->granted);
    }

    public function test_an_unlimited_token_records_uses_when_tracking_is_on(): void
    {
        $service = $this->service(ResourceTokenConfig::of(trackUses: true));
        $minted  = $service->mint($this->file(), maxUses: null);

        $service->verify($minted->secret(), $this->file());
        $service->verify($minted->secret(), $this->file());

        self::assertSame(2, $this->store->get($minted->id())?->uses);
    }

    // ── Revoking and listing ─────────────────────────────────────────────────

    public function test_revoking_an_unknown_token_is_false_rather_than_an_error(): void
    {
        self::assertFalse($this->service()->revoke('no-such-token'));
    }

    public function test_revoking_twice_reports_false_the_second_time(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file());

        self::assertTrue($service->revoke($minted->id()));
        self::assertFalse($service->revoke($minted->id()), 'already revoked');
    }

    public function test_revoking_a_resource_withdraws_every_token_for_it(): void
    {
        // The operation with no equivalent for a stateless signed URL, and the
        // one actually reached for in an incident.
        $service = $this->service();

        $a = $service->mint($this->file('f-1'));
        $b = $service->mint($this->file('f-1'));
        $c = $service->mint($this->file('f-2'));

        self::assertSame(2, $service->revokeFor($this->file('f-1')));

        self::assertFalse($service->verify($a->secret(), $this->file('f-1'))->granted);
        self::assertFalse($service->verify($b->secret(), $this->file('f-1'))->granted);
        self::assertTrue($service->verify($c->secret(), $this->file('f-2'))->granted, 'untouched');
    }

    public function test_listing_a_resource_returns_no_secrets(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file('f-1'), label: 'emailed to the claimant');

        $tokens = $service->tokensFor($this->file('f-1'));

        self::assertCount(1, $tokens);
        self::assertSame('emailed to the claimant', $tokens[0]->label);

        $flat = json_encode(
            array_map(static fn ($t) => $t->describe(new \DateTimeImmutable()), $tokens),
            \JSON_THROW_ON_ERROR,
        );

        self::assertStringNotContainsString($minted->secret(), $flat);
    }

    public function test_counts_report_each_state_once(): void
    {
        $service = $this->service();

        $service->mint($this->file('a'));                                   // active
        $service->revoke($service->mint($this->file('b'))->id());           // revoked
        $spent = $service->mint($this->file('c'), maxUses: 1);
        $service->verify($spent->secret(), $this->file('c'));               // spent
        $service->mint($this->file('d'), ttlSeconds: 1);

        $this->clock->travelSeconds(2);                                      // expired

        $counts = $service->counts();

        self::assertSame(1, $counts['active']);
        self::assertSame(1, $counts['revoked']);
        self::assertSame(1, $counts['spent']);
        self::assertSame(1, $counts['expired']);
        self::assertSame(4, array_sum($counts), 'the states partition the table');
    }

    // ── Housekeeping and reporting ───────────────────────────────────────────

    public function test_pruning_removes_settled_rows_past_the_window_and_keeps_live_ones(): void
    {
        $service = $this->service(ResourceTokenConfig::of(pruneAfterDays: 1));

        $live = $service->mint($this->file('live'), ttlSeconds: ResourceTokenContract::NEVER_EXPIRES);
        $service->revoke($service->mint($this->file('gone'))->id());

        $this->clock->travel('+2 days');

        self::assertSame(1, $service->prune());
        self::assertNotNull($this->store->get($live->id()), 'a permanent token is not stale');
    }

    public function test_describe_carries_the_tuning_and_no_secret(): void
    {
        $service = $this->service();
        $minted  = $service->mint($this->file());

        $described = $service->describe();

        self::assertStringContainsString('table=', $described);
        self::assertStringContainsString('default_ttl=', $described);
        self::assertStringNotContainsString($minted->secret(), $described);
    }

    // ── The secret stays out of stack traces ─────────────────────────────────

    public function test_a_presented_token_does_not_reach_a_stack_trace(): void
    {
        // BEHAVIOUR, and the one place in this plugin where
        // #[\SensitiveParameter] has an observable effect today: the store can
        // throw, and PHP puts call arguments in an exception's trace with
        // `zend.exception_ignore_args` at 0, which is PHP's own built-in
        // default — it is 1 only in php.ini-production, which plenty of
        // deployments never copy. Without the attribute, a database blip
        // during verification puts a live token in whatever the error reporter
        // mails and writes to a file.
        $secret = TokenDigest::mint();

        $trace = $this->traceFromVerify($secret);

        self::assertStringNotContainsString($secret, $trace);
        self::assertStringContainsString('SensitiveParameterValue', $trace, 'it was redacted HERE');
    }

    public function test_the_control_case_proves_the_ini_setting_is_not_doing_the_work(): void
    {
        // Without this, the test above would pass on a machine with
        // `zend.exception_ignore_args=1` even if the attribute had been
        // deleted — it would be asserting the ini setting rather than the code.
        // verify()'s SECOND argument carries no attribute, so if arguments
        // reach traces at all on this machine, that one appears.
        $trace = $this->traceFromVerify(TokenDigest::mint());

        if (!str_contains($trace, 'Object(')) {
            self::markTestSkipped(
                'Arguments do not reach a trace in this configuration '
                . '(zend.exception_ignore_args is on), so this control case cannot distinguish '
                . 'the attribute from the ini setting.',
            );
        }

        self::assertStringContainsString(
            'Object(Plugins\\ResourceTokens\\Domain\\ResourceRef)',
            $trace,
            'the unmarked second argument IS in the trace, so redacting the first one is the '
            . 'attribute doing work and not the ini setting hiding everything',
        );
    }

    /** A trace taken while verify()'s frame is live, by making the store throw. */
    private function traceFromVerify(string $secret, ?ResourceRef $resource = null): string
    {
        $store = new class extends InMemoryTokenStore {
            public function findByDigest(string $digest): never
            {
                throw new \RuntimeException('the database went away');
            }
        };

        $service = new ResourceTokenService($store, ResourceTokenConfig::of(), $this->clock);

        try {
            $service->verify($secret, $resource ?? $this->file());
            self::fail('the arranged store must throw');
        } catch (\RuntimeException $e) {
            return $e->getTraceAsString();
        }
    }
}
