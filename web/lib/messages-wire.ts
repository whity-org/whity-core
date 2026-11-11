/**
 * Messages (ADR 0016): the wire shapes of `/api/v1/messages/…`, the customer
 * side `/api/v1/me/messages/…` and the public chat `/api/v1/public/chat/…`.
 */

export type ConversationStatus = 'open' | 'pending' | 'closed';

export interface MessagingPerson {
  id: number;
  name: string;
}

export interface Conversation {
  id: number;
  channel: string;
  subject: string | null;
  status: ConversationStatus;
  contact: { name: string | null; address: string };
  customer: MessagingPerson | null;
  assignee: MessagingPerson | null;
  last_message_at: string;
  last_inbound_at: string | null;
  preview: string | null;
  created_at: string;
  closed_at: string | null;
}

export type MessageStatus =
  | 'received'
  | 'queued'
  | 'sent'
  | 'delivered'
  | 'read'
  | 'failed'
  | 'cancelled'
  | 'noted';

export interface Message {
  id: number;
  conversation_id: number;
  kind: 'inbound' | 'outbound' | 'note';
  body: string;
  has_html: boolean;
  author: MessagingPerson | null;
  by_agent: boolean;
  source: string | null;
  status: MessageStatus;
  status_detail: string | null;
  attachments_not_shown: number;
  send_after: string | null;
  sent_at: string | null;
  created_at: string;
}

export interface ConversationList {
  data: Conversation[];
  next_cursor: string | null;
  total: number;
  counts?: Partial<Record<ConversationStatus, number>>;
}

export interface ConversationDetail extends Conversation {
  messages: Message[];
}

export interface MessagingSettings {
  webchat_enabled: boolean;
  webchat_greeting: string | null;
  inbound_email_address: string | null;
  has_inbound_secret: boolean;
  updated_at: string | null;
  workspace_slug: string | null;
}

/** What a customer or visitor sees of a message. */
export interface CustomerMessage {
  id: number;
  from: 'you' | 'workspace';
  author_name: string | null;
  body: string;
  created_at: string;
}

export interface PublicChatInfo {
  workspace_name: string;
  greeting: string | null;
  max_length: number;
  bot_check: { provider: string; site_key: string } | null;
}

/** A message still waiting to leave: an agent's held reply, or one in the queue. */
export function isPending(message: Message): boolean {
  return message.status === 'queued';
}

/** Server timestamps are UTC without a zone; read them as UTC. */
export function parseServerTime(value: string): Date {
  const iso = value.includes('T') ? value : value.replace(' ', 'T');
  return new Date(/[zZ]|[+-]\d{2}:?\d{2}$/.test(iso) ? iso : `${iso}Z`);
}

/** The name to show for a conversation's contact. */
export function contactLabel(conversation: Pick<Conversation, 'contact' | 'customer'>): string {
  return conversation.customer?.name || conversation.contact.name || displayAddress(conversation.contact.address);
}

/** Web chat visitors have an internal address; never show it as if it were one. */
export function displayAddress(address: string): string {
  if (address.startsWith('visitor:')) return '';
  if (address.startsWith('profile:')) return '';
  return address;
}

/** Error text from a JSON error body, or the fallback. */
export async function errorText(response: Response, fallback: string): Promise<string> {
  const body = (await response.json().catch(() => null)) as { error?: string } | null;
  return body?.error && response.status === 422 ? body.error : fallback;
}
