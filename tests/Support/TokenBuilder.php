<?php

declare(strict_types=1);

namespace Tests\Plugins\ResourceTokens\Support;

use Plugins\ResourceTokens\Domain\ResourceRef;
use Plugins\ResourceTokens\Domain\ResourceToken;
use Plugins\ResourceTokens\Domain\TokenAbilities;

/**
 * A `ResourceToken` in whatever state a test needs.
 *
 * NOT named `*Factory`: the plugin already has a
 * {@see \Plugins\ResourceTokens\Infrastructure\ResourceTokenFactory}, and two
 * things called a factory in one codebase is one import away from the wrong one.
 *
 * Every argument is named and defaulted so a test states only the field it is
 * about — `TokenBuilder::make(expiresAt: $past)` reads as "an expired token",
 * and the fourteen fields it did not mention cannot be mistaken for part of the
 * scenario.
 */
final class TokenBuilder
{
    public static function make(
        string $id = 'token-1',
        ?ResourceRef $resource = null,
        ?TokenAbilities $abilities = null,
        string $digest = '',
        ?\DateTimeImmutable $expiresAt = null,
        ?int $maxUses = null,
        int $uses = 0,
        ?\DateTimeImmutable $lastUsedAt = null,
        ?\DateTimeImmutable $exhaustedAt = null,
        ?\DateTimeImmutable $revokedAt = null,
        ?\DateTimeImmutable $createdAt = null,
        string $label = '',
        string $createdBy = '',
    ): ResourceToken {
        return new ResourceToken(
            id:          $id,
            resource:    $resource ?? self::file(),
            abilities:   $abilities ?? TokenAbilities::readOnly(),
            // A digest that is the right SHAPE but is not the hash of anything
            // the test knows. A test that needs a usable secret hashes its own
            // and passes it in.
            digest:      $digest !== '' ? $digest : str_pad($id, 64, 'f'),
            expiresAt:   $expiresAt,
            maxUses:     $maxUses,
            uses:        $uses,
            lastUsedAt:  $lastUsedAt,
            exhaustedAt: $exhaustedAt,
            revokedAt:   $revokedAt,
            createdAt:   $createdAt ?? new \DateTimeImmutable('2026-01-01 00:00:00'),
            label:       $label,
            createdBy:   $createdBy,
        );
    }

    public static function file(string $id = 'file-1'): ResourceRef
    {
        return ResourceRef::of('file', $id);
    }
}
