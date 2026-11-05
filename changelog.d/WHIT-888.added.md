- **Issue a revision of a document.** `POST /api/documents/{id}/revisions` with a reason issues
  a new document from the record as it is now, with the same number and the next revision letter.
  The original becomes `superseded`, its QR code stops verifying, and the response says what
  changed, field by field. A document with no record, a withdrawn one and one still in review are
  refused with a clear reason (WHIT-888, #1625, SDK 1.68).
