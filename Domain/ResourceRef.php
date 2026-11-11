<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Domain;

/**
 * WHICH resource a token opens. A type and an id, nothing else.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THIS IS THE WHOLE POINT OF THE PLUGIN, SO IT IS THE FIRST CLASS
 * ────────────────────────────────────────────────────────────────────────────
 *
 * A resource token is not a credential for the application; it opens ONE
 * resource. The failure mode this class exists to prevent is the one that reads
 * as working code:
 *
 *   ✗ $token = $tokens->find($request->query('token'));
 *     if ($token !== null) { return $this->serve($request->param('id')); }
 *
 * That looks like a check and is not one. The resource came from the URL and the
 * token was never compared against it, so a token minted for the cheapest file
 * in the system opens every file in the system. It is the confused deputy, and
 * the only reliable defence is that verification CANNOT be called without
 * saying which resource is expected — which is why `verify()` takes one of
 * these and there is no `find()` on the contract at all.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE TYPE IS CANONICAL; THE ID IS OPAQUE
 * ────────────────────────────────────────────────────────────────────────────
 *
 * They are treated differently ON PURPOSE.
 *
 * The TYPE is validated into one canonical spelling — lowercase, starting with
 * a letter. It is a name the application chooses once and writes in two places
 * (where a token is minted, and where it is verified), so a mismatch between
 * `File` and `file` would be a token that can never be used, discovered in
 * production. Refusing the non-canonical spelling turns that into an exception
 * the first time the code runs.
 *
 * The ID is compared BYTE FOR BYTE and never normalised. Ids are opaque: a
 * UUID, a ULID, a slug, a signed external key. Lowercasing one would conflate
 * two different resources in any id space that is case-sensitive, and
 * conflating two resources is precisely the bug above. So the asymmetry is
 * deliberate: a strict type is a developer error caught immediately, while a
 * normalised id is a security hole discovered later.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NO COMPOSITE KEY
 * ────────────────────────────────────────────────────────────────────────────
 *
 * The type and the id are stored and compared as TWO columns, and {@see label()}
 * is for messages only. The reason is the paragraph above: the id is OPAQUE. A
 * single `type:id` key needs a separator, and a separator is a character the id
 * may not contain — so storing them together would impose a constraint on the
 * application's own identifiers, for this plugin's convenience, and an
 * application whose ids already contain that character would have to escape
 * them on the way in and unescape on the way out, in two places, correctly.
 *
 * As it happens the label IS unambiguous, because a type cannot contain a colon
 * — but nothing parses it back, so that is a property and not a design.
 */
final readonly class ResourceRef
{
    /**
     * Lowercase, letter-first, then letters/digits/dot/dash/underscore.
     *
     * Dot and dash are allowed because an application's own naming already uses
     * them (`storage.file`, `invoice-pdf`); a space or a slash is not, because
     * this value reaches a log line and a CLI argument.
     *
     * ─────────────────────────────────────────────────────────────────────
     * THE `D` MODIFIER IS LOAD-BEARING. WITHOUT IT, `"file\n"` PASSES.
     * ─────────────────────────────────────────────────────────────────────
     *
     * PCRE's `$` matches at the end of the subject OR immediately before a
     * final newline, so an anchored pattern is not anchored at all against a
     * value with a trailing `\n`. `D` (PCRE_DOLLAR_ENDONLY) removes that
     * second meaning.
     *
     * It was missing here, and a test for `"file\n"` is what found it: the
     * type was accepted, stored, and would have been printed into a log line
     * and a CLI table carrying a newline it could use to forge a line of
     * either. Every anchored pattern in this plugin now carries `D`, and
     * there is a test per pattern that passes a trailing newline.
     */
    private const TYPE_PATTERN = '/^[a-z][a-z0-9._-]*$/D';

    private const MAX_TYPE = 64;

    /**
     * 191, not 255.
     *
     * The id is half of an index, and a utf8mb4 column is four bytes per
     * character: 191 × 4 = 764, which fits the 767-byte index prefix limit that
     * MySQL installations on older row formats still enforce. Choosing 255 here
     * produces a migration that applies on some MySQL servers and fails on
     * others, for a reason that has nothing to do with this plugin.
     */
    private const MAX_ID = 191;

    private function __construct(
        public string $type,
        public string $id,
    ) {}

    /**
     * @throws \InvalidArgumentException when either part could not be stored,
     *         indexed or compared safely
     */
    public static function of(string $type, string $id): self
    {
        if ($type === '') {
            throw new \InvalidArgumentException('A resource type cannot be empty.');
        }

        if (\strlen($type) > self::MAX_TYPE) {
            throw new \InvalidArgumentException(\sprintf(
                'A resource type is at most %d characters; got %d.',
                self::MAX_TYPE,
                \strlen($type),
            ));
        }

        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Resource type [%s] is not canonical. Use lowercase, starting with a letter, '
                . 'then letters, digits, dot, dash or underscore — e.g. "file" or "invoice-pdf". '
                . 'The spelling is refused rather than corrected because the same name is written '
                . 'where a token is minted and where it is verified, and a silent correction would '
                . 'hide a mismatch until a token could not be used in production.',
                $type,
            ));
        }

        if ($id === '') {
            throw new \InvalidArgumentException(
                'A resource id cannot be empty. An empty id would make one token match every '
                . 'resource of its type that was also minted with an empty id.',
            );
        }

        if (\strlen($id) > self::MAX_ID) {
            throw new \InvalidArgumentException(\sprintf(
                'A resource id is at most %d characters; got %d.',
                self::MAX_ID,
                \strlen($id),
            ));
        }

        // The id reaches a log line, a CLI table and an error message. A CR or
        // an ESC in it could forge a line of either, and a NUL can truncate
        // C-side string handling — so it is refused at the door rather than
        // stripped, because stripping would silently change which resource is
        // meant.
        if (preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
            throw new \InvalidArgumentException(
                'A resource id cannot contain control characters.',
            );
        }

        return new self($type, $id);
    }

    /**
     * Same type, same id, byte for byte.
     *
     * `===` and not `hash_equals()`: neither part is a secret, and reaching for
     * a constant-time comparison here would suggest to the next reader that one
     * of them is.
     */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    /** For a message, a log line or a CLI table. NOT a key — see the class docblock. */
    public function label(): string
    {
        return $this->type . ':' . $this->id;
    }
}
