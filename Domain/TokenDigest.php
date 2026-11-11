<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Domain;

/**
 * How a token is minted, and how a presented one is turned into a lookup key.
 *
 * Minting and digesting live in ONE class because they are two halves of one
 * invariant: the verifier has to produce the same bytes the minter stored. Put
 * them in separate layers and the day somebody changes the secret's length or
 * alphabet, one half moves.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * SHA-256, AND DELIBERATELY NOT BCRYPT OR ARGON2
 * ────────────────────────────────────────────────────────────────────────────
 *
 * This looks wrong to anyone who has learned "never store a credential under a
 * fast hash", so it is worth being explicit. A slow, salted hash exists to make
 * an OFFLINE GUESSING ATTACK expensive, and guessing is only a threat when the
 * input space is small enough to search — which is what a human-chosen password
 * is. This secret is 32 bytes from `random_bytes()`: 2^256 candidates, no
 * dictionary, no reuse across sites, no pattern. There is nothing to guess.
 *
 * What a slow hash WOULD cost here is the lookup. Bcrypt and Argon2 are salted
 * per row, so the digest of a presented value cannot be computed without
 * knowing which row it belongs to — which means either a plaintext column to
 * find the row by, or verifying the presented secret against EVERY row in the
 * table. Both are worse than this: the first defeats the hashing entirely, and
 * the second turns one indexed lookup into a linear scan with a deliberately
 * expensive operation per row, which is a denial-of-service endpoint anyone can
 * dial by sending junk. This is the same scheme the Auth plugin uses for
 * personal access tokens, for the same reasons.
 *
 * So the properties actually relied on are the ones SHA-256 has: it is
 * one-way (a stolen table cannot be turned back into usable tokens), it is
 * deterministic (one indexed lookup), and it is collision-resistant (two
 * distinct secrets cannot map to one row).
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THERE IS NO SECRET COMPARISON ANYWHERE IN THIS PLUGIN
 * ────────────────────────────────────────────────────────────────────────────
 *
 * A presented value is HASHED and the hash is used as a WHERE key. Nothing ever
 * compares a stored secret with a given one, so the usual
 * `hash_equals`-versus-`===` hazard does not arise — there is no comparison to
 * get wrong. That is not a happy accident; it is the reason the lookup is
 * shaped this way.
 */
final class TokenDigest
{
    public const ALGORITHM = 'sha256';

    /** 32 bytes = 256 bits of entropy. */
    public const SECRET_BYTES = 32;

    /** What {@see mint()} returns: 32 bytes, hex-encoded. */
    public const SECRET_LENGTH = 64;

    /** What {@see of()} returns: SHA-256, hex-encoded. */
    public const DIGEST_LENGTH = 64;

    /**
     * Enough of a digest to correlate a leaked token with its row, and useless
     * for anything else. See {@see ResourceToken::describe()}.
     */
    public const PREFIX_LENGTH = 12;

    /**
     * A new token secret: 64 lowercase hex characters.
     *
     * HEX, not base64url. These values travel in a query string, so the
     * alphabet has to survive being URL-encoded, logged, copied out of a
     * terminal and pasted into a form, without any of those steps changing it.
     * Base64url is shorter and gets there too, but `+`, `/` and `=` creep back
     * in through any encoder that is not strictly base64URL, and the failure is
     * a token that works for most people.
     */
    public static function mint(): string
    {
        return bin2hex(random_bytes(self::SECRET_BYTES));
    }

    /**
     * The storage and lookup key for a secret.
     *
     * `#[\SensitiveParameter]` keeps the raw value out of stack traces: without
     * it, any exception thrown below this frame carries the token in
     * `getTraceAsString()`, and that string is exactly what an error reporter
     * mails, posts to Slack and writes to a log file.
     */
    public static function of(#[\SensitiveParameter] string $secret): string
    {
        return hash(self::ALGORITHM, $secret);
    }

    /**
     * Does this even look like one of our tokens?
     *
     * A cheap shape check, run BEFORE the database is touched, so that an
     * endpoint being sprayed with junk costs a `strlen` and a regex rather than
     * an indexed query per request. It is a filter, never a decision: a value
     * that passes has proved nothing at all.
     *
     * The shape is tied to {@see mint()} on purpose. If the mint format ever
     * changes, this has to accept BOTH shapes for as long as any token of the
     * old shape is still valid — otherwise every outstanding token is refused
     * as malformed at the moment of deploy, which looks like a database problem
     * and is not one.
     */
    public static function isPlausible(#[\SensitiveParameter] string $presented): bool
    {
        // `D` for the same reason every anchored pattern in this plugin carries
        // it: PCRE's `$` also matches immediately before a final newline. Here
        // the length check already closes that door — 64 hex characters plus a
        // newline is 65 — so this one is belt and braces rather than a fix.
        return \strlen($presented) === self::SECRET_LENGTH
            && preg_match('/^[0-9a-f]{' . self::SECRET_LENGTH . '}$/D', $presented) === 1;
    }

    /** The first {@see PREFIX_LENGTH} characters of a digest. */
    public static function prefix(string $digest): string
    {
        return substr($digest, 0, self::PREFIX_LENGTH);
    }
}
