- **A final document at the end of a route.** A route can end with a final document stage. Nobody
  acts on it: when everything before it is done and approved, Whity makes the PDF from the approved
  version with a document template, gives it its number and verification code, and keeps it on the
  document. For a form submission, that is when its document is made. The PDF says which version it
  was made from, with its SHA-256.
- **Choose the template.** The stage can name its own template, or use the form's new setting
  "Final document template". Add the stage in the route editor with "Add the final document". It is
  always the last stage, a route has at most one, and it is only offered where PDFs can be made.
- **When the PDF can't be made right away.** The route still finishes, the stage shows "Final
  document waiting", and Whity tries again until it is made.
- **The sender gets it.** Under My submissions the sender sees "Final document waiting" and then a
  link to open the final document, unless the form turns that off. Reviewers open it from the
  route's page.

`GET /api/v1/me/form-submissions/{id}/final-document` and `GET /api/v1/routes/{id}/final-document`
serve the final PDF. A form template can print a submission's answers: each form is a record type,
`form.<key>`, with fields `submission.<question>` (WHIT-921, #1725).
