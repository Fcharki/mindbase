# Database Structure

The Knowledge Manager datastore. PostgreSQL 18 with pgvector, driven by Eloquent
on Laravel 12. 20 migrations, 20 tables — 10 for the app, 10 that Laravel and
Sanctum bring along.

This covers what each table is for, why it's shaped the way it is, and the
handful of things in here that will bite.

**Contents** — [The tables](#the-tables) · [Indexes](#indexes) ·
[Search](#how-search-works) · [Revision tracking](#content_hash) ·
[Deletes](#deleting-things) · [Tenancy](#multi-tenancy) ·
[Problems](#problems-worth-fixing) · [Priority](#if-you-fix-things)

---

## The tables

Table names are colour-coded by area, so it's quick to tell at a glance which
part of the system a table belongs to.

| Colour | Area |
| --- | --- |
| <span style="color:#0969da">**blue**</span> | Accounts and sign-in |
| <span style="color:#bc4c00">**orange**</span> | Workspaces and who can see them |
| <span style="color:#1a7f37">**green**</span> | The actual content |
| <span style="color:#8250df">**purple**</span> | Retrieval and the assistant |
| <span style="color:#6e7781">**grey**</span> | Laravel plumbing |

### <span style="color:#0969da">Accounts</span>

#### <span style="color:#0969da">users</span>

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `name` | varchar | |
| `email` | varchar | unique |
| `google_id` | varchar | unique, nullable — added in a later migration |
| `email_verified_at` | timestamp | nullable |
| `password` | varchar | hashed via cast; still required even for Google-only accounts |
| `remember_token` | varchar | |
| `two_factor_secret` | text | nullable, encrypted TOTP seed |
| `two_factor_recovery_codes` | text | nullable, JSON |
| `two_factor_confirmed_at` | timestamp | nullable |
| `created_at`, `updated_at` | timestamp | |

`two_factor_confirmed_at` is the one that isn't obvious. A user can start 2FA
enrolment and abandon it, and without this column a half-finished account looks
identical to a working one — so the login flow would demand a code from someone
who never set one up.

`google_id` is unique and nullable, which is fine: Postgres lets unlimited
`NULL`s through a unique index, so password-only accounts don't collide.

#### <span style="color:#0969da">passkeys</span>

WebAuthn credentials. `user_id` FK (cascade), `name`, `credential_id` (unique —
the lookup key at sign-in), `credential` (json), `last_used_at`.

The explicit `user_id` index is not redundant. Postgres doesn't index the
referencing side of a foreign key, so listing someone's credentials would
otherwise scan the table.

#### <span style="color:#0969da">personal_access_tokens</span>

Sanctum. `tokenable_type` + `tokenable_id` (polymorphic, indexed), `token`
(unique, hashed at rest), `abilities`, `last_used_at`, `expires_at` (indexed).

#### <span style="color:#0969da">password_reset_tokens</span> and <span style="color:#0969da">sessions</span>

Stock Laravel. `password_reset_tokens` keys on `email`, so a new reset request
overwrites the old one — that's intentional.

`sessions` is created but stays empty. Auth is Sanctum bearer tokens; the
database session driver isn't in play.

### <span style="color:#bc4c00">Workspaces</span>

#### <span style="color:#bc4c00">workspaces</span>

The tenant root.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `name` | varchar | |
| `invite_code` | varchar(32) | unique, nullable — set in a model hook |
| `owner_id` | bigint | FK → `users`, **cascade** |
| `created_at`, `updated_at` | timestamp | |

`unique (owner_id, name)` means two people can each have a workspace called
"Personal", but you can't have two in one account.

`invite_code` is `Str::random(10)` from a `creating` hook. The column is declared
32 wide for a 10-character value — harmless, but the two numbers disagree.

> `owner_id` is covered by the `(owner_id, name)` unique, so owner lookups are
> indexed.

#### <span style="color:#bc4c00">workspace_members</span>

Membership with a role, modelled as a pivot with a custom `Pivot` class so
`role` is readable as an attribute.

`workspace_id` and `user_id` (both cascade), `role` defaulting to `member`, plus
`unique (workspace_id, user_id)`. Roles are `owner`, `member`, `viewer`.

The surrogate `id` is redundant — the pair above is already unique — but it
keeps the table uniform and lets Eloquent use the `Pivot` class without a
composite-key override.

> The unique constraint leads with `workspace_id`, so it can't serve lookups by
> `user_id` — which is the direction every policy check goes. Hence the separate
> `user_id` index. See
> [foreign keys](#every-foreign-key-column-is-indexed).

#### <span style="color:#bc4c00">membership_requests</span>

Asks to join a workspace. `status` (`pending` / `approved` / `rejected`) and
`requested_role` (`member` / `viewer`), both with a `pending` default.

The interesting part is that this table holds *current state* in a row that
also has a lifecycle. A rejection is recorded in place rather than deleted, so
the record of who asked and who said no survives. That works fine — until the
unique constraint gets in the way. See
[A rejected user can never ask again](#rejected-users-can-never-request-again).

The policy raises 409 with distinct wording for "you already own this", "you're
already a member", and "you already asked", so the client can tell them apart
from a 403.

### <span style="color:#1a7f37">Content</span>

#### <span style="color:#1a7f37">knowledge_entries</span>

The central record.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `workspace_id` | bigint | FK → `workspaces`, cascade |
| `created_by` | bigint | FK → `users`, **cascade** |
| `title` | varchar | |
| `content` | longtext | nullable — **rich text HTML**, not plain text |
| `created_at`, `updated_at` | timestamp | |

`content` stores HTML from the editor, and three different places strip it
differently: the GIN index uses `regexp_replace`, `ChunkingService::flatten()`
removes `<script>`/`<style>` bodies then decodes entities, and the SQLite search
path does a raw `LIKE` against the markup.

> `workspace_id` is indexed as `(workspace_id, created_at)`, which serves both
> the tenant filter and the newest-first ordering — see
> [foreign keys](#every-foreign-key-column-is-indexed).

> `created_by` cascades, so deleting a user takes out entries they wrote even
> when the workspace and its other members are fine. See
> [Deleting a user takes their workspaces with them](#user-deletion-destroys-workspaces).

#### <span style="color:#1a7f37">tags</span>

`workspace_id` (cascade), `name`, `unique (workspace_id, name)`, plus a GIN
index on `to_tsvector('english', name)`.

Tags belong to a workspace rather than being global, and that's what makes the
composite unique correct — two workspaces can each have an `urgent` tag. The
composite also happens to cover `workspace_id` lookups, so no extra index needed.

#### <span style="color:#1a7f37">knowledge_entry_tag</span>

The entries↔tags pivot. Just `knowledge_entry_id` and `tag_id`, both cascade,
with a composite primary key on the pair. No surrogate key, no timestamps.

The composite PK makes duplicate tagging impossible at the storage layer, which
is the entire job of a pivot.

> `tag_id` is indexed, because the composite primary key leads with
> `knowledge_entry_id` and reverse traversal — "which entries have this tag",
> including the `withCount('entries')` in tag search — needs the other
> direction.

#### <span style="color:#1a7f37">documents</span>

Metadata for an uploaded file. The bytes live on a disk, not in the database.

| Column | Type | Notes |
| --- | --- | --- |
| `knowledge_entry_id` | bigint | FK → `knowledge_entries`, cascade |
| `uploaded_by` | bigint | FK → `users`, nullable, **set null** |
| `disk` | varchar | default `documents` |
| `path` | varchar | |
| `original_name` | varchar | |
| `mime_type` | varchar | nullable |
| `size` | bigint | default 0 |
| `checksum` | char(64) | nullable, hex SHA-256 |
| `created_at`, `updated_at` | timestamp | |

Three decisions worth knowing:

`disk` is stored per row so the local disk can be swapped for S3 later without
rewriting anything, and so files can live in different places.

`uploaded_by` is **set null**, not cascade. Delete the uploader and the file and
its metadata survive — only the attribution goes. That's the right call for
content other people depend on, and it's the opposite of what
`knowledge_entries.created_by` does.

Nothing keeps the bytes in Postgres. `TOAST` would handle it, but storing
uploads in the database makes backups, replication, and `SELECT *` worse for no
gain, since the content is never queried.

Cascades don't fire Eloquent events, so both `KnowledgeEntry` and `Document`
have `deleting` hooks to remove the file from disk first. That covers deletion
through the model — but a raw `DELETE` or a mass delete skips it and orphans
the file permanently.

#### <span style="color:#1a7f37">summaries</span>

The stored summary of an entry. One row per entry, rewritten in place.

`knowledge_entry_id` (cascade, **unique**), `content_hash` char(64),
`body` longtext, `backend` varchar(32) — which model wrote it.

Four choices here look odd until you know why, and they're exactly the sort of
thing someone "tidies up" later:

**Unique on `knowledge_entry_id`, not a history table.** A reader always wants
the current summary. A second row for the same entry would be ambiguous rather
than historical.

**`content_hash` is load-bearing.** Generation is queued and slow, so without a
fingerprint a summary of revision *N* can land after one of revision *N+1* and
overwrite it with stale text — and nothing in the schema would record that the
two disagree. See [content_hash](#content_hash).

**`backend` matters because cached summaries outlive a model swap.** Without it
you can't tell which rows would need regenerating.

**`char(64)` matches SHA-256 exactly** — 64 hex characters, no truncation, no
wasted space.

### <span style="color:#8250df">Retrieval</span>

#### <span style="color:#8250df">entry_chunks</span>

The retrieval substrate. Each row is one embedded passage, carrying both the
text a keyword index can match and the vector a similarity search can rank.
This is the most heavily indexed table in the schema, and deliberately so — it
serves two unrelated access patterns from one place.

| Column | Type | Notes |
| --- | --- | --- |
| `knowledge_entry_id` | bigint | FK → `knowledge_entries`, cascade |
| `source` | varchar(16) | `summary` or `content` |
| `position` | integer | ordinal within `(entry, source)` |
| `content_hash` | char(64) | revision fingerprint |
| `body` | text | **plain text** — markup already stripped |
| `embedding` | vector(1024) | pgvector; plain `text` on SQLite |
| `created_at`, `updated_at` | timestamp | |

Indexes on it:

- `CREATE EXTENSION IF NOT EXISTS vector`
- unique `(knowledge_entry_id, source, position)`
- composite `(knowledge_entry_id, source)`
- **HNSW** on `embedding`, opclass `vector_cosine_ops`
- **GIN** on `to_tsvector('english', body)`

A few things about this table that aren't visible from the columns:

**The vector width is coupled to the model.** The column is built from
`config('ai.embedding_dimension')`, default 1024, matching bge-m3. Change the
embedding model and you change that config, which means rebuilding the column
and every vector in it. Nothing in the schema records which model the stored
vectors came from.

**The HNSW opclass matches the query operator.** Laravel's `vectorIndex()` emits
`vector_cosine_ops` and the search uses `<=>`, so the index is genuinely used.
An L2 or inner-product index would be silently ignored by a cosine query and
degrade to a scan — that mismatch doesn't exist here, but it's a one-word change
away.

**`source` and `position` are unique together, not globally.** Positions count
from zero independently per source, so a summary chunk at 0 and a content chunk
at 0 are different rows.

**Chunks are replaced wholesale, not merged.** Editing an entry drops and
rewrites all of its chunks in one transaction. Without that, a shortened entry
would leave rows from the longer version still answering searches with text the
entry no longer contains.

**`body` is stored already flattened,** which is why its GIN index needs no
`regexp_replace` and why a search result reads as legible text.

#### <span style="color:#8250df">assistant_messages</span>

One question asked of a workspace's assistant, and the answer it produced.

`workspace_id` and `user_id` (both cascade), `question`, `answer` (longtext,
**nullable**), `sources` (json, nullable), `status` defaulting to `pending`, and
`error` varchar(255). Indexed `(workspace_id, created_at)` because history is
always read newest first.

Four decisions carry weight:

**`status` exists so a polling client can tell three states apart.** Without it,
"still working" and "gave up" look identical, and a failure reads as an answer
that never arrived. `pending` is the normal state for most of a row's life,
because generation takes minutes while the request returns `202` immediately.

**`sources` is frozen with the answer.** Re-running retrieval when the answer is
read would cite whatever is indexed *now*, not what the model actually read. The
stored passages are the audit trail, and every citation points at real text.

**A failed row stays.** The job writes `status = failed` and a readable `error`
instead of deleting the row. An unanswered turn is more honest than a missing
one, and it tells the user retrying is worth it.

**`sources` is json, not a join table.** It's an immutable snapshot, only ever
read alongside its message, never queried on its own — so a normalized table
would add joins and a cascade for nothing. The trade-off is that you can't ask
"which answers cited entry 42?" in SQL.

### <span style="color:#6e7781">Laravel plumbing</span>

| Table | For |
| --- | --- |
| <span style="color:#6e7781">jobs</span> | The queue. `queue` indexed, `payload`, `attempts`, `reserved_at`, `available_at` |
| <span style="color:#6e7781">job_batches</span> | `Bus::batch` counters |
| <span style="color:#6e7781">failed_jobs</span> | Dead-letter queue. `uuid` unique, `payload`, `exception` |
| <span style="color:#6e7781">cache</span> | `key` PK, `value`, `expiration` indexed |
| <span style="color:#6e7781">cache_locks</span> | `key` PK, `owner`, `expiration` indexed |

The database being the queue backend is what lets generation be deferred without
adding Redis. It also makes `jobs.reserved_at` load-bearing — see
[Timeouts](#timeouts-have-to-stay-in-order).

---

## Indexes

### What's there

| Table | Index | Type | Serves |
| --- | --- | --- | --- |
| <span style="color:#bc4c00">workspaces</span> | `(owner_id, name)` | unique | Owner-scoped naming |
| <span style="color:#bc4c00">workspaces</span> | `invite_code` | unique | Invite redemption |
| <span style="color:#bc4c00">workspace_members</span> | `(workspace_id, user_id)` | unique | No duplicate members |
| <span style="color:#bc4c00">membership_requests</span> | `(workspace_id, user_id)` | unique | No duplicate requests |
| <span style="color:#1a7f37">tags</span> | `(workspace_id, name)` | unique | Workspace-scoped names |
| <span style="color:#1a7f37">tags</span> | `to_tsvector('english', name)` | **GIN** | Tag search |
| <span style="color:#1a7f37">knowledge_entries</span> | `to_tsvector('english', regexp_replace(title ‖ content))` | **GIN** | Entry search |
| <span style="color:#1a7f37">documents</span> | `(knowledge_entry_id, created_at)` | B-tree | Entry file listing |
| <span style="color:#1a7f37">summaries</span> | `knowledge_entry_id` | unique | One summary per entry |
| <span style="color:#8250df">entry_chunks</span> | `(knowledge_entry_id, source, position)` | unique | Chunk identity, wholesale replace |
| <span style="color:#8250df">entry_chunks</span> | `(knowledge_entry_id, source)` | B-tree | Source-filtered reads |
| <span style="color:#8250df">entry_chunks</span> | `embedding` | **HNSW** | Similarity search |
| <span style="color:#8250df">entry_chunks</span> | `to_tsvector('english', body)` | **GIN** | Lexical search |
| <span style="color:#8250df">assistant_messages</span> | `(workspace_id, created_at)` | B-tree | Newest-first history |
| <span style="color:#0969da">passkeys</span> | `user_id` | B-tree | Credential listing |
| <span style="color:#0969da">personal_access_tokens</span> | `(tokenable_type, tokenable_id)` | B-tree | Polymorphic lookup |
| <span style="color:#1a7f37">knowledge_entries</span> | `(workspace_id, created_at)` | B-tree | Entry listing — see [foreign keys](#every-foreign-key-column-is-indexed) |
| <span style="color:#1a7f37">knowledge_entries</span> | `created_by` | B-tree | Author's entries |
| <span style="color:#1a7f37">knowledge_entry_tag</span> | `tag_id` | B-tree | Reverse lookup — tag → entries |
| <span style="color:#1a7f37">documents</span> | `uploaded_by` | B-tree | Hygiene; no query uses it yet |
| <span style="color:#bc4c00">workspace_members</span> | `user_id` | B-tree | Membership checks — see [foreign keys](#every-foreign-key-column-is-indexed) |
| <span style="color:#bc4c00">membership_requests</span> | `user_id` | B-tree | "My requests" |
| <span style="color:#8250df">assistant_messages</span> | `user_id` | B-tree | A user's own history |

The two `knowledge_entries` and `entry_chunks` GIN indexes are **expression**
indexes, which means the query has to spell out the identical expression. Both
migrations and `SearchService::documentVector()` carry a comment saying so.

If the two drift — someone changes `'english'` to `'simple'`, or drops the
`regexp_replace` — Postgres doesn't complain. The index stays valid and simply
stops being used. No error, no warning, no failing test; search just quietly
becomes a table scan.

### Every foreign key column is indexed

`foreignId()->constrained()` emits only a `FOREIGN KEY` clause. Postgres doesn't
index the referencing side of a foreign key, and neither does Laravel, so a
column is covered only when some other index happens to lead with it.

That left seven columns uncovered until
`2026_10_04_000000_add_missing_foreign_key_indexes.php` added them. Three were
on hot paths:

| Column | Query it serves |
| --- | --- |
| <span style="color:#1a7f37">knowledge_entries.workspace_id</span> | Every entry list, every search, every nested route |
| <span style="color:#bc4c00">workspace_members.user_id</span> | `$user->workspaces()`, which **every policy gate** calls |
| <span style="color:#1a7f37">knowledge_entry_tag.tag_id</span> | `Tag::entries()` and the `withCount('entries')` in tag search |

The rest — `knowledge_entries.created_by`,
<span style="color:#bc4c00">membership_requests.user_id</span>,
<span style="color:#8250df">assistant_messages.user_id</span>,
<span style="color:#1a7f37">documents.uploaded_by</span> — are low-traffic but
indexed so the rule holds uniformly as code gets added.

`knowledge_entries` got one composite rather than `workspace_id` *and*
`(workspace_id, created_at)`. A composite serves lookups on its leading column
too, so a single-column index there would be a second copy of the same prefix.
The composite also means `KnowledgeEntryController`'s `orderBy('created_at')` is
answered by the index instead of a sort.

Plain `CREATE INDEX`, not `CREATE INDEX CONCURRENTLY`. Concurrent builds can't
run inside the transaction that wraps a migration, and a build interrupted part
way leaves an invalid index that has to be dropped by hand — the wrong trade at
this size. The migration's `down()` documents what to change if that stops
being true.

---

## How search works

Three tables: <span style="color:#8250df">entry_chunks</span> for passages,
<span style="color:#1a7f37">knowledge_entries</span> for scoping,
<span style="color:#1a7f37">tags</span> for filtering.

**Chunks, not entries.** One embedding per entry averages every topic in it
together, so a question about any single topic lands in the same blur.
`ChunkingService` splits text at roughly 1800 characters with 300 characters of
overlap, capped at 60 chunks per entry. The overlap matters — without it, a
sentence straddling a boundary gets embedded as two fragments and neither one
contains the whole thought.

**Two sources per entry.** The `summary` chunk matches broad questions cheaply;
the `content` chunks carry the specific terms the summary dropped.

**Two queries, fused.** Lexical is `tsvector @@ tsquery` ranked by `ts_rank` —
the only thing that finds an exact identifier like `E4021`, but blind to
meaning. Semantic is `embedding <=> ?` — handles paraphrase, but has no notion
of an exact string and will happily rank a fluent passage about something else.

Fusion is Reciprocal Rank Fusion with `k = 60`, and it combines **by rank, not by
value**. A `ts_rank` of 0.08 and a cosine distance of 0.31 aren't comparable and
neither is calibrated across queries, so weighting them together would mostly be
measuring the two scoring functions. RRF uses position in each list, which is
scale-free.

Three guards worth not removing by accident:

- one vote per chunk per engine, so a chunk repeated in one list can't float to
  the top on position alone
- a **distance floor of 0.7** — bge-m3 doesn't spread vectors over the full
  range; paraphrases measured 0.25–0.39 here, unrelated ones 0.72–0.75, so 0.7
  sits in the gap
- embedding failures degrade instead of breaking: if the AI service is down, the
  semantic side logs a warning and the user still gets keyword results

**One scaling note.** The HNSW index is global and the workspace filter applies
*after* the ANN search — pgvector can't partition a vector index by tenant. So
a large workspace's nearest neighbours can crowd out a small workspace's. The
`× 5` candidate multiplier covers this, and no result ever crosses a tenant
boundary. What isn't guaranteed is that a small workspace sees its own best
passages in the top 8 under heavy global load. Worth knowing before it's
discovered as a quality complaint.

---

## content_hash

The subtlest thing in the schema, and the easiest to break by accident.

Three queued jobs write derived rows. All of them carry a fingerprint of the
content **at dispatch time**, and all of them re-check it before writing:

```php
hash('sha256', (string) $content)   // EntrySummaryService::hashFor()
```

The sequence:

1. An edit lands. Content hashes to `H1`.
2. Jobs are dispatched carrying `H1` — not the content itself.
3. The user edits again. Content now hashes to `H2`; new jobs carry `H2`.
4. The `H1` job finishes, re-reads the entry, computes the current hash, finds
   `H2 ≠ H1`, and **returns without writing.**

Generation takes minutes and edits take seconds, so step 4 arriving out of order
is the common case, not the exception. Without the guard, a summary of revision
1 silently overwrites revision 2 and nothing records the disagreement.

Note the ordering detail: the hash is carried **on the job**, not read at
handling time. Reading it at handling time would compare the content against
itself and always match, which defeats the whole mechanism.

One more subtlety in <span style="color:#8250df">entry_chunks</span>: chunk
positions are assigned *before* embedding, so a chunk the model returns nothing
for gets skipped and leaves a harmless gap in the sequence rather than colliding
with its neighbour. The unique constraint is on `(entry, source, position)` — a
gap is safe, a duplicate is not.

---

## Deleting things

Everything is a hard delete. No `softDeletes()` anywhere, so there's no recovery
once a cascade has run.

| Delete | Takes with it |
| --- | --- |
| <span style="color:#0969da">users</span> (as owner) | <span style="color:#bc4c00">workspaces</span> → everything inside, including other members' data |
| <span style="color:#0969da">users</span> (as creator) | <span style="color:#1a7f37">knowledge_entries</span> → chunks, summaries, documents, tags |
| <span style="color:#0969da">users</span> (as member) | <span style="color:#bc4c00">workspace_members</span> row only |
| <span style="color:#0969da">users</span> (as asker) | <span style="color:#8250df">assistant_messages</span> history |
| <span style="color:#0969da">users</span> (as uploader) | Nothing — <span style="color:#1a7f37">documents.uploaded_by</span> is set null |
| <span style="color:#bc4c00">workspaces</span> | Members, requests, entries, tags, messages — transitively |
| <span style="color:#1a7f37">knowledge_entries</span> | Tags, documents, summary, chunks |

The first row is the one worth pausing on. Deleting one user can destroy an
entire workspace that several other people are actively using.

The fourth row is the counterexample worth copying: same kind of authorship
reference, opposite resolution. One preserves content others depend on, the
other destroys it.

---

## Multi-tenancy

Isolation lives in two places, and notably not in the database.

**Relationships** scope every query from the workspace —
`$workspace->knowledgeEntries()`, `->tags()`, `->assistantMessages()`,
`->membershipRequests()`.

**Policies** check membership. `WorkspacePolicy` gates update, delete, and
member management on `owner_id`. `KnowledgeEntryPolicy` gives write access to
`owner` and `member`. `DocumentPolicy` is stricter than `KnowledgeEntryPolicy`
for reads — uploads need membership, entry text doesn't.

**The database enforces nothing.** `workspace_id` columns and foreign keys keep
the shape of the data consistent; they don't stop a query that forgets to scope.
No Postgres RLS is in place, so a join across workspaces without a tenant
predicate returns cross-tenant rows and no constraint complains. That's the most
likely place a data leak would come from in this schema.

Worth knowing: `KnowledgeEntryPolicy::view()` returns `true` unconditionally.
Any authenticated user can view any entry they hold a reference to. That's a
deliberate product choice — browse-as-viewer by default — but it means entry
text isn't tenant-secret. Only the routes that resolve a workspace are.

---

## Problems worth fixing

Ordered by how likely each is to cause real trouble.

### Two migrations can't be rolled back

<span style="color:#1a7f37">knowledge_entry_tag</span>'s migration creates the
singular table name but its `down()` drops the plural one — a silent no-op that
leaves the table behind while the ledger claims it's gone.
(`2026_09_07_003133_create_knowledge_entry_tags_table.php:27`)

`add_google_id_to_users_table` has a `down()` containing nothing but a comment.
(`2026_09_12_223945_add_google_id_to_users_table.php:26`)

Both bite during a failed deploy or a `migrate:fresh` session, when the leftover
table collides with the re-run `Schema::create`. Both are a few lines to fix.

### Rejected users can never request again

`membership_requests` has `unique (workspace_id, user_id)`, so a user gets
exactly one request row per workspace *ever*. But the policy only blocks a
duplicate when the existing request is `pending`:

```php
$alreadyPending = $workspace->membershipRequests()
    ->where('user_id', $user->id)
    ->where('status', MembershipRequest::STATUS_PENDING)
    ->exists();

if ($alreadyPending) {
    throw $this->conflict('You already have a pending request for this workspace.');
}

return true;
```

So someone whose request was **rejected** passes the gate, and the action's
plain `create()` then trips the unique constraint. The user gets a `500` from an
unhandled `QueryException` instead of a new pending request.

The cleanest fix keeps both the table's history intent and the policy's stated
semantics — allow re-request, keep the rejected rows:

```sql
CREATE UNIQUE INDEX membership_requests_pending_unique
    ON membership_requests (workspace_id, user_id)
    WHERE status = 'pending';
```

### Authorization was doing table scans

`workspace_members.user_id` had no index, and the table's unique constraint leads
with `workspace_id` so it couldn't serve the lookup either. Every policy that
checks membership ran:

```php
$user->workspaces()
    ->where('workspaces.id', $workspaceId)
    ->wherePivotIn('role', ['owner', 'member'])
    ->exists();
```

`User::workspaces()` is a `belongsToMany`, so that's a join filtered on
`user_id` — one sequential scan per authorization check.

Fixed by
[2026_10_04_000000_add_missing_foreign_key_indexes.php](../backend/database/migrations/2026_10_04_000000_add_missing_foreign_key_indexes.php).

One related problem is still open, because it's an application issue rather than
a schema one: `DocumentPolicy::workspaceIdFor()` lazily loads
`$document->knowledgeEntry` on every check, so listing documents is an N+1.
Eager loading the relation before authorizing would fix it.

### User deletion destroys workspaces

`workspaces.owner_id` and `knowledge_entries.created_by` are both `cascade`. One
`DELETE FROM users` for someone who owns a workspace takes out the workspace,
all its entries, every chunk and embedding, every summary, every document, and
every assistant message — including ones asked by other members. There are no
soft deletes, so this is unrecoverable except from a backup.

The workspace, not the user, is the durable unit here, so:

- `workspaces.owner_id` → **restrict**, and require ownership transfer first
- `knowledge_entries.created_by` → **set null**, preserving content and matching
  what `documents.uploaded_by` already does

Both make the schema internally consistent about authorship.

### role and status have no database constraint

`role`, `status`, and `requested_role` are plain `varchar`. The legal values
exist only as PHP constants, so the database will happily store `'admin'` — and
such a row matches no policy branch and silently *denies* access rather than
granting it.

Nothing outside the models writes these today, so the risk is a typo in a
seeder or a hand-written `UPDATE`. Cheap to close:

```sql
ALTER TABLE workspace_members
    ADD CONSTRAINT workspace_members_role_check
    CHECK (role IN ('owner', 'member', 'viewer'));

ALTER TABLE assistant_messages
    ADD CONSTRAINT assistant_messages_status_check
    CHECK (status IN ('pending', 'ready', 'failed'));
```

### Email is case-sensitive

`users.email` is unique under Postgres' default comparison, so
`Bob@example.com` and `bob@example.com` are two accounts that both receive reset
emails. Worse, `password_reset_tokens` uses `email` as its *primary key*, so
there the collision doesn't even error — it just writes a second row.

Lowercase on write is one line and keeps the stored value equal to what the user
typed. A `citext` column or a unique index on `lower(email)` also work.

### Smaller ones

**`invite_code` has no collision retry.** The `creating` hook assigns
`Str::random(10)` unconditionally and `Str::random` doesn't check for existing
values. Probability is negligible — 62 characters of entropy over 62 possible
values — but the fix is a `do…while` and the retry is what's actually missing.

**No dimension metadata on the vectors.** Nothing records which model or
dimension `entry_chunks.embedding` holds. A model swap fails per-row with a
Postgres error naming a dimension rather than a model. A small metadata table
checked by `IndexEntryChunks` before its first write would turn that into one
clear error.

**32-bit timestamps wrap in 2038.** `jobs.available_at`, `jobs.reserved_at`,
`cache.expiration`, `sessions.last_activity` and friends are `unsignedInteger`
holding Unix seconds. Twelve years out, and these are stock Laravel migrations,
so fix it in a migration of your own rather than by editing the framework
default.

**Storage is dominated by chunks.** A 1024-float vector is ~4 KB binary, ~9 KB
as the text Postgres actually stores. At the 60-chunk cap one maximum-size
entry is roughly 0.5 MB before the GIN index. Two costs stand out: the
`knowledge_entries` GIN index duplicates rich-text `content` into the index (so
it's a large share of that table and every write updates it), and each edit
deletes and rewrites all of an entry's vectors. If this becomes the issue,
dropping the `knowledge_entries` GIN index and searching chunks only is the
first thing to try — chunk bodies are already plain text and cover the same
content.

---

## If you fix things

| # | Change | Effort | Cost of waiting |
| --- | --- | --- | --- |
| 1 | Fix the two broken `down()` methods | ~5 lines | Rollback and `migrate:fresh` breakage |
| 2 | Partial unique index on pending requests | One migration | `500` on a permitted user action |
| 3 | `owner_id` → restrict, `created_by` → set null | One migration | Unrecoverable workspace loss |
| 4 | `CHECK` constraints on `role` / `status` | One migration | Silent miscategorisation |
| 5 | Lowercase email on write | 1 line + call sites | Duplicate accounts |
| 6 | Share the `to_tsvector` expression | Small refactor | Silent loss of index usage |
| 7 | Embedding dimension metadata | One small table | Confusing failure on model swap |
| 8 | Eager-load relations before authorizing | Small refactor | N+1 on document listings |
| 9 | Postgres-backed CI job | CI config | Vector path ships untested |

Already done: the
[missing foreign key indexes](#every-foreign-key-column-is-indexed) went in as
`2026_10_04_000000_add_missing_foreign_key_indexes.php`.

Items 1, 5, 6, and 8 are additive or app-side — ship them. Items 2–4 need a data
check first: no existing `membership_requests` row may violate the new partial
index, no user may own a workspace that `restrict` would block, and no existing
row may carry a `role` or `status` outside the new `CHECK` lists. A `CHECK` added
to a table that already violates it fails, and the bad rows have to be fixed
first.

---

## Running it

```bash
docker compose up -d --build
docker compose exec backend php artisan migrate
```

`create_entry_chunks` issues `CREATE EXTENSION IF NOT EXISTS vector`, so the
database user needs superuser rights on first run. The `pgvector/pgvector:pg18`
image ships the extension files but doesn't create the extension.

```bash
cd backend && php artisan test      # 192 tests, in-memory SQLite
```

### Tests run on a different database than production does

Four migrations branch on the driver instead of assuming Postgres. The full-text
migrations no-op, and <span style="color:#8250df">entry_chunks</span> gets a
plain `text` column instead of `vector(1024)`. The search services fall back to
`LIKE`, and the semantic half of hybrid search returns nothing because there's no
vector column to query.

That's a reasonable trade — 192 tests in seconds with no container — and the
README is straight about it. The gap is the HNSW path and the fusion of two real
ranked lists; neither is covered. If retrieval becomes the thing being changed,
a Postgres job in CI is the cheapest way to stop guessing.

### Timeouts have to stay in order

Generation is queued because the model needs minutes on CPU, longer than a
request may hold a worker. That makes `jobs.reserved_at` load-bearing:

```
DB_QUEUE_RETRY_AFTER (3000) > job $timeout (2700) > ai.answer_timeout (2400)
                        > ai.background_timeout (600) > ai.timeout (120)
```

`reserved_at` is what implements the left edge: a worker reserves a row for
`DB_QUEUE_RETRY_AFTER` seconds, and if it dies without releasing it the job
becomes visible again. Set `DB_QUEUE_RETRY_AFTER` below any job's `$timeout` and
the same job runs twice at once — two model calls for one question, and for
`IndexEntryChunks`, two writers racing on the same entry's chunks.

### Backups need two things

`pg_dump` covers the database but not the document binaries, which live on the
`documents` disk. A consistent backup needs both, taken together — the
`checksum` column makes it possible to verify a restored set, and
`Document::fileExists()` is the check, but nothing automates it.

<span style="color:#8250df">entry_chunks</span> dominates dump size and is the
only table that can be rebuilt from source, by re-running `IndexEntryChunks` for
every entry. Excluding it from routine backups is reasonable if regeneration
time is acceptable — worth confirming that bge-m3 is deterministic on your
hardware first, since a library version change can shift vectors slightly.

---

*Written from `backend/database/migrations` (20 files, 20 tables),
`backend/app/Models`, `backend/app/Services/Search`,
`backend/app/Policies`, `backend/app/Jobs`, `backend/app/Casts/AsVector.php`,
`backend/config/ai.php`, `backend/config/database.php`, and
`docker-compose.yml`.*

*Table name colours use inline HTML. If you're reading this on github.com they
get stripped — say the word and I'll switch to backticks, which render as a
coloured chip everywhere.*