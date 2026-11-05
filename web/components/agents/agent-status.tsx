'use client';

import { Avatar } from '@whity/ui/avatar';
import { Badge } from '@whity/ui/badge';
import { useTranslation } from '@whity/features/i18n';

/** Working, Paused or Archived (TplAgents). */
export function AgentStatusBadge({ status, paused }: { status: 'active' | 'archived'; paused: boolean }) {
  const t = useTranslation('admin');
  if (status === 'archived') return <Badge variant="outline">{t('agents.status.archived', 'Archived')}</Badge>;
  if (paused) return <Badge variant="outline">{t('agents.status.paused', 'Paused')}</Badge>;
  return <Badge variant="success">{t('agents.status.working', 'Working')}</Badge>;
}

/** How much an agent does on its own: how many actions are Allowed and how many Ask first. */
export function OnItsOwn({ counts }: { counts: { allowed: number; askFirst: number } | null }) {
  const t = useTranslation('admin');
  if (counts === null) return null;
  return (
    <span className="inline-flex flex-wrap items-center gap-1.5">
      {counts.allowed > 0 && <Badge variant="success">{t('agents.onItsOwn.allowed', '{n} allowed', { n: counts.allowed })}</Badge>}
      {counts.askFirst > 0 && <Badge variant="warning">{t('agents.onItsOwn.askFirst', '{n} ask first', { n: counts.askFirst })}</Badge>}
    </span>
  );
}

/** Who an agent works for, from the assignments: names with avatars, never ids. */
export function WorksFor({ people }: { people: { id: number; name: string | null }[] }) {
  const t = useTranslation('admin');
  const first = people[0];
  if (first === undefined) return <span className="text-sm text-muted-foreground">{t('agents.worksFor.nobody', 'Nobody yet')}</span>;
  const name = first.name ?? t('agents.worksFor.unknown', 'Someone');
  return (
    <span className="inline-flex items-center gap-2">
      <Avatar name={name} size={28} />
      <span>{name}</span>
      {people.length > 1 && <span className="text-sm text-muted-foreground">{t('agents.worksFor.more', '+{n} more', { n: people.length - 1 })}</span>}
    </span>
  );
}
