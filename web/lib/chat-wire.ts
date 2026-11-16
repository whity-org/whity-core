/**
 * Team chat (CH.1, CH.2): the wire shapes of `/api/v1/chat/…`.
 */

export interface ChatPerson {
  id: number;
  name: string;
}

export type ChatMode = 'off' | 'ask' | 'allowed';
export type ChatReads = 'now' | 'week' | 'all';

export interface ChatLink {
  type: 'board' | 'meeting' | 'record';
  id: string;
  label: string;
}

export interface ChatPersonMember {
  member_id: number;
  type: 'person';
  profile_id: number;
  name: string;
  added_by: ChatPerson | null;
  joined_at: string | null;
}

export interface ChatAgentMember {
  member_id: number;
  type: 'agent';
  agent_id: number;
  name: string;
  name_ar: string;
  role: string;
  role_ar: string;
  works_for: ChatPerson;
  reads: ChatReads;
  reads_from: string | null;
  actions: Record<string, ChatMode>;
  added_by: ChatPerson | null;
  joined_at: string | null;
}

export type ChatMember = ChatPersonMember | ChatAgentMember;

export interface ChatAuthor {
  type: 'person' | 'agent';
  profile_id?: number;
  agent_id?: number;
  name: string;
  works_for?: ChatPerson;
}

export interface ChatMention {
  type: 'person' | 'agent' | 'everyone';
  id: number;
  name: string;
}

export interface ChatProposal {
  id: number;
  tool: string;
  summary: string;
  status: string;
  waiting_for: ChatPerson;
  can_decide: boolean;
  expires_at: string | null;
}

export interface ChatEvent {
  event: 'created' | 'renamed' | 'linked' | 'unlinked' | 'person_added' | 'person_removed' | 'left' | 'agent_added' | 'agent_removed';
  by?: ChatPerson;
  person?: ChatPerson;
  agent?: { id: number; name: string; name_ar?: string };
  works_for?: ChatPerson;
  reads?: ChatReads;
  actions?: Record<string, ChatMode>;
  title?: string;
  link?: ChatLink | null;
}

export interface ChatMessage {
  id: number;
  chat_id: number;
  kind: 'message' | 'system' | 'line' | 'proposal';
  body: string;
  author: ChatAuthor | null;
  mentions: ChatMention[];
  status: 'posted' | 'held' | 'declined';
  waiting_for: ChatPerson | null;
  can_decide: boolean;
  proposal: ChatProposal | null;
  event: ChatEvent | null;
  created_at: string | null;
  // UC.6–UC.12 (WHIT-1094)
  reply_to: ChatQuote | null;
  thread_root_id: number | null;
  in_chat: boolean;
  thread: { count: number; last_at: string | null; people: string[] } | null;
  reactions: ChatReaction[];
  attachments: ChatAttachment[];
  block: ChatBlock | null;
  pinned: boolean;
  edited: boolean;
  deleted: boolean;
  can_edit: boolean;
  can_delete: boolean;
}

/** The message a reply answers, quoted above it. */
export interface ChatQuote {
  id: number;
  author_name: string;
  body: string;
  deleted: boolean;
}

/** A poll inside a message (UC.5): live counts, and whether the reader voted. */
export interface ChatPollBlock {
  type: 'poll';
  question: string;
  multiple: boolean;
  closes_at: string | null;
  closed: boolean;
  voters: number;
  options: { text: string; votes: number; mine: boolean; people: string[] }[];
}

/** A Whity record inside a message (UC.5), with its status as it is now. */
export interface ChatRecordBlock {
  type: 'record';
  record: 'booking' | 'document';
  id: number;
  missing?: boolean;
  title: string;
  status: string;
  href: string;
  starts_at?: string | null;
  ends_at?: string | null;
  with?: string | null;
  number?: string | null;
  kind?: string | null;
  created_at?: string | null;
}

export type ChatBlock = ChatPollBlock | ChatRecordBlock;

/** A photo, video, file or voice message on a message (UC.10). */
export interface ChatAttachment {
  id: number;
  kind: 'image' | 'video' | 'file' | 'voice';
  content_type: string;
  byte_size: number;
  filename: string | null;
  width: number | null;
  height: number | null;
  duration_ms: number | null;
  transcript: string | null;
  message_id: number | null;
  url: string;
}

export interface ChatReaction {
  emoji: string;
  count: number;
  mine: boolean;
  people: string[];
}

export interface ChatReadMark {
  member_id: number;
  profile_id: number;
  name: string;
  last_read_message_id: number;
}

/** What an open chat polls for (`GET /api/v1/chat/chats/{id}/state`). */
export interface ChatState {
  messages: ChatMessage[];
  changed: ChatMessage[];
  read_marks: ChatReadMark[];
  typing: string[];
  pinned: ChatMessage[];
  now: string | null;
}

export type ChatLayout = 'bubbles' | 'rows';

export interface ChatSummary {
  id: number;
  type: 'group' | 'direct';
  title: string;
  with: ChatMember | null;
  people_count: number;
  agents_count: number;
  link: ChatLink | null;
  unread: number;
  last_message: ChatMessage | null;
  last_message_at: string | null;
  created_at: string | null;
}

export interface ChatDetail extends Omit<ChatSummary, 'unread' | 'last_message'> {
  members: ChatMember[];
  messages: ChatMessage[];
  has_earlier: boolean;
  my_member_id: number;
  last_read_message_id: number;
}

export interface ChatDirectory {
  people: { type: 'person'; id: number; name: string }[];
  agents: {
    type: 'agent';
    id: number;
    name: string;
    name_ar: string;
    role: string;
    role_ar: string;
  }[];
}

/**
 * One chat (UC.1, WHIT-1093): an item of the one Chat list, `GET /api/v1/chat/inbox`.
 * A team chat carries the {@link ChatSummary} fields; a customer conversation
 * its channel, status, contact and assignee. Both kinds share the
 * conversation id, so `/chat?c=<id>` opens either.
 */
export interface CustomerLastMessage {
  from: 'customer' | 'team';
  body: string;
  by_agent: boolean;
  author: ChatPerson | null;
}

export interface CustomerChatItem {
  id: number;
  kind: 'customer';
  type: 'customer';
  title: string | null;
  channel: string;
  status: 'open' | 'pending' | 'closed';
  subject: string | null;
  contact: { name: string | null; address: string };
  customer: ChatPerson | null;
  assignee: ChatPerson | null;
  waiting_since: string | null;
  unread: number;
  pinned: boolean;
  muted?: boolean;
  last_message: CustomerLastMessage | null;
  last_message_at: string | null;
  created_at: string | null;
}

export type TeamChatItem = ChatSummary & { kind: 'team'; pinned: boolean; muted?: boolean };

/** A number or address the workspace blocked (UC.12). */
export interface ChatBlockedSender {
  id: number;
  channel: string;
  address: string;
  name: string | null;
  reported_spam: boolean;
  blocked_by: string | null;
  created_at: string | null;
}

/** A bot set by the workspace (UC.4). */
export interface ChatBot {
  kind: 'away' | 'welcome' | 'handover';
  enabled: boolean;
  settings: Record<string, unknown>;
}

export type ChatInboxItem = TeamChatItem | CustomerChatItem;

export interface ChatInbox {
  data: ChatInboxItem[];
  /** Whether customer conversations are in the list (messages.read). */
  customers: boolean;
}
