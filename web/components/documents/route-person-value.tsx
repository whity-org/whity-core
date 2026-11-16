'use client';

/**
 * A person value at a route step (merge map A3), such as the supervisor the
 * office names at pre-check. Searches the workspace's people by name and stores
 * the chosen person's user id; a later step can leave that person out.
 */

import { useEffect, useState } from 'react';
import { Button } from '@whity/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@whity/features/i18n';
import { api } from '@/lib/api/client';

interface Person {
  id: number;
  name: string;
}

export function RoutePersonValue({
  id,
  value,
  required,
  onChange,
}: {
  id: string;
  /** The chosen user id as text, or '' for nobody yet. */
  value: string;
  required: boolean;
  onChange: (value: string) => void;
}) {
  const t = useTranslation('documents');
  const [query, setQuery] = useState('');
  const [people, setPeople] = useState<Person[]>([]);
  const [chosen, setChosen] = useState<Person | null>(null);

  // A value set before this field knew the name shows its id until changed.
  const shown: Person | null = value === '' ? null : (chosen ?? { id: Number(value), name: `#${value}` });

  const picked = shown !== null;

  useEffect(() => {
    if (picked) return undefined;
    let live = true;
    const timer = setTimeout(() => {
      api
        .GET('/api/v1/chat/directory', { params: { query: { q: query } } })
        .then(({ data }) => {
          if (!live) return;
          const found = (data as { data?: { people?: Person[] } } | undefined)?.data?.people ?? [];
          setPeople(found.map((p) => ({ id: p.id, name: p.name })));
        })
        .catch(() => live && setPeople([]));
    }, 200);
    return () => {
      live = false;
      clearTimeout(timer);
    };
  }, [query, picked]);

  if (shown !== null) {
    return (
      <div className="flex items-center justify-between gap-2 rounded-control border border-border px-3 py-2">
        <span className="text-sm">{shown.name}</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => {
            setChosen(null);
            onChange('');
          }}
        >
          {t('routing.act.values.personChange', 'Change')}
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-1">
      <Input
        id={id}
        value={query}
        aria-required={required}
        placeholder={t('routing.act.values.personSearch', 'Search by name')}
        onChange={(e) => setQuery(e.target.value)}
      />
      {people.length > 0 ? (
        <ul className="max-h-48 overflow-y-auto rounded-control border border-border" role="listbox">
          {people.map((person) => (
            <li key={person.id}>
              <button
                type="button"
                role="option"
                aria-selected={false}
                className="w-full px-3 py-2 text-start text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                onClick={() => {
                  setChosen(person);
                  onChange(String(person.id));
                }}
              >
                {person.name}
              </button>
            </li>
          ))}
        </ul>
      ) : (
        query.trim() !== '' && (
          <p className="text-sm text-muted-foreground">{t('routing.act.values.personNone', 'Nobody in this workspace has that name.')}</p>
        )
      )}
    </div>
  );
}
