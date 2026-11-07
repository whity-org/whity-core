- **Routes now say what they travel on and what each step is.** A route records its subject (today
  always a document), and each step records its kind: review, decide or deliver. Both appear in the
  routing API (`subject` on a route, `kind` on a step). Nothing about how routes behave has changed;
  this prepares one routing engine for documents, form submissions and plugin records (WHIT-918, #1722).
