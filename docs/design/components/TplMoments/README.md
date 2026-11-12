# In-app moments (web)

Lottie in the app (LT.4): one small scene, at most, on a screen, at the moments that need it, recoloured to the workspace's brand and to dark mode. The words always say what happened; the picture never carries the meaning alone.

| Moment | File | Length | When |
| --- | --- | --- | --- |
| No invoices / stock / documents / people yet | `empty-invoices`, `empty-stock`, `empty-documents`, `empty-people` | 1.6 s | The first time the empty list is seen in a session (`data-once`), then still |
| No results | `no-results` | 1.5 s | Each new search with nothing found |
| Inbox or Approvals empty | `all-caught-up` | 1 s | After the last item is decided |
| Approve one item | `approve-set-down` | 1.2 s | Approve on one proposal or record; Undo plays it back. **Bulk approval stays instant** (code, 200 ms) |
| Sent | `invoice-sent` | 1.4 s | Invoice, quote or reminder sent (toast) |
| Paid | `payment-received` | 1.4 s | A payment arrives (toast) |
| Received | `stock-received` | 1.4 s | Goods received, a count saved |
| Company ready | `setup-done` | 2.8 s | End of setup, once ever |
| First invoice, first agent, your brand | `money-invoice`, `agents-propose`, `brand-sheet-platform` | up to 1.6 s | The first visit to each, once ever |
| Waiting for the tax system | `waiting-reply` | loops | Only while really waiting; stops with `WhityLottie.done(el)` |
| Uploading, importing | `uploading` | loops | Only while really uploading |
| Offline | `offline` | 1.2 s, then still | While offline |
| Something went wrong, not found | `went-wrong`, `not-found` | 1.6 s | 500 and 404 pages and panels |

**Animations setting** (Account › Preferences): Full (default), Calm (pictures fade in at their end), Off (still). The device's reduce-motion setting means Off. Set it on the page as `<html data-motion="full|calm|off">`; the player reads it.

Never block: buttons work while anything plays, and nothing waits for an animation to end.
