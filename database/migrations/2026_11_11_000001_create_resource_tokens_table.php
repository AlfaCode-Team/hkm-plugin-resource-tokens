<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * One table: a token, and which resource it opens.
 *
 * One rather than two, unlike the webhook plugin's endpoints-and-deliveries
 * split, because there is only one lifetime here. A token is minted, used some
 * number of times, settles, and is pruned — there is no second thing with a
 * different life span to separate out.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('resource_tokens', static function ($t) {
            // The UUID is the identity, not a surrogate key: it is what
            // `restokens:revoke` takes and what an application stores against
            // its own records. A second auto-increment id would be a column
            // that means nothing to anybody.
            $t->char('id', 36);

            // WHICH resource, as TWO columns.
            //
            // Not one `type:id` key, because a resource id may contain a colon
            // and a composite string would then be ambiguous: `a:b:c` could be
            // type `a` id `b:c` or type `a:b` id `c`, and two different
            // resources could collide on one key.
            $t->string('resource_type', 64);

            // 191 and not 255: utf8mb4 is four bytes per character, and
            // 191 × 4 = 764 fits the 767-byte index prefix limit that MySQL
            // installations on older row formats still enforce. 255 produces a
            // migration that applies on some MySQL servers and not others.
            $t->string('resource_id', 191);

            // SHA-256 of the secret, hex — 64 characters, fixed. The secret
            // itself is never stored in any form: it is generated, digested and
            // returned once.
            //
            // A FAST hash on purpose. See Domain/TokenDigest for why bcrypt and
            // Argon2 are the wrong tools for a 256-bit random value, and why
            // either of them would force a plaintext column back into this
            // table or turn one indexed lookup into a full scan.
            $t->char('token_digest', 64);

            // What the token may do to that resource, as a JSON list. Not a
            // join table: the list is read as a set on every verification,
            // never queried across rows, and a second table would turn the hot
            // path into a join.
            $t->json('abilities');

            $t->string('label', 255)->default('');

            // Audit only. It grants nothing and is never checked — a token
            // carries no identity, which is the property that keeps this from
            // being an authentication mechanism.
            $t->string('created_by', 191)->default('');

            // NULL = never expires. A real case (a long-lived avatar link) and
            // the most dangerous token this plugin can mint, which is why
            // RESOURCE_TOKENS_ALLOW_PERMANENT exists to forbid it.
            $t->timestamp('expires_at')->nullable();

            // NULL = unlimited. A value here is enforced by the conditional
            // UPDATE in the repository, never by a read-then-write.
            $t->unsignedInteger('max_uses')->nullable();

            // Incremented by the DATABASE (`uses = uses + 1`), never read into
            // PHP and written back — so two concurrent uses cannot lose one.
            $t->unsignedInteger('uses')->default(0);

            $t->timestamp('last_used_at')->nullable();

            // THREE settle timestamps rather than one `settled_at`, so each arm
            // of the prune query has its own indexable column. A single column
            // would need the prune to OR across three conditions on one field
            // that means three different things, and would lose the ability to
            // say WHY a token is no longer live.
            $t->timestamp('exhausted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();

            // The default is a safety net for a row inserted by hand. NOTHING
            // in this plugin compares created_at, which matters: a value
            // written by the DATABASE is in the server's session timezone while
            // every value written by the code is in PHP's, and comparing the
            // two would be wrong by the offset between them. Every timestamp
            // the code does compare is written by the code.
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $t->primary(['id'], 'pk_resource_token');

            // THE lookup, on every single verification, and the only index that
            // is on a hot path. Unique as well as fast: it is what makes a
            // digest collision impossible to insert rather than something that
            // would silently hand one token's secret another token's row.
            $t->unique(['token_digest'], 'uniq_resource_token_digest');

            // "Every token for this resource" — the listing, the per-resource
            // revoke, and the mint-time cap. Type first because it is the
            // coarser column and a query always supplies both.
            $t->index(['resource_type', 'resource_id'], 'idx_resource_token_resource');

            // The three prune arms. Each is its own single-column index because
            // the prune query ORs across them: a composite would serve the
            // first column only, and the other two arms would scan.
            $t->index(['expires_at'], 'idx_resource_token_expires');
            $t->index(['revoked_at'], 'idx_resource_token_revoked');
            $t->index(['exhausted_at'], 'idx_resource_token_exhausted');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('resource_tokens');
    }
};
