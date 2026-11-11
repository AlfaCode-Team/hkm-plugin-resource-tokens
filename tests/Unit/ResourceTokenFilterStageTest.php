<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Unit;

use AlfacodeTeam\Ground\Fakes\FrozenClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\FilterRegistry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;
use Plugins\ResourceTokens\Application\Services\ResourceTokenService;
use Plugins\ResourceTokens\Domain\MintedToken;
use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\ResourceToken;
use Plugins\ResourceTokens\Domain\TokenAbilities;
use Plugins\ResourceTokens\Infrastructure\Config\ResourceTokenConfig;
use Plugins\ResourceTokens\Infrastructure\Http\Stages\ResourceTokenFilterStage;
use Tests\Plugins\ResourceTokens\Support\InMemoryTokenStore;

/**
 * The `restoken` route filter.
 *
 * This is where a mistake is most expensive, because it is the only surface
 * that can serve a protected resource by accident. Three things are therefore
 * tested as hard as the verification itself:
 *
 *   - the resource comes from the MATCHED ROUTE, never from the request body or
 *     a header the caller controls;
 *   - a route that forgot `requires: ["resource.tokens"]` FAILS rather than
 *     passing;
 *   - every refusal produces the same response, so the presenter cannot tell
 *     an expired token from a resource that does not exist.
 */
#[CoversClass(ResourceTokenFilterStage::class)]
final class ResourceTokenFilterStageTest extends TestCase
{
    private InMemoryTokenStore $store;

    private FrozenClock $clock;

    private ResourceTokenService $service;

    protected function setUp(): void
    {
        $this->store   = new InMemoryTokenStore();
        $this->clock   = new FrozenClock('2026-06-01 12:00:00');
        $this->service = new ResourceTokenService($this->store, ResourceTokenConfig::of(), $this->clock);
    }

    /**
     * A request as it reaches the `after.load` position: route resolved, route
     * params attached, container built.
     *
     * @param array<string, string> $routeParams
     * @param list<string>          $filterArgs
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private function request(
        array $routeParams = ['id' => 'f-1'],
        array $filterArgs = ['file', 'id'],
        array $headers = [],
        array $query = [],
        bool $bindService = true,
        ?LoggerPort $logger = null,
    ): Request {
        $core = new CoreContainer();

        if ($logger !== null) {
            $core->instance(LoggerPort::class, $logger);
        }

        $container = new ModuleContainer($core);

        if ($bindService) {
            $container->bind(ResourceTokenContract::class, fn (): ResourceTokenService => $this->service);
        }

        return Request::build(method: 'GET', path: '/files/f-1/raw', headers: $headers, query: $query)
            ->withAttribute('route_params', $routeParams)
            ->withAttribute('active_filters', [ResourceTokenFilterStage::ALIAS])
            ->withAttribute('filter_args', [ResourceTokenFilterStage::ALIAS => $filterArgs])
            ->withContainer($container);
    }

    /** Run the stage; the downstream handler reports that it was reached. */
    private function runStage(Request $request): Response
    {
        return (new ResourceTokenFilterStage())->handle(
            $request,
            static fn (Request $r): Response => Response::json([
                'reached' => true,
                // Proof the verdict's token is handed downstream, so a
                // controller need not verify a second time and spend a second
                // use of a single-use token.
                'token'   => $r->attribute('resource_token') instanceof ResourceToken
                    ? $r->attribute('resource_token')->id
                    : null,
            ]),
        );
    }

    private function mint(string $resourceId = 'f-1', ?TokenAbilities $abilities = null): MintedToken
    {
        return $this->service->mint(
            ResourceRef::of('file', $resourceId),
            abilities: $abilities,
        );
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_a_valid_token_in_the_header_reaches_the_handler(): void
    {
        $minted = $this->mint();

        $response = $this->runStage($this->request(
            headers: ['X-Resource-Token' => $minted->secret()],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"reached":true', (string) $response->getContent());
    }

    public function test_a_valid_token_in_the_query_string_also_works(): void
    {
        // Supported because it is the only option for the case this plugin
        // exists for: a browser fetching an <img src> cannot set a header.
        $minted = $this->mint();

        $response = $this->runStage($this->request(query: ['token' => $minted->secret()]));

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_the_header_is_preferred_over_the_query_string(): void
    {
        // The header first, because a query string is written to a web server's
        // access log, kept in browser history and sent onward in a Referer.
        // A caller that can set both should have the safer one honoured.
        $good = $this->mint();

        $response = $this->runStage($this->request(
            headers: ['X-Resource-Token' => $good->secret()],
            query:   ['token' => str_repeat('0', 64)],
        ));

        self::assertSame(200, $response->getStatusCode(), 'the header won');
    }

    public function test_the_verdicts_token_is_handed_downstream(): void
    {
        $minted = $this->mint();

        $response = $this->runStage($this->request(
            headers: ['X-Resource-Token' => $minted->secret()],
        ));

        self::assertStringContainsString('"token":"' . $minted->id() . '"', (string) $response->getContent());
    }

    public function test_the_handler_is_reached_exactly_once_so_one_use_is_spent(): void
    {
        // A stage registered as BOTH a global hook and a route filter would run
        // twice and spend two uses of a single-use token. This pins that one
        // pass through the filter is one use.
        $minted = $this->service->mint(ResourceRef::of('file', 'f-1'), maxUses: 1);

        self::assertSame(200, $this->runStage($this->request(
            headers: ['X-Resource-Token' => $minted->secret()],
        ))->getStatusCode());

        self::assertSame(1, $this->store->get($minted->id())?->uses);
    }

    // ── The resource comes from the route ────────────────────────────────────

    public function test_a_token_for_another_resource_is_refused(): void
    {
        // THE property the filter exists for. The resource is the one the
        // router already resolved, so no token can reach a controller for a
        // resource it was not minted for.
        $minted = $this->mint('f-1');

        $response = $this->runStage($this->request(
            routeParams: ['id' => 'f-2'],
            headers:     ['X-Resource-Token' => $minted->secret()],
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_the_resource_type_comes_from_the_manifest_and_not_the_request(): void
    {
        // The type is written in module.json; nothing a caller sends can change
        // it. Otherwise two resource types sharing an id space — a file and an
        // invoice both keyed by the same UUID — would be interchangeable.
        $minted = $this->service->mint(ResourceRef::of('invoice', 'f-1'));

        $response = $this->runStage($this->request(
            filterArgs: ['file', 'id'],
            headers:    ['X-Resource-Token' => $minted->secret()],
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_the_ability_can_be_named_as_a_third_argument(): void
    {
        $readOnly = $this->mint('f-1', TokenAbilities::readOnly());

        self::assertSame(200, $this->runStage($this->request(
            filterArgs: ['file', 'id', 'read'],
            headers:    ['X-Resource-Token' => $readOnly->secret()],
        ))->getStatusCode());

        self::assertSame(404, $this->runStage($this->request(
            filterArgs: ['file', 'id', 'write'],
            headers:    ['X-Resource-Token' => $readOnly->secret()],
        ))->getStatusCode());
    }

    public function test_the_ability_defaults_to_read(): void
    {
        $readOnly = $this->mint('f-1', TokenAbilities::readOnly());

        self::assertSame(200, $this->runStage($this->request(
            filterArgs: ['file', 'id'],
            headers:    ['X-Resource-Token' => $readOnly->secret()],
        ))->getStatusCode());
    }

    // ── Every refusal looks the same ─────────────────────────────────────────

    public function test_every_refusal_produces_the_same_response(): void
    {
        // Telling the presenter which check failed is free reconnaissance: an
        // expiry confirms the token was real and the resource exists, "wrong
        // resource" confirms the token is live and invites trying every id,
        // "exhausted" says the recipient already collected the file.
        $expired = $this->service->mint(ResourceRef::of('file', 'f-1'), ttlSeconds: 1);
        $revoked = $this->mint();
        $this->service->revoke($revoked->id());
        $wrong   = $this->mint('f-9');

        $this->clock->travelSeconds(5);

        $responses = [];

        foreach ([
            'nothing presented' => '',
            'malformed'         => 'not-a-token',
            'unknown'           => str_repeat('a', 64),
            'expired'           => $expired->secret(),
            'revoked'           => $revoked->secret(),
            'wrong resource'    => $wrong->secret(),
        ] as $why => $presented) {
            $response = $this->runStage($this->request(
                headers: $presented === '' ? [] : ['X-Resource-Token' => $presented],
            ));

            $responses[$why] = [$response->getStatusCode(), (string) $response->getContent()];
        }

        self::assertCount(
            1,
            array_unique(array_map(static fn (array $r): string => $r[0] . '|' . $r[1], $responses)),
            'all six refusals must be byte-identical: ' . json_encode(array_keys($responses)),
        );
        self::assertSame(404, $responses['expired'][0]);
    }

    public function test_the_refusal_body_says_nothing_about_tokens(): void
    {
        // 404 and "Resource not found" — so an invalid token is
        // indistinguishable from a resource that is not there, and a caller
        // holding a guessed token learns neither that the resource is real nor
        // which part of the check failed.
        $body = (string) $this->runStage($this->request(
            headers: ['X-Resource-Token' => str_repeat('b', 64)],
        ))->getContent();

        foreach (['token', 'expired', 'revoked', 'ability', 'resource_token'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $body, $leak);
        }
    }

    public function test_the_refusal_status_is_configurable_within_4xx(): void
    {
        // Through the environment, which the stage reads itself. Checked here
        // because the stage memoizes the config, and a memoized value read from
        // the wrong place would be read once and wrong forever.
        $_ENV['RESOURCE_TOKENS_REFUSED_STATUS'] = '403';

        try {
            $response = $this->runStage($this->request(
                headers: ['X-Resource-Token' => str_repeat('c', 64)],
            ));

            self::assertSame(403, $response->getStatusCode());
        } finally {
            unset($_ENV['RESOURCE_TOKENS_REFUSED_STATUS']);
        }
    }

    // ── Misconfiguration must be loud ────────────────────────────────────────

    public function test_a_route_missing_requires_fails_rather_than_passing(): void
    {
        // A module's register() only runs when it is in that request's
        // dependency graph, so the filter alias resolving does NOT imply the
        // bindings exist. Failing open here would turn one missing line of JSON
        // into a private file served to anybody who asked.
        $minted = $this->mint();

        try {
            $this->runStage($this->request(
                headers:     ['X-Resource-Token' => $minted->secret()],
                bindService: false,
            ));
            self::fail('a route without requires[] must not pass');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('"requires": ["resource.tokens"]', $e->getMessage());
            self::assertStringContainsString('register()', $e->getMessage());
        }
    }

    public function test_a_filter_with_no_arguments_fails_loudly(): void
    {
        // Defaulting the type would compare every token against the same empty
        // type, and the filter would then either refuse everything or — far
        // worse — match a token minted for a different resource that happened
        // to share an id.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/needs a resource type and a route parameter/');

        $this->runStage($this->request(filterArgs: []));
    }

    public function test_a_filter_naming_only_a_type_fails_loudly(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->runStage($this->request(filterArgs: ['file']));
    }

    public function test_a_filter_naming_a_parameter_the_route_does_not_have_fails_loudly(): void
    {
        try {
            $this->runStage($this->request(
                routeParams: ['slug' => 'x'],
                filterArgs:  ['file', 'id'],
            ));
            self::fail('a parameter the path does not declare must be reported');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('{id}', $e->getMessage());
            // The message lists what the route DOES declare, which is the one
            // piece of information that makes the typo obvious.
            self::assertStringContainsString('{slug}', $e->getMessage());
        }
    }

    public function test_an_empty_route_parameter_is_refused_not_reported(): void
    {
        // An optional `{id?}` that matched nothing. The route matched, so this
        // is a request-shaped problem rather than a manifest one — and the
        // presenter must not learn the difference between "bad id" and "bad
        // token".
        $response = $this->runStage($this->request(
            routeParams: ['id' => ''],
            headers:     ['X-Resource-Token' => $this->mint()->secret()],
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    // ── Logging ──────────────────────────────────────────────────────────────

    public function test_a_refusal_is_logged_with_its_reason(): void
    {
        // The ONLY place the reason is recorded, since the response is
        // deliberately identical for every one of them. Without this, "the
        // link doesn't work" is unanswerable.
        $logger = new RecordingLogger();
        $wrong  = $this->mint('f-9');

        $this->runStage($this->request(
            headers: ['X-Resource-Token' => $wrong->secret()],
            logger:  $logger,
        ));

        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString('wrong_resource', $logger->warnings[0]);
        self::assertStringContainsString($wrong->id(), $logger->warnings[0], 'which token it was');
        self::assertStringContainsString('file:f-9', $logger->warnings[0], 'and what it was for');
    }

    public function test_a_log_line_never_carries_the_presented_token(): void
    {
        $logger = new RecordingLogger();
        $wrong  = $this->mint('f-9');

        $this->runStage($this->request(
            headers: ['X-Resource-Token' => $wrong->secret()],
            logger:  $logger,
        ));

        self::assertStringNotContainsString($wrong->secret(), $logger->warnings[0]);
    }

    public function test_a_granted_request_is_not_logged(): void
    {
        // A log entry per successful fetch would turn an <img src> token into a
        // log line per image view, which is how a warning channel becomes
        // unreadable.
        $logger = new RecordingLogger();

        $this->runStage($this->request(
            headers: ['X-Resource-Token' => $this->mint()->secret()],
            logger:  $logger,
        ));

        self::assertSame([], $logger->warnings);
    }

    public function test_a_refusal_without_a_logger_bound_still_refuses(): void
    {
        // It just leaves no trace, which the README says out loud.
        $response = $this->runStage($this->request(
            headers: ['X-Resource-Token' => str_repeat('d', 64)],
            logger:  null,
        ));

        self::assertSame(404, $response->getStatusCode());
    }

    // ── How the kernel builds this stage ─────────────────────────────────────

    public function test_the_stage_can_be_built_with_no_constructor_arguments(): void
    {
        // FilterRegistry::resolve() does
        // `$core->has($class) ? $core->make($class) : new $class()`, so a stage
        // with required constructor arguments that is not bound in the
        // CoreContainer is constructed with NONE and dies with an
        // ArgumentCountError on the first request that touches the route.
        //
        // Asserted through the registry rather than by calling `new` directly,
        // because it is the registry's behaviour that imposes the constraint.
        $registry = new FilterRegistry();
        $registry->register(ResourceTokenFilterStage::ALIAS, ResourceTokenFilterStage::class);

        $stage = $registry->resolve(ResourceTokenFilterStage::ALIAS, new CoreContainer());

        self::assertInstanceOf(ResourceTokenFilterStage::class, $stage);
    }

    public function test_one_memoized_stage_serves_two_requests_with_different_containers(): void
    {
        // The registry memoizes a stage for the life of the worker, so it can
        // hold nothing request-scoped. If it captured the service — or the
        // container it came from — then under OpenSwoole the second request
        // would be served by the first request's bindings, which for a tenancy
        // deployment that rebinds DatabasePort per request means the first
        // tenant's database.
        $stage = new ResourceTokenFilterStage();

        $first  = $this->mint('f-1');
        $second = $this->service->mint(ResourceRef::of('file', 'f-2'));

        $next = static fn (Request $r): Response => Response::json(['ok' => true]);

        self::assertSame(200, $stage->handle(
            $this->request(routeParams: ['id' => 'f-1'], headers: ['X-Resource-Token' => $first->secret()]),
            $next,
        )->getStatusCode());

        self::assertSame(200, $stage->handle(
            $this->request(routeParams: ['id' => 'f-2'], headers: ['X-Resource-Token' => $second->secret()]),
            $next,
        )->getStatusCode());

        // And the first request's token still cannot reach the second's
        // resource through the reused stage.
        self::assertSame(404, $stage->handle(
            $this->request(routeParams: ['id' => 'f-2'], headers: ['X-Resource-Token' => $first->secret()]),
            $next,
        )->getStatusCode());
    }
}

/** A LoggerPort that keeps what it was told. */
final class RecordingLogger implements LoggerPort
{
    /** @var list<string> */
    public array $warnings = [];

    public function emergency(string|\Stringable $message, array $context = []): void {}

    public function alert(string|\Stringable $message, array $context = []): void {}

    public function critical(string|\Stringable $message, array $context = []): void {}

    public function error(string|\Stringable $message, array $context = []): void {}

    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = (string) $message;
    }

    public function notice(string|\Stringable $message, array $context = []): void {}

    public function info(string|\Stringable $message, array $context = []): void {}

    public function debug(string|\Stringable $message, array $context = []): void {}

    public function log(string $level, string|\Stringable $message, array $context = []): void {}
}
