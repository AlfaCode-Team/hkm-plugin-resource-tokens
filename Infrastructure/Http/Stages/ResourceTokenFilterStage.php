<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;
use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\TokenAbilities;
use Plugins\ResourceTokens\Infrastructure\Config\ResourceTokenConfig;

/**
 * The `restoken` route filter: a route declares the resource it serves, and a
 * valid token for THAT resource is required to reach it.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * DECLARED ON THE ROUTE, NOT WIRED IN A CONTROLLER
 * ────────────────────────────────────────────────────────────────────────────
 *
 *   { "method": "GET", "path": "/files/{id:uuid}/raw",
 *     "handler": "App\\Http\\FileController@raw",
 *     "requires": ["resource.tokens"],
 *     "filters":  ["restoken:file,id"] }
 *
 * `restoken:<resource-type>,<route-param>[,<ability>]`
 *
 *   restoken:file,id            resource type `file`, id from `{id}`, ability `read`
 *   restoken:invoice,ref,write  resource type `invoice`, id from `{ref}`, ability `write`
 *
 * The TYPE is written in the manifest and the ID comes from the matched route,
 * which is the whole safety property: the resource the token is checked against
 * is the one the router already resolved, so a controller cannot be reached
 * with a token for a different resource. Checking it by hand inside the
 * controller is the version that gets forgotten on the fourth endpoint.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * `requires: ["resource.tokens"]` IS NOT OPTIONAL
 * ────────────────────────────────────────────────────────────────────────────
 *
 * A module's `register()` runs only when the module is in THAT request's
 * dependency graph. A route that names this filter without also requiring the
 * domain reaches this stage with the contract unbound — so the omission is
 * reported as a 500 with a message naming the fix, and NEVER as a pass.
 * Failing open here would turn one missing line of JSON into a private file
 * served to anybody who asked.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE CONSTRUCTOR TAKES NOTHING, AND IT HAS TO
 * ────────────────────────────────────────────────────────────────────────────
 *
 * `FilterRegistry::resolve()` builds a filter as
 * `$core->has($class) ? $core->make($class) : new $class()` — so a stage with
 * required constructor arguments that is not bound in the CoreContainer is
 * constructed with none and dies with an ArgumentCountError on the first
 * request that touches the route.
 *
 * It is also MEMOIZED: one instance per worker, shared by every request. So it
 * can hold nothing request-scoped. The service is therefore resolved from
 * `$request->container()` on each call rather than injected — which is also the
 * correct lifetime for it, since that container is rebuilt per request and a
 * captured one would, under OpenSwoole, still be pointing at the first
 * request's bindings (for a tenancy deployment that rebinds DatabasePort per
 * request, at the first tenant's database).
 *
 * The CONFIG is memoized, and that is safe for the opposite reason: it comes
 * from the environment, which does not change between requests.
 */
final class ResourceTokenFilterStage implements HttpStageContract
{
    public const ALIAS = 'restoken';

    private ?ResourceTokenConfig $config = null;

    public function handle(Request $request, callable $next): Response
    {
        $config = $this->config ??= ResourceTokenConfig::fromEnv();

        [$type, $param, $ability] = $this->spec($request);

        $tokens = $this->service($request);

        try {
            $resource = ResourceRef::of($type, $this->routeParam($request, $param));
        } catch (\InvalidArgumentException $e) {
            // The route matched but its parameter does not make a usable
            // resource id — an empty optional `{id?}`, or a `{id:any}` that
            // captured a slash. Refused like any other failure rather than
            // reported, because the presenter must not learn the difference
            // between "bad id" and "bad token".
            $this->log($request, 'resource token refused: ' . $e->getMessage());

            return $this->refuse($config);
        }

        $verdict = $tokens->verify(
            $this->presented($request, $config),
            $resource,
            $ability,
        );

        if (!$verdict->granted) {
            // The ONLY place the reason is recorded. It never reaches the
            // response — see Domain/TokenRefusal for why telling the presenter
            // which check failed is free reconnaissance.
            $this->log($request, $verdict->logLine());

            return $this->refuse($config);
        }

        // Handed downstream so a controller can read what the token allows
        // without verifying a second time — which would spend a second use of
        // a single-use token.
        return $next($request->withAttribute('resource_token', $verdict->token));
    }

    /**
     * `restoken:type,param[,ability]`, from the route's own declaration.
     *
     * @return array{string, string, string}
     */
    private function spec(Request $request): array
    {
        $args = $request->attribute('filter_args');
        $args = \is_array($args) ? ($args[self::ALIAS] ?? []) : [];
        $args = \is_array($args) ? array_values($args) : [];

        $type    = isset($args[0]) ? trim((string) $args[0]) : '';
        $param   = isset($args[1]) ? trim((string) $args[1]) : '';
        $ability = isset($args[2]) && trim((string) $args[2]) !== ''
            ? trim((string) $args[2])
            : TokenAbilities::READ;

        if ($type === '' || $param === '') {
            // A manifest error, and it must be loud. Defaulting the type or the
            // parameter name would check the token against a resource nobody
            // declared: with no type, every token in the table would be
            // compared against the same empty type and the filter would either
            // refuse everything or — far worse — match tokens minted for a
            // different resource that happened to share an id.
            throw new \RuntimeException(
                'Route filter [' . self::ALIAS . '] needs a resource type and a route parameter: '
                . '"' . self::ALIAS . ':file,id" means resource type `file` with the id taken from '
                . 'the route\'s {id}. An optional third argument names the ability, which defaults '
                . 'to "' . TokenAbilities::READ . '".',
            );
        }

        return [$type, $param, $ability];
    }

    private function routeParam(Request $request, string $param): string
    {
        $params = $request->attribute('route_params');

        if (!\is_array($params) || !\array_key_exists($param, $params)) {
            // Also a manifest error: the filter names a parameter the path does
            // not declare. Named rather than guessed, because the alternative
            // is an empty id that ResourceRef would refuse for a reason that
            // reads like a bad request from the client.
            throw new \RuntimeException(\sprintf(
                'Route filter [%s] expects a route parameter named {%s}, and the matched route has '
                . 'none. Declared parameters: %s.',
                self::ALIAS,
                $param,
                \is_array($params) && $params !== []
                    ? implode(', ', array_map(static fn ($k): string => '{' . $k . '}', array_keys($params)))
                    : 'none',
            ));
        }

        return (string) $params[$param];
    }

    /**
     * The presented token: HEADER FIRST, then the query string.
     *
     * The header is preferred because a query string is written to a web
     * server's access log, kept in browser history, and sent onward in a
     * `Referer` on any outbound link from the page — so a token that travelled
     * that way is sitting in several places that outlive it. The query string
     * is still supported because it is the only option for the case this plugin
     * exists for: a browser fetching an `<img src>` cannot set a header.
     *
     * An empty string when neither carries one. It is passed to `verify()`
     * anyway rather than short-circuited, so that a missing token and a wrong
     * token take the same path and produce the same response — one fewer place
     * for the two to diverge.
     */
    private function presented(Request $request, ResourceTokenConfig $config): string
    {
        $header = $request->header($config->header);

        if (\is_string($header) && $header !== '') {
            return $header;
        }

        // query(), not input(): input() merges the request BODY, and reading a
        // token from a POST body would mean a form field could supply one. The
        // token belongs in the URL or the header, both of which the recipient
        // of the link controls — a body is written by whatever page did the
        // submitting.
        $value = $request->query($config->queryParam);

        return \is_string($value) ? $value : '';
    }

    private function service(Request $request): ResourceTokenContract
    {
        $container = $request->container();

        if ($container === null || !$container->has(ResourceTokenContract::class)) {
            throw new \RuntimeException(
                'Route filter [' . self::ALIAS . '] ran without the resource.tokens module loaded. '
                . 'Add "requires": ["resource.tokens"] to the route (or to the module declaring '
                . 'it): a module\'s register() only runs when it is in the request\'s dependency '
                . 'graph, so the filter alias resolving does not imply its bindings exist. This '
                . 'fails the request rather than allowing it, because the alternative is serving '
                . 'the protected resource to everybody.',
            );
        }

        $service = $container->make(ResourceTokenContract::class);

        if (!$service instanceof ResourceTokenContract) {
            throw new \RuntimeException(
                'The resource.tokens binding did not produce a ResourceTokenContract.',
            );
        }

        return $service;
    }

    /**
     * ONE response for every refusal.
     *
     * 404 by default, and the message says nothing about tokens: an invalid
     * token is then indistinguishable from a resource that does not exist, so
     * a caller holding a guessed token learns neither that the resource is
     * real nor which part of the check failed.
     */
    private function refuse(ResourceTokenConfig $config): Response
    {
        if ($config->refusedStatus === 404) {
            return Response::notFound();
        }

        return Response::json(
            ['error' => ['code' => 'not_found', 'message' => 'Resource not found.']],
            $config->refusedStatus,
        );
    }

    /**
     * Record a refusal, if there is anywhere to record it.
     *
     * Optional, because a LoggerPort may not be bound — but a refusal nobody
     * can see makes "the link doesn't work" unanswerable, since the response is
     * deliberately identical for all seven reasons. The README says this out
     * loud: without a LoggerPort, this plugin's refusals leave no trace.
     */
    private function log(Request $request, string $message): void
    {
        $container = $request->container();

        if ($container === null || !$container->has(LoggerPort::class)) {
            return;
        }

        $logger = $container->make(LoggerPort::class);

        if (!$logger instanceof LoggerPort) {
            return;
        }

        $logger->warning($message, [
            'path'   => $request->path(),
            'method' => $request->method(),
        ]);
    }
}
