---
paths:
  - "lib/Service/ExtractionService.php"
  - "lib/Service/ExtractionResultHandler.php"
  - "lib/Service/BookingService.php"
  - "lib/Service/BookingMatcher.php"
  - "lib/Service/Dto/**"
  - "lib/Llm/**"
  - "tests/unit/Service/**"
---

# Extraction JSON contract

Keep extraction and parsing logic free of OCP types, so it stays testable
with no stubs at all: that is why `ExtractionService` uses literal type
strings, not `Booking::TYPE_*`.

`core:text2text` returns **raw text only** (no JSON/function-calling mode), so
every response goes through `ExtractionService`: strip fences → extract first
balanced `{…}` → `json_decode` → **classify** each booking (`type` in allowlist)
→ validate the per-type `details` (normalise the anchoring date(s), pass unknown
fields through). Anti-hallucination: a booking without its anchoring date is
dropped — flight, train, bus ⇒ ≥1 segment with a valid `departureLocal`;
car_rental ⇒ valid `pickup.local`; accommodation ⇒ valid `checkIn.local`. The
three segmented types share one validator (`validateSegmentedDetails`, gated on
`SEGMENTED_TYPES`), which is the only place that rule lives.

**What the model writes loosely is normalised, never refused for its form**
(2026-11-12, review items 4, 13, 25, 27). Each of these used to cost data
silently:
- **An all-digit identifier written as a JSON number is the same identifier.**
  `identifier()` stringifies an int for both header columns, and
  `json_decode(…, JSON_BIGINT_AS_STRING)` keeps one too long for an int from
  coming back as a rounded float. A decimal is not an identifier. Inside
  `details`, `flightNumber`/`serviceNumber`/`ticketNumber` get the same
  treatment (`numbersAsStrings`), because the matcher reads them as strings.
  Losing the number mattered beyond the card: with both identifiers gone,
  `BookingMatcher`'s tier 2 could no longer see that two rooms' references
  differed, and suppressed the second.
- **ISO decoration on a date is dropped, not converted** (V8): fractional
  seconds, `Z` and a numeric offset are stripped and the wall clock kept as
  written. Rejecting them cost the leg, and a one-leg flight its booking.
- **A journey starts at its first departure and ends at the latest departure or
  arrival.** Arrivals in the start made a 22:00 departure with a date-only
  arrival begin at midnight.
- **Segments as one object are one leg**, an object of objects is a keyed list,
  and an entry that is not an object still counts as a lost leg. The segment
  issues quote what each rejected leg carried, as the car and hotel issues do.

**Malformed JSON is repaired, never silently.** `repairJson` rebuilds the
response while scanning it, keeping a **stack of open containers** (not a depth
counter) so it can insert a closer the model skipped *at the point it skipped
it* — the observed provider failure closes the `bookings` array while a booking
object is still open, so the missing `}` belongs before the `]` and appending at
the end cannot fix it. A repair is accepted only if the result actually parses,
and is **refused** when the response was cut off inside a string (closing the
quote would turn a half-written value into a plausible whole one). Every repair
raises a `repaired_json` issue — a rising repair rate is how a degrading model
shows up before it starts failing outright.

**A repair never passes off part of a response as the whole** (2026-11-12,
review items 5 and 12):
- **A closer that does not match is read two ways.** The scan assumes closers
  were forgotten before it; when that ends the document with more JSON still to
  come (`{flight}}, {bus}`), it was an extra one, and the scan runs again with it
  dropped. Before, the bus vanished and the row said `processed`.
- **What still cannot be read is a drop, not a repair.** An `incomplete_json`
  issue (`dropped = true`) is raised when text after the object could not be
  read or the response was cut off below the root — only a reply missing its
  root `}` loses nothing. A trailing comma at the cut is removed, since that is
  where a reply stopping between bookings ends. The Messages row warns that
  bookings may be missing, whatever the status.
- **A cut-off with no booking read fails the parse.** Closing `{"bookings": [`
  gives an empty list that reads as an email with nothing in it, which is never
  retried.
- **A repeated key refuses the response**, at any depth. `json_decode` keeps the
  last value silently, so a flight followed by a second `"bookings": []` decoded
  as no booking at all.

**Rejections are reported, never swallowed.** `parseAndValidate` returns an
`ExtractionResult` (`bookings` + `issues`), where each `ExtractionIssue` carries a
reason slug, a human description (including the unusable raw date, which is what
you tune the prompt against) and whether the whole booking was lost or only part
of it (e.g. a flight that kept 2 of 3 legs). This exists because zero bookings is
otherwise ambiguous: `messages.status` is now `no_booking` when the model
genuinely found nothing versus **`dropped`** when it found bookings we then
refused — only the latter is worth retrying or tuning against. Output shape:
`{ "bookings": [ { type, provider, booking_reference, confirmation_number,
status, title, confidence, details: { …type-specific… } } ] }` — see
`ExtractionService::buildPrompt` for each type's `details` schema.
