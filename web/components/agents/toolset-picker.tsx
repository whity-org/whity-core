'use client';

import { Checkbox } from '@whity/ui/checkbox';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { useFetch } from '@/hooks/useFetch';
import { api } from '@/lib/api/client';
import { toolsetLabel } from './workspace-agents';
import { localizedText } from '@whity/ui/localized-text';

/**
 * "What it works with": the areas an agent may use, by name; the toolset id
 * shows only with Show technical details.
 */
export function ToolsetPicker({
  offered,
  chosen,
  onChange,
}: {
  offered: string[];
  chosen: string[];
  onChange: (toolsets: string[]) => void;
}) {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const names = useFetch<{ id: string; title: string; titleAr: string | null }[]>(async () => {
    const { data, response } = await api.GET('/api/v1/me/agent-toolsets');
    return response.ok ? (data as unknown as { data: { id: string; title: string; titleAr: string | null }[] }).data : [];
  }, []);
  const nameOf = (id: string): string => {
    const known = names.data?.find((toolset) => toolset.id === id);
    return known !== undefined ? localizedText({ en: known.title, ar: known.titleAr }, arabic ? 'ar' : 'en') : toolsetLabel(id);
  };

  return (
    <section className="space-y-3">
      <h2 className="text-sm font-semibold">{t('agents.field.toolsets', 'What it works with')}</h2>
      <p className="text-sm text-muted-foreground">{t('agents.toolsets.help', 'Choosing none means everything the person using it can reach.')}</p>
      <div className="grid gap-2 sm:grid-cols-2">
        {offered.map((toolset) => (
          <label key={toolset} className="flex items-center gap-2 text-sm">
            <Checkbox
              checked={chosen.includes(toolset)}
              onCheckedChange={(on) => onChange(on === true ? [...chosen, toolset] : chosen.filter((id) => id !== toolset))}
            />
            <span>{nameOf(toolset)}</span>
            <TechnicalDetails>
              <span dir="ltr" className="font-mono text-xs text-muted-foreground">
                {toolset}
              </span>
            </TechnicalDetails>
          </label>
        ))}
      </div>
    </section>
  );
}
