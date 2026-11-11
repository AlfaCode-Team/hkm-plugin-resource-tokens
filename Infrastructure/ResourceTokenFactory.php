<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use Plugins\ResourceTokens\Application\Services\ResourceTokenService;
use Plugins\ResourceTokens\Infrastructure\Config\ResourceTokenConfig;
use Plugins\ResourceTokens\Infrastructure\Persistence\ResourceTokenRepository;

/**
 * One place the service is assembled.
 *
 * It is built from two containers — the request-scoped one by `Provider::
 * register()`, and the CoreContainer by the CLI path — and a second assembly
 * site is how the two drift: one of them ends up with a different clock, or
 * misses a dependency added later. This keeps the wiring in one function so
 * both callers get the same object.
 */
final class ResourceTokenFactory
{
    public static function make(
        DatabasePort $db,
        ?ResourceTokenConfig $config = null,
        ?ClockPort $clock = null,
    ): ResourceTokenService {
        return new ResourceTokenService(
            store:  new ResourceTokenRepository($db),
            config: $config ?? ResourceTokenConfig::fromEnv(),
            // Falling back to a real clock rather than requiring the port: a
            // ClockPort is OPTIONAL in a deployment, and a plugin that refused
            // to start without one would make an injectable clock a hard
            // dependency of installing it. The port is used where it is bound,
            // which is what makes expiry testable without sleeping.
            clock:  $clock ?? new SystemClock(),
        );
    }
}
