---
paths:
  - "lib/Imap/**"
  - "lib/Service/IngestionService.php"
  - "lib/Service/ExtractionQueue.php"
  - "lib/Service/QueuePlanner.php"
  - "tests/unit/Imap/**"
---

# IMAP

- **IMAP / Horde.** `bytestream/horde-imap-client` is a **runtime** dep (in
  `require`, not `require-dev`) so it ships in the `--no-dev` prod build. It is
  **untyped**, so `HordeImapClient` carries blanket `@psalm-suppress Mixed*`
  (adapter-over-untyped-lib) — keep all parsing/decoding logic that *can* be
  typed (e.g. `Imap/Html`) out of that class so it stays analysable and testable.
  Read-only discipline (V6) is enforced two ways: open the mailbox
  `OPEN_READONLY` (EXAMINE) **and** fetch body parts with `['peek' => true]` so
  the `\Seen` flag is never set. Dedup key is the envelope `message_id`
  (`Imap/MessageId::key`: a synthetic `<tm-{uidvalidity}-{uid}@…>` when absent,
  and `<tm-sha1-…@…>` of the whole value when it is longer than its column or
  not valid UTF-8 — **hashed, never cut**, since two ids sharing 255 characters
  would otherwise be one email and the second would never be read). Each text
  part is fetched with a `length` of 2 MiB: far above the text cap, because an
  email's HTML can be ten times its text, so it bounds memory without cutting
  an itinerary.
  **Horde verifies no certificate unless told to** — always pass the `context`
  built by `sslContext`, never construct `Horde_Imap_Client_Socket` without it
  (see the connection decision in `docs/decisions.md`).
- **Fetch the last N by sequence number, not SEARCH/SORT.** `fetchRecent` reads
  the mailbox `MESSAGES` count and fetches the trailing sequence range
  `max-N+1:max` (new mail arrives last). The earlier approach (`search()` with an empty `Search_Query` + a `SORT
  (REVERSE ARRIVAL)` option) is fragile: some servers (e.g. Purelymail) reject the
  resulting `UID SORT … ` with no search key as **`UID failed. Illegal
  arguments.`**, and SORT isn't universally supported. Sequence fetch needs only
  base IMAP. Surface real Horde errors via the exception's public `$details`
  (raw server response) — `getMessage()` alone is the generic "IMAP error
  reported by server." (see `HordeImapClient::describe()`).
- **Messages reach the model oldest first, and that is a contract.** *Which*
  messages (the newest N) and *what order* they are handed over in are separate
  decisions: `fetchRecent` sorts the window ascending **by UID**, and
  `IngestionService` must not re-order it. Deduplication treats the first email
  about a booking as the one that creates it and every later one as being *about*
  that booking, so scheduling newest-first made "first" mean "whichever
  extraction task happened to finish first" — which is arbitrary, and put the
  duplicate flag on whichever of two emails won the race rather than on the later
  one. Sort **by UID, not by the order the server sent the untagged FETCH
  responses in**: UIDs increase strictly with arrival within a UIDVALIDITY
  (RFC 3501), so that is arrival order by definition rather than by convention.
  Deliberately **not** by the `Date` header — on forwarded mail it is the
  forwarding time, so it carries no more truth than arrival order and is
  client-controlled besides. **This orders scheduling only**: extraction is
  asynchronous, so with more than one AI worker the results can still arrive out
  of order. A hard guarantee would mean serialising per user or holding results
  until earlier ones are applied, both of which fight the async design; not done.
- **The body is every inline text part, not Horde's `findBody()`** (2026-10-13).
  `findBody` returns the *first* inline text part, and a real forward
  ("Fwd: Booking Summary and receipt Z6VSLM") reached the model as a bare
  subject line: an empty forward note came first, so the model answered "no
  booking" — correctly, for what it was given — and the row then claimed its text
  was "no longer retained". `MimeText` reads every inline text part in order,
  follows an attached `message/rfc822` (on a forward-as-attachment it *is* the
  content), and within an alternative takes plain only when it is not blank. An
  email that still yields nothing is **never sent**: it lands as
  `failed`/`no_text` with the MIME tree in `error`, because an empty prompt earns
  a confident "no booking" that hides our fault behind the model's verdict.
  Reading more parts surfaced the next trap at once: **`mb_convert_encoding`
  throws `ValueError` on a charset mbstring lacks** (`windows-1256` was the real
  one) — `@` does not stop it, and uncaught it ended the *whole* mailbox read on
  every run. `MimeText::toUtf8` falls back to iconv and always scrubs to valid
  UTF-8, and a part that still fails to decode costs only that part.
