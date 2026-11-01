'use client';

/**
 * The top bar of the standard shell (WHIT-147; the app-shell cards): the
 * search field that opens ⌘K, with its shortcut. The cards also draw a bell
 * and help at the end; those wait for a destination to point at, so the end
 * of the bar is left empty rather than holding buttons that go nowhere.
 */

import { useSyncExternalStore } from 'react';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';
import { OPEN_COMMAND_PALETTE_EVENT } from './app-command-palette';

/** The platform never changes while the page is open. */
const noSubscription = () => () => {};

export function TopBar() {
  const t = useTranslation('common');
  // ⌘ on Apple keyboards, Ctrl elsewhere. The server snapshot is Ctrl, so the
  // server render and hydration agree before the client's answer lands.
  const shortcut = useSyncExternalStore(
    noSubscription,
    () => (/Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent) ? '⌘K' : 'Ctrl K'),
    () => 'Ctrl K',
  );

  return (
    <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-border bg-card py-3 ps-14 pe-4 md:px-6">
      <button
        type="button"
        onClick={() => window.dispatchEvent(new Event(OPEN_COMMAND_PALETTE_EVENT))}
        aria-label={t('topBar.search', 'Search')}
        aria-keyshortcuts="Control+K Meta+K"
        data-testid="top-bar-search"
        className="flex h-9 w-full max-w-[360px] items-center gap-2 rounded-control border border-input bg-background px-2.5 text-sm text-muted-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
      >
        <Icon name="search" size={16} />
        <span className="flex-1 text-start">{t('topBar.search', 'Search')}</span>
        <kbd className="rounded border border-border px-1 font-mono text-[0.6875rem]" dir="ltr">
          {shortcut}
        </kbd>
      </button>
    </header>
  );
}
