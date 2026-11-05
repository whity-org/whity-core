'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Button } from '@whity/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@whity/ui/dialog';
import { Icon, type IconName } from '@whity/ui/icon';
import { AreaError, LoadingRows } from '@whity/ui/page-states';
import { useFormattingLocale, useTranslation } from '@whity/features/i18n';
import { localized, refusal, toolsetLabel, type AgentPreset, type WorkspaceAgent } from '@/components/agents/workspace-agents';
import { useFetch } from '@/hooks/useFetch';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';

/**
 * New agent (AI.2, TplAgentPresets): a gallery of ready agents with Start from
 * blank last. A new agent from a preset starts with every action on Ask first
 * (the server's rule). Each card says what it's for, what it does and what it
 * needs. How many workspaces use a preset is left out: that is data about other
 * workspaces, so the card counts this workspace's own agents.
 */
export function AgentPresetGallery({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const t = useTranslation('admin');
  const arabic = useFormattingLocale() === 'ar';
  const router = useRouter();
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [busy, setBusy] = useState<string | null>(null);

  const presets = useFetch<AgentPreset[]>(async () => {
    if (!open) return [];
    const response = await apiClient('/api/v1/agent-presets');
    if (!response.ok) throw new Error(t('agents.error.presets', 'The presets could not be loaded.'));
    return ((await response.json()) as { data: AgentPreset[] }).data;
  }, [apiClient, open]);

  const use = async (preset: AgentPreset) => {
    setBusy(preset.key);
    try {
      const response = await apiClient('/api/v1/agents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ presetKey: preset.key }),
      });
      if (!response.ok) {
        addToast(await refusal(response, t('agents.error.save', 'This agent could not be saved. Try again.')), 'error');
        return;
      }
      const body = (await response.json()) as { data: WorkspaceAgent };
      router.push(`/admin/agents/${body.data.id}`);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{t('agents.new.pick', 'Pick an agent to start from')}</DialogTitle>
          <DialogDescription>
            {t('agents.new.description', 'A ready agent asks you before doing anything that matters. You can change everything before anyone uses it.')}
          </DialogDescription>
        </DialogHeader>
        {presets.loading ? (
          <LoadingRows headers={[t('agents.table.agent', 'Agent')]} rows={3} label={t('agents.loading', 'Loading…')} />
        ) : presets.error !== null ? (
          <AreaError message={presets.error} retryLabel={t('agents.retry', 'Try again')} onRetry={presets.refetch} />
        ) : (
          <ul className="grid max-h-[60vh] gap-3 overflow-y-auto sm:grid-cols-2">
            {(presets.data ?? []).map((preset) => (
              <li key={preset.key} className="flex flex-col gap-3 rounded-card border border-border bg-card p-4">
                <div className="flex items-center gap-3">
                  <span aria-hidden="true" className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-tint text-primary-tint-foreground">
                    <Icon name={(preset.icon ?? 'robot') as IconName} size={20} />
                  </span>
                  <span className="min-w-0">
                    <span className="block font-semibold">{localized(preset.name, preset.nameAr, arabic)}</span>
                    {preset.agentsFromIt > 0 && (
                      <span className="block text-sm text-muted-foreground">
                        {t('agents.new.fromIt', 'You have {n} from this', { n: preset.agentsFromIt })}
                      </span>
                    )}
                  </span>
                </div>
                <p className="text-sm text-muted-foreground">{localized(preset.role, preset.roleAr, arabic)}</p>
                {preset.does.length > 0 && (
                  <ul className="flex-1 list-disc space-y-1 ps-5 text-sm">
                    {(arabic && preset.doesAr.length > 0 ? preset.doesAr : preset.does).map((thing) => (
                      <li key={thing}>{thing}</li>
                    ))}
                  </ul>
                )}
                <p className="text-sm text-muted-foreground">
                  {preset.needs === null
                    ? t('agents.new.alone', 'Works with Whity alone')
                    : t('agents.new.needs', 'Needs {name}', { name: toolsetLabel(preset.needs) })}
                </p>
                <Button variant="outline" disabled={busy !== null} loading={busy === preset.key} onClick={() => void use(preset)}>
                  {t('agents.new.usePreset', 'Use this agent')}
                </Button>
              </li>
            ))}
            <li className="flex flex-col items-start justify-center gap-3 rounded-card border border-dashed border-border p-4">
              <span className="font-semibold">{t('agents.new.blank', 'Start from blank')}</span>
              <p className="text-sm text-muted-foreground">{t('agents.new.blankHelp', 'Write the instructions yourself.')}</p>
              <Button variant="outline" asChild>
                <Link href="/admin/agents/new">{t('agents.new.scratch', 'Start from blank')}</Link>
              </Button>
            </li>
          </ul>
        )}
      </DialogContent>
    </Dialog>
  );
}
