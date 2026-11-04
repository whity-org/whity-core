'use client';

import { useMemo } from 'react';
import { Button } from '@whity/ui/button';
import { Icon } from '@whity/ui/icon';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { useTranslation } from '@whity/features/i18n';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';

const KINDS = ['wiki', 'note', 'template'];

/**
 * What it may read (TplAgentInstructions): notes, wiki pages and templates the
 * agent uses for reference. It never widens access: only what the editor can
 * read can be added, the server checks each one, and each person's agent still
 * reads only what that person can. A document this viewer can't read keeps its
 * place without its name.
 */
export function MayReadPicker({
  chosen,
  known,
  onChange,
}: {
  chosen: number[];
  known: { id: number; title: string | null }[];
  onChange: (ids: number[]) => void;
}) {
  const t = useTranslation('admin');
  const { apiClient } = useAuth();

  const sources = useFetch<{ id: number; title: string }[]>(async () => {
    const lists = await Promise.all(
      KINDS.map(async (kind) => {
        const response = await apiClient(`/api/v1/document-sources?kind=${kind}`);
        return response.ok ? ((await response.json()) as { data?: { id: number; title: string }[] }).data ?? [] : [];
      }),
    );
    return lists.flat();
  }, [apiClient]);

  const titles = useMemo(() => {
    const map = new Map<number, string>();
    for (const source of sources.data ?? []) map.set(source.id, source.title);
    for (const source of known) if (source.title !== null) map.set(source.id, source.title);
    return map;
  }, [sources.data, known]);
  const remaining = (sources.data ?? []).filter((source) => !chosen.includes(source.id));

  return (
    <section className="space-y-3 rounded-card border border-border bg-card p-5">
      <div>
        <h2 className="text-base font-semibold">{t('agentRecord.mayRead', 'What it may read')}</h2>
        <p className="text-sm text-muted-foreground">
          {t('agentRecord.mayRead.help', 'Pages and templates it uses for reference. It never reads more than the person it works for can.')}
        </p>
      </div>
      {chosen.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t('agentRecord.mayRead.empty', 'Nothing yet. It uses only the records its permissions allow.')}</p>
      ) : (
        <ul className="divide-y divide-divider">
          {chosen.map((id) => {
            const title = titles.get(id) ?? t('agentRecord.mayRead.hidden', 'A document you can’t open');
            return (
              <li key={id} className="flex items-center gap-2 py-2 text-sm">
                <Icon name="document" size={16} />
                <span className="min-w-0 flex-1 truncate">{title}</span>
                <Button
                  variant="ghost"
                  size="sm"
                  aria-label={t('agentRecord.mayRead.remove', 'Remove {name}', { name: title })}
                  onClick={() => onChange(chosen.filter((other) => other !== id))}
                >
                  {t('agentRecord.mayRead.removeShort', 'Remove')}
                </Button>
              </li>
            );
          })}
        </ul>
      )}
      {remaining.length > 0 && (
        <Select value="" onValueChange={(value) => onChange([...chosen, Number(value)])}>
          <SelectTrigger aria-label={t('agentRecord.mayRead.add', 'Add a page or template')} className="w-full">
            <SelectValue placeholder={t('agentRecord.mayRead.add', 'Add a page or template')} />
          </SelectTrigger>
          <SelectContent>
            {remaining.map((source) => (
              <SelectItem key={source.id} value={String(source.id)}>
                {source.title}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
    </section>
  );
}
