<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\ResourceTokens\Domain\TokenAbilities;

#[CoversClass(TokenAbilities::class)]
final class TokenAbilitiesTest extends TestCase
{
    public function test_the_default_is_read_only(): void
    {
        // Every omitted argument in a security API is a decision made by
        // whoever wrote the default. `read` is the narrowest useful grant; a
        // default of `*` would mean mint() with no further thought handed out a
        // token that could delete the thing.
        $abilities = TokenAbilities::readOnly();

        self::assertTrue($abilities->permits('read'));
        self::assertFalse($abilities->permits('write'));
        self::assertFalse($abilities->permits('delete'));
        self::assertFalse($abilities->isUnrestricted());
    }

    public function test_a_wildcard_permits_everything(): void
    {
        $abilities = TokenAbilities::all();

        self::assertTrue($abilities->permits('read'));
        self::assertTrue($abilities->permits('delete'));
        self::assertTrue($abilities->permits('anything-at-all'));
        self::assertTrue($abilities->isUnrestricted());
    }

    public function test_a_wildcard_is_still_bounded_by_the_resource(): void
    {
        // Worth pinning as documentation: `*` means "anything to THIS
        // resource", never "anything". The resource check lives in
        // ResourceTokenService::verify() and runs BEFORE this class is
        // consulted, so there is no ability value that can widen it.
        self::assertSame(['*'], TokenAbilities::all()->toArray());
    }

    public function test_an_empty_list_is_refused(): void
    {
        // Not "everything" and not "nothing in particular" — a token that can
        // never be used. Refused at mint rather than handed to somebody.
        $this->expectException(\InvalidArgumentException::class);

        TokenAbilities::of([]);
    }

    public function test_a_wildcard_mixed_with_a_name_is_refused(): void
    {
        // Refused rather than collapsed to ['*']. It is not a typo worth
        // guessing at: it means the author believed the list was additive and
        // that writing `read` next to `*` narrowed something.
        try {
            TokenAbilities::of(['*', 'read']);
            self::fail('a mixed list must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('NARROW', $e->getMessage());
        }
    }

    public function test_duplicates_are_collapsed(): void
    {
        self::assertSame(['read'], TokenAbilities::of(['read', 'read'])->toArray());
    }

    public function test_the_list_is_sorted_so_one_grant_stores_one_way(): void
    {
        // Two equivalent lists written in two orders would otherwise produce
        // two different JSON columns, and a diff of two rows would read as a
        // difference in permissions.
        self::assertSame(
            TokenAbilities::of(['read', 'write'])->toArray(),
            TokenAbilities::of(['write', 'read'])->toArray(),
        );
        self::assertSame(['read', 'write'], TokenAbilities::of(['write', 'read'])->toArray());
    }

    /** @return list<array{string}> */
    public static function nonCanonicalAbilities(): array
    {
        return [
            ['Read'],
            ['1read'],
            ['read write'],
            ['read/write'],
            ['read!'],
            [''],
            // PCRE's `$` also matches immediately before a final newline, so
            // this one passed until the pattern gained the `D` modifier. An
            // ability is printed into a CLI table and a log line.
            ["read\n"],
            ["read\r"],
        ];
    }

    #[DataProvider('nonCanonicalAbilities')]
    public function test_a_non_canonical_ability_is_refused(string $ability): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TokenAbilities::of([$ability]);
    }

    public function test_matching_is_exact_and_never_by_prefix(): void
    {
        // If `file.read` implied `file.read.metadata`, then granting `file`
        // would grant everything beginning with it — and the day somebody added
        // a `file.delete` ability, every existing `file`-prefixed token would
        // quietly acquire it.
        $abilities = TokenAbilities::of(['file.read']);

        self::assertTrue($abilities->permits('file.read'));
        self::assertFalse($abilities->permits('file'), 'the shorter name is not implied');
        self::assertFalse($abilities->permits('file.read.metadata'), 'nor is a longer one');
        self::assertFalse($abilities->permits('file.readall'), 'nor a string prefix of it');
    }

    public function test_too_many_abilities_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TokenAbilities::of(array_map(static fn (int $i): string => 'a' . $i, range(1, 33)));
    }

    public function test_the_label_is_readable(): void
    {
        self::assertSame('read,write', TokenAbilities::of(['write', 'read'])->label());
    }
}
