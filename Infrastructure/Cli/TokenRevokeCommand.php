<?php

declare(strict_types=1);

namespace Plugins\ResourceTokens\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\ResourceTokens\API\Contracts\ResourceTokenContract;
use Plugins\ResourceTokens\Domain\ResourceRef;

/**
 * restokens:revoke — withdraw one token, or every token for a resource.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE COMMAND THAT CANNOT EXIST FOR A SIGNED URL
 * ────────────────────────────────────────────────────────────────────────────
 *
 * This is the operation that justifies the table. A stateless signed URL is
 * valid until it expires because nothing records that it was ever issued, so
 * "we mailed that link to the wrong address" has no answer short of rotating
 * the application key and invalidating every signed URL at once.
 *
 * `--all` is the form actually reached for during an incident: not "revoke this
 * token" — whoever is asking usually does not have it — but "revoke everything
 * that still opens this document".
 *
 * ────────────────────────────────────────────────────────────────────────────
 * IT TAKES AN ID OR A RESOURCE, NEVER A TOKEN
 * ────────────────────────────────────────────────────────────────────────────
 *
 * There is deliberately no `--token=` form. A secret passed as an argument goes
 * into shell history, into `ps` output for as long as the process runs, and
 * into any command log the shell keeps — which is three new places for a
 * credential to live, to save one `shasum`. `restokens:list` shows a digest
 * prefix for exactly this purpose.
 */
final class TokenRevokeCommand extends AbstractCommand
{
    public function __construct(
        private readonly ResourceTokenContract $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'restokens:revoke';
        $this->description = 'Withdraw a resource token, or every token for a resource';

        $this->addOption('id', '', 'The token id to withdraw', acceptsValue: true);
        $this->addOption('all', '', 'Withdraw every token for TYPE:ID', acceptsValue: true);
        $this->addOption('yes', 'y', 'Do not ask before a --all');
    }

    protected function handle(): int
    {
        $id  = $this->option('id');
        $all = $this->option('all');

        if (\is_string($id) && $id !== '') {
            return $this->revokeOne($id);
        }

        if (\is_string($all) && $all !== '') {
            return $this->revokeAll($all);
        }

        $this->error(
            'Nothing to revoke. Pass --id=<token id>, or --all=<type>:<id> for every token on a '
            . 'resource. `restokens:list <type> <id>` shows the ids.',
        );

        return self::FAILURE;
    }

    private function revokeOne(string $id): int
    {
        if ($this->tokens->revoke($id)) {
            $this->success("Token {$id} revoked.");

            return self::SUCCESS;
        }

        // One message for both cases on purpose: an unknown id and an
        // already-revoked id are the same outcome — that token does not work —
        // and distinguishing them would only matter to somebody guessing ids.
        $this->warning(
            "Nothing revoked. Token {$id} does not exist, or was already revoked. Either way it "
            . 'no longer opens anything.',
        );

        return self::SUCCESS;
    }

    private function revokeAll(string $spec): int
    {
        // `type:id` from the command line, split on the FIRST colon only — a
        // resource id may contain one, and splitting on the last would move
        // part of the id into the type.
        $parts = explode(':', $spec, 2);

        if (\count($parts) !== 2) {
            $this->error('--all takes <type>:<id>, e.g. --all=file:3f2a-….');

            return self::FAILURE;
        }

        try {
            $resource = ResourceRef::of($parts[0], $parts[1]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $live = array_values(array_filter(
            $this->tokens->tokensFor($resource),
            static fn ($token): bool => !$token->isRevoked(),
        ));

        if ($live === []) {
            $this->muted('No un-revoked tokens for ' . $resource->label() . '.');

            return self::SUCCESS;
        }

        // Asked before, not after. Revoking cannot be undone: the secrets
        // cannot be recovered, so re-issuing means every recipient needs a new
        // link. --yes is there for the scripted path.
        if (!(bool) $this->option('yes')
            && !$this->confirm(
                \sprintf('Revoke %d token(s) for %s? Links already sent will stop working.',
                    \count($live),
                    $resource->label(),
                ),
                default: false,
            )
        ) {
            $this->muted('Nothing revoked.');

            return self::SUCCESS;
        }

        $revoked = $this->tokens->revokeFor($resource);

        $this->success(\sprintf('%d token(s) revoked for %s.', $revoked, $resource->label()));

        return self::SUCCESS;
    }
}
