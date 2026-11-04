/**
 * Types for the audit-log admin page (WC-34).
 *
 * Mirror the public contract returned by `GET /api/audit-logs`
 * ({@see \Whity\Api\AuditLogApiHandler::toPublicEntry}).
 */

export interface AuditLogEntry {
  id: number;
  tenantId: number | null;
  actorUserId: number | null;
  action: string;
  targetType: string | null;
  targetId: number | null;
  metadata: Record<string, unknown>;
  ipAddress: string | null;
  createdAt: string | null;
  actor: {
    kind: 'person' | 'agent' | 'system';
    name: string;
    agent: string | null;
    forPerson: string | null;
  };
  target: { type: string; name: string } | null;
  /** The sentence in the reader's language, for reading as it is. */
  sentence: string;
  /** The same sentence with `{actor}` and `{target}` left in, to set the names in bold. */
  sentenceTemplate: string;
  area: string;
  how: string | null;
}

export interface AuditLogFacets {
  people: { id: number; name: string }[];
  agents: { id: string; name: string }[];
  areas: { id: string; name: string }[];
}

export interface AuditLogPagination {
  page: number;
  perPage: number;
  total: number;
  totalPages: number;
}

export interface AuditLogResponse {
  data: AuditLogEntry[];
  pagination: AuditLogPagination;
}

/**
 * Active filter state for the audit-log listing. Empty strings mean "no filter".
 */
export interface AuditLogFilters {
  action: string;
  targetType: string;
  actor: string;
  agent: string;
  area: string;
  /** A preset: today, week or month. Wins over `from`. */
  when: string;
  from: string;
  to: string;
}
