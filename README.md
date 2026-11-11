# hkm-plugin-resource-tokens

A revocable, resource-scoped bearer token, so a caller with **no account** can
reach **exactly one thing**.

```php
// Mint — the application has already checked that this caller may share the file.
$minted = $tokens->mint(
    ResourceRef::of('file', $file->id),
    ttlSeconds: 3600,
    abilities:  TokenAbilities::of(['download']),
    maxUses:    1,
    label:      'emailed to the claimant',
    createdBy:  $identity->userId,
);

$link = route('files.raw', ['id' => $file->id]) . '?token=' . $minted->secret();
// ↑ the ONLY time the secret exists. Only its SHA-256 was stored.
```

```jsonc
// The route that serves it — no controller code required.
{ "method": "GET", "path": "/files/{id:uuid}/raw",
  "handler":  "App\\Http\\FileController@raw",
  "requires": ["resource.tokens"],
  "filters":  ["restoken:file,id"] }
```

| | |
|---|---|
| `solves` | `resource.tokens` |
| `requires` | nothing (needs a `DatabasePort`, which is a port) |
| `exposes` | `ResourceTokenContract` |
| Kernel floor | `^1.15.0` |
| Commands | `restokens:status`, `restokens:list`, `restokens:revoke`, `restokens:prune` |
| Route filter | `restoken:<type>,<route-param>[,<ability>]` |
| Table | `resource_tokens` |

---

## Why not just use `signedRoute()`?

**Use `signedRoute()` first.** The kernel's `UrlGenerator::signedRoute()` is
cheaper in every way — no table, no query, no pruning — and for a one-off link
it is the right answer.

It cannot do four things, and every one of them is a direct consequence of it
storing nothing:

| | |
|---|---|
| **Revocation** | A signed URL is valid until it expires because nothing records that it was issued. There is no list to remove it from. *"We mailed that link to the wrong address"* has no answer short of rotating `APP_KEY`, which invalidates every signed URL in the application at once. |
| **Use limits** | *"This download link works once"* cannot be expressed without somewhere to count, and counting is state. |
| **Audit** | Who minted it, when, what for, has it been used. |
| **Listing** | *"Show me every live link to this document"* — the question that actually gets asked, usually during an incident. |

Need none of those → `signedRoute()`. Need any of them → this. The cost is a row
and one indexed query per access, and that is exactly what the four properties
are worth.

## Why not the Auth plugin's personal access tokens?

A PAT belongs to a **user** and carries abilities across the whole application.
These belong to a **resource**, carry abilities over that resource alone, and
have no user anywhere — the recipient typically has no account at all.

The *storage* scheme here is deliberately identical to that plugin's (random
secret, SHA-256 digest, raw value returned once), because the reasoning is the
same and two first-party plugins disagreeing about how to store a token would be
a defect in one of them.

---

## The one bug this plugin exists to prevent

```php
// ✗ This looks like a check and is not one.
$token = $tokens->find($request->query('token'));
if ($token !== null) {
    return $this->serve($request->param('id'));
}
```

The resource came from the **URL** and the token was never compared against it —
so a token minted for the cheapest file in the system opens every file in the
system. It is the confused deputy, and it passes every test you would think to
write.

The defence is structural: **verification cannot be called without saying which
resource is expected.** `verify()` takes a `ResourceRef`, and there is no
`find()` on the contract at all.

```php
$verdict = $tokens->verify($presented, ResourceRef::of('file', $id), 'download');
```

---

## `ResourceRef` — the type is canonical, the id is opaque

They are treated differently on purpose.

The **type** is validated into one spelling: lowercase, letter-first. It is a
name the application writes in two places — where a token is minted and where it
is verified — so `File` is *refused* rather than corrected, because a silent
correction would hide a mismatch until a token could not be used in production.

The **id** is compared byte for byte and never normalised. Ids are opaque: a
UUID, a ULID, a slug, a signed external key. Lowercasing one would conflate two
different resources in any case-sensitive id space, and conflating two resources
is the bug above.

> They are stored as **two columns**, because a single `type:id` key needs a
> separator, and a separator is a character the id may not contain — a
> constraint on the application's own identifiers, imposed for this plugin's
> convenience, with escaping to get wrong in two places.

## `TokenAbilities` — the resource is the hard boundary, this is the soft one

`abilities` defaults to `['read']`, never `['*']`. Every omitted argument in a
security API is a decision made by whoever wrote the default, and `mint($file)`
with no further thought must not hand out a token that can delete the file.

`'*'` is allowed but only **alone**: `['*', 'read']` is refused rather than
collapsed, because it means the author believed the list was additive and that
writing `read` next to `*` narrowed something.

Matching is **exact, never by prefix**. If `file.read` implied
`file.read.metadata`, then the day somebody added a `file.delete` ability every
existing `file`-prefixed token would quietly acquire it.

`'*'` is still bounded by the resource. It means *anything to this file*, never
*anything*.

---

## Lifetime: `null`, a positive number, or `NEVER_EXPIRES`

```php
$tokens->mint($ref);                                              // the configured default
$tokens->mint($ref, ttlSeconds: 600);                             // ten minutes
$tokens->mint($ref, ttlSeconds: ResourceTokenContract::NEVER_EXPIRES);  // no expiry
$tokens->mint($ref, ttlSeconds: 0);                               // ✗ REFUSED
```

`NEVER_EXPIRES` is `-1` and **not** `0`, which is the decision in this API most
worth knowing about. Somebody writing `ttlSeconds: 0` almost certainly means
"expires immediately" — and if `0` meant "never", that reading would silently
produce the most dangerous token this plugin can make. So `0` is refused with a
message pointing at the constant, and permanence has to be asked for by name.

A lifetime past `RESOURCE_TOKENS_MAX_TTL` is **refused, not clamped**: a clamp
would hand back a token that expires sooner than the caller asked for, and the
caller would then tell its user the wrong thing about how long the link lasts.

The ceiling does not apply to a permanent token — it bounds a lifetime, and a
permanent token has none. `RESOURCE_TOKENS_ALLOW_PERMANENT=false` is the separate
switch, because *"no token may last more than an hour"* and *"no token may be
permanent"* are two different decisions.

---

## The decision and the recording are ONE statement

This is the guarantee everything else rests on.

```sql
UPDATE resource_tokens
   SET uses = uses + 1, last_used_at = :now
 WHERE id = :id
   AND revoked_at IS NULL
   AND (expires_at IS NULL OR expires_at > :expiry_now)
   AND (max_uses IS NULL OR uses < max_uses)
```

One affected row means this use was **legal and is now recorded**. Zero means
somebody else got there first.

Reading the row, deciding in PHP, then writing, leaves a window in which two
concurrent requests both read `uses = 0`, both find it below a limit of one, and
both proceed — a single-use download link that serves the file twice. That
window is one line of `curl` wide.

So the service's own checks exist **only to produce a precise refusal reason for
the log**. The affected-row count is the authority, and when the two disagree
this one wins. `uses = uses + 1` is computed by the database, so concurrent uses
cannot lose an increment either.

> `exhausted_at` is stamped by a **second** statement, on purpose. Folding it in
> would need `uses + 1` inside a `CASE`, and MySQL evaluates a `SET` list
> left-to-right with later assignments seeing earlier ones, while PostgreSQL and
> SQLite evaluate every right-hand side against the old row. The single
> statement would be off by one between MySQL and the driver the test suite runs
> on — which is the driver that could never show it.

### Use tracking can be turned off, but only where it is free

`RESOURCE_TOKENS_TRACK_USES=false` skips the write for **unlimited** tokens.
That is the `<img src>` case: a token on a hot page would otherwise turn every
image request into an `UPDATE`, which is how a read path becomes a replication
bottleneck.

A **limited** token always writes, whatever the setting says, because the write
*is* the enforcement. If this switch could reach it, `TRACK_USES=false` would
silently turn every single-use link into an unlimited one.

---

## Why SHA-256 and deliberately not bcrypt or Argon2

This looks wrong to anyone who has learned *never store a credential under a
fast hash*, so it is worth being explicit.

A slow, salted hash exists to make an **offline guessing attack** expensive, and
guessing is only a threat when the input space is small enough to search — which
is what a human-chosen password is. This secret is 32 bytes from
`random_bytes()`: 2²⁵⁶ candidates, no dictionary, no reuse, no pattern. There is
nothing to guess.

What a slow hash *would* cost is the lookup. Bcrypt and Argon2 salt per row, so
the digest of a presented value cannot be computed without first knowing which
row it belongs to — which means either a plaintext column to find the row by, or
verifying the presented secret against **every row in the table** with a
deliberately expensive operation per row. The first defeats the hashing
entirely; the second is a denial-of-service endpoint anyone can dial by sending
junk.

The properties actually relied on are the ones SHA-256 has: one-way,
deterministic, collision-resistant.

> **There is no secret comparison anywhere in this plugin.** A presented value is
> hashed and the hash is used as a `WHERE` key, so the usual
> `hash_equals`-versus-`===` hazard does not arise — there is no comparison to
> get wrong. That is why the lookup is shaped this way.

---

## The route filter

```
restoken:<resource-type>,<route-param>[,<ability>]

restoken:file,id              type `file`, id from {id}, ability `read`
restoken:invoice,ref,write    type `invoice`, id from {ref}, ability `write`
```

The **type** is written in the manifest and the **id** comes from the matched
route. That is the safety property: the resource a token is checked against is
the one the router already resolved, so a controller cannot be reached with a
token for a different resource. Checking it by hand inside the controller is the
version that gets forgotten on the fourth endpoint.

On success the verdict's token is attached as the `resource_token` request
attribute, so a controller can read what was granted **without verifying a second
time** — which would spend a second use of a single-use token.

### `"requires": ["resource.tokens"]` is not optional

A module's `register()` runs only when it is in that request's dependency graph,
so the filter alias resolving does **not** imply the bindings exist. A route that
names the filter without requiring the domain gets a 500 with a message naming
the fix, and never a pass. Failing open there would turn one missing line of JSON
into a private file served to anybody who asked.

### The header is preferred over the query string

A query string is written to a web server's access log, kept in browser history,
and sent onward in a `Referer` on any outbound link from the page — so a token
that travelled that way is sitting in several places that outlive it.

```
X-Resource-Token: <token>     ← preferred
?token=<token>                ← supported
```

The query string is supported anyway because it is the only option for the case
this plugin exists for: a browser fetching an `<img src>` cannot set a header.
Short lifetimes and use limits are the mitigation, which is why the default TTL
is an hour and not a year.

The token is read from the **query**, never from the body: a token belongs in the
URL or the header, both of which the recipient of the link controls — a body is
written by whatever page did the submitting.

### Every refusal looks identical

All seven reasons produce the same response, `404` by default.

Telling the presenter which check failed is free reconnaissance. *"This token
expired"* confirms the token was real and that the resource exists; *"wrong
resource"* confirms the token is live and invites trying it against every id in
range; *"exhausted"* says the recipient already collected the file. `404` also
makes an invalid token indistinguishable from a resource that is not there.

`RESOURCE_TOKENS_REFUSED_STATUS` is clamped to `4xx`. A `2xx` or `3xx` would turn
every refusal into a **success** — a private resource served to anybody, from one
typo in an `.env` file — so it falls back to 404 rather than being honoured.

### The reason goes to the log, and only to the log

| Refusal | Means |
|---|---|
| `wrong_resource` | a live token aimed at a resource it was not minted for — **somebody is trying things** |
| `missing_ability` | a live token aimed at an operation it was not granted |
| `revoked` | withdrawn deliberately, and still in circulation |
| `expired` | ordinary, and the common one |
| `exhausted` | a single-use link used twice |
| `unknown` | no such token; also what a guessed value produces |
| `malformed` | not even the right shape — refused without a database lookup |

`TokenRefusal::suggestsProbing()` separates the first two, so an application can
route them to an alert and leave the rest as the ordinary life of a link.

> **Without a `LoggerPort` bound, this plugin's refusals leave no trace.** The
> response is deliberately identical for all seven, so the log is the only place
> the reason exists — and *"the link doesn't work"* is the only symptom a user
> will ever report.

---

## No HTTP routes, and no security layer

**No routes.** Who may mint a token for a given resource is the same question as
who may read that resource, and only the application can answer it. A shipped
`POST /tokens` endpoint would have to either refuse everybody or trust a caller's
claim about a resource it cannot check — privilege escalation dressed as a
convenience: mint a token for somebody else's file, then use it. The application
exposes its own route, runs its own check, and calls `mint()` once it passes.

**No security layer, and no `Identity`.** This is the boundary that keeps the
plugin from being a back door, and it is worth stating because the obvious next
feature is exactly the wrong one. If a resource token minted an `Identity`, every
token would be an account — one that passed the security gateway, carried
whatever roles the mapping gave it, and reached every route in the application. A
token would stop meaning *this one file* and start meaning *logged in*, with the
resource it was minted for reduced to a comment on a row.

**Minting is absent from the CLI**, because it returns a secret that must be
shown once and a CLI would put it in shell history and in `ps` output.
`restokens:revoke` takes a token **id** or a resource, never a token, for the
same reason.

---

## Incident response

Given a token found in an access log or pasted into a support ticket:

```sh
printf %s "$TOKEN" | shasum -a 256          # → 1a2b3c4d5e6f…
hkm cli restokens:list file 3f2a-9c         # match the digest column
hkm cli restokens:revoke --id=<token id>
```

The first twelve characters of the SHA-256 are shown as the `digest` column.
That prefix is **not a credential**: presenting a digest authenticates nothing,
because verification hashes whatever it is given. It is there so a leaked token
can be traced to its row *without the token itself ever being typed into a
command*, where it would land in shell history and in this plugin's own logs.
The full digest is not shown, because it would be the obvious thing to paste into
a URL by somebody who assumed it was the token.

When the token is not in hand — the usual case — revoke by resource:

```sh
hkm cli restokens:revoke --all=file:3f2a-9c
```

---

## Configuration

| Key | Default | |
|---|---|---|
| `RESOURCE_TOKENS_DEFAULT_TTL` | `3600` | lifetime for a mint that does not name one |
| `RESOURCE_TOKENS_MAX_TTL` | `0` | ceiling on any lifetime; `0` = none |
| `RESOURCE_TOKENS_ALLOW_PERMANENT` | `true` | whether `NEVER_EXPIRES` may be minted |
| `RESOURCE_TOKENS_MAX_PER_RESOURCE` | `100` | live tokens per resource; `0` = unlimited |
| `RESOURCE_TOKENS_PRUNE_AFTER_DAYS` | `30` | retention for settled rows |
| `RESOURCE_TOKENS_TRACK_USES` | `true` | record uses on **unlimited** tokens |
| `RESOURCE_TOKENS_QUERY_PARAM` | `token` | |
| `RESOURCE_TOKENS_HEADER` | `X-Resource-Token` | preferred over the query param |
| `RESOURCE_TOKENS_REFUSED_STATUS` | `404` | clamped to 4xx |

Every value is normalised in the **private constructor**, not in `fromEnv()` —
which is where it was first, and a test of `of()` is what showed why that is the
wrong place: `of()` went straight past all of it, so a refusal status of `200`
was reachable through the constructor the route filter and every test use.
Validation that lives in one of two factories is not an invariant, it is a habit.

The per-resource cap is a **guard rail against a loop, not a security boundary** —
two concurrent mints can both pass the count. What it catches is code that mints
a fresh token on every page render instead of reusing one, which fills the table
and leaves a thousand live links to one file.

## Pruning

`restokens:prune` deletes **settled** rows past the retention window: revoked,
expired, or spent. A **live** token is never pruned however old it is, which
matters most for the case that looks like neglect — a deliberately permanent
token for an avatar or a public asset is not stale, and it is the one deletion
here that cannot be undone, since the secret cannot be recovered to re-create the
row.

The retention window exists because a revoked token's row is the only evidence it
ever existed. Deleting it the moment it is revoked would make *"was this link
ever issued, and who issued it"* unanswerable exactly when somebody asks.

The three settle timestamps (`revoked_at`, `expires_at`, `exhausted_at`) are
separate columns so each arm of the prune query has its own index. The prune is
select-ids-then-delete-by-id in batches of 1000, because `DELETE … LIMIT` is
MySQL-only and an unbounded `DELETE` on a table nobody has pruned for a year is
one enormous transaction holding locks while it runs.

---

## Verified

- **225 tests, 431 assertions**, green. `ground check --strict`: clean.
- **The use-limit race is demonstrated**, not asserted: the integration suite
  reads a token, finds it usable, has another writer take the last use through
  raw SQL, and then watches the conditional `UPDATE` match no row.
- **The real SQL runs** against a database built by this plugin's own migration
  (`MigratedSqlite`) — so a column that does not exist, a missing unique index,
  or a conditional `UPDATE` whose row count is ignored would fail the suite.
- **Nineteen revert checks**, seventeen of which failed on the first attempt.
  Two findings came out of them, both recorded in `CHANGELOG.md`.
- `schedule[]` was compiled with the real `CompileScheduleManifestStage` and its
  cron expression confirmed to fire at 04:41 daily and not at 04:40.
- Every env var the code reads is declared in `module.json` `config[]`, and every
  declared var is read — checked in both directions. No `getenv()`.
- `#[\SensitiveParameter]` on `verify()` is tested as **behaviour**: the store is
  made to throw and the trace is asserted not to contain the presented token,
  with a control case proving `zend.exception_ignore_args` is not what hid it.

## NOT verified — read this before deploying

- **No HTTP request has ever reached this plugin.** The route filter is tested by
  constructing a `Request` and calling `handle()` directly, which exercises the
  stage but not the pipeline: nothing confirms that `RouteFilterStage` passes
  `filter_args` in the shape the stage reads, or that the alias registered in
  `Provider::boot()` resolves at pipeline build. **This is the largest gap.**
- **No command has been run.** All four CLI commands are covered by reading only.
  `restokens:list`'s table rendering and `restokens:revoke`'s confirmation prompt
  have never executed.
- **Only SQLite has run the SQL.** The `SET`-list ordering hazard described above
  is specifically a *MySQL* behaviour, and the driver that could exhibit it is the
  one not tested. The two-statement split is what makes that safe, and the split
  is reasoned about rather than measured.
- **There is no real concurrency anywhere.** The race is demonstrated by explicit
  interleaving with raw SQL in one process, which proves the statement's
  semantics. It does not prove behaviour under genuine parallel load.
- **`markExhaustedIfSpent()`'s `exhausted_at IS NULL` guard is unpinned.**
  Removing it passes the whole suite. It is unreachable sequentially, and its one
  real scenario needs two processes interleaving between the two statements of one
  `consume()` call. It is kept for the idempotence it makes evident, not because a
  test holds it.
- **`#[\SensitiveParameter]` is defence in depth on three of its four uses.** It
  has an observable effect only where something below the call can throw, which
  today is `verify()` alone. On `TokenDigest::of()`, `isPlausible()` and
  `MintedToken::__construct` nothing currently throws, so those are held by a
  reflection guardrail rather than by behaviour — labelled as such in the test.
- **No scheduler has run the declared task.** The entry compiles and the cron
  expression is correct; `restokens:prune --yes` has never been invoked by one.
- **The permanent-token hazard is documented, not prevented.** A token with no
  expiry in a query string is the longest-lived credential an application can
  accidentally create, and `RESOURCE_TOKENS_ALLOW_PERMANENT` defaults to `true`.

---

## Licence

MIT. See `LICENSE`.
