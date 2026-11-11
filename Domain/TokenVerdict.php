<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Domain;

/**
 * The answer to "may this presented value open this resource?"
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY A VERDICT OBJECT AND NOT A BOOL OR AN EXCEPTION
 * ────────────────────────────────────────────────────────────────────────────
 *
 * A bool loses the reason, and the reason is the only thing an operator has to
 * work with when a user reports "the link doesn't work".
 *
 * An exception would be worse: refusing a token is the ORDINARY path here, not
 * an error. Expired links are the normal end of a link's life, and an
 * application that throws on each one fills its error reporter with events that
 * are working as designed, which is how a real alert gets missed. The kernel's
 * own `SecurityVerdict` is shaped this way for the same reason, and this
 * follows it deliberately.
 *
 * So: a verdict carries the decision for the caller, and the reason for the
 * log. {@see TokenRefusal} documents why the reason must not reach the
 * presenter.
 */
final readonly class TokenVerdict
{
    private function __construct(
        public bool $granted,
        /**
         * The row, when there was one. Present on some refusals — a token
         * refused for the WRONG RESOURCE is a real token, and an operator
         * investigating needs to know which one it was.
         */
        public ?ResourceToken $token,
        public ?TokenRefusal $refusal,
    ) {}

    public static function granted(ResourceToken $token): self
    {
        return new self(true, $token, null);
    }

    public static function refused(TokenRefusal $refusal, ?ResourceToken $token = null): self
    {
        return new self(false, $token, $refusal);
    }

    /**
     * The row's id, or '' when the value matched no row.
     *
     * Not nullable, because this exists to be interpolated into a log line and
     * every call site would otherwise need the same `?? ''`.
     */
    public function tokenId(): string
    {
        return $this->token?->id ?? '';
    }

    public function resourceLabel(): string
    {
        return $this->token?->resource->label() ?? '';
    }

    /**
     * One line for a log. Safe: no secret, and no digest beyond the prefix.
     *
     * Shaped for an operator reading a log, not for a parser — the structured
     * form is `$verdict->refusal->value` plus `$verdict->tokenId()`.
     */
    public function logLine(): string
    {
        if ($this->granted) {
            return \sprintf(
                'resource token %s granted for %s',
                $this->tokenId(),
                $this->resourceLabel(),
            );
        }

        $refusal = $this->refusal ?? TokenRefusal::Unknown;

        return \sprintf(
            'resource token refused (%s): %s%s',
            $refusal->value,
            $refusal->explain(),
            // Only when a row was actually found. Printing "token " with
            // nothing after it reads as a truncated log line.
            $this->token !== null
                ? \sprintf(' [token %s, minted for %s]', $this->tokenId(), $this->resourceLabel())
                : '',
        );
    }
}
