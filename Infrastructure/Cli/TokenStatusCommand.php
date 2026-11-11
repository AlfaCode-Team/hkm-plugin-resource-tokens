<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;

/**
 * restokens:status — how many tokens exist, and in what state.
 *
 * Exits non-zero only when there is something a person should look at. The
 * ordinary life of this table — expired and spent rows waiting to be pruned —
 * is not a failure, so it does not report one: a status command that is always
 * red is a status command nobody reads.
 */
final class TokenStatusCommand extends AbstractCommand
{
    /**
     * Enough settled rows to suggest the prune is not running.
     *
     * A threshold rather than "any", because some settled rows are always
     * present between prunes — that is what the retention window means.
     */
    private const SETTLED_BACKLOG = 10_000;

    public function __construct(
        private readonly ResourceTokenContract $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'restokens:status';
        $this->description = 'Show resource token counts by state';
    }

    protected function handle(): int
    {
        $counts = $this->tokens->counts();

        $this->section('Resource tokens');
        // The table, the dialect and the tuning — never a secret, a digest, a
        // host or a DSN.
        $this->muted($this->tokens->describe());
        $this->newLine();

        foreach ($counts as $state => $total) {
            $this->info(\sprintf('  %-8s %d', $state, $total));
        }

        $settled = ($counts['expired'] ?? 0) + ($counts['spent'] ?? 0) + ($counts['revoked'] ?? 0);

        $this->newLine();

        if ($settled > self::SETTLED_BACKLOG) {
            $this->warning(\sprintf(
                '%d settled tokens are still stored. `restokens:prune` deletes those past the '
                . 'retention window — this many usually means it is not scheduled.',
                $settled,
            ));

            return self::FAILURE;
        }

        $this->success(\sprintf(
            '%d live token(s), %d settled and awaiting prune.',
            $counts['active'] ?? 0,
            $settled,
        ));

        return self::SUCCESS;
    }
}
