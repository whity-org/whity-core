- **Send back to the author, and resubmit.** A person holding an item on a route can send it back to
  its author for changes, with a note the author sees. On a committee step, one member's send-back
  goes at once, without a vote. The sender chooses where the route resumes: their own step, with its
  people resolved again, or step 1. Earlier approvals stay in the trail. A route design can turn
  send-back off for a step (`allow_send_back`). Returning an item one step back to a colleague works
  as before.
- **Versions of what was reviewed.** Each submit and resubmit records a version of the subject and
  its SHA-256, and every trail entry names the version it was made on, so the trail shows which
  version each approval was given on.
- **Notes for the author.** A note can be marked for the author (`to_author`). Notes are internal by
  default and never reach the author.

The routing API accepts `sent_back` (with `resume_from`) and `resubmitted`. The trail's action list
is widened once for the whole routes plan; on SQLite this edits the stored table definition and
rewrites no rows (WHIT-919, #1723).
