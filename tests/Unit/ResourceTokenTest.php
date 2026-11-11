<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\ResourceTokens\Domain\MintedToken;
use Plugins\ResourceTokens\Domain\ResourceToken;
use Plugins\ResourceTokens\Domain\TokenAbilities;
use Plugins\ResourceTokens\Domain\TokenDigest;
use Plugins\ResourceTokens\Domain\TokenRefusal;
use Tests\Plugins\ResourceTokens\Support\TokenBuilder;

#[CoversClass(ResourceToken::class)]
#[CoversClass(MintedToken::class)]
#[CoversClass(TokenRefusal::class)]
final class ResourceTokenTest extends TestCase
{
    private const NOW = '2026-06-01 12:00:00';

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    // ── State ────────────────────────────────────────────────────────────────

    public function test_a_fresh_token_is_usable(): void
    {
        $token = TokenBuilder::make(expiresAt: new \DateTimeImmutable('2026-06-01 13:00:00'));

        self::assertTrue($token->isUsableAt($this->now()));
        self::assertNull($token->refusalAt($this->now()));
        self::assertSame('active', $token->state($this->now()));
    }

    public function test_a_token_with_no_expiry_never_expires(): void
    {
        $token = TokenBuilder::make(expiresAt: null);

        self::assertFalse($token->isExpired($this->now()));
        self::assertTrue($token->isUsableAt(new \DateTimeImmutable('2099-01-01 00:00:00')));
    }

    public function test_expiry_is_inclusive_of_the_instant_itself(): void
    {
        // `<=`, not `<`. A token minted with a one-second lifetime and used in
        // the same second would otherwise still work, and that boundary is the
        // one somebody eventually tests by hand and reports.
        $token = TokenBuilder::make(expiresAt: $this->now());

        self::assertTrue($token->isExpired($this->now()), 'at the instant of expiry it is gone');
        self::assertSame(TokenRefusal::Expired, $token->refusalAt($this->now()));
    }

    public function test_a_token_at_its_use_limit_is_exhausted(): void
    {
        self::assertFalse(TokenBuilder::make(maxUses: 2, uses: 1)->isExhausted());
        self::assertTrue(TokenBuilder::make(maxUses: 2, uses: 2)->isExhausted());
        // `>=` and not `===`: a row that somehow overshot must stay refused
        // rather than wrapping back round to usable.
        self::assertTrue(TokenBuilder::make(maxUses: 2, uses: 9)->isExhausted());
    }

    public function test_an_unlimited_token_is_never_exhausted(): void
    {
        self::assertFalse(TokenBuilder::make(maxUses: null, uses: 10_000)->isExhausted());
    }

    public function test_uses_remaining_is_null_when_unlimited(): void
    {
        self::assertNull(TokenBuilder::make(maxUses: null)->usesRemaining());
        self::assertSame(3, TokenBuilder::make(maxUses: 5, uses: 2)->usesRemaining());
        self::assertSame(0, TokenBuilder::make(maxUses: 5, uses: 9)->usesRemaining(), 'never negative');
    }

    // ── Refusal precedence ───────────────────────────────────────────────────

    public function test_revoked_beats_expired_and_exhausted(): void
    {
        // All three refuse, so the order changes nothing about safety — only
        // what an operator is told when more than one applies. Revoked is
        // reported first because it is the one that was a DECISION, and
        // therefore the one with a person behind it to ask.
        $token = TokenBuilder::make(
            expiresAt: new \DateTimeImmutable('2020-01-01 00:00:00'),
            maxUses:   1,
            uses:      1,
            revokedAt: new \DateTimeImmutable('2026-05-01 00:00:00'),
        );

        self::assertSame(TokenRefusal::Revoked, $token->refusalAt($this->now()));
        self::assertSame('revoked', $token->state($this->now()));
    }

    public function test_expired_beats_exhausted(): void
    {
        $token = TokenBuilder::make(
            expiresAt: new \DateTimeImmutable('2020-01-01 00:00:00'),
            maxUses:   1,
            uses:      1,
        );

        self::assertSame(TokenRefusal::Expired, $token->refusalAt($this->now()));
    }

    public function test_only_two_refusals_suggest_probing(): void
    {
        // The split an application routes on: one group belongs in an alert,
        // the other is the ordinary life of a link.
        self::assertTrue(TokenRefusal::WrongResource->suggestsProbing());
        self::assertTrue(TokenRefusal::MissingAbility->suggestsProbing());

        foreach ([
            TokenRefusal::Expired,
            TokenRefusal::Exhausted,
            TokenRefusal::Revoked,
            TokenRefusal::Unknown,
            TokenRefusal::Malformed,
        ] as $refusal) {
            self::assertFalse($refusal->suggestsProbing(), $refusal->value);
        }
    }

    public function test_every_refusal_explains_itself(): void
    {
        foreach (TokenRefusal::cases() as $refusal) {
            self::assertNotSame('', $refusal->explain(), $refusal->value);
        }
    }

    // ── Immutable transitions ────────────────────────────────────────────────

    public function test_recording_a_use_returns_a_new_instance(): void
    {
        $token = TokenBuilder::make(maxUses: 3, uses: 1);
        $used  = $token->used($this->now());

        self::assertSame(1, $token->uses, 'the original is untouched');
        self::assertSame(2, $used->uses);
        self::assertEquals($this->now(), $used->lastUsedAt);
        self::assertNull($used->exhaustedAt, 'not spent yet');
    }

    public function test_the_use_that_reaches_the_limit_stamps_exhausted_at(): void
    {
        $used = TokenBuilder::make(maxUses: 2, uses: 1)->used($this->now());

        self::assertTrue($used->isExhausted());
        self::assertEquals($this->now(), $used->exhaustedAt);
        self::assertSame('spent', $used->state($this->now()));
    }

    public function test_an_unlimited_token_never_stamps_exhausted_at(): void
    {
        self::assertNull(TokenBuilder::make(maxUses: null)->used($this->now())->exhaustedAt);
    }

    public function test_revoking_twice_keeps_the_first_decision(): void
    {
        // The first withdrawal is the one that matters. Re-revoking would move
        // the timestamp away from the moment somebody actually decided, which
        // is the single fact an audit of this row is for.
        $first = new \DateTimeImmutable('2026-05-01 00:00:00');

        $token = TokenBuilder::make()->revoked($first)->revoked($this->now());

        self::assertEquals($first, $token->revokedAt);
    }

    // ── describe() ───────────────────────────────────────────────────────────

    public function test_describe_carries_no_secret_and_only_a_digest_prefix(): void
    {
        $secret = TokenDigest::mint();
        $digest = TokenDigest::of($secret);

        $described = TokenBuilder::make(digest: $digest)->describe($this->now());

        $flat = json_encode($described, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($secret, $flat, 'the secret is not even held');
        self::assertStringNotContainsString($digest, $flat, 'and not the full digest either');
        self::assertSame(substr($digest, 0, 12), $described['digest_prefix']);
    }

    public function test_describe_shows_the_resource_in_both_forms(): void
    {
        $described = TokenBuilder::make(resource: TokenBuilder::file('f-9'))->describe($this->now());

        // The label for a human, the two parts for anything that has to match
        // them back against its own records.
        self::assertSame('file:f-9', $described['resource']);
        self::assertSame('file', $described['resource_type']);
        self::assertSame('f-9', $described['resource_id']);
    }

    public function test_describe_reports_uses_left(): void
    {
        $limited = TokenBuilder::make(maxUses: 5, uses: 2)->describe($this->now());
        self::assertSame(3, $limited['uses_left']);

        $unlimited = TokenBuilder::make(maxUses: null, uses: 2)->describe($this->now());
        self::assertNull($unlimited['uses_left'], 'null means unlimited, not zero');
    }

    // ── MintedToken ──────────────────────────────────────────────────────────

    public function test_a_minted_token_returns_its_secret_once(): void
    {
        $secret = TokenDigest::mint();
        $minted = new MintedToken(TokenBuilder::make(digest: TokenDigest::of($secret)), $secret);

        self::assertSame($secret, $minted->secret());
        self::assertSame('token-1', $minted->id());
    }

    public function test_a_minted_token_redacts_its_secret_from_var_dump(): void
    {
        // Real behaviour, not a guardrail: var_dump() is what a debug bar, a
        // dd() helper and an error page's variable panel are all built on, and
        // without __debugInfo() every one of them would print the token.
        $secret = TokenDigest::mint();
        $minted = new MintedToken(TokenBuilder::make(), $secret);

        ob_start();
        var_dump($minted);
        $dumped = (string) ob_get_clean();

        self::assertStringNotContainsString($secret, $dumped);
        // The LENGTH survives, because "did I get a secret at all" is the
        // question somebody dumping this is trying to answer.
        self::assertStringContainsString('redacted', $dumped);
        self::assertStringContainsString('64 characters', $dumped);
    }

    public function test_a_minted_tokens_describe_carries_no_secret(): void
    {
        $secret = TokenDigest::mint();
        $minted = new MintedToken(TokenBuilder::make(), $secret);

        self::assertStringNotContainsString(
            $secret,
            json_encode($minted->describe($this->now()), \JSON_THROW_ON_ERROR),
        );
    }

    public function test_abilities_round_trip_through_describe(): void
    {
        $described = TokenBuilder::make(
            abilities: TokenAbilities::of(['download', 'read']),
        )->describe($this->now());

        self::assertSame(['download', 'read'], $described['abilities']);
    }
}
