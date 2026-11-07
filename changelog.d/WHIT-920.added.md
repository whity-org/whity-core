- **Form submissions travel on their own routes.** Sending a form no longer makes a document. The
  route travels on the submission, and its reviewers open it from their inbox on a page that shows
  the answers, the trail and what they can do. Routes on submissions sent before this now travel on
  the submission too; their documents stay.
- **Edit and send again.** When a reviewer sends a submission back for changes, the person who sent
  it can change it under My submissions and send it again. Each version is kept, and a reviewer sees
  what changed since the version they last acted on.
- **What the sender sees.** Under My submissions the sender sees each stage and its outcome, and the
  notes written for them. Reviewers are not named unless the workspace turns on
  `forms.show_reviewer_names`.
- **Not approved.** On a submission, a rejection needs a reason, ends the route, and the sender reads
  "Not approved" with the reason. They can start a new submission filled in from the rejected one.
- **Sign in on a public form.** A form's public link can ask visitors to sign in, so only the
  workspace's members and customers send it, under their own name. A submission from somebody who
  did not sign in cannot be sent back, and the route editor says so when a public form uses a route
  that allows it.

Routes can be read and acted on by their subject: `GET /api/v1/routes?subject_type=…&subject_id=…`
and `POST /api/v1/routes/{id}/actions`. The document routing endpoints work as before
(WHIT-920, #1724).
