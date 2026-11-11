<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Infrastructure\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;

/**
 * Lives in Infrastructure/ because it extends a kernel exception, and the
 * Domain layer imports nothing outside Domain/ — the Domain classes here throw
 * `\InvalidArgumentException`.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * ONLY MINTING THROWS. VERIFYING NEVER DOES.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Every exception in this class is raised while MINTING a token — a caller
 * asked for something that cannot be made. A refused token at verification
 * time is not an error at all; it is the ordinary end of a link's life, and it
 * comes back as a {@see \Plugins\ResourceTokens\Domain\TokenVerdict}. An
 * application whose error reporter fills up with expired links is an
 * application that will miss the one alert that mattered.
 *
 * `ServiceException` is HTTP 422, which is right for all of these: the caller
 * asked for a lifetime or a quantity that is not allowed, and the message is
 * meant to be read and acted on. A 500 would say the fault was ours.
 */
final class ResourceTokenException extends ServiceException
{
    /**
     * `ttlSeconds: 0` — refused rather than interpreted.
     *
     * Its own case because of what the alternatives would be. Treating 0 as
     * "never expires" would mean somebody meaning "expire immediately" minted
     * a permanent token; treating it as "expire immediately" would mint a token
     * that is dead before it is returned, which looks like the plugin being
     * broken. There is no reading of 0 worth having.
     */
    public static function zeroLifetime(): self
    {
        return new self(
            'A lifetime of 0 seconds is not allowed. Pass a positive number of seconds, omit it '
            . 'for the configured default, or pass ResourceTokenContract::NEVER_EXPIRES ('
            . ResourceTokenContract::NEVER_EXPIRES . ') to mint a token with no expiry. '
            . 'Zero is refused rather than interpreted, because it reads as "expires immediately" '
            . 'and would have to mean the opposite.',
            layer: 'service.resource-tokens.mint',
        );
    }

    public static function negativeLifetime(int $given): self
    {
        return new self(
            "A lifetime of {$given} seconds is in the past. Pass a positive number of seconds, or "
            . 'ResourceTokenContract::NEVER_EXPIRES (' . ResourceTokenContract::NEVER_EXPIRES
            . ') for no expiry.',
            layer: 'service.resource-tokens.mint',
            context: ['ttl' => $given],
        );
    }

    public static function lifetimeTooLong(int $given, int $ceiling): self
    {
        return new self(
            "A lifetime of {$given} seconds exceeds the configured ceiling of {$ceiling}. "
            . 'Raise RESOURCE_TOKENS_MAX_TTL, or mint a shorter-lived token and mint another when '
            . 'it runs out.',
            layer: 'service.resource-tokens.mint',
            context: ['ttl' => $given, 'ceiling' => $ceiling],
        );
    }

    /**
     * A permanent token was asked for where the deployment forbids them.
     *
     * The setting exists because a token with no expiry in a query string is
     * the longest-lived credential an application can accidentally create: it
     * outlives the reason it was minted, and it is sitting in a web server's
     * access log for as long as those are kept.
     */
    public static function permanentRefused(): self
    {
        return new self(
            'This deployment does not allow tokens without an expiry '
            . '(RESOURCE_TOKENS_ALLOW_PERMANENT is off). Pass a lifetime in seconds.',
            layer: 'service.resource-tokens.mint',
        );
    }

    /**
     * The per-resource cap.
     *
     * A guard rail against a loop, not a security boundary — two concurrent
     * mints can both pass the count. What it catches is the real failure: code
     * that mints a fresh token on every page render instead of reusing one,
     * which fills the table and leaves a thousand live links to one file.
     */
    public static function tooManyForResource(string $resource, int $cap): self
    {
        return new self(
            "Resource [{$resource}] already has {$cap} live tokens, which is the configured "
            . 'maximum. Revoke the ones that are no longer needed, or raise '
            . 'RESOURCE_TOKENS_MAX_PER_RESOURCE. Hitting this usually means a token is being '
            . 'minted per request where one should be reused.',
            layer: 'service.resource-tokens.mint',
            context: ['resource' => $resource, 'cap' => $cap],
        );
    }

    public static function invalidMaxUses(int $given): self
    {
        return new self(
            "A use limit of {$given} is not allowed. Pass a positive number, or null for "
            . 'unlimited. A limit of 0 would mint a token that cannot be used once.',
            layer: 'service.resource-tokens.mint',
            context: ['max_uses' => $given],
        );
    }

    /**
     * Persistence failed.
     *
     * Separate from every case above because the cause is ours, not the
     * caller's — reporting a database failure as a bad lifetime would send
     * somebody to change an argument that was fine.
     */
    public static function saveFailed(string $resource, \Throwable $previous): self
    {
        return new self(
            'The resource token could not be saved.',
            layer: 'repository.resource-tokens.save',
            context: ['resource' => $resource],
            previous: $previous,
        );
    }
}
