<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Domain;

/**
 * The one and only time the secret exists.
 *
 * Returned by `mint()`, carrying the stored row and the raw secret. After this
 * object is discarded the secret is unrecoverable — not withheld, GONE: only
 * its SHA-256 was stored. Hand it to whoever the link is for, or mint another.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE SECRET IS KEPT OUT OF STACK TRACES AND VAR_DUMP, ON PURPOSE
 * ────────────────────────────────────────────────────────────────────────────
 *
 * A credential does not have to be logged deliberately to end up in a log. PHP
 * puts constructor ARGUMENTS in an exception's stack trace, and
 * `zend.exception_ignore_args` is `0` in PHP's own built-in defaults — it is
 * `1` only in `php.ini-production`, which plenty of deployments never copy. So
 * on a default build, any exception thrown anywhere below this constructor
 * carries the raw token in `getTraceAsString()`, and that string is precisely
 * what an error reporter mails, posts to Slack and writes to a file.
 *
 * Two measures, both small:
 *
 *   `#[\SensitiveParameter]` on the constructor argument. PHP replaces it with
 *   `Object(SensitiveParameterValue)` in every trace, regardless of the ini
 *   setting. There is a test that throws below the constructor and asserts the
 *   value is absent from the trace, because this is behaviour and not decoration.
 *
 *   `__debugInfo()` so `var_dump()` and anything built on it — a debug bar, a
 *   dd() helper, an error page's variable panel — prints the length and not the
 *   value.
 *
 * Neither is a substitute for not passing it around. The secret belongs in the
 * response that answers the mint request, and nowhere else.
 */
final readonly class MintedToken
{
    public function __construct(
        public ResourceToken $token,
        #[\SensitiveParameter]
        private string $secret,
    ) {}

    /**
     * The raw secret. Show it once.
     *
     * A method rather than a public property so that reading it is a visible
     * act in a diff — `$minted->secret()` greps, and a property that happens to
     * be interpolated into a template does not.
     */
    public function secret(): string
    {
        return $this->secret;
    }

    public function id(): string
    {
        return $this->token->id;
    }

    /** The row, safe to log. Never the secret — see {@see ResourceToken::describe()}. */
    public function describe(\DateTimeImmutable $now): array
    {
        return $this->token->describe($now);
    }

    /**
     * What `var_dump()` prints.
     *
     * The length is kept because "did I get a secret at all" is the question
     * somebody is dumping this to answer.
     */
    public function __debugInfo(): array
    {
        return [
            'token'  => $this->token,
            'secret' => '[redacted: ' . \strlen($this->secret) . ' characters]',
        ];
    }
}
