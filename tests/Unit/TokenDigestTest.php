<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;
use Plugins\ResourceTokens\Application\Services\ResourceTokenService;
use Plugins\ResourceTokens\Domain\MintedToken;
use Plugins\ResourceTokens\Domain\TokenDigest;

/**
 * How a secret is made, and how it becomes a lookup key.
 *
 * These are the compatibility contract for the stored column: anything asserted
 * here is something an existing row depends on, so a change that breaks one of
 * these refuses every token already in circulation.
 */
#[CoversClass(TokenDigest::class)]
final class TokenDigestTest extends TestCase
{
    public function test_a_minted_secret_is_64_lowercase_hex_characters(): void
    {
        // HEX, not base64url. These values travel in a query string, so the
        // alphabet has to survive URL-encoding, logging, being copied out of a
        // terminal and pasted into a form, without any step changing it.
        $secret = TokenDigest::mint();

        self::assertSame(64, \strlen($secret));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function test_two_mints_differ(): void
    {
        // Not a test of randomness quality — that is random_bytes()' job. It
        // catches the one implementation mistake that would be catastrophic
        // and invisible: a constant, or a seeded generator reset per request.
        $secrets = [];

        for ($i = 0; $i < 50; $i++) {
            $secrets[] = TokenDigest::mint();
        }

        self::assertCount(50, array_unique($secrets));
    }

    public function test_the_digest_is_sha256_of_the_secret(): void
    {
        // Pinned to the literal, because it is what every stored row was
        // written with. Changing the algorithm refuses every outstanding token.
        $secret = TokenDigest::mint();

        self::assertSame('sha256', TokenDigest::ALGORITHM);
        self::assertSame(hash('sha256', $secret), TokenDigest::of($secret));
        self::assertSame(64, \strlen(TokenDigest::of($secret)));
    }

    public function test_the_digest_is_deterministic(): void
    {
        // The property the single indexed lookup depends on, and the one a
        // salted hash would NOT have — which is why bcrypt here would force
        // either a plaintext column or a scan of the whole table.
        self::assertSame(TokenDigest::of('abc'), TokenDigest::of('abc'));
    }

    public function test_the_digest_does_not_contain_the_secret(): void
    {
        $secret = TokenDigest::mint();

        self::assertStringNotContainsString($secret, TokenDigest::of($secret));
    }

    // ── The cheap shape check ────────────────────────────────────────────────

    public function test_a_minted_secret_is_plausible(): void
    {
        self::assertTrue(TokenDigest::isPlausible(TokenDigest::mint()));
    }

    /** @return list<array{string, string}> */
    public static function implausibleValues(): array
    {
        return [
            ['',                               'empty'],
            ['abc',                             'far too short'],
            [str_repeat('a', 63),               'one character short'],
            [str_repeat('a', 65),               'one character long'],
            [str_repeat('A', 64),               'upper-case hex — mint() produces lower'],
            [str_repeat('g', 64),               'not hex'],
            [str_repeat('a', 63) . '!',         'punctuation'],
            [str_repeat('a', 63) . "\n",        'a newline'],
            ["\x00" . str_repeat('a', 63),      'a NUL'],
        ];
    }

    #[DataProvider('implausibleValues')]
    public function test_an_implausible_value_is_rejected_without_a_lookup(string $value, string $why): void
    {
        // The point of this check is the LOOKUP IT AVOIDS: an endpoint being
        // sprayed with junk costs a strlen and a regex rather than an indexed
        // query per request. It is a filter, never a decision — a value that
        // passes has proved nothing.
        self::assertFalse(TokenDigest::isPlausible($value), $why);
    }

    public function test_the_prefix_is_twelve_characters(): void
    {
        $digest = TokenDigest::of('whatever');

        self::assertSame(12, \strlen(TokenDigest::prefix($digest)));
        self::assertSame(substr($digest, 0, 12), TokenDigest::prefix($digest));
    }

    public function test_the_prefix_is_what_an_operator_computes_by_hand(): void
    {
        // The documented incident-response path: `shasum -a 256` of a token
        // found in a log, and the first twelve characters identify the row in
        // `restokens:list` — without the token ever being typed into a command.
        $secret = TokenDigest::mint();

        self::assertSame(
            substr(hash('sha256', $secret), 0, 12),
            TokenDigest::prefix(TokenDigest::of($secret)),
        );
    }

    // ── Every raw-secret parameter is marked sensitive ───────────────────────

    /** @return list<array{string, string, int}> */
    public static function sensitiveParameters(): array
    {
        return [
            [TokenDigest::class,         'of',          0],
            [TokenDigest::class,         'isPlausible', 0],
            [MintedToken::class,         '__construct', 1],
            [ResourceTokenService::class, 'verify',     0],
            [ResourceTokenContract::class, 'verify',    0],
        ];
    }

    /**
     * A GUARDRAIL, and the honest account of why it is one.
     *
     * The effect of `#[\SensitiveParameter]` is visible only in a stack trace
     * taken while the marked frame is live — so it can be demonstrated as
     * BEHAVIOUR wherever something below the call can throw, and
     * {@see ResourceTokenServiceTest::test_a_presented_token_does_not_reach_a_stack_trace()}
     * does exactly that for `verify()`, which is the one that matters: a
     * database blip during verification would otherwise put the presented
     * token in the error report.
     *
     * Nothing currently throws inside `hash()`, inside `isPlausible()`'s regex,
     * or inside MintedToken's constructor, so for those three the attribute is
     * defence in depth against code added later rather than something with an
     * observable effect today. That cannot be tested by behaviour, because
     * there is no behaviour yet — which is what makes this reflection check
     * the only available check, and why it is labelled as one instead of being
     * counted as coverage.
     */
    #[DataProvider('sensitiveParameters')]
    public function test_a_raw_secret_parameter_is_marked_sensitive(
        string $class,
        string $method,
        int $position,
    ): void {
        $parameters = (new \ReflectionMethod($class, $method))->getParameters();

        self::assertArrayHasKey($position, $parameters, "{$class}::{$method} parameter {$position}");
        self::assertNotSame(
            [],
            $parameters[$position]->getAttributes(\SensitiveParameter::class),
            \sprintf(
                '%s::%s() parameter $%s takes a raw token and must carry #[\SensitiveParameter], '
                . 'or PHP will put the token in every stack trace taken below it.',
                $class,
                $method,
                $parameters[$position]->getName(),
            ),
        );
    }
}
