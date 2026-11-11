<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\ResourceTokens\Infrastructure\Config\ResourceTokenConfig;

/**
 * The tuning, and the two values that are not merely tuning.
 *
 * `refusedStatus` and `header` both have a wrong setting that would be worse
 * than no setting at all, so both fall back rather than being used.
 */
#[CoversClass(ResourceTokenConfig::class)]
final class ResourceTokenConfigTest extends TestCase
{
    // ── The refusal status cannot be made permissive ─────────────────────────

    /** @return list<array{int, int, string}> */
    public static function refusalStatuses(): array
    {
        return [
            [404, 404, 'the default, and the one that says nothing'],
            [403, 403, 'allowed: an operator may prefer to be explicit'],
            [401, 401, 'allowed'],
            [410, 410, 'allowed'],

            // THE setting that must not be possible. A 2xx or 3xx would turn
            // every refusal into a SUCCESS — a private resource served to
            // anybody, from one typo in an .env file.
            [200, 404, 'a success status would serve the resource on every refusal'],
            [204, 404, 'likewise'],
            [302, 404, 'a redirect is not a refusal either'],
            [500, 404, 'a 5xx says the fault was ours'],
            [0,   404, 'nonsense'],
            [-1,  404, 'nonsense'],
            [999, 404, 'not a status'],
        ];
    }

    #[DataProvider('refusalStatuses')]
    public function test_a_refusal_status_outside_4xx_falls_back_to_404(
        int $given,
        int $expected,
        string $why,
    ): void {
        self::assertSame($expected, ResourceTokenConfig::of(refusedStatus: $given)->refusedStatus, $why);
    }

    // ── Header and parameter names ───────────────────────────────────────────

    /** @return list<array{string, string, string}> */
    public static function headerNames(): array
    {
        return [
            ['X-Resource-Token', 'X-Resource-Token', 'the default'],
            ['X-My-Token',       'X-My-Token',       'an operator\'s own name'],
            ['Authorization',    'Authorization',    'allowed, though unwise'],

            ['',                 'X-Resource-Token', 'empty would read nothing and refuse everything'],
            ['  ',               'X-Resource-Token', 'whitespace only'],
            ['1-Token',          'X-Resource-Token', 'must start with a letter'],
            ['X Token',          'X-Resource-Token', 'a space'],
            ['X:Token',          'X-Resource-Token', 'a colon would split the header line'],

            // A header name carrying a newline is a header-injection primitive
            // anywhere it is echoed back, so the injection shapes are refused.
            ["X-Token\nY: z",    'X-Resource-Token', 'an injected second header'],
            ["X-Token\r\nY: z",  'X-Resource-Token', 'CRLF'],

            // A TRAILING newline is TRIMMED and the name then accepted, which
            // is the wanted behaviour and not a hole: an operator whose .env
            // line ends in whitespace should get their header name, not a
            // silent fallback to the default. It is also why the `D` modifier
            // on this particular pattern is belt and braces rather than a fix —
            // trim() has already removed it, and an EMBEDDED newline fails on
            // the character class whatever `$` does. The modifier matters where
            // nothing trims: see ResourceRefTest and TokenAbilitiesTest.
            ["X-Token\n",        'X-Token',          'a trailing newline is trimmed, then valid'],
            ["  X-Token  ",      'X-Token',          'surrounding spaces likewise'],
        ];
    }

    #[DataProvider('headerNames')]
    public function test_a_malformed_header_name_falls_back(
        string $given,
        string $expected,
        string $why,
    ): void {
        self::assertSame($expected, ResourceTokenConfig::of(header: $given)->header, $why);
    }

    public function test_a_malformed_query_parameter_name_falls_back(): void
    {
        self::assertSame('token', ResourceTokenConfig::of(queryParam: '')->queryParam);
        self::assertSame('token', ResourceTokenConfig::of(queryParam: 'a=b')->queryParam);
        self::assertSame('token', ResourceTokenConfig::of(queryParam: "a\nb")->queryParam);
        self::assertSame('access', ResourceTokenConfig::of(queryParam: 'access')->queryParam);
        self::assertSame('t', ResourceTokenConfig::of(queryParam: "t\n")->queryParam, 'trimmed');
    }

    public function test_normalisation_is_not_bypassable_through_of(): void
    {
        // It used to be. The clamps and both name checks lived in fromEnv(),
        // so of() — which the route filter and every test use — went straight
        // past all of them, and a refusal status of 200 was reachable. They now
        // live in the private constructor, which is the only way in.
        $config = ResourceTokenConfig::of(
            defaultTtl: 0,
            maxTtl: -5,
            maxPerResource: -1,
            pruneAfterDays: 0,
            queryParam: '',
            header: '',
            refusedStatus: 200,
        );

        self::assertSame(1, $config->defaultTtl, 'a 0 default would expire every token at birth');
        self::assertSame(0, $config->maxTtl);
        self::assertSame(0, $config->maxPerResource);
        self::assertSame(1, $config->pruneAfterDays);
        self::assertSame('token', $config->queryParam);
        self::assertSame('X-Resource-Token', $config->header);
        self::assertSame(404, $config->refusedStatus);
    }

    // ── Derived values ───────────────────────────────────────────────────────

    public function test_the_prune_window_is_in_seconds(): void
    {
        self::assertSame(86_400, ResourceTokenConfig::of(pruneAfterDays: 1)->pruneAfterSeconds());
        self::assertSame(2_592_000, ResourceTokenConfig::of(pruneAfterDays: 30)->pruneAfterSeconds());
    }

    public function test_describe_reads_zero_as_the_word_it_means(): void
    {
        // 0 means two different things in two different keys, and a status line
        // printing a bare "0" for both would be read as "none" in one place and
        // "nothing allowed" in the other.
        $described = ResourceTokenConfig::of(maxTtl: 0, maxPerResource: 0)->describe();

        self::assertSame('none', $described['max_ttl']);
        self::assertSame('unlimited', $described['max_per_resource']);
    }

    public function test_describe_carries_no_credential(): void
    {
        // Nothing secret lives in this config, so this is a regression guard
        // rather than a fix: the day a signing key or a DSN is added here,
        // describe() is what would print it into a status line.
        $flat = json_encode(ResourceTokenConfig::of()->describe(), \JSON_THROW_ON_ERROR);

        foreach (['secret', 'password', 'key', 'dsn', 'token='] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $flat, $forbidden);
        }
    }
}
