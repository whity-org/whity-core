'use client';

/**
 * What ⌘K reads from the server besides search (WHIT-1070): the person's
 * agents in Chat for `/ask`, what is waiting for them for `/approve`, and
 * their recent documents for the empty box.
 *
 * Each is asked for only when it can be shown: agents and recent documents
 * when the palette opens (and the person has Chat, or may read documents),
 * the waiting list only once `/approve` is typed. Every read is the caller's
 * own (`/api/me/*`, Chat's directory); a failure leaves that part empty
 * rather than blocking the palette.
 */

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import type { ChatDirectory } from '@/lib/chat-wire';
import type { AgentProposal } from '@/components/agents/proposals';
import { routingHref, routingMeta, type InboxItem } from '@/components/documents/routing-wire';
import type { ChatAgent, WaitingEntry } from './core-commands';
import type { RecentPlace } from './recent';

interface WaitingResponse {
  data: Array<{ key: string; kind: 'inbox' | 'proposal'; at: string; item?: InboxItem; proposal?: AgentProposal }>;
}

interface RecentDocument {
  kind: 'document' | 'draft';
  id: number;
  title: string;
}

/** How many waiting rows `/approve` lists; the rest are in Inbox and Approvals. */
const WAITING_LIMIT = 20;
/** How many of the server's recent documents join the palette's own recents. */
const RECENT_DOCUMENTS = 3;

export interface PaletteDataOptions {
  open: boolean;
  /** `/approve` is being typed. */
  wantWaiting: boolean;
  chat: boolean;
  /** `documents:read`: recent documents open only for someone who may read them. */
  documents: boolean;
  /** Where an agent proposal opens; null when the person can't open Approvals. */
  approvalsHref: string | null;
  arabic: boolean;
  unknownAgent: string;
}

export interface PaletteData {
  agents: ChatAgent[] | null;
  waiting: WaitingEntry[] | null;
  waitingLoaded: boolean;
  recentDocuments: RecentPlace[];
}

export function toWaitingEntries(
  body: WaitingResponse,
  approvalsHref: string | null,
  arabic: boolean,
  unknownAgent: string,
): WaitingEntry[] {
  return body.data.flatMap((row): WaitingEntry[] => {
    if (row.proposal !== undefined) {
      const p = row.proposal;
      return [
        {
          key: row.key,
          title: p.summary,
          hint: (arabic && p.agent?.nameAr) || p.agent?.name || unknownAgent,
          href: approvalsHref,
          proposal: true,
        },
      ];
    }
    if (row.item === undefined) return [];
    const meta = routingMeta(row.item);
    return [
      {
        key: row.key,
        title: row.item.title,
        hint: row.item.subtitle ?? '',
        href:
          row.item.resource_type === 'conversation' && row.item.resource_id
            ? `/admin/messages?c=${encodeURIComponent(row.item.resource_id)}`
            : meta === null
              ? null
              : routingHref(meta),
        proposal: false,
      },
    ];
  });
}

export function usePaletteData(options: PaletteDataOptions): PaletteData {
  const { open, wantWaiting, chat, documents, approvalsHref, arabic, unknownAgent } = options;
  const [agents, setAgents] = useState<ChatAgent[] | null>(null);
  const [waiting, setWaiting] = useState<WaitingEntry[] | null>(null);
  const [waitingLoaded, setWaitingLoaded] = useState(false);
  const [recentDocuments, setRecentDocuments] = useState<RecentPlace[]>([]);

  // Agents and recent documents: once per opening.
  useEffect(() => {
    if (!open) return undefined;
    let live = true;
    if (chat) {
      api
        .GET('/api/v1/chat/directory', { params: { query: { q: '' } } })
        .then(({ data: body }) => ((body as { data?: ChatDirectory } | undefined)?.data ?? null))
        .then(
          (directory) => {
            if (!live) return;
            setAgents(
              (directory?.agents ?? []).map((a) => ({
                id: a.id,
                name: arabic && a.name_ar ? a.name_ar : a.name,
                role: arabic && a.role_ar ? a.role_ar : a.role,
              })),
            );
          },
          () => live && setAgents([]),
        );
    }
    if (documents) {
      api
        .GET('/api/v1/me/recent-documents')
        .then(({ data: body }) => ((body as { data?: RecentDocument[] } | undefined)?.data ?? []))
        .then(
          (rows) => {
            if (!live) return;
            setRecentDocuments(
              rows.slice(0, RECENT_DOCUMENTS).map((row) => {
                const href = row.kind === 'draft' ? `/admin/document-sources/${row.id}` : `/admin/document-library/${row.id}`;
                return { id: `recent-${row.kind}-${row.id}`, label: row.title, href };
              }),
            );
          },
          () => live && setRecentDocuments([]),
        );
    }
    return () => {
      live = false;
    };
  }, [open, chat, documents, arabic]);

  // What is waiting: when `/approve` is typed, fresh each time.
  useEffect(() => {
    if (!open || !wantWaiting) return undefined;
    // The last answer stays on screen while a fresh one loads.
    let live = true;
    api
      .GET('/api/v1/me/waiting', { params: { query: { limit: WAITING_LIMIT } } })
      .then(({ data: body }) => (body === undefined ? null : (body as unknown as WaitingResponse)))
      .then(
        (body) => {
          if (!live) return;
          setWaiting(body === null ? null : toWaitingEntries(body, approvalsHref, arabic, unknownAgent));
          setWaitingLoaded(true);
        },
        () => {
          if (!live) return;
          setWaiting(null);
          setWaitingLoaded(true);
        },
      );
    return () => {
      live = false;
    };
  }, [open, wantWaiting, approvalsHref, arabic, unknownAgent]);

  // Without Chat there is no one to ask: an empty list, not "loading".
  return { agents: chat ? agents : [], waiting, waitingLoaded, recentDocuments };
}
