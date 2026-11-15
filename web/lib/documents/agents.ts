import type { EditorAgentChoice, EditorAgentRequest, NewAgentRequest } from '@whity/ui/editor/agents';
import type { DocumentEditorAdapter } from '@whity/features/document-editor';
import { api } from '@/lib/api/client';
import type { components } from '@whity/api-client';

/**
 * Ask an agent from the editor (editor M6): the web half of
 * `/api/v1/document-sources/{id}/agent-requests` and
 * `/api/v1/document-agent-requests/{rid}/stop`, plus the person's agents from
 * `/api/v1/me/agents`. Whity hosts no model: the person's own agent picks the
 * request up over MCP, and what it writes arrives as suggestions.
 */

type WireRequest = components['schemas']['DocumentAgentRequest'];

export function toAgentRequest(r: WireRequest): EditorAgentRequest {
  return {
    id: String(r.id),
    request: r.request,
    quote: r.quote ?? null,
    status: r.status,
    agent: r.agent ? { id: String(r.agent.id ?? ''), name: String(r.agent.name ?? '') } : null,
    title: r.title,
    progress: r.progress ? { done: r.progress.done ?? 0, total: r.progress.total ?? 0 } : null,
    detail: r.detail,
    stopping: r.stopping,
    at: r.at,
  };
}

export const agentsAdapter: Pick<DocumentEditorAdapter, 'agentChoices' | 'agentRequests' | 'askAgent' | 'stopAgentRequest'> = {
  /** The agents this person uses, then "any of my agents" for one connected over MCP without a workspace agent. */
  async agentChoices(anyLabel): Promise<EditorAgentChoice[]> {
    const { data } = await api.GET('/api/v1/me/agents');
    const mine = (data?.data ?? []).filter((a) => a.assignment !== null && !a.paused).map((a) => ({ id: a.id, name: a.name }));
    return [...mine, { id: null, name: anyLabel }];
  },

  async agentRequests(id): Promise<EditorAgentRequest[]> {
    const { data, response } = await api.GET('/api/v1/document-sources/{id}/agent-requests', {
      params: { path: { id: Number(id) } },
    });
    if (data === undefined) throw new Error(`Failed to load the agent requests (${response.status})`);
    return data.data.map(toAgentRequest);
  },

  async askAgent(id, request: NewAgentRequest): Promise<EditorAgentRequest> {
    const { data, response } = await api.POST('/api/v1/document-sources/{id}/agent-requests', {
      params: { path: { id: Number(id) } },
      body: {
        request: request.request,
        agent_id: request.agentId,
        anchor_block_id: request.anchorBlockId ?? null,
        quote: request.quote ?? null,
      },
    });
    if (data === undefined) throw new Error(`Failed to ask the agent (${response.status})`);
    return toAgentRequest(data.data);
  },

  async stopAgentRequest(requestId): Promise<EditorAgentRequest> {
    const { data, response } = await api.POST('/api/v1/document-agent-requests/{rid}/stop', {
      params: { path: { rid: Number(requestId) } },
    });
    if (data === undefined) throw new Error(`Failed to stop the request (${response.status})`);
    return toAgentRequest(data.data);
  },
};
