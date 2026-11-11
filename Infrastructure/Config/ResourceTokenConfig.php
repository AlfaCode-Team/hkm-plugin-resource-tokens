<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Infrastructure\Config;

/**
 * Every tuning knob, read once from the environment.
 *
 * `env()` and not `getenv()`: `$_ENV` is the source of truth in this framework
 * and `putenv()` is never called, so `getenv()` returns null for a value that is
 * present — a default silently winning over configuration.
 *
 * Every key here is declared in `module.json` `config[]`, which the boot
 * pipeline validates. A read of an undeclared key fails the boot rather than
 * defaulting quietly.
 */
final readonly class ResourceTokenConfig
{
    /** Lifetime for a mint that does not name one. */
    public int $defaultTtl;

    /** Hard ceiling on any lifetime. 0 = no ceiling. */
    public int $maxTtl;

    /** Whether a permanent token may be minted at all. */
    public bool $allowPermanent;

    /** Live tokens allowed per resource. 0 = unlimited. */
    public int $maxPerResource;

    /** Keep settled rows this long before pruning. */
    public int $pruneAfterDays;

    /** Whether a use is recorded for UNLIMITED tokens. See below. */
    public bool $trackUses;

    /** Query-string parameter the route filter reads. */
    public string $queryParam;

    /** Header the route filter prefers. */
    public string $header;

    /** Status the route filter answers on every refusal. */
    public int $refusedStatus;

    /**
     * EVERY value is normalised HERE, not in a named constructor.
     *
     * It was in `fromEnv()` first, and a test of `of()` is what showed why
     * that is the wrong place: `of()` went straight past all of it, so a
     * refusal status of 200 — the one setting that turns every refusal into a
     * private resource served to anybody — was reachable through the
     * constructor the route filter and every test use. Validation that lives
     * in one of two factories is not an invariant, it is a habit.
     */
    private function __construct(
        int $defaultTtl,
        int $maxTtl,
        bool $allowPermanent,
        int $maxPerResource,
        int $pruneAfterDays,
        bool $trackUses,
        string $queryParam,
        string $header,
        int $refusedStatus,
    ) {
        // A lifetime of at least a second: 0 or negative would make every
        // default-lifetime token expire at the instant it was minted.
        $this->defaultTtl     = max(1, $defaultTtl);
        $this->maxTtl         = max(0, $maxTtl);
        $this->allowPermanent = $allowPermanent;
        $this->maxPerResource = max(0, $maxPerResource);
        $this->pruneAfterDays = max(1, $pruneAfterDays);
        $this->trackUses      = $trackUses;
        $this->queryParam     = self::name($queryParam, 'token');
        $this->header         = self::name($header, 'X-Resource-Token');
        $this->refusedStatus  = self::status($refusedStatus);
    }

    public static function fromEnv(): self
    {
        return new self(
            defaultTtl:     (int) env('RESOURCE_TOKENS_DEFAULT_TTL', 3600),
            maxTtl:         (int) env('RESOURCE_TOKENS_MAX_TTL', 0),
            allowPermanent: (bool) env('RESOURCE_TOKENS_ALLOW_PERMANENT', true),
            maxPerResource: (int) env('RESOURCE_TOKENS_MAX_PER_RESOURCE', 100),
            pruneAfterDays: (int) env('RESOURCE_TOKENS_PRUNE_AFTER_DAYS', 30),
            trackUses:      (bool) env('RESOURCE_TOKENS_TRACK_USES', true),
            queryParam:     (string) env('RESOURCE_TOKENS_QUERY_PARAM', 'token'),
            header:         (string) env('RESOURCE_TOKENS_HEADER', 'X-Resource-Token'),
            refusedStatus:  (int) env('RESOURCE_TOKENS_REFUSED_STATUS', 404),
        );
    }

    /**
     * For a test, and for a deployment that configures this in code.
     *
     * Named arguments only — a positional call here would silently swap two
     * ints the day a field is inserted.
     */
    public static function of(
        int $defaultTtl = 3600,
        int $maxTtl = 0,
        bool $allowPermanent = true,
        int $maxPerResource = 100,
        int $pruneAfterDays = 30,
        bool $trackUses = true,
        string $queryParam = 'token',
        string $header = 'X-Resource-Token',
        int $refusedStatus = 404,
    ): self {
        return new self(
            defaultTtl:     $defaultTtl,
            maxTtl:         $maxTtl,
            allowPermanent: $allowPermanent,
            maxPerResource: $maxPerResource,
            pruneAfterDays: $pruneAfterDays,
            trackUses:      $trackUses,
            queryParam:     $queryParam,
            header:         $header,
            refusedStatus:  $refusedStatus,
        );
    }

    /** Seconds before a settled row may be deleted. */
    public function pruneAfterSeconds(): int
    {
        return $this->pruneAfterDays * 86400;
    }

    /**
     * A header or parameter name an operator typed, or the default.
     *
     * An empty or malformed value falls back rather than being used: an empty
     * header name would make the filter read nothing and refuse every request,
     * and a name containing a newline would be a header-injection primitive on
     * any path that echoes it back.
     *
     * A TRAILING newline is trimmed and the name then accepted, on purpose: an
     * operator whose `.env` line ends in whitespace should get the header name
     * they wrote, not a silent fallback to the default. The injection shapes —
     * `X-Token\nY: z` and its CRLF form — are refused by the character class.
     *
     * The `D` modifier is belt and braces HERE, because `trim()` has already
     * removed anything `$`'s second meaning could have matched before. It is
     * load-bearing in {@see \Plugins\ResourceTokens\Domain\ResourceRef} and
     * {@see \Plugins\ResourceTokens\Domain\TokenAbilities}, which do not trim —
     * see the long note on the first of those.
     */
    private static function name(string $given, string $fallback): string
    {
        $given = trim($given);

        return $given !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $given) === 1
            ? $given
            : $fallback;
    }

    /**
     * 404 by default, and a value outside 400–499 is refused.
     *
     * 404 rather than 401 or 403 because it is the one that says nothing: a 403
     * confirms the resource exists and that the token was the problem, and a
     * 401 invites a client to retry with credentials it does not have. Making
     * an invalid token indistinguishable from a resource that is not there is
     * the whole reason refusals are uniform.
     *
     * A 2xx or 3xx here would turn every refusal into a SUCCESS, which is the
     * one misconfiguration that must not be possible — so it falls back.
     */
    private static function status(int $given): int
    {
        return $given >= 400 && $given <= 499 ? $given : 404;
    }

    /**
     * Safe to print. No secrets live here, but the shape is kept uniform with
     * the other describe() surfaces so a status command can print them together.
     *
     * @return array<string, scalar>
     */
    public function describe(): array
    {
        return [
            'default_ttl'      => $this->defaultTtl,
            'max_ttl'          => $this->maxTtl === 0 ? 'none' : $this->maxTtl,
            'allow_permanent'  => $this->allowPermanent,
            'max_per_resource' => $this->maxPerResource === 0 ? 'unlimited' : $this->maxPerResource,
            'prune_after_days' => $this->pruneAfterDays,
            'track_uses'       => $this->trackUses,
            'query_param'      => $this->queryParam,
            'header'           => $this->header,
            'refused_status'   => $this->refusedStatus,
        ];
    }
}
