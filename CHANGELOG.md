# Changelog

All notable changes to `hkm-plugin-resource-tokens` are documented here. The
format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: a revocable, resource-scoped bearer token, so a caller with
  no account can reach exactly one thing.** Solves `resource.tokens` and
  requires no other module. A token names ONE resource and the abilities it
  grants over it, optionally expires, optionally limits how many times it may be
  used, and can be withdrawn individually or for a whole resource.

- **Verification cannot be called without naming the resource, and that is the
  whole design.** There is no `find()` on the contract. The bug it prevents is
  the one that reads as working code: look the token up, find it valid, serve
  whatever the URL asked for — which means a token minted for the cheapest file
  in the system opens every file in the system. It is the confused deputy, and
  it passes every test anyone would think to write.

- **Why not `UrlGenerator::signedRoute()`.** That is cheaper in every way and
  should be the first choice. It cannot do four things, all because it stores
  nothing: it cannot be REVOKED (nothing records that a URL was issued, so
  "we mailed that link to the wrong address" has no answer short of rotating
  APP_KEY and invalidating every signed URL at once); it cannot LIMIT USES
  (counting is state); it cannot be AUDITED; and it cannot be LISTED, which is
  the question actually asked during an incident. Need none of those, use
  `signedRoute()`; need any, use this.

- **The decision and the recording are ONE statement.** A use is granted by a
  conditional UPDATE whose affected-row count is the authority:
  `uses = uses + 1 WHERE id = … AND revoked_at IS NULL AND (expires_at IS NULL
  OR expires_at > :now) AND (max_uses IS NULL OR uses < max_uses)`. The
  service's own checks exist only to produce a precise refusal reason for the
  log; when the two disagree the row count wins. Reading, deciding in PHP, then
  writing leaves a window in which two concurrent requests both read `uses = 0`,
  both find it below a limit of one, and both proceed — a single-use download
  link that serves the file twice, through a window one line of `curl` wide.

- **`exhausted_at` is stamped by a SECOND statement, and the reason is a
  cross-driver trap.** Folding it into the UPDATE would need `uses + 1` inside a
  CASE, and MySQL evaluates a SET list LEFT TO RIGHT with later assignments
  seeing earlier ones, while PostgreSQL and SQLite evaluate every right-hand
  side against the OLD row. One statement would be off by one between MySQL and
  the driver the suite runs on — which is the driver that could never show it.
  Split out, it references no arithmetic, is idempotent, and reads the same
  everywhere.

- **`NEVER_EXPIRES` is `-1`, deliberately not `0`.** Somebody writing
  `ttlSeconds: 0` almost certainly means "expires immediately", and if 0 meant
  "never" that reading would silently produce the most dangerous token this API
  can make. So 0 is refused with a message naming the constant, and permanence
  has to be asked for by name. A lifetime past the ceiling is refused rather
  than CLAMPED: a clamp hands back a token that expires sooner than the caller
  asked for, and the caller then tells its user the wrong thing.

- **SHA-256 and deliberately not bcrypt or Argon2.** A slow salted hash exists
  to make offline GUESSING expensive, and there is nothing to guess in 32 bytes
  from `random_bytes()`. What it would cost is the lookup: both salt per row, so
  a presented value's digest cannot be computed without first knowing which row
  it belongs to — meaning either a plaintext column to find the row by, or
  verifying against every row in the table with a deliberately expensive
  operation per row, which is a denial-of-service endpoint anyone can dial by
  sending junk. Consequence worth naming: there is NO secret comparison anywhere
  in this plugin, so the usual `hash_equals`-versus-`===` hazard cannot arise.

- **The `restoken` route filter puts the resource in the manifest and the id in
  the route.** `restoken:file,id` means resource type `file` with the id taken
  from the matched route's `{id}`. The resource a token is checked against is
  therefore the one the router already resolved — checking it by hand inside the
  controller is the version that gets forgotten on the fourth endpoint. The
  verdict's token is attached as a request attribute so a controller need not
  verify twice, which would spend two uses of a single-use token.

- **A route naming the filter without `requires: ["resource.tokens"]` FAILS.** A
  module's `register()` only runs when it is in that request's dependency graph,
  so the alias resolving does not imply the bindings exist. Failing open there
  would turn one missing line of JSON into a private file served to anybody who
  asked, so it is a 500 with a message naming the fix.

- **Every refusal produces the same response**, 404 by default, and the reason
  goes only to the log. Telling the presenter which check failed is free
  reconnaissance: an expiry confirms the token was real and the resource exists,
  "wrong resource" confirms the token is live and invites trying every id in
  range, "exhausted" says the recipient already collected the file.
  `RESOURCE_TOKENS_REFUSED_STATUS` is clamped to 4xx, because a 2xx or 3xx would
  turn every refusal into a SUCCESS from one typo in an `.env` file.

- **The header is preferred over the query string.** A query string is written
  to a web server's access log, kept in browser history, and sent onward in a
  Referer from any outbound link on the page. The query string is supported
  anyway because it is the only option for the case this plugin exists for — a
  browser fetching an `<img src>` cannot set a header — and short lifetimes are
  the mitigation. The token is never read from the request BODY: the URL and the
  header are controlled by the recipient of the link, a body by whatever page
  did the submitting.

- **Use tracking can be turned off only where it is free.**
  `RESOURCE_TOKENS_TRACK_USES=false` skips the write for UNLIMITED tokens, which
  is the `<img src>` case — an UPDATE per image request is how a read path
  becomes a replication bottleneck. A LIMITED token always writes, because the
  write IS the enforcement; if the switch could reach it, the setting would
  silently turn every single-use link into an unlimited one.

- **Abilities default to `read`, and matching is exact.** Every omitted argument
  in a security API is a decision made by whoever wrote the default. `'*'` is
  allowed but only ALONE — `['*', 'read']` is refused rather than collapsed,
  because it means the author believed the list was additive. No prefix
  matching: if `file.read` implied `file.read.metadata`, then the day somebody
  added a `file.delete` ability every existing `file`-prefixed token would
  quietly acquire it.

- **The resource type is canonical and the resource id is opaque.** The type is
  refused rather than lower-cased, because the same name is written where a
  token is minted and where it is verified and a silent correction would hide a
  mismatch until a token could not be used in production. The id is compared
  byte for byte and never normalised, because lowercasing one would conflate two
  different resources in any case-sensitive id space — which is the confused
  deputy again.

- **No HTTP routes, no security layer, no `Identity`, and no mint command.** Who
  may mint a token for a resource is the same question as who may read it, and
  only the application can answer it. A security layer runs before any module
  loads and produces an Identity, which a resource token must never do: every
  token would then be an account that passed the gateway and reached every route
  in the application, with the resource reduced to a comment on a row. Minting
  is absent from the CLI because it returns a secret that must be shown once,
  and `restokens:revoke` takes an id or a resource — never a token — for the
  same reason.

- **A `digest` prefix for incident response.** `restokens:list` shows twelve
  characters of the SHA-256, so a token found in an access log can be traced to
  its row with `shasum -a 256` without the token itself ever being typed into a
  command. It is not a credential — presenting a digest authenticates nothing,
  because verification hashes whatever it is given — and the full digest is
  withheld because it would be the obvious thing to paste into a URL by somebody
  who assumed it was the token.

- **Three settle timestamps rather than one.** `revoked_at`, `expires_at` and
  `exhausted_at` are separate columns so each arm of the prune query has its own
  index, and so the plugin can say WHY a token is no longer live. A LIVE token is
  never pruned however old it is: a deliberately permanent token for an avatar is
  not stale, and it is the one deletion here that cannot be undone, since the
  secret cannot be recovered to re-create the row.

### Fixed during development

- **`"file\n"` passed the resource-type validation.** PCRE's `$` matches at the
  end of the subject OR immediately before a final newline, so an anchored
  pattern is not anchored at all against a value with a trailing `\n`. The type
  was accepted, stored, and would have been printed into a log line and a CLI
  table carrying a newline it could use to forge a line of either. Every
  anchored pattern in the plugin now carries the `D` modifier
  (`PCRE_DOLLAR_ENDONLY`), and there is a trailing-newline case per pattern.

  The same miss was in the header-name guard, where the comment directly above
  the regex claimed to stop exactly this. There `trim()` happens to catch the
  trailing case, so the modifier is belt and braces — but the comment described
  a guard the pattern did not implement.

- **`ResourceTokenConfig::of()` bypassed every clamp and both name checks.** All
  of it lived in `fromEnv()`, so the constructor used by the route filter and by
  every test went straight past it — and a refusal status of `200`, the one
  setting that turns every refusal into a private resource served to anybody, was
  reachable. Normalisation moved into the private constructor. Validation that
  lives in one of two factories is not an invariant, it is a habit.

- **An unreadable `expires_at` would have made a dead token permanent.** The
  hydrator parsed every timestamp leniently, and `null` in that column means
  NEVER EXPIRES — so a date the parser could not read collapsed into "absent"
  and promoted the row. There is no instant that means "refuse", so the only
  fail-closed option is to drop the row, and `expires_at` and `revoked_at` are
  now parsed where a failure does that. The cosmetic columns stay lenient,
  because an unreadable `last_used_at` must not take a live token out of
  circulation.

### Notes

- Verification **records a use**. That is the contract, not a surprise: a limit
  cannot be enforced without the check and the count being one operation.
  Calling `verify()` twice for one request spends two uses of a single-use token.
- `restokens:status` exits non-zero only when settled rows have built up past
  the point where the prune is clearly not running. Expired and spent rows
  waiting for the window are the ordinary life of the table, and a status
  command that is always red is one nobody reads.
- **Without a `LoggerPort` bound, refusals leave no trace.** The response is
  identical for all seven reasons, so the log is the only place the reason
  exists — and "the link doesn't work" is the only symptom a user will report.
- `markExhaustedIfSpent()`'s `exhausted_at IS NULL` guard is **unpinned**: a
  revert check established that removing it passes the whole suite. It is
  unreachable sequentially, and its one real scenario needs two processes
  interleaving between the two statements of a single `consume()` call. It is
  kept for the idempotence it makes evident on the face of the statement.
- The per-resource cap is a guard rail against a loop, not a security boundary:
  two concurrent mints can both pass the count. What it catches is code that
  mints a token per request where one should be reused.
