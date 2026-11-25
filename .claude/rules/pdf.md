---
paths:
  - "lib/Pdf/**"
  - "lib/Service/AttachmentService.php"
  - "lib/Service/MessageText.php"
  - "tests/unit/Pdf/**"
  - "composer.json"
---

# PDF attachments

- **Never use prinsfrank/pdfparser's `getMarkdown()`.** It inlines every link
  target, and a FlixBus boarding pass's manage-my-booking link carries an
  `auth_token` — which would then sit in `body_text` and go to the model. Text
  only, through `PrinsPdfReader`.
- **prinsfrank/pdfparser 4.3.0 cannot decrypt RC4.** It opens an owner-only
  file without asking (right) and then fails to inflate its streams, even with
  the correct password (poppler reads the same files). `PrinsPdfReader` reports
  any failure on a file carrying `/Encrypt` as `encrypted`;
  `PrinsPdfReaderTest::testAnOwnerOnlyRc4FileIsReportedAsEncryptedForNow` pins
  it and will fail once the library is fixed. Ghostscript writes only RC4, so
  AES was not tested.
- **`ext-gd` is declared in `composer.json`'s `config.platform`**, not
  installed. prinsfrank requires GD for image rasterising, which this app never
  calls, and Nextcloud requires GD anyway; the declaration lets Composer resolve
  on a machine without it. The PDF tests run without GD.
