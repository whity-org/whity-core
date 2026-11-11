<?php

declare(strict_types=1);

namespace Whity\Core\Inbox;

use Whity\Sdk\Inbox\InboxSource;

/**
 * One contributor of items to the caller's inbox (#881).
 *
 * #881's framing, worth keeping because it is the whole reason this interface
 * exists rather than each subsystem shipping its own screen:
 *
 *   "If every plugin owns its own queue and supplies its own source, then a user
 *    with items from several plugins has several inboxes. Someone senior — who
 *    receives things from more than one system — opens the app and has no single
 *    answer to 'what needs me today'. Each list is correct; the union of them
 *    exists nowhere."
 *
 * WHY THIS LANDS WITH #947 ITEM 3 RATHER THAN WAITING FOR #881
 * -----------------------------------------------------------
 * `document_route_recipients` IS an inbox — an open row is an item awaiting
 * somebody. Shipping it as its own endpoint beside the aggregate would be the
 * exact mistake #881 was raised to prevent, and #947 says so: "two inbox
 * surfaces would be the same mistake as two audit trails." So routing's
 * recipients register HERE, as core's first source.
 *
 * WHAT WAS LEFT OPEN, AND IS NOW DECIDED
 * ---------------------------------------
 * #881 named three questions a single-source list does not have to answer:
 * ordering across sources, per-source failure isolation, and pagination across
 * sources. This seam shipped first without deciding them. ADR 0016 decides
 * them: {@see \Whity\Sdk\Inbox\InboxOrder}, a failing source reported as
 * unavailable, and keyset paging in {@see InboxAggregate}.
 *
 * SELF-SCOPED, ALWAYS
 * -------------------
 * Every method takes `(tenantId, profileId)` and must answer only for THAT
 * person in THAT tenant. There is no "list everyone's items" on this interface,
 * because an inbox is by definition the caller's own — the same posture
 * `/api/me/notifications` and `/api/me/sessions` take, and the reason the
 * endpoint needs no RBAC permission.
 *
 * SDK 1.97 PUBLISHED THE CONTRACT
 * -------------------------------
 * The methods now live on {@see InboxSource} in the SDK so plugins can supply
 * sources too (ADR 0016 decides the order and paging #881 left open: see
 * {@see \Whity\Sdk\Inbox\InboxOrder}). This interface stays as core's name
 * for the same contract, so core sources need not change.
 *
 * WHY THE NOTIFICATION INBOX IS NOT A SOURCE
 * ------------------------------------------
 * `/api/me/notifications` ({@see \Whity\Api\InboxApiHandler}) is not folded in
 * here, and that is a judgement rather than an omission. A notification is
 * something you READ — subject, body, `read_at`. An inbox item is something
 * that is WAITING FOR YOU TO ACT. #881's aggregate answers "what needs me
 * today", and mixing a read/unread feed into it would answer a different
 * question less well: the count that matters would be diluted by anything that
 * merely happened. Folding it in would also silently change the contract of an
 * endpoint clients already consume.
 */
interface InboxSourceInterface extends InboxSource
{
}
