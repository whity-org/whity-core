'use client';

import { Fragment, type ReactNode } from 'react';
import { useTranslation } from '@whity/features/i18n';
import { useDateDisplay } from '@whity/features/datetime';
import { ActorChip } from '@whity/ui/actor-chip';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import type { AuditLogEntry } from './types';

/**
 * Sets a sentence from its template, so the names are bold and an agent is a chip.
 * The names replace placeholders in the server's template and are never searched
 * for in the sentence, so a person called `{target}` is only ever a name.
 */
function SentenceText({ entry }: { entry: AuditLogEntry }) {
  const t = useTranslation('admin');
  const parts = entry.sentenceTemplate.split(/(\{actor\}|\{target\})/);

  return (
    <>
      {parts.map((part, index): ReactNode => {
        if (part === '{actor}') {
          return entry.actor.kind === 'agent' && entry.actor.agent ? (
            <ActorChip
              key={index}
              kind="agent"
              name={entry.actor.agent}
              onBehalfOf={entry.actor.forPerson ?? undefined}
              labels={{ for: t('auditLogs.actor.for', 'for'), agent: t('auditLogs.actor.agent', 'agent') }}
            />
          ) : (
            <b key={index} className="font-medium">
              {entry.actor.name}
            </b>
          );
        }
        if (part === '{target}') {
          return (
            <b key={index} className="font-medium">
              {entry.target?.name ?? ''}
            </b>
          );
        }
        return <Fragment key={index}>{part}</Fragment>;
      })}
    </>
  );
}

/** The day an entry falls on, in the reader's own time zone. */
function dayKey(createdAt: string | null): string {
  if (createdAt === null) return '';
  const when = new Date(createdAt.replace(' ', 'T') + (/[zZ]|[+-]\d\d:?\d\d$/.test(createdAt) ? '' : 'Z'));
  return Number.isNaN(when.getTime()) ? '' : `${when.getFullYear()}-${when.getMonth()}-${when.getDate()}`;
}

function offsetDayKey(days: number): string {
  const day = new Date();
  day.setDate(day.getDate() - days);
  return `${day.getFullYear()}-${day.getMonth()}-${day.getDate()}`;
}

/** The feed: entries grouped by day (W.4), newest first as the server sends them. */
export function AuditFeed({ entries }: { entries: AuditLogEntry[] }) {
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  const groups: { key: string; first: AuditLogEntry; rows: AuditLogEntry[] }[] = [];
  for (const entry of entries) {
    const key = dayKey(entry.createdAt);
    const last = groups[groups.length - 1];
    if (last && last.key === key) last.rows.push(entry);
    else groups.push({ key, first: entry, rows: [entry] });
  }

  const dayLabel = (group: { key: string; first: AuditLogEntry }): string | null => {
    if (dates.hidden) return null;
    if (group.key === offsetDayKey(0)) return t('auditLogs.day.today', 'Today');
    if (group.key === offsetDayKey(1)) return t('auditLogs.day.yesterday', 'Yesterday');
    return dates.date(group.first.createdAt);
  };

  return (
    <div data-testid="audit-feed" className="rounded-card border border-border bg-card p-4">
      {groups.map((group) => {
        const label = dayLabel(group);
        return (
          <section key={group.key} className="not-first:mt-4">
            {label !== null && <h3 className="mb-2 text-sm font-semibold text-muted-foreground">{label}</h3>}
            <ul>
              {group.rows.map((entry) => (
                <li
                  key={entry.id}
                  data-testid={`audit-entry-${entry.id}`}
                  className="grid grid-cols-[auto_minmax(0,1fr)] gap-3 border-b border-border py-3 last:border-b-0"
                >
                  <time className="min-w-12 pt-0.5 text-sm tabular-nums text-muted-foreground" dir="ltr">
                    {dates.time(entry.createdAt)}
                  </time>
                  <div className="min-w-0">
                    <p className="leading-relaxed">
                      <SentenceText entry={entry} />
                    </p>
                    {entry.how && <p className="mt-0.5 text-sm text-muted-foreground">{entry.how}</p>}
                    <TechnicalDetails>
                      <dl className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-muted-foreground" dir="ltr">
                        <div className="flex gap-1">
                          <dt>{t('auditLogs.table.action', 'Action')}</dt>
                          <dd className="font-mono">{entry.action}</dd>
                        </div>
                        {entry.actorUserId !== null && (
                          <div className="flex gap-1">
                            <dt>{t('auditLogs.table.actor', 'Actor')}</dt>
                            <dd className="font-mono">#{entry.actorUserId}</dd>
                          </div>
                        )}
                        {entry.targetType && (
                          <div className="flex gap-1">
                            <dt>{t('auditLogs.table.target', 'Target')}</dt>
                            <dd className="font-mono">
                              {entry.targetType}
                              {entry.targetId !== null ? ` #${entry.targetId}` : ''}
                            </dd>
                          </div>
                        )}
                        {entry.ipAddress && (
                          <div className="flex gap-1">
                            <dt>{t('auditLogs.table.ip', 'IP')}</dt>
                            <dd className="font-mono">{entry.ipAddress}</dd>
                          </div>
                        )}
                        {Object.keys(entry.metadata).length > 0 && (
                          <div className="flex min-w-0 gap-1">
                            <dt>{t('auditLogs.table.details', 'Details')}</dt>
                            <dd className="truncate font-mono">{JSON.stringify(entry.metadata)}</dd>
                          </div>
                        )}
                      </dl>
                    </TechnicalDetails>
                  </div>
                </li>
              ))}
            </ul>
          </section>
        );
      })}
    </div>
  );
}
