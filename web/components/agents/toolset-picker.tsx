'use client';

import { Checkbox } from '@whity/ui/checkbox';
import { TechnicalDetails } from '@whity/ui/display-preferences';
import { useTranslation } from '@whity/features/i18n';
import { toolsetLabel } from './workspace-agents';

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
            <span>{toolsetLabel(toolset)}</span>
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
