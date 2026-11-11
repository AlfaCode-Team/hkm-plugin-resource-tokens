<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Domain;

/**
 * WHAT a token may do to the resource it opens.
 *
 * The resource is the hard boundary; this is the soft one inside it. A token
 * handed out so a browser can render a private image should not also be able to
 * replace that image, and the caller that mints it has to be able to say so
 * without inventing its own convention.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE DEFAULT IS `read`, AND IT IS NOT `*`
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Every omitted argument in a security API is a decision made by whoever wrote
 * the default. `['read']` is the narrowest thing a token can usefully be, and
 * the overwhelmingly common case — a link that lets somebody fetch one thing.
 * A default of `*` would mean that `mint($file)` with no further thought handed
 * out a token that could delete the file.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * `*` IS ALLOWED, BUT ONLY ALONE
 * ────────────────────────────────────────────────────────────────────────────
 *
 * `['*', 'read']` is refused rather than collapsed to `['*']`. It is not a
 * typo worth guessing at: it means the author believed the list was additive
 * and that writing `read` next to `*` narrowed something. Accepting it would
 * confirm that belief and the next list would be `['*', 'read']` where
 * `['read']` was intended.
 *
 * Note what `*` does NOT mean: it is still bounded by the resource. A `*`
 * resource token is "anything to THIS file", never "anything".
 */
final readonly class TokenAbilities
{
    /** Every ability, for this resource only. */
    public const ALL = '*';

    /** The default, and the narrowest useful grant. */
    public const READ = 'read';

    /**
     * `D` because PCRE's `$` also matches immediately before a final newline,
     * so without it `"read\n"` would be accepted as canonical. See the longer
     * note on {@see \Plugins\ResourceTokens\Domain\ResourceRef}.
     */
    private const PATTERN = '/^[a-z][a-z0-9._:-]*$/D';

    private const MAX_LENGTH = 64;

    private const MAX_COUNT = 32;

    /** @param list<string> $abilities non-empty, canonical, de-duplicated, sorted */
    private function __construct(public array $abilities) {}

    /**
     * @param list<string> $abilities
     *
     * @throws \InvalidArgumentException on an empty list, a non-canonical name,
     *         or `*` mixed with anything else
     */
    public static function of(array $abilities): self
    {
        if ($abilities === []) {
            throw new \InvalidArgumentException(
                'A token needs at least one ability. An empty list is not "everything" and not '
                . '"nothing in particular" — it is a token that can never be used, so it is '
                . 'refused at mint time rather than handed to somebody.',
            );
        }

        if (\count($abilities) > self::MAX_COUNT) {
            throw new \InvalidArgumentException(\sprintf(
                'A token carries at most %d abilities; got %d.',
                self::MAX_COUNT,
                \count($abilities),
            ));
        }

        foreach ($abilities as $ability) {
            if ($ability === self::ALL) {
                continue;
            }

            if ($ability === '' || \strlen($ability) > self::MAX_LENGTH) {
                throw new \InvalidArgumentException(\sprintf(
                    'An ability is between 1 and %d characters; got %d.',
                    self::MAX_LENGTH,
                    \strlen($ability),
                ));
            }

            if (preg_match(self::PATTERN, $ability) !== 1) {
                throw new \InvalidArgumentException(\sprintf(
                    'Ability [%s] is not canonical. Use lowercase, starting with a letter — '
                    . 'e.g. "read", "download", "file.replace".',
                    $ability,
                ));
            }
        }

        $unique = array_values(array_unique($abilities));

        if (\in_array(self::ALL, $unique, true) && \count($unique) > 1) {
            throw new \InvalidArgumentException(
                'Ability "*" cannot be combined with a named ability. "*" already includes every '
                . 'ability for this resource, so a list containing both means the author expected '
                . 'the names to NARROW it — which they do not. Write ["*"], or write the names.',
            );
        }

        // Sorted so two equivalent lists are stored identically. Without it the
        // same grant, written in two orders, produces two different JSON
        // columns and a diff of two rows reads as a difference in permissions.
        sort($unique);

        return new self($unique);
    }

    /** The default grant: read, and nothing else. */
    public static function readOnly(): self
    {
        return new self([self::READ]);
    }

    /** Every ability, for this resource only. */
    public static function all(): self
    {
        return new self([self::ALL]);
    }

    public function permits(string $ability): bool
    {
        // The wildcard is checked first because it is the cheap answer, not
        // because it is the common case.
        if (\in_array(self::ALL, $this->abilities, true)) {
            return true;
        }

        // Exact match only. No prefix matching: if `file.read` implied
        // `file.read.metadata`, then granting `file` would grant everything
        // beginning with it, and the day somebody added a `file.delete` ability
        // every existing `file`-prefixed token would quietly acquire it.
        return \in_array($ability, $this->abilities, true);
    }

    public function isUnrestricted(): bool
    {
        return \in_array(self::ALL, $this->abilities, true);
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->abilities;
    }

    public function label(): string
    {
        return implode(',', $this->abilities);
    }
}
