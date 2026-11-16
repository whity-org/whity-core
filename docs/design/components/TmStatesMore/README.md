# Mobile: more states

Each state says what happened, whether anything was lost, and one clear next step (with the reasons in the voice of section 07).

- **Session ended:** when the workspace's session limit passes; **Continue with Face ID** gets straight back in.
- **Update needed:** when the installation needs a newer app; one button to the store; decisions waiting to send are kept.
- **No access:** names who the page is for and who to ask, with **Ask Emad for access** (sends a request to the admin's Inbox).
- **Something went wrong:** says what didn't happen ("Your signature wasn't sent, so nothing changed."), **Try again**, **Report it**, and a reference code in mono for support.

Together with `TmStatesMobile` (offline, installation not answering, updating, all caught up) these cover the web's 403, 404, 500, offline, maintenance and rate-limit states (U06).
