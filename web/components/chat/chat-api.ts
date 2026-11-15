/**
 * Team chat calls (`/api/v1/chat/…`). Errors come back as plain-words
 * messages: the server's own sentence for a refusal, ours otherwise.
 */

import type { ChatDetail, ChatDirectory, ChatInbox, ChatLayout, ChatMessage, ChatMode, ChatReads, ChatState, ChatSummary, ChatLink } from '@/lib/chat-wire';

type ApiClient = (path: string, init?: RequestInit) => Promise<Response>;

export class ChatError extends Error {
  constructor(
    message: string,
    readonly status: number
  ) {
    super(message);
  }
}

async function whole<T>(api: ApiClient, path: string, init?: RequestInit): Promise<T> {
  const response = await api(path, init);
  const body = (await response.json().catch(() => null)) as (T & { error?: string }) | null;
  if (!response.ok) {
    const plain = response.status >= 400 && response.status < 500 && body?.error ? body.error : '';
    throw new ChatError(plain, response.status);
  }
  return body as T;
}

async function call<T>(api: ApiClient, path: string, init?: RequestInit): Promise<T> {
  return (await whole<{ data?: T }>(api, path, init))?.data as T;
}

function json(method: string, body: unknown): RequestInit {
  return {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  };
}

/** Errors carry the server's sentence for a refusal, or an empty message for the caller to word ({@link chatErrorText}). */
export function chatApi(api: ApiClient) {
  return {
    list: () => call<ChatSummary[]>(api, '/api/v1/chat/chats'),
    // One chat (UC.1): team chats and customer conversations in one list.
    inbox: () => whole<ChatInbox>(api, '/api/v1/chat/inbox'),
    pin: (id: number, pinned: boolean) => call<unknown>(api, `/api/v1/chat/inbox/${id}/pin`, json('POST', { pinned })),
    readCustomer: (id: number, messageId: number) => call<unknown>(api, `/api/v1/chat/inbox/${id}/read`, json('POST', { message_id: messageId })),
    show: (id: number) => call<ChatDetail>(api, `/api/v1/chat/chats/${id}`),
    after: (id: number, after: number) => call<ChatMessage[]>(api, `/api/v1/chat/chats/${id}/messages?after=${after}`),
    before: (id: number, before: number) => call<ChatMessage[]>(api, `/api/v1/chat/chats/${id}/messages?before=${before}`),
    post: (
      id: number,
      body: string,
      mentions: { type: 'person' | 'agent' | 'everyone'; id: number }[],
      extra: { reply_to?: number; thread_root?: number; also_to_chat?: boolean } = {}
    ) => call<ChatMessage>(api, `/api/v1/chat/chats/${id}/messages`, json('POST', { body, mentions, ...extra })),
    // The parts of a message (UC.6–UC.12).
    state: (id: number, after: number, since: string | null) =>
      call<ChatState>(api, `/api/v1/chat/chats/${id}/state?after=${after}${since ? `&since=${encodeURIComponent(since)}` : ''}`),
    edit: (messageId: number, body: string) => call<ChatMessage>(api, `/api/v1/chat/messages/${messageId}`, json('PATCH', { body })),
    deleteMessage: (messageId: number) => call<ChatMessage>(api, `/api/v1/chat/messages/${messageId}`, { method: 'DELETE' }),
    react: (messageId: number, emoji: string) => call<ChatMessage>(api, `/api/v1/chat/messages/${messageId}/reactions`, json('POST', { emoji })),
    pinMessage: (messageId: number, pinned: boolean) => call<ChatMessage>(api, `/api/v1/chat/messages/${messageId}/pin`, json('POST', { pinned })),
    thread: (messageId: number) => call<{ root: ChatMessage; replies: ChatMessage[] }>(api, `/api/v1/chat/messages/${messageId}/thread`),
    search: (id: number, q: string, from: number | null) =>
      call<ChatMessage[]>(api, `/api/v1/chat/chats/${id}/search?q=${encodeURIComponent(q)}${from ? `&from=${from}` : ''}`),
    typing: (id: number) => call<unknown>(api, `/api/v1/chat/chats/${id}/typing`, { method: 'POST' }),
    layout: () => call<{ layout: ChatLayout }>(api, '/api/v1/chat/preferences'),
    setLayout: (layout: ChatLayout) => call<{ layout: ChatLayout }>(api, '/api/v1/chat/preferences', json('PUT', { layout })),
    read: (id: number, messageId: number) => call<unknown>(api, `/api/v1/chat/chats/${id}/read`, json('POST', { message_id: messageId })),
    // WHIT-1077: no push for this chat while it is open in a focused window.
    focus: (id: number, focused: boolean) => call<unknown>(api, `/api/v1/chat/chats/${id}/focus`, json('POST', { focused })),
    createGroup: (title: string, people: number[], link: ChatLink | null) =>
      call<ChatDetail>(
        api,
        '/api/v1/chat/chats',
        json('POST', {
          type: 'group',
          title,
          people,
          ...(link ? { link } : {}),
        })
      ),
    direct: (target: { profile_id: number } | { agent_id: number }) => call<ChatDetail>(api, '/api/v1/chat/chats', json('POST', { type: 'direct', ...target })),
    addPerson: (id: number, profileId: number) => call<ChatDetail>(api, `/api/v1/chat/chats/${id}/members`, json('POST', { profile_id: profileId })),
    addAgent: (id: number, agentId: number, worksFor: number, reads: ChatReads, actions: Record<string, ChatMode>) =>
      call<ChatDetail>(
        api,
        `/api/v1/chat/chats/${id}/members`,
        json('POST', {
          agent_id: agentId,
          works_for: worksFor,
          reads,
          actions,
        })
      ),
    remove: (id: number, memberId: number) => call<unknown>(api, `/api/v1/chat/chats/${id}/members/${memberId}`, { method: 'DELETE' }),
    decide: (messageId: number, approve: boolean) =>
      call<ChatMessage>(api, `/api/v1/chat/messages/${messageId}/${approve ? 'approve' : 'decline'}`, { method: 'POST' }),
    approveProposal: (proposalId: number) => call<unknown>(api, `/api/v1/me/agent-proposals/${proposalId}/approve`, { method: 'POST' }),
    directory: (q: string) => call<ChatDirectory>(api, `/api/v1/chat/directory?q=${encodeURIComponent(q)}`),
  };
}

export type ChatApi = ReturnType<typeof chatApi>;

/** The words to show for a failed call: the server's own sentence, or the generic one. */
export function chatErrorText(e: unknown, generic: string): string {
  return e instanceof Error && e.message !== '' ? e.message : generic;
}
