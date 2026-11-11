<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;
use Plugins\ResourceTokens\Domain\ResourceRef;

/**
 * restokens:list — every token for one resource.
 *
 * Takes a resource rather than listing the whole table, because "every token
 * in the system" is not a question anybody asks; "what still opens this file"
 * is, and it is usually asked during an incident.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE DIGEST PREFIX IS THE COLUMN THIS COMMAND EXISTS FOR
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Given a token found in an access log or pasted into a support ticket:
 *
 *   shasum -a 256 <<< "$TOKEN"     # or: printf %s "$TOKEN" | shasum -a 256
 *
 * and the first twelve characters of that identify the row here, so it can be
 * revoked — without the token itself ever being typed into a command, where it
 * would land in shell history and in this plugin's own logs.
 */
final class TokenListCommand extends AbstractCommand
{
    public function __construct(
        private readonly ResourceTokenContract $tokens,
        private readonly ClockPort $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'restokens:list';
        $this->description = 'List every token minted for one resource';

        $this->addArgument('type', 'Resource type, e.g. "file"', required: true);
        $this->addArgument('id', 'Resource id', required: true);
        $this->addOption('live', '', 'Show only tokens that still work');
    }

    protected function handle(): int
    {
        try {
            $resource = ResourceRef::of(
                (string) $this->argument('type'),
                (string) $this->argument('id'),
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $now    = $this->clock->now();
        $tokens = $this->tokens->tokensFor($resource);

        if ((bool) $this->option('live')) {
            $tokens = array_values(array_filter(
                $tokens,
                static fn ($token): bool => $token->isUsableAt($now),
            ));
        }

        $this->section('Tokens for ' . $resource->label());

        if ($tokens === []) {
            $this->muted('None.');

            // Not a failure. "This resource has no live links" is a perfectly
            // good answer and often the one being hoped for.
            return self::SUCCESS;
        }

        $rows = [];

        foreach ($tokens as $token) {
            $described = $token->describe($now);

            $rows[] = [
                $described['id'],
                $described['state'],
                // Twelve characters of the SHA-256. Not a credential:
                // presenting a digest authenticates nothing, because
                // verification hashes whatever it is given.
                $described['digest_prefix'],
                implode(',', $described['abilities']),
                $described['max_uses'] === null
                    ? (string) $described['uses']
                    : $described['uses'] . '/' . $described['max_uses'],
                $described['expires_at'] ?? 'never',
                $described['label'],
            ];
        }

        $this->table()
            ->headers(['id', 'state', 'digest', 'abilities', 'uses', 'expires', 'label'])
            ->rows($rows)
            ->render();

        $live = \count(array_filter($tokens, static fn ($t): bool => $t->isUsableAt($now)));

        $this->newLine();
        $this->info(\sprintf('%d token(s), %d of them live.', \count($tokens), $live));

        return self::SUCCESS;
    }
}
