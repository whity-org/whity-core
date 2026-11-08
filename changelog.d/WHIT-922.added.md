- **Electronic signatures with an audit trail.** A route can have a signing step (`kind: sign`),
  answered by signing, declining with a reason, or sending back. Every signer must sign (`all`, the
  default) or any one of them may (`any`). The step can say the capacity the signer signs in and on
  whose behalf, and the signer sees both.
- **Signing.** The signer ticks the consent line, draws their signature (or uses the one they saved
  for reuse) and confirms it is them: with a code from their authenticator app when they use one,
  by signing in again through their sign-in provider when they sign in that way, and otherwise with
  a one-time code sent by email. They sign exactly the content on the screen: if it changed since
  they opened it, they are asked to read it again.
- **The signature record.** Each signature records the signer, capacity, on behalf of, step, the
  document version and its SHA-256, how the signer confirmed, the time to the second, the address
  and browser, and the version of the consent wording. It is bound to that version and does not
  carry over to a new one. Each record is sealed with this site's Ed25519 key; the public keys are
  published at `GET /api/signing-keys`.
- **A linked trail.** Every new trail entry stores the hash of the entry before it on the same
  document. Entries written earlier are not changed: the first linked entry covers them.
- **Audit page.** A document's audit page lists every act and signature, whether each seal checks,
  and whether the trail is intact (`GET /api/documents/{id}/audit`).
- `GET /api/documents/{id}` returns `signatures`: who signed the current version, in what capacity,
  and when.

These are electronic signatures with an audit trail. Certified signatures through licensed
providers come later (WHIT-922, #1726).
