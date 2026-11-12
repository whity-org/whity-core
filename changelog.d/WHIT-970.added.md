- **Ask an agent from a document, with your own AI app.** A request waits until
  the agent you connected over MCP picks it up (`document_agent_requests_next`),
  reads the document (`document_read`), and answers with suggestions and comment
  replies (`document_suggest_blocks`, `document_suggest_rewrite`,
  `document_comment_reply`). Nothing it does changes the draft until a person
  accepts it, and each suggestion names the agent, the person it acts for and
  the permission it used. It reports progress with
  `document_agent_request_update`, which tells it to stop when you press Stop
  (WHIT-970).
