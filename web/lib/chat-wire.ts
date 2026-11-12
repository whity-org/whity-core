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
  type: 'person' | 'agent';
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
}

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
