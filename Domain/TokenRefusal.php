<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Domain;

/**
 * Why a token was refused — for the LOG, never for the caller.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE CALLER GETS ONE ANSWER; THE OPERATOR GETS THE REASON
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Telling the presenter which of these happened is free reconnaissance. "This
 * token expired" confirms the token was real and that the resource exists;
 * "wrong resource" confirms the token is live and invites trying it against
 * every id in range; "exhausted" says the link was used, which in a
 * single-use-download flow tells an attacker the recipient already collected
 * the file. So every one of these produces the SAME response to the presenter —
 * {@see \Plugins\ResourceTokens\Infrastructure\Http\Stages\ResourceTokenFilterStage}
 * answers 404 for all of them by default, which also makes an invalid token
 * indistinguishable from a resource that does not exist.
 *
 * The operator needs the opposite. "Nothing works" is the only symptom a user
 * will ever report, and these five cases have five different fixes. So the
 * reason is carried on the verdict, logged, and never serialised into a
 * response body.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE ORDER OF THE CASES IS THE ORDER THEY ARE CHECKED IN
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Every one of them refuses, so the order changes nothing about safety — it
 * changes only what an operator is told when more than one applies. They are
 * ordered by how strongly they suggest somebody is TRYING THINGS, because that
 * is the reading worth surfacing first:
 *
 *   WrongResource  — a live token aimed at a resource it was not minted for.
 *                    The only case here that is a probe rather than an
 *                    expiry, and the one worth an alert.
 *   MissingAbility — a live token aimed at an operation it was not granted.
 *   Revoked        — somebody deliberately withdrew this token and it is
 *                    still in circulation.
 *   Expired        — ordinary, and the overwhelmingly common one.
 *   Exhausted      — ordinary: a single-use link used twice.
 *   Unknown        — no such token. Also what a guessed value produces.
 *   Malformed      — not even the right shape; refused without a query.
 */
enum TokenRefusal: string
{
    case Malformed      = 'malformed';
    case Unknown        = 'unknown';
    case WrongResource  = 'wrong_resource';
    case MissingAbility = 'missing_ability';
    case Revoked        = 'revoked';
    case Expired        = 'expired';
    case Exhausted      = 'exhausted';

    /** One line for an operator, in a log. Never sent to the presenter. */
    public function explain(): string
    {
        return match ($this) {
            self::Malformed      => 'not the shape of a token — refused without a database lookup',
            self::Unknown        => 'no token with that digest; it was never minted, or it has been pruned',
            self::WrongResource  => 'a valid token for a DIFFERENT resource — somebody is trying it elsewhere',
            self::MissingAbility => 'a valid token without the ability this operation needs',
            self::Revoked        => 'revoked, and still in circulation',
            self::Expired        => 'past its expiry',
            self::Exhausted      => 'already used the maximum number of times',
        };
    }

    /**
     * Does this reading suggest somebody is probing rather than using a link
     * that simply ran out?
     *
     * Separated so an application can route these two groups differently: one
     * belongs in an alert, the other is the ordinary life of a link.
     */
    public function suggestsProbing(): bool
    {
        return $this === self::WrongResource || $this === self::MissingAbility;
    }
}
