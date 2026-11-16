# ADR 0016: Messages in core, channels as a plugin interface

- **Status:** Accepted
- **Date:** 2026-11-11
- **Task / Issue:** WHIT-936 (ADR), WHIT-937 (SDK), WHIT-938 (server), WHIT-939 (web); finishes #881
- **Deciders:** Owner (scope decision 2026-11-11), Whity-Core maintainers

## Context

The owner decided on 2026-11-11 that a minimal Messages feature ships in core
and stays free: a workspace talks to its customers by **email** and **web
chat** from one staff inbox. **WhatsApp, SMS** and bulk campaigns become paid
store plugins licensed through Whity ID, where the installer connects their own
provider account, the same way Whity Pay works. A parallel lane is building a
core calendar that will send booking reminders through whatever this lane
builds, so sending has to be an API other core modules can call, not only a
screen.

What exists today and shapes the design:

- **Customers** are `profiles` with a `memberships` row of `kind = 'customer'`
  (migration 222). There is no contacts table and no phone column. Anonymous
  visitors (public forms) are counted by `CustomerUsage` as
  `submission:<id>`.
- **Email** goes out through `Whity\Core\Mail\Mailer::send($to, $subject,
  $text, $html)`. The SMTP settings are instance wide. The interface cannot set
  `Message-ID`, `In-Reply-To`, `References` or `Reply-To`. Nothing receives
  email.
- **Public routes** take the workspace from a slug in the path, are listed one
  by one in `EnforceTenantIsolation::PUBLIC_ROUTE_PATTERNS`, and throttle per IP
  and per target over `DatabaseSharedStore` (`PublicFormsApiHandler`).
  `BotCheck` (Turnstile) exists for plugin public forms.
- **There is no realtime transport** outside MCP. No SSE, no websockets.
- **Agents** act only through MCP tools. `ToolsCallHandler::assertAgentMayRun`
  turns a call into an `agent_proposals` row when the effective mode is Ask
  first; a write with no undo is capped at Ask first.
- **Inbox** (`InboxSourceRegistry`, #989/#1000) aggregates core sources one tab
  at a time. #881 asks for the plugin interface plus ordering and pagination
  across sources; that was blocked on a decision this ADR makes.
- **Metering:** `MeterService::record()` never refuses; keys in
  `EntitlementRegistry::WARN_ONLY` only warn (#1799, #1800).
- **Notification transports** (`Whity\Sdk\Notification\NotificationTransport`)
  deliver one-way notices to members. They are not conversations and have no
  inbound side, so they are not reused as channels.

## Decision

### 1. Data model (migrations 243 to 245)

All three tables carry `tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON
DELETE CASCADE`, are listed in `TenantOwnedTables`, and every query filters on
`tenant_id` (the tenant-predicate guard enforces it).

**`conversations`** (243): `id`, `tenant_id`, `channel` (`email`, `webchat`, or
a namespaced plugin channel such as `acme:whatsapp`), `subject`, `status`
(`open | pending | closed`), `assigned_profile_id` (staff, nullable),
`customer_profile_id` (a customer membership's profile, nullable),
`contact_name`, `contact_address` (the address on that channel: an email
address, a phone number, a visitor id), `last_message_at`,
`last_inbound_at`, `created_at`, `updated_at`, `closed_at`.
Index `(tenant_id, status, last_message_at DESC)` for the inbox and
`(tenant_id, channel, contact_address)` to find a contact's open conversation.

**`messages`** (243): `id`, `tenant_id`, `conversation_id`, `kind`
(`inbound | outbound | note`), `author_profile_id` (staff who wrote it,
nullable), `agent_proposal_id` (when an agent drafted it, nullable),
`body_text`, `body_html` (email only, sanitised before display),
`external_id` (the channel's own id: email `Message-ID`, a plugin's provider
id), `in_reply_to`, `status` (`received | queued | sent | delivered | read |
failed | cancelled`), `status_detail`, `send_after`, `sent_at`, `created_at`.
Unique `(tenant_id, conversation_id, external_id)` where `external_id` is set,
and an index `(tenant_id, external_id)` for threading lookups.
Notes are `kind = 'note'`: they never leave the workspace and no channel sees
them.

**`webchat_visitors`** (245): `id`, `tenant_id`, `token_hash` (SHA-256 of a
random bearer token the browser keeps), `conversation_id`, `profile_id`
(set when a signed-in customer chats), `display_name`, `email` (optional,
given by the visitor), `ip_hash`, `created_at`, `last_seen_at`.

**Permissions** (244), granted by capability, never by role name (copy of
migration 195): `messages:read` (see the inbox), `messages:reply` (send, write
notes), `messages:manage` (assign, change status, channel settings). All three
go to the roles that already hold `settings:write`; a workspace then gives
`messages:read` and `messages:reply` to whichever staff roles answer customers.
The verbs `read` and `reply` are not on `CustomerSafeRole`'s privileged list,
but customers never reach staff routes anyway: their side is `/api/me/messages`
and the public chat routes, which check ownership, not a permission.

Migration 246 stays reserved for this lane.

**Search** uses the existing `SearchText::sqlFold()` `LIKE` pattern over
`subject`, `contact_name`, `contact_address` and `body_text`, scoped by
`tenant_id`, and a `messages` source in `CoreSearchSources`. No `tsvector` or
`pg_trgm`: the same query has to run on SQLite in tests and the corpus per
workspace is small. A real index is a later, separate decision.

### 2. One send API for every core module

`Whity\Core\Messaging\MessagingService` is the only way a message is written:

```php
send(int $tenantId, Recipient $to, string $body, SendOptions $o): SentMessage
reply(int $tenantId, int $conversationId, int $authorProfileId, string $body, ...): SentMessage
note(int $tenantId, int $conversationId, int $authorProfileId, string $body): int
ingest(int $tenantId, string $channel, InboundMessage $m): int   // inbound
applyStatus(int $tenantId, string $channel, DeliveryStatusUpdate $u): void
```

`Recipient` is a customer profile id or a channel address; `SendOptions`
carries the channel (default: the contact's last channel, else email), a
subject, an optional template, and `source` (`calendar.reminder`,
`staff.reply`, `agent.reply`). The calendar lane calls `send()` with
`source = 'calendar.reminder'`. Writing the row, metering and queueing happen in
one place, so a reminder shows up in the customer's conversation like any other
message.

Sending is asynchronous: the row is written as `queued` and a core job
(`messages.deliver`, the existing `jobs` queue) hands it to the channel and
writes back `sent` or `failed`. A request never waits on SMTP or a provider.

### 3. The channel interface (SDK 1.97)

New namespace `Whity\Sdk\Messaging`:

```php
interface MessageChannel {
    public function key(): string;                 // bare slug, host namespaces it
    public function label(): LocalizedText;
    public function capabilities(): ChannelCapabilities;
    public function send(OutboundMessage $m): SendOutcome;   // never throws on provider failure
    /** @return list<InboundMessage|DeliveryStatusUpdate> */
    public function receive(int $tenantId, string $rawBody, array $headers): array;
    /** @return list<MessageTemplate> */
    public function templates(int $tenantId): array;
}
interface PluginMessageChannelsInterface {
    /** @return list<MessageChannel> */
    public function getMessageChannels(): array;
}
```

- `ChannelCapabilities`: `supportsSubject`, `supportsHtml`,
  `deliveryReceipts`, `readReceipts`, `maxBodyLength`, `requiresTemplate`,
  `freeformWindowSeconds` (WhatsApp's 24-hour window is
  `requiresTemplate = true, freeformWindowSeconds = 86400`).
- `OutboundMessage` carries tenant id, conversation id, message id, the
  recipient address, subject, body, optional template name and variables,
  and the threading ids (`inReplyTo`, `references`).
- `SendOutcome`: `sent(externalId)`, `failed(reason, retryable)`.
- `receive()` verifies the provider's signature itself (as
  `BillingPortal::verifyNotification` does) and returns an empty list when the
  request is not authentic. The host never parses provider payloads.
- Credentials are the plugin's business: it stores the installer's provider
  account in its own settings, so core never sees a WhatsApp token.

Host side: `MessageChannelRegistry` (core channels `email` and `webchat`, plus
plugin channels as `<plugin>:<key>`), a `PluginLoader` block with the usual
per-plugin error boundary, and one public webhook route per plugin channel:
`POST /api/public/messages/inbound/{channel}/{workspaceSlug}`. The workspace
comes from the slug, the channel decides authenticity, each verified item goes
through `MessagingService::ingest()` or `applyStatus()`. A store plugin adds
WhatsApp or SMS with no core change.

Core's own `email` and `webchat` channels implement the same interface, so the
inbox, the send API, metering and agents treat all channels the same way.

### 4. Email channel

**Outbound.** A new `Whity\Core\Mail\ThreadedMailer` interface,
`sendThreaded(OutgoingEmail $e): void`, carries `Message-ID`, `In-Reply-To`,
`References` and `Reply-To` as well as the existing fields. `SmtpMailer`,
`OAuth2SmtpMailer`, `LogMailer`, `NullMailer` and `LazyMailer` implement it;
`Mailer` itself is unchanged so nothing else breaks. The sender stays the
instance's From address with the workspace name as the display name; replies
come back through `Reply-To`, set to the workspace's inbound address. Each
outbound message gets a `Message-ID` of `<m.{messageId}.{random}@{host}>`.

**Inbound: a webhook, not IMAP polling, in this lane.** Route
`POST /api/public/messages/inbound/email/{workspaceSlug}` accepts a raw RFC 822
message (`message/rfc822` or a JSON `{ "raw": "..." }` body) with an
`X-Whity-Signature: sha256=<hmac>` header over the body, keyed by a
per-workspace secret shown once in Settings → Messages. This works with
Cloudflare Email Routing, Postmark, Mailgun and SES through a few lines of
forwarder, and keeps no mailbox password in core. The workspace's inbound
address is whatever the admin points at that forwarder; core stores it only to
use as `Reply-To`.

IMAP polling is **not** built now. It needs per-workspace mailbox credentials
and a polling job per workspace, and it is the one design that turns core into
a mail client. It is tracked as a follow-up issue and will feed the same
`ingest()`.

**Threading.** An inbound email joins a conversation when its `In-Reply-To` or
any id in `References` matches a message's `external_id` in the same
workspace; failing that, the open email conversation with the same sender
address and the same subject after stripping `Re:`/`Fwd:`/`رد:` prefixes;
failing that, a new conversation. Inbound bodies keep the text part; an HTML
part is stored and only ever rendered sanitised. Attachments are out of scope
for this lane (named in the message as "1 attachment not shown").

### 5. Web chat channel

- **Anonymous visitors**: `POST /api/public/chat/{workspaceSlug}/conversations`
  starts a conversation and returns a visitor token once; `POST …/messages` and
  `GET …/messages?after={id}` use it as `Authorization: Bearer`. The browser
  polls (5 seconds while the box is open, backing off to 30). No websockets.
- **Signed-in customers**: `/api/me/messages` (list own conversations, read,
  send). Ownership is `customer_profile_id = me`, never a permission.
- **Where the box appears**: a chat button on the workspace's public form pages
  and join page, a standalone page `/chat/{workspace}` a workspace can link or
  embed, and a Messages page for signed-in customers. Off by default; an admin
  turns it on in Settings → Messages.
- **Abuse protection**, all of it before any database write: per-IP and
  per-workspace fixed windows (start 5/hour/IP, send 30/10 minutes/token,
  600/hour/workspace), a hidden honeypot field, a 4,000-character body cap,
  Turnstile when the workspace has bot checks configured, a cap of 3 open
  visitor conversations per IP, and the same 404 for every unknown or disabled
  workspace. Visitor tokens are stored hashed.

### 6. Staff side, agents, metering, MCP and API

- **Staff API** under `/api/messages/…`: list (status, assignee, channel,
  search, keyset paging by `last_message_at, id`), read, reply, note, assign,
  set status. Permission-gated with the slugs above; declared in
  `CoreApiSchemas`, so MCP tools are derived from the routes as everywhere else,
  grouped in a `core.messages` toolset.
- **Agents**: the reply route is an ordinary MCP tool. A send cannot be
  unsent, so it is made undoable by **holding** it: a reply written by an agent
  is queued with `send_after = now + 5 minutes`, and the route declares an undo
  operation (`POST /api/messages/{id}/cancel`) with that window. So the person's
  grant decides: Off refuses, Ask first (the default for writes) creates a
  proposal shown in Approvals with the draft as its preview, and Allowed queues
  it with the hold, cancellable from the conversation. The message records the
  agent and the person it acts for through `agent_proposal_id` and the existing
  `agent_actions` row. A dashed bubble shows "not sent yet" in the thread.
- **Metering**: every outbound message (not notes, not inbound) calls
  `MeterService::record($tenantId, ['messages.outbound.per_month'])`. The key is
  added to `METER_PERIODS`, `DEFAULTS` (unlimited), `TYPES`, `DESCRIPTIONS` and
  `WARN_ONLY`. Nothing is ever blocked; crossing the allowance logs and shows on
  Billing like the #1799 limits. Plugin channels are metered by core the same
  way, so a hosted plan can carry one allowance across channels.
- **Inbox source**: Messages registers a core inbox source (`messages`): open
  conversations assigned to me, newest first, so they appear in the
  Waiting-for-you list.

### 7. Plugin inbox sources and cross-source ordering (finishes #881)

`InboxSourceInterface` moves to `Whity\Sdk\Inbox\InboxSource` (core keeps a
class alias) and `PluginInboxSourcesInterface::getInboxSources()` is published
in SDK 1.97, namespaced `<plugin>:<key>` by the host.

- **Ordering**: one total order across sources: `urgent` first (a boolean an
  item may set; default false), then the item's timestamp descending, then
  source key, then item id. Recency is the default; urgency is explicit and per
  item, so an approval queue can raise what is due without the host guessing.
- **Pagination**: keyset, never page numbers, for the merged list.
  `InboxSource::listBefore(tenantId, profileId, openOnly, ?InboxCursor, limit)`
  returns up to `limit` items strictly after the cursor in that order. The
  aggregate asks each source for `limit` items after the cursor, merges, keeps
  the first `limit`, and returns the last item as the next cursor. That is
  correct for any number of sources and never truncates at a page boundary
  (#867). Sources that only implement the old offset `list()` are adapted by
  the host (fetch `offset + limit`), with the cost noted in the docblock.
- **Failure isolation**: each source runs inside the per-plugin error
  boundary; a failing source is reported in `unavailable` and the rest of the
  list still renders.

## Alternatives Considered

- **Messages entirely as a plugin.** Rejected by the owner: the free plan is the
  full product and talking to customers is core to a business platform.
- **Reuse `NotificationTransport` as the channel interface.** It is one-way,
  addressed to members, with no inbound side, threading or delivery status.
  Stretching it would change a published SDK contract for every notification
  plugin.
- **IMAP polling for inbound email now.** Postponed for the reasons in 4.
- **A contacts table.** Not needed for the minimal feature: the contact is the
  conversation's address, or a customer profile when one exists. A shared
  contacts model is a larger, separate decision (CRM territory).
- **Websockets or SSE for chat.** No such infrastructure exists, FrankenPHP
  workers are not built for long-held connections here, and polling at chat
  volumes is cheap.
- **Agents send directly when Allowed.** A send cannot be undone, and the
  register says agents never change anything directly; the hold keeps
  "Allowed" honest.

## Consequences

### Positive

- Email and web chat work in every free workspace; WhatsApp and SMS plugins
  need no core change.
- Calendar reminders, staff replies and agent drafts share one path, one meter
  and one thread per customer.
- #881 closes with a decided ordering and a pagination that cannot truncate.

### Negative / Trade-offs

- Inbound email needs a forwarder in front of core. Self-hosters with only a
  mailbox wait for the IMAP follow-up.
- Polling chat costs a request every few seconds per open chat box.
- `LIKE` search will slow down on very large workspaces; a real index is
  deferred.
- The instance-wide SMTP sender means all workspaces send from one domain
  until per-workspace senders (`BRAND_OWN_EMAIL_SENDER`) land.

### Impact on existing conventions

- SDK 1.97: new `Whity\Sdk\Messaging` and `Whity\Sdk\Inbox` namespaces, two
  capability interfaces, coverage matrix rows for messaging and inbox.
- Migrations 243 to 245, all tenant-owned; 246 reserved.
- New public routes are listed one by one in `PUBLIC_ROUTE_PATTERNS`.
- `Mailer` is unchanged; `ThreadedMailer` is additive.

## References

- #881 (cross-plugin inbox), #867 (page-1 truncation), #868/#871 (inbox block)
- #1799, #1800 (plan limits, warn mode)
- ADR 0013 (event spine), ADR 0014 (plugin exception), ADR 0010 (plans)
- Migration 195 (grant by capability), 222 (customer memberships)
