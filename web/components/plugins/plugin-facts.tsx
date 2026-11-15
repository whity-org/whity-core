'use client';

import type { components } from '@whity/api-client';
import { useCapabilities } from '@/hooks/useCapabilities';
import { PermissionButton } from '@/components/rbac/permission-button';
import { useTranslation } from '@whity/features/i18n';
import { Button } from '@whity/ui/button';
import { IconDownload } from '@tabler/icons-react';

export type CataloguePlugin = components['schemas']['StoreCataloguePlugin'];

export const PLUGINS_INSTALL = 'plugins:install';

/** What a plugin adds, as the chips on its tile and page (section 19). Only what the store said. */
export function usePluginAddsLabels() {
  const t = useTranslation('admin');

  return (plugin: CataloguePlugin): string[] => {
    const adds = plugin.adds;
    const labels: string[] = [];
    if (adds?.screens) labels.push(t('pluginStore.adds.screens', 'Screens'));
    if (adds?.document_blocks) labels.push(t('pluginStore.adds.blocks', 'Document blocks'));
    if (adds?.agent_tools) labels.push(t('pluginStore.adds.tools', 'Agent tools'));
    if (adds?.fields) labels.push(t('pluginStore.adds.fields', 'Fields'));
    if ((plugin.outside_domains ?? []).length > 0) labels.push(t('pluginStore.adds.connects', 'Connects out'));
    return labels;
  };
}

/** The price, in normal units. Nothing when the store didn't give one: never "Free" by guesswork. */
export function usePriceLabel() {
  const t = useTranslation('admin');

  return (plugin: CataloguePlugin): string | null => {
    const price = plugin.price;
    if (!price) return null;
    const amount = `${price.currency} ${price.amount}`;
    if (price.interval === 'month') return t('pluginStore.price.month', '{amount} a month', { amount });
    if (price.interval === 'year') return t('pluginStore.price.year', '{amount} a year', { amount });
    return amount;
  };
}

/** "Used by N workspaces", when the store reports it. */
export function useUsageLabel() {
  const t = useTranslation('admin');

  return (plugin: CataloguePlugin): string | null =>
    typeof plugin.used_by === 'number'
      ? t('pluginStore.usedBy', 'Used by {count} workspaces', { count: plugin.used_by })
      : null;
}

export interface InstallButtonProps {
  /** The server's answer: this caller's tenant may install (operators only, Q4). */
  canInstall: boolean;
  disabled?: boolean;
  onClick: () => void;
}

/**
 * Install, for people who can. Anyone else sees it disabled with S.3's sentence:
 * either they don't hold plugins:install, or they're in a workspace, and code
 * plugins are installed for the whole deployment.
 */
export function InstallButton({ canInstall, disabled, onClick }: InstallButtonProps) {
  const t = useTranslation('admin');
  const { hasPermission } = useCapabilities();

  if (!canInstall || !hasPermission(PLUGINS_INSTALL)) {
    const hint = t('pluginStore.askToInstall', 'Ask someone with permission to install plugins');
    return (
      <span className="inline-flex flex-col items-end gap-1">
        <Button disabled className="gap-2">
          <IconDownload />
          {t('pluginStore.action.install', 'Install')}
        </Button>
        <span className="text-xs text-muted-foreground">{hint}</span>
      </span>
    );
  }

  return (
    <PermissionButton permission={PLUGINS_INSTALL} disabled={disabled} onClick={onClick} className="gap-2">
      <IconDownload />
      {t('pluginStore.action.install', 'Install')}
    </PermissionButton>
  );
}
