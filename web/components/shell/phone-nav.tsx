'use client';

/**
 * The phone's navigation (`PwNav`, PW.1–PW.3): the tab bar under 640px and
 * the Create sheet its centre button opens (M.11).
 *
 * Home, Inbox, Chat and More are fixed places; Inbox and Chat appear only when
 * the person has them (the server's nav decides, by permission), and an empty
 * slot keeps Create centred. Create leads with Ask an agent, then up to six
 * tiles for the pages this person can create things on, in the order below.
 */

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useMemo, useState } from 'react';
import { PhoneTabBar, type PhoneTab } from '@whity/ui/phone-tab-bar';
import { Icon, type IconName } from '@whity/ui/icon';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@whity/ui/sheet';
import { useTranslation } from '@whity/features/i18n';
import { useShellNav } from '@/components/shell/use-shell-nav';
import { useHomePath } from '@/lib/branding-context';

/** The More page: the sidebar's groups as a list (PW.2). */
export const MORE_PATH = '/more';

interface CreateTile {
  id: string;
  /** The nav item that must be present (the permission check). */
  navId: string;
  icon: IconName;
  label: string;
}

const isUnder = (pathname: string, href: string | undefined): boolean =>
  !!href && (pathname === href || pathname.startsWith(`${href}/`));

export function PhoneNav() {
  const pathname = usePathname() ?? '';
  const t = useTranslation('common');
  const { counts, hrefOf } = useShellNav();
  // #2386: Home is the instance's home page, the dashboard unless the operator chose another.
  const homePath = useHomePath();
  const [createOpen, setCreateOpen] = useState(false);

  const countLabel = (count: number) =>
    t('sidebar.count.waiting', '{count} waiting').replace('{count}', String(count));

  const homeHref = homePath;
  const inboxHref = hrefOf('inbox');
  const chatHref = hrefOf('chat');

  const onHome = isUnder(pathname, homeHref);
  const onInbox = isUnder(pathname, inboxHref);
  const onChat = isUnder(pathname, chatHref);

  const start: PhoneTab[] = [
    { id: 'home', label: t('phoneNav.home', 'Home'), href: homeHref, icon: 'dashboard', active: onHome },
  ];
  if (inboxHref) {
    start.push({
      id: 'inbox',
      label: t('sidebar.item.inbox', 'Inbox'),
      href: inboxHref,
      icon: 'inbox',
      count: counts.inbox,
      countLabel: counts.inbox ? countLabel(counts.inbox) : undefined,
      active: onInbox,
    });
  }
  const end: PhoneTab[] = [];
  if (chatHref) {
    end.push({
      id: 'chat',
      label: t('sidebar.item.chat', 'Chat'),
      href: chatHref,
      icon: 'comment',
      count: counts.chat,
      countLabel: counts.chat ? countLabel(counts.chat) : undefined,
      active: onChat,
    });
  }
  // Every page that is not one of the three places was reached from More.
  end.push({
    id: 'more',
    label: t('phoneNav.more', 'More'),
    href: MORE_PATH,
    icon: 'menu',
    active: !onHome && !onInbox && !onChat,
  });

  const tiles = useMemo<CreateTile[]>(
    () => [
      { id: 'form', navId: 'member-forms', icon: 'form', label: t('phoneNav.create.form', 'Fill a form') },
      { id: 'document', navId: 'document-library', icon: 'document', label: t('phoneNav.create.document', 'New document') },
      { id: 'booking', navId: 'calendar', icon: 'calendar', label: t('phoneNav.create.booking', 'New booking') },
      { id: 'contact', navId: 'contacts', icon: 'customer', label: t('phoneNav.create.contact', 'New contact') },
      { id: 'task', navId: 'tasks', icon: 'list-todo', label: t('phoneNav.create.task', 'New task') },
      { id: 'message', navId: 'chat', icon: 'comment', label: t('phoneNav.create.message', 'New message') },
      { id: 'catalog', navId: 'forms-catalog', icon: 'form', label: t('phoneNav.create.form', 'Fill a form') },
    ],
    [t],
  );
  const shown = tiles
    .filter((tile) => hrefOf(tile.navId))
    // One "Fill a form" whichever forms page this person has.
    .filter((tile, index, all) => all.findIndex((other) => other.label === tile.label) === index)
    .slice(0, 6);

  return (
    <>
      <PhoneTabBar
        start={inboxHref ? start : [...start, null]}
        end={chatHref ? end : [null, ...end]}
        createLabel={t('phoneNav.create', 'Create')}
        onCreate={() => setCreateOpen(true)}
        navLabel={t('sidebar.navLabel', 'Main')}
        linkComponent={Link}
      />
      <Sheet open={createOpen} onOpenChange={setCreateOpen}>
        <SheetContent
          side="bottom"
          closeLabel={t('common.close', 'Close')}
          className="gap-3 rounded-t-2xl pb-[calc(1.25rem+env(safe-area-inset-bottom))] text-sm sm:mx-auto sm:max-w-md"
          data-testid="create-sheet"
        >
          <div aria-hidden className="mx-auto h-1 w-10 rounded-full bg-border" />
          <SheetHeader>
            <SheetTitle className="text-lg font-semibold">{t('phoneNav.create', 'Create')}</SheetTitle>
            <SheetDescription className="sr-only">
              {t('phoneNav.create.description', 'Start something new')}
            </SheetDescription>
          </SheetHeader>
          {chatHref && (
            <Link
              href={chatHref}
              onClick={() => setCreateOpen(false)}
              className="flex min-h-12 items-center gap-3 rounded-xl bg-primary-tint px-4 text-base text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <Icon name="robot" size={22} />
              <span className="flex-1">{t('phoneNav.create.askAgent', 'Ask an agent')}</span>
            </Link>
          )}
          {shown.length > 0 && (
            <ul className="grid grid-cols-3 gap-2">
              {shown.map((tile) => (
                <li key={tile.id}>
                  <Link
                    href={hrefOf(tile.navId) ?? '#'}
                    onClick={() => setCreateOpen(false)}
                    data-testid={`create-tile-${tile.id}`}
                    className="flex min-h-24 flex-col items-center justify-center gap-2 rounded-xl border border-border bg-muted/40 p-2 text-center text-sm text-foreground outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                  >
                    <span className="text-primary">
                      <Icon name={tile.icon} size={24} />
                    </span>
                    {tile.label}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </SheetContent>
      </Sheet>
    </>
  );
}

