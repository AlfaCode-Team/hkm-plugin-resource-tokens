<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\ResourceTokens\Domain\ResourceRef;

/**
 * Which resource a token opens.
 *
 * The asymmetry between the type and the id is the subject of most of these:
 * the type is forced into one canonical spelling, and the id is treated as
 * opaque bytes. Getting that backwards is how two different resources come to
 * share a token.
 */
#[CoversClass(ResourceRef::class)]
final class ResourceRefTest extends TestCase
{
    public function test_a_type_and_an_id_make_a_reference(): void
    {
        $ref = ResourceRef::of('file', '3f2a9c');

        self::assertSame('file', $ref->type);
        self::assertSame('3f2a9c', $ref->id);
        self::assertSame('file:3f2a9c', $ref->label());
    }

    // ── The type is canonical ────────────────────────────────────────────────

    /** @return list<array{string, string}> */
    public static function nonCanonicalTypes(): array
    {
        return [
            ['File',        'an upper-case letter'],
            ['FILE',        'all upper case'],
            ['1file',       'starting with a digit'],
            ['_file',       'starting with an underscore'],
            ['my file',     'a space — it reaches a log line and a CLI argument'],
            ['my/file',     'a slash'],
            ['file!',       'punctuation'],
            ['',            'empty'],
            ["file\n",      'a newline'],
        ];
    }

    #[DataProvider('nonCanonicalTypes')]
    public function test_a_non_canonical_type_is_refused(string $type, string $why): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ResourceRef::of($type, 'id-1');
    }

    public function test_a_type_is_refused_rather_than_lower_cased(): void
    {
        // THE decision this class makes, and the reason it is not a
        // convenience. The same name is written in two places — where a token
        // is minted and where it is verified — so silently correcting `File` to
        // `file` would hide a mismatch until a token could not be used. The
        // exception happens the first time the code runs.
        try {
            ResourceRef::of('File', 'id-1');
            self::fail('an upper-case type must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not canonical', $e->getMessage());
            self::assertStringContainsString('minted', $e->getMessage(), 'the message says WHY');
        }
    }

    /** @return list<array{string}> */
    public static function canonicalTypes(): array
    {
        return [['file'], ['f'], ['invoice-pdf'], ['storage.file'], ['a_b'], ['x1']];
    }

    #[DataProvider('canonicalTypes')]
    public function test_a_canonical_type_is_accepted(string $type): void
    {
        self::assertSame($type, ResourceRef::of($type, 'id-1')->type);
    }

    public function test_a_type_longer_than_the_column_is_refused(): void
    {
        // Refused here rather than truncated by the database. MySQL in
        // non-strict mode would truncate on write, and a token stored under a
        // truncated type can never be verified against the full one.
        $this->expectException(\InvalidArgumentException::class);

        ResourceRef::of('f' . str_repeat('a', 64), 'id-1');
    }

    // ── The id is opaque ─────────────────────────────────────────────────────

    public function test_an_id_keeps_its_case(): void
    {
        // Ids are opaque. Lowercasing one would conflate two resources in any
        // id space that is case-sensitive, and conflating two resources is the
        // exact bug this class exists to prevent.
        self::assertSame('AbC-123', ResourceRef::of('file', 'AbC-123')->id);
    }

    public function test_two_ids_differing_only_in_case_are_different_resources(): void
    {
        self::assertFalse(
            ResourceRef::of('file', 'abc')->equals(ResourceRef::of('file', 'ABC')),
            'a token for one must not open the other',
        );
    }

    public function test_an_id_may_contain_characters_a_type_may_not(): void
    {
        foreach (['A/B', 'a b', 'x:y:z', 'uuid-with-dashes', '{"json":true}', 'é'] as $id) {
            self::assertSame($id, ResourceRef::of('file', $id)->id, $id);
        }
    }

    public function test_an_empty_id_is_refused(): void
    {
        // An empty id would make one token match every resource of its type
        // that was also minted with an empty id — a shared token by accident.
        $this->expectException(\InvalidArgumentException::class);

        ResourceRef::of('file', '');
    }

    /** @return list<array{string, string}> */
    public static function controlCharacterIds(): array
    {
        return [
            ["id\r\nmore", 'a CR rewrites the line it is on in a terminal'],
            ["id\x1b[2J",  'an ESC sequence can repaint the screen'],
            ["id\x00",     'a NUL can truncate C-side string handling'],
            ["id\t",       'a tab breaks a column in a CLI table'],
        ];
    }

    #[DataProvider('controlCharacterIds')]
    public function test_a_control_character_in_an_id_is_refused(string $id, string $why): void
    {
        // REFUSED, not stripped. This id reaches a log line and a CLI table, so
        // it cannot be allowed through — but stripping it would silently change
        // which resource is meant, and the caller would never know the token
        // they got was for something else.
        $this->expectException(\InvalidArgumentException::class);

        ResourceRef::of('file', $id);
    }

    public function test_an_id_longer_than_the_column_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ResourceRef::of('file', str_repeat('a', 192));
    }

    public function test_the_id_length_limit_is_191_not_255(): void
    {
        // 191 × 4 bytes of utf8mb4 = 764, which fits the 767-byte index prefix
        // limit older MySQL row formats enforce. 255 would produce a migration
        // that applies on some MySQL servers and fails on others.
        self::assertSame(191, \strlen(ResourceRef::of('file', str_repeat('a', 191))->id));
    }

    // ── Equality ─────────────────────────────────────────────────────────────

    public function test_equality_needs_both_parts(): void
    {
        $ref = ResourceRef::of('file', 'a');

        self::assertTrue($ref->equals(ResourceRef::of('file', 'a')));
        self::assertFalse($ref->equals(ResourceRef::of('file', 'b')), 'different id');
        self::assertFalse($ref->equals(ResourceRef::of('invoice', 'a')), 'different type');
    }

    public function test_an_id_containing_a_colon_needs_no_escaping(): void
    {
        // The reason the type and the id are two COLUMNS: the id is opaque, and
        // a single `type:id` key would need a separator the id was forbidden to
        // contain — a constraint on the application's own identifiers, imposed
        // for this plugin's convenience, with escaping to get wrong in two
        // places.
        $ref = ResourceRef::of('a', 'b:c');

        self::assertSame('b:c', $ref->id, 'stored verbatim');
        self::assertFalse($ref->equals(ResourceRef::of('a', 'b')));
    }

    public function test_a_type_cannot_contain_a_colon(): void
    {
        // Which is what makes label() unambiguous — a property rather than a
        // design, since nothing parses it back.
        $this->expectException(\InvalidArgumentException::class);

        ResourceRef::of('a:b', 'c');
    }
}
