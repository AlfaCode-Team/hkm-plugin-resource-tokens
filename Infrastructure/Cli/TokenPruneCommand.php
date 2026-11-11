<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;

/**
 * restokens:prune — delete SETTLED tokens past the retention window.
 *
 * Settled means revoked, expired or spent. A LIVE token is never pruned however
 * old it is, which matters most for the one case that looks like neglect: a
 * deliberately permanent token for an avatar or a public asset is not stale,
 * and deleting it would break a link somebody is relying on. It is also the one
 * deletion in this plugin that cannot be undone — the secret cannot be
 * recovered, so the row cannot be re-created.
 *
 * The retention window exists because a revoked token's row is the only
 * evidence it ever existed. Deleting it the moment it is revoked would mean that
 * "was this link ever issued, and who issued it" becomes unanswerable exactly
 * when somebody asks.
 */
final class TokenPruneCommand extends AbstractCommand
{
    public function __construct(
        private readonly ResourceTokenContract $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'restokens:prune';
        $this->description = 'Delete settled resource tokens past their retention window';

        $this->addOption('yes', 'y', 'Do not ask before deleting');
    }

    protected function handle(): int
    {
        $counts  = $this->tokens->counts();
        $settled = ($counts['expired'] ?? 0) + ($counts['spent'] ?? 0) + ($counts['revoked'] ?? 0);

        if ($settled === 0) {
            $this->muted('Nothing settled. Nothing to prune.');

            return self::SUCCESS;
        }

        // The count is of EVERY settled row, not of the ones old enough to go —
        // the cutoff is applied in SQL and this is a read taken before it. Said
        // out loud, because "up to" versus "exactly" is the difference between
        // a prompt that is honest and one that overstates what is about to
        // happen.
        if (!(bool) $this->option('yes')
            && !$this->confirm(
                \sprintf('Delete settled tokens older than the retention window? Up to %d row(s) '
                    . 'are candidates.', $settled),
                default: false,
            )
        ) {
            $this->muted('Nothing deleted.');

            return self::SUCCESS;
        }

        $deleted = $this->tokens->prune();

        if ($deleted === 0) {
            $this->muted(\sprintf(
                '%d settled token(s) stored, none of them past the retention window yet.',
                $settled,
            ));

            return self::SUCCESS;
        }

        $this->success("{$deleted} settled token(s) deleted.");

        return self::SUCCESS;
    }
}
