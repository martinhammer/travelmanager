# PDF attachments — spec

Status: **implemented** (2026-11-10, app version 1.19.0). Revised once before
implementation (prinsfrank replaces smalot) and amended during it where the code
taught something; those amendments are marked *Implemented:*. Read the text of PDF attachments with
`prinsfrank/pdfparser`, plus a small wrapper that marks column breaks, and pass
it to the model with the email body.

## 1. Why

Some booking emails carry nothing in the body ("your ticket is attached") and
the itinerary only in a PDF. Today `MimeText::textLeaf()` skips every
`attachment` part, so such an email reaches the model as a near-empty prompt
(answered "no booking") or lands as `failed`/`no_text`.

Three real samples (kept out of git in `samples/`) drove the decisions below:
a FlixBus boarding pass and a Flibco shuttle ticket (both Chromium → pdf-lib,
the Flibco one with Type 3 fonts), and a Malaysia Airlines e-ticket produced by
Amadeus. Each was run through the extractor and then through the configured
model with the app's own `buildPrompt` and `parseAndValidate`:

| Extractor | Flight (Amadeus) | Flibco | FlixBus |
|---|---|---|---|
| `pdftotext -layout` (poppler) | correct | correct (minor field noise) | correct, but "120 kg" luggage |
| `smalot/pdfparser` `getText()` | **wrong: MH120, 07:45 → 08:50** | correct | correct |
| `prinsfrank/pdfparser` `getText()` | correct | destination merged with origin address | luggage weights swapped |
| prinsfrank + column marker (§3.3) | **correct** | **correct** | **correct** |

What each result taught:
- **`pdftotext` is not an option.** The Nextcloud snap ships without it, and
  nothing can be installed into a snap. The `nextcloud-dev` image lacks it too.
- **Nextcloud has no server-side PDF text service to depend on.** PDF Viewer is
  pdf.js running in the browser; its PHP side is a settings page and a template.
  The other App Store "PDF" apps view, sign, annotate or convert *to* PDF. The
  Assistant app reads PDFs with smalot's plain `getText()`, which fails the same
  way on the Amadeus ticket. Context Chat and Full-text search need external
  services (AppAPI/Docker, Elasticsearch).
- **smalot estimates glyph widths** (half the font size per character) and
  inserts a space only when a gap is at least 2× the font size, which is
  hard-coded. The Amadeus PDF places every word separately with ~0.35 em gaps
  and no space glyphs, so no space is ever inserted (`MH120406:40`). The model
  then split that as MH120 + 4… and shifted every time by one column. The result
  passed validation with no issue raised.
- **prinsfrank computes real advance widths** per the PDF spec (§9.4.4),
  including bundled metrics for the 14 standard fonts, and breaks words at
  0.25 em. Words come out right on all three. Its one weakness: side-by-side
  columns are joined with a single space, so a table row loses its column
  boundaries. That caused both bus errors.
- **Column gaps are measurable.** Across the three PDFs, gaps within text are
  at most **0.43 em** and gaps between columns at least **1.14 em** (FlixBus
  luggage: 1.14; Amadeus row: 1.88, 2.31, 4.69; Flibco: 4.39 and up). A tab at
  gaps over **0.8 em** separates every column in the samples, and with it the
  model extracted all three correctly.

## 2. Scope

In:
- PDF attachments of newly ingested messages, including PDFs inside an
  attached `message/rfc822` (forward-as-attachment).
- Text-layer PDFs only.
- Recording, per message, which attachments were read and with what outcome.
- An admin switch to turn attachment reading off.
- The two prompt fixes the test exposed (§8).

Out (deferred):
- Scanned or image-only PDFs (OCR). They are detected and reported, not read.
- Re-reading already-ingested messages. Retry uses the stored `body_text`, which
  has no attachment text for old rows. The workaround is the developer wipe plus
  Read mailbox now; a proper "re-read from mailbox" is a separate feature.
- Other attachment types (`.ics`, `.pkpass`, images).
- prinsfrank's Markdown output (§3.3).

## 3. Pipeline changes

### 3.1 Finding attachments — `Imap/MimeText`

New `MimeText::pdfAttachments(Horde_Mime_Part $root): list<AttachmentRef>`,
pure like the rest of the class. `AttachmentRef` is a new value object:
`mimeId`, `filename` (from the disposition or content-type `name`
parameter, null if absent) and `bytes` (`getBytes()`, the encoded size).

A part is a PDF when either of these holds:
- its type is `application/pdf` or `application/x-pdf`;
- its type is `application/octet-stream` and the filename ends in `.pdf`
  (case-insensitive).

Disposition does not matter: an inline PDF counts. Parts are walked in MIME
order, descending into `multipart/*` and `message/rfc822` exactly as `slots()`
does. The decoded bytes are confirmed to start with `%PDF-` later (§3.3); the
MIME labels are only the shortlist.

`textLeaf()` is unchanged. A PDF is never a text leaf.

### 3.2 Fetching — after dedup, not inside `fetchRecent`

`fetchRecent` currently downloads every text part of the newest N messages on
every run, including ones already processed. Doing that for PDFs would
re-download megabytes every 15 minutes. So:

- `ImapMessage` gains `attachments: list<AttachmentRef>`, from the structure
  `fetchRecent` already fetches. No PDF bytes are downloaded there.
- `IImapClient` gains
  `fetchParts(ImapConnection, int $uidValidity, int $uid, list<string> $mimeIds): array<string, string>`,
  returning the **decoded** bytes per MIME id. It fetches with `peek => true` in
  an `OPEN_READONLY` mailbox (V6) and throws `ImapException` if UIDVALIDITY has
  changed since the listing.
- `IngestionService` calls it only for messages that passed dedup and are about
  to be stored, and only for the attachments that pass the caps in §3.4.

The extra round trip per new message with a PDF is negligible next to the model
call it feeds.

### 3.3 Converting — `Pdf/PrinsPdfReader` (adapter) + `Pdf/PdfLayout` (pure)

New namespace `OCA\TravelManager\Pdf`:

- **`IPdfReader`** with `read(string $bytes, int $maxPages): PdfText`.
  `PdfText` holds the page count, the number of pages read, and the text.
  It throws `PdfException` for encrypted, malformed or unreadable files.
- **`PrinsPdfReader`** implements it. It is the only class that touches the
  library:
  - checks the `%PDF-` header, then calls `PdfParser::parseString()`;
  - leaves decryption to the library, which tries the empty user password by
    itself — how a viewer opens an owner-only file (printing or copying
    restricted) without asking — and throws `AuthenticationFailedException` only
    when a real password is needed. That becomes `PdfException` with
    `encrypted = true`.
    *Implemented:* as of 4.3.0 the library cannot decrypt RC4 streams at all,
    even with the right password (verified against Ghostscript output that
    poppler reads). So any failure on a file carrying `/Encrypt` is reported as
    encrypted rather than as a broken file, and
    `testAnOwnerOnlyRc4FileIsReportedAsEncryptedForNow` pins it: when the
    library is fixed, that test fails and should assert the text instead. AES,
    which most current generators use, could not be tested locally;
  - walks pages up to `$maxPages`, and builds each line from the library's own
    pieces: `TextGrouping::group()` for lines, `SpaceDetector` for word spaces,
    `PositionedTextElement::getRight()` and `getHeight()` for the gap. That is
    the library's `TextExtractor::extractContent` with one addition: it asks
    `PdfLayout` what goes between two elements;
  - never calls `getMarkdown()`. It inlines link targets, and the FlixBus PDF's
    booking-management link carries an `auth_token` that must not reach a model
    or the database.
- **`PdfLayout`** is pure and dependency-free:
  `separator(float $gap, float $height, bool $libraryWantsSpace): string`
  returns `"\t"` when `$gap > COLUMN_GAP_EM × $height`, otherwise `' '` or `''`
  as the library decided. `COLUMN_GAP_EM = 0.8`, with a comment recording the
  measurements in §1. It also owns the page join (blank line), the
  normalisation the body gets (line endings, collapsed blank runs, trim) and the
  `no_text` decision.

Why the wrapper is ours rather than a configuration option: the library has
no column-gap option. It is worth proposing one upstream, and the maintainer is
very active. If one lands, the wrapper shrinks to the setting and `PdfLayout`
stays as the test of it.

A document whose text is empty after trimming is **`no_text`**: a scan or an
image-only PDF.

### 3.4 Caps and failure isolation

| Cap | Value | Reason |
|---|---|---|
| PDFs read per message | 3 | invoices and terms-and-conditions files ride along with e-tickets |
| Size per PDF | 5 MB decoded (encoded `bytes` × 0.75 as the pre-download estimate) | the library holds the file in memory; the samples peaked at 8–16 MB for 100–170 KB files |
| Pages per PDF | 10 | page 1 carries the booking in every sample, and terms run to dozens |
| Text per PDF | 8 000 bytes | samples are 1.1–3.6 KB |
| Total `body_text` | 32 000 bytes | body + attachments; see §3.5 |

A PDF over a cap is skipped (or truncated, for the text cap) and **recorded**
(§4), never silently dropped.

**Failure isolation.** One PDF that will not read costs only that PDF: catch
`\Throwable` around `read()`, as `HordeImapClient::extractText` does per MIME
part.

That does not cover a PHP **fatal** error (out of memory, time limit), which no
catch reaches. A fatal mid-ingestion means the message is never stored, so the
next run fetches it again and dies again, blocking that user's ingestion every
15 minutes. The guard is a **breadcrumb**:
- Before reading a PDF, write `pdf_reading = <message-id>` to the user's
  config (`IUserConfig`).
- Clear it after the read, whether it succeeded or not.
- At the start of handling a message, if the breadcrumb names this message, the
  previous attempt died there. Skip its PDFs, record them as `crashed`, store the
  message, and clear the breadcrumb.

So a poison PDF costs one failed run, not all future ones.

### 3.5 Assembling `body_text`

`body_text` remains "exactly what was fed to the model" (§2 of CLAUDE.md). No
second column is needed for the text itself, and Retry, the Raw message pane and
backups keep working unchanged.

```
<email body text>

--- ATTACHMENT: Your Electronic Ticket-EMD Receipt.pdf (PDF, 2 pages) ---
<pdf text>

--- ATTACHMENT: invoice.pdf (PDF, 1 page) ---
<pdf text>
```

- The body comes first, then the attachments in MIME order. A missing filename
  reads `attachment 1`, `attachment 2`, and so on.
- **Budget:** each PDF's text is capped at 8 000 bytes first. The body then
  gets `32 000 − (sum of the attachment sections)`, and never more than today's
  20 000. With no attachments nothing changes: still 20 000.
- Every cut uses `mb_strcut` (as `normalise()` does) and appends a line
  `[… truncated]`, so the model and the Raw message pane both see that text is
  missing.
- `MAX_BODY_LENGTH` moves out of `HordeImapClient`: truncation now needs to
  know about the attachments. The client keeps only a generous memory guard
  (100 000 bytes: nothing bounds what a server sends), and a pure assembler
  (`MimeText::assemble` or a small new class) applies the budget.
- **`no_text` is decided on the assembled result.** A blank body plus a
  readable PDF is now sent to the model. A blank body plus only unreadable PDFs
  stays `failed`/`no_text`, with the MIME structure as now plus each PDF's
  outcome.
- Tabs from the column marker survive normalisation; nothing collapses them.

## 4. Recording what was read — `messages.attachments`

New nullable TEXT column, holding a JSON list, one entry per PDF found
(including skipped ones):

```json
[
  {
    "filename": "Your Electronic Ticket-EMD Receipt.pdf",
    "pages": 2,
    "pagesRead": 2,
    "chars": 3591,
    "outcome": "read",
    "truncated": false
  }
]
```

`outcome` is one of:

| Outcome | Meaning |
|---|---|
| `read` | Text extracted. |
| `no_text` | No text layer (probably a scan). |
| `encrypted` | Encrypted; not read. |
| `failed` | `PdfException` or another `\Throwable`; the message goes to the log. |
| `crashed` | A previous attempt died reading it (§3.4). |
| `too_large` | Over the size cap; not downloaded. |
| `too_many` | Beyond the 3rd PDF; not downloaded. |
| `disabled` | The admin switch is off (§6); not downloaded. |

Why a column and not `issue_reasons`: `issue_reasons` describes the **last
extraction attempt** and is cleared on retry. What was read from an attachment
is fixed at ingestion and true for every attempt. Putting it in `issue_reasons`
would make Retry erase it. Why JSON and not slugs: the Messages view needs the
filename and page counts, and an entry per file is the provenance trail
(CLAUDE.md, "Provenance").

Consequences:
- Migration `Version2600Date20261110…` adds the column, and the app version
  goes 1.18.0 → **1.19.0**, because a migration has to run.
- `ProcessedMessage` gets the property, and `jsonSerialize()` and the OpenAPI
  shape gain `attachments`. It is small (a few entries), so unlike `body_text`
  it belongs in the list response.
- **Backup:** `BackupService` exports it, and `BackupFormat` treats it as
  optional, so older files still import. `testAnExportCoversEveryColumn` fails
  until this is done, which is the point of that test. Check whether
  `BackupFormat::parse` rejects unknown keys; if it does, an older app reading
  a newer file needs `VERSION` bumped.

## 5. UI

- **Messages row (expanded)** — `messageNotices()` in `src/messages.ts` adds:
  - **info** when attachments were read: "Read 1 PDF attachment: Your Electronic
    Ticket-EMD Receipt.pdf (2 pages).";
  - **warning** for `no_text`, `encrypted`, `failed`, `crashed`, `too_large`
    and `too_many`, each with a sentence naming the file and what happened.
    `no_text` says it is probably a scanned document and that scans are not
    read;
  - **info** for `disabled` (*Implemented:* was a warning in the draft). It is
    the admin's decision, not a fault, and the user cannot act on it — amber in
    this view means "this wants something from you";
  - **truncated** adds "Only the first N pages / part of the text was read."
- **Raw message pane:** no change. It already shows `body_text`, which now
  includes the attachment sections. That is the honest view of what was sent.
- **Detail sidebar, message panel:** an "Attachments" field listing the
  filenames with their outcome lozenge. No download link: the app does not keep
  the PDF.
- **Messages grid:** no new column. A paperclip icon beside the subject, like
  `ContentDuplicate` beside a booking title, marks a row whose text included an
  attachment.
- Wording lives in `labels.ts`. The outcome → notice mapping is a pure
  function in `messages.ts` and is tested.

## 6. Settings

- **Admin:** new app config `pdf_attachments_enabled`, a bool defaulting to
  true. The name is namespaced on purpose (CLAUDE.md §7: never `enabled`).
  When off, PDFs are still listed in `attachments`, with outcome `disabled`,
  so a row says why its PDF was not read.
- **Extraction model section (both panels):** the `NcNoteCard` privacy text
  gains "Text from PDF attachments is sent as well." Add a label/value row
  "PDF attachments: read (text layer only, no OCR) / off".

## 7. Dependency

- `prinsfrank/pdfparser` `^4.3` in `require` (runtime, like Horde). MIT, so
  compatible with AGPL. About 2 MB with its dependencies.
- **Requires** PHP ^8.2 (the app's own floor), `ext-gd`, `ext-iconv` and
  `ext-zlib`. All are standard on Nextcloud; GD is a server requirement.
  Its dependencies are the author's own small packages
  (`core-14-font-metrics`, `glyph-lists`, `markdown-dom`). Nothing duplicates a
  library the server ships, so the CLAUDE.md §7 rule (the DBAL incident) is not
  in play.
- **Maturity, as checked 2026-11-10:** first release 2022, 921 commits, 151 in
  the last 90 days, 189 GitHub stars, 6 open issues. Essentially one maintainer.
  Majors move fast: v4.0.0 shipped 2026-10-30, with v2 and v3 still patched in
  parallel. Hence:
  - pin `^4.3` and let Dependabot propose majors, never `*`;
  - keep the library behind `IPdfReader`, with `PrinsPdfReader` the only class
    that imports it. The classes the wrapper uses (`TextGrouping`,
    `SpaceDetector`, `PositionedTextElement`) are public but not promised stable
    across majors;
  - a major-version bump is reviewed by rerunning the sample check in §9.
- **Psalm:** the library is strictly typed (it is developed against PHPStan's
  strict rules), so no blanket suppressions like `HordeImapClient`'s should be
  needed.
- **Speed and memory** on the samples: 160–460 ms and 8–16 MB peak per file.
  Fine for a background job; the caps in §3.4 bound the rest.
- *Implemented:* `composer.json` declares `ext-gd` under `config.platform`. The
  library uses GD only to rasterise images, which this app never asks for, and
  Nextcloud requires GD anyway — so declaring it lets `composer install` and
  `make build` resolve on a machine without it, and the reader's tests run
  there too (they do; nothing in the text path touches GD).
- The system Composer on the dev machine (2.9.5 on PHP 8.3) crashes in its
  security-advisory step (`array_all()` undefined): pass
  `--no-security-blocking`. A tooling note, not a repo change.

## 8. Prompt changes

`promptTemplate()` gains:
1. "The email may be followed by text taken from its PDF attachments, each
   section starting with a line `--- ATTACHMENT: <name> ---`. Treat that text as
   part of the email. When the body and an attachment describe the same booking,
   return it once." Optionally add: "Tabs separate columns of a table." The test
   passed without it; add it only if a real email needs it. The parser does not
   depend on rule 1: a second copy from the same email is matched against the
   first, because `applyExtraction` inserts one booking at a time and queries
   candidates from the database each time. The prompt rule just avoids the
   noise.
2. Ticket numbers go only in `passengers[].ticketNumber`, never in
   `booking_reference` or `confirmation_number`. In two of the test runs the
   model put the Amadeus ticket number into `confirmation_number`, which turns a
   per-passenger number into a matching key. E-ticket PDFs make this case
   common.
3. `cabinClass` only when the document names the cabin. A fare or booking
   class letter (`Class: Q`) is not a cabin. In all four flight runs the model
   filled it in from "Q": twice as "Economy", twice as "Q".

`buildPrompt` is unchanged: attachment text arrives inside `$emailText`.

## 9. Tests

- **`PdfLayoutTest`** (pure, standalone like `QueuePlannerTest`):
  - the measured gaps as cases: 0.43 em and below → the library's decision;
    1.14, 1.88, 2.31 and 4.69 em → tab;
  - the threshold is a strict `>`;
  - page joining and normalisation keep tabs;
  - empty text is `no_text`.
- **`PrinsPdfReaderTest`** on small **synthetic** PDFs committed as fixtures.
  Never the samples, which hold a real name and live booking references.
  Generate them once with a script kept in `tests/fixtures/`, and commit both
  the script and the output. They need:
  - a two-column table row → a tab between the columns;
  - words drawn as separately positioned strings with no space glyphs (the
    Amadeus construction) → spaced correctly;
  - an encrypted file → `PdfException`;
  - a file without text → empty text;
  - more pages than `$maxPages` → only those pages read;
  - bytes that are not a PDF → `PdfException`.
  *Implemented:* they need no GD and run everywhere (§7). The encrypted
  fixtures are written with Ghostscript, so regenerating needs `gs`.
- **`MimeTextTest`:** `pdfAttachments` on raw MIME fixtures. PDF by type, by
  octet-stream + filename, inline PDF, PDF inside `message/rfc822`, and a
  `.pdf.exe` that is not a PDF.
- **Assembler:** the budget arithmetic, truncation markers, a blank body plus a
  PDF is not `no_text`, a blank body plus an unreadable PDF is.
- **`IngestionServiceTest`:**
  - parts fetched only for messages that passed dedup;
  - the caps;
  - the breadcrumb: a message whose breadcrumb is set gets stored with
    `crashed` and its PDFs are not fetched;
  - the admin switch.
- **`ExtractionServiceTest`:** the three prompt rules are present (as the
  existing personal-attributes rule is tested).
- **Frontend:** `messages.spec.ts` for the outcome → notice mapping.
- **Sample check (manual, before merging, and on every major bump of the
  library):** run the three samples through `PrinsPdfReader` and the configured
  model with the scratch harness. The bar:
  - MH1204, 06:40 → 07:45 on 2026-09-04, `DM4R6O`;
  - Flibco: Charleroi Airport → Ghent, 15:00 → 16:35 on 2026-11-11, `37713300`;
  - FlixBus: Utrecht Leidsche Rijn Centrum → Antwerp Rooseveltplaats,
    17:20 → 19:15 on 2026-11-01, `3408379387`, seat 1C.

  A pinned model id makes the comparison meaningful; `openrouter/free` changes
  model per request.

## 10. Order of work

1. Dependency + `PdfLayout` + `PrinsPdfReader` + fixtures + tests.
2. The sample check through the real reader. **Stop here if it fails:** the
   rest is plumbing for text that does not work.
3. `MimeText::pdfAttachments`, `fetchParts`, assembler, breadcrumb, caps.
4. Migration + `attachments` column + backup.
5. Prompt changes.
6. UI notices, sidebar field, paperclip, settings.
7. CLAUDE.md: decision entry in §3 (including why not smalot, `pdftotext` or
   PDF Viewer, so the question is not reopened), current-state line in §4, and a
   §7 gotcha for the Markdown output's link tokens.
8. Upstream: propose a column-gap option to prinsfrank/pdfparser.

## 11. Decisions and follow-ups

1. **`pdf_attachments_enabled` defaults to on** (decided 2026-11-10), with the
   privacy note in the Extraction model section saying attachment text is sent.
2. **The 0.8 em threshold rests on three samples.** It sits between 0.43 and
   1.14 em, a margin of about 0.3 em on either side. Revisit as more PDF-only
   emails arrive; they can be added to `samples/` and run through the reader
   without code changes.
3. **Upstream, for prinsfrank/pdfparser:** a column-gap option (§3.3), and the
   RC4 decryption failure (§3.3) with a reproduction from
   `tests/fixtures/pdf/generate.php`.
