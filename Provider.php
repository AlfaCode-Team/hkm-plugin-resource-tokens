<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;
use Plugins\ResourceTokens\Application\Services\ResourceTokenService;
use Plugins\ResourceTokens\Infrastructure\Cli\TokenListCommand;
use Plugins\ResourceTokens\Infrastructure\Cli\TokenPruneCommand;
use Plugins\ResourceTokens\Infrastructure\Cli\TokenRevokeCommand;
use Plugins\ResourceTokens\Infrastructure\Cli\TokenStatusCommand;
use Plugins\ResourceTokens\Infrastructure\Config\ResourceTokenConfig;
use Plugins\ResourceTokens\Infrastructure\Http\Stages\ResourceTokenFilterStage;
use Plugins\ResourceTokens\Infrastructure\ResourceTokenFactory;

/**
 * ResourceTokens — wires the 'resource.tokens' domain.
 *
 * Requires no other module. It needs a `DatabasePort`, which is a PORT and
 * therefore resolves from the CoreContainer rather than being named in
 * `requires[]`, and optionally a `ClockPort`.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * IT REGISTERS NO SECURITY LAYER, AND THAT IS THE DESIGN
 * ────────────────────────────────────────────────────────────────────────────
 *
 * A `SecurityLayerContract` runs in the gateway, before any module loads and
 * before a route is matched, and its job is to produce an `Identity`. A
 * resource token must never do either: it would then be a credential for the
 * APPLICATION rather than for one resource, and the resource it was minted for
 * would be reduced to a comment on a row. Verification happens after routing,
 * against a resource the router has already resolved — which is what the route
 * filter below is.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'resource.tokens';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [ResourceTokenContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(
            ResourceTokenConfig::class,
            static fn (): ResourceTokenConfig => ResourceTokenConfig::fromEnv(),
        );

        $container->bind(
            ResourceTokenContract::class,
            static fn (ModuleContainer $c): ResourceTokenService => ResourceTokenFactory::make(
                db:     $c->make(DatabasePort::class),
                config: $c->make(ResourceTokenConfig::class),
                // Optional. Without it the factory falls back to a real clock,
                // so a deployment that binds no ClockPort still works — it just
                // cannot freeze time in a test.
                clock:  $c->has(ClockPort::class) ? $c->make(ClockPort::class) : null,
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // The declarative half: a route says which resource it serves, and the
        // stage requires a token for that resource. See the stage's docblock
        // for the grammar and for why a route must ALSO declare
        // `requires: ["resource.tokens"]`.
        //
        // A filter alias and NOT a global hook. A global hook runs on every
        // request and would have to self-gate on a path pattern, which is the
        // thing route filters exist to replace; and a stage registered as both
        // would run twice, spending two uses of a single-use token.
        $http->filter(ResourceTokenFilterStage::ALIAS, ResourceTokenFilterStage::class);

        // Instances, not class-strings. A class-string is built from the
        // CoreContainer, and a command whose dependencies do not resolve there
        // is SILENTLY NOT REGISTERED — it does not appear in `list` at all,
        // which looks like the plugin not being installed. Every command here
        // takes ResourceTokenContract, an interface bound per request, which
        // reflection cannot autowire from the core.
        //
        // Deferred, so the DatabasePort behind them is built only on the CLI
        // path: every module's boot() also runs during an HTTP build, and these
        // have no business opening a connection there.
        $cli->defer(static function (CliPipeline $cli): void {
            $core   = $cli->container();
            $tokens = self::forCli($core);
            $clock  = $core->has(ClockPort::class) ? $core->make(ClockPort::class) : new SystemClock();

            $cli->command(new TokenStatusCommand($tokens));
            $cli->command(new TokenListCommand($tokens, $clock));
            $cli->command(new TokenRevokeCommand($tokens));
            $cli->command(new TokenPruneCommand($tokens));
        });
    }

    /**
     * The service the CLI operates on.
     *
     * Built from the CoreContainer, which for a single-database application is
     * the only connection there is. In a database-per-tenant deployment the
     * tokens live in each tenant's own database and nothing here iterates
     * tenants — that is the tenancy plugin's business. Such a deployment runs
     * these commands once per tenant with the port rebound, like any other
     * per-tenant command.
     */
    private static function forCli(CoreContainer $core): ResourceTokenService
    {
        return ResourceTokenFactory::make(
            db:     $core->make(DatabasePort::class),
            config: ResourceTokenConfig::fromEnv(),
            clock:  $core->has(ClockPort::class) ? $core->make(ClockPort::class) : null,
        );
    }
}
