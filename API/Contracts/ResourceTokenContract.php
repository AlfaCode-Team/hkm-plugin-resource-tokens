<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\API\Contracts;

use Plugins\ResourceTokens\Domain\MintedToken;
use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\ResourceToken;
use Plugins\ResourceTokens\Domain\TokenAbilities;
use Plugins\ResourceTokens\Domain\TokenVerdict;

/**
 * Mint, verify and withdraw a token that opens ONE resource.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS FOR
 * ────────────────────────────────────────────────────────────────────────────
 *
 * A caller with no account needs access to one thing: a browser rendering a
 * private image in an `<img src>`, a download link in an email, a PDF handed to
 * an auditor, a preview URL in a Slack message. None of those callers can hold
 * a session, and giving them one would be far more than they need.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT IT IS NOT: THIS PLUGIN NEVER AUTHENTICATES ANYBODY
 * ────────────────────────────────────────────────────────────────────────────
 *
 * It ships NO security layer and produces NO `Identity`. That is the boundary
 * that keeps it from being a back door, and it is worth stating because the
 * obvious next feature is exactly the wrong one: if a resource token minted an
 * Identity, then every token would be an account — one that passed the security
 * gateway, carried whatever roles the mapping gave it, and reached every route
 * in the application. A token would stop meaning "this one file" and start
 * meaning "logged in", with the resource it was minted for reduced to a comment.
 *
 * So verification is a question a controller or a route filter asks about ONE
 * resource it already knows the identity of. It grants nothing else and never
 * runs before a route is matched.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY NOT `signedRoute()`? BECAUSE YOU CANNOT TAKE ONE BACK
 * ────────────────────────────────────────────────────────────────────────────
 *
 * The kernel's `UrlGenerator::signedRoute()` is the right tool for a one-off
 * link and is cheaper than this in every way: no table, no query, no pruning.
 * Reach for it first. It is NOT the right tool when any of these is true, and
 * each of them is a direct consequence of it being stateless:
 *
 *   REVOCATION. A signed URL is valid until it expires because nothing records
 *   that it exists. There is no list to remove it from. "We mailed that link to
 *   the wrong address" has no answer short of rotating `APP_KEY`, which
 *   invalidates every signed URL in the application at once.
 *
 *   USE LIMITS. "This download link works once" cannot be expressed without
 *   somewhere to count, and counting is state.
 *
 *   AUDIT. Who minted it, when, what was it for, has it been used — all of it
 *   needs a row.
 *
 *   LISTING. "Show me every live link to this document" is the question that
 *   actually gets asked, usually during an incident.
 *
 * So the rule is: no state needed, use `signedRoute()`; any of the four above,
 * use this. The cost of this one is a row and a query per access, and that is
 * what the four properties cost.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY NOT THE AUTH PLUGIN'S PERSONAL ACCESS TOKENS?
 * ────────────────────────────────────────────────────────────────────────────
 *
 * A PAT belongs to a USER and carries abilities across the whole application —
 * it is that user acting through an API. These belong to a RESOURCE and carry
 * abilities over that resource alone, with no user anywhere; the recipient is
 * typically somebody with no account at all. The storage scheme here is
 * deliberately the same as that plugin's (random secret, SHA-256 digest, raw
 * value shown once), because the reasoning is the same and two first-party
 * plugins disagreeing about how to store a token would be a defect in one of them.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THERE ARE NO HTTP ROUTES, AND THAT IS NOT AN OMISSION
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Who may mint a token for a given resource is the single most important
 * authorization question here, and only the application can answer it — it is
 * the same question as "who may read this resource", which this plugin has no
 * way to evaluate. A shipped `POST /tokens` endpoint would have to either
 * refuse everybody, or trust a caller's claim about a resource it cannot check,
 * which is a privilege-escalation hole dressed as a convenience: mint a token
 * for somebody else's file, then use it.
 *
 * So the application exposes its own route, performs its own check, and calls
 * {@see mint()} once that check has passed.
 */
interface ResourceTokenContract
{
    /**
     * Pass as `$ttlSeconds` to mint a token that never expires.
     *
     * Deliberately NOT `0`. `0` is refused with a message pointing here,
     * because somebody writing `ttlSeconds: 0` almost certainly means "expires
     * immediately" — and if 0 meant "never", that reading would silently
     * produce the most dangerous token the API can make. A constant has to be
     * typed on purpose and reads correctly at the call site.
     */
    public const NEVER_EXPIRES = -1;

    /**
     * Mint a token for one resource, and return the secret ONCE.
     *
     * @param ResourceRef         $resource    what the token opens
     * @param int|null            $ttlSeconds  null = the configured default;
     *                                         a positive number of seconds;
     *                                         or {@see NEVER_EXPIRES}
     * @param TokenAbilities|null $abilities   null = read only
     * @param int|null            $maxUses     null = unlimited
     * @param string              $label       free text for a human
     * @param string              $createdBy   audit only; grants nothing
     *
     * @throws \Plugins\ResourceTokens\Infrastructure\Exceptions\ResourceTokenException
     *         on a refused lifetime, or when the resource already holds the
     *         configured maximum number of live tokens
     */
    public function mint(
        ResourceRef $resource,
        ?int $ttlSeconds = null,
        ?TokenAbilities $abilities = null,
        ?int $maxUses = null,
        string $label = '',
        string $createdBy = '',
    ): MintedToken;

    /**
     * May this presented value do `$ability` to `$expected`?
     *
     * A successful verification RECORDS A USE. That is not a side effect to be
     * surprised by, it is the contract: a use limit cannot be enforced without
     * the check and the count being one operation. Calling this twice for one
     * request spends two uses of a single-use token.
     *
     * `$expected` is required, and there is no way to look a token up without
     * it. See {@see ResourceRef} for the bug that prevents.
     */
    public function verify(
        #[\SensitiveParameter] string $presented,
        ResourceRef $expected,
        string $ability = TokenAbilities::READ,
    ): TokenVerdict;

    /** Withdraw one token. False = no such token, or already revoked. */
    public function revoke(string $tokenId): bool;

    /**
     * Withdraw every token for a resource. Returns how many were revoked.
     *
     * The operation people actually need during an incident, and the one that
     * has no equivalent for a stateless signed URL.
     */
    public function revokeFor(ResourceRef $resource): int;

    /**
     * Every token minted for a resource, newest first, WITHOUT any secret.
     *
     * @return list<ResourceToken>
     */
    public function tokensFor(ResourceRef $resource): array;

    /** Delete settled rows past the configured retention. Returns how many went. */
    public function prune(): int;

    /** @return array<string, int> totals by state */
    public function counts(): array;

    /** A status line. Never a credential. */
    public function describe(): string;
}
