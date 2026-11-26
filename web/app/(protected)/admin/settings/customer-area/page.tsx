'use client';

/**
 * Settings › Customer area (WHIT-1416, design section 41 §12, CA.22): whether
 * the workspace's customers can sign in to their own area at /@handle, which
 * core tabs show, the line on their Home and How to pay, in English and
 * Arabic, and the address to share.
 *
 * Read with settings:read; saving needs settings:write, which the server
 * checks. No card yet (Q14): built to section 21, with the save bar.
 *
 * Plugin screens (WHIT-1438, Q8): each enabled plugin's customer screens
 * with a switch. A customer sees one only through their role, so when the
 * workspace's customers lack its permission, turning it on (or Give your
 * customers this screen) asks first, then adds it to the customer roles.
 */

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { ForbiddenError } from '@/lib/forbidden';
import { AdminHeader } from '@/components/admin/admin-header';
import { AccessDenied } from '@/components/ui/access-denied';
import { Input } from '@/components/ui/input';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { SaveBar } from '@whity/ui/save-bar';
import { Switch } from '@whity/ui/switch';
import { Textarea } from '@whity/ui/textarea';
import { ConfirmAction } from '@whity/ui/confirm-action';
import { localizedText } from '@whity/ui/localized-text';
import { useLanguageCode, useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';

type Settings = components['schemas']['CustomerAreaSettings'];
const TABS = ['bookings', 'bills', 'messages'] as const;
type TabId = (typeof TABS)[number];
type Screen = Settings['screens'][number];
type Draft = Pick<Settings, 'enabled' | 'welcome_en' | 'welcome_ar' | 'how_to_pay_en' | 'how_to_pay_ar' | 'hidden_screens'> & { hidden_tabs: TabId[] };

export default function CustomerAreaSettingsPage() {
  const t = useTranslation('admin');
  const language = useLanguageCode();
  const { addToast } = useToast();
  const { data, loading, forbidden, refetch } = useFetch(async () => {
    const { data: body, response } = await api.GET('/api/v1/settings/customer-area');
    if (response.status === 403) throw new ForbiddenError();
    if (!response.ok || !body) throw new Error('failed');
    return (body as { data: Settings }).data;
  }, []);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [giving, setGiving] = useState<Screen | null>(null);

  if (forbidden) {
    return <AccessDenied description={t('settings.customerArea.accessDenied', 'Ask a workspace admin to change the customer area.')} />;
  }
  if (loading || !data) return <div className="h-64 animate-pulse rounded-lg bg-muted" aria-busy="true" />;

  const current: Draft = draft ?? { ...data, hidden_tabs: data.hidden_tabs.filter((x): x is TabId => (TABS as readonly string[]).includes(x)) };
  const dirty = draft !== null;
  const update = (patch: Partial<Draft>) => setDraft({ ...current, ...patch });
  const label: Record<(typeof TABS)[number], string> = {
    bookings: t('settings.customerArea.tab.bookings', 'Bookings'),
    bills: t('settings.customerArea.tab.bills', 'Bills: quotes, invoices and receipts'),
    messages: t('settings.customerArea.tab.messages', 'Messages'),
  };
  const screenName = (screen: Screen) => localizedText(screen.label as Record<string, string> | string, language, null, screen.id);
  const setScreen = (id: string, on: boolean) =>
    update({ hidden_screens: on ? current.hidden_screens.filter((x) => x !== id) : [...current.hidden_screens.filter((x) => x !== id), id] });
  const give = async (screen: Screen) => {
    const { error, response } = await api.POST('/api/v1/settings/customer-area/screens/{screen}/grant', {
      params: { path: { screen: screen.id } },
      body: { confirm: true },
    });
    if (response.ok) {
      // The server turned it on; keep any unsaved changes, with it on.
      if (draft) setDraft({ ...draft, hidden_screens: draft.hidden_screens.filter((x) => x !== screen.id) });
      refetch();
      addToast(t('settings.customerArea.screens.given', 'Your customers can now open {name}', { name: screenName(screen) }), 'success');
      return;
    }
    const text = (error as { error?: unknown } | undefined)?.error;
    addToast(
      response.status === 403
        ? t('settings.customerArea.screens.notYours', 'You can only give customers what you can open yourself. Ask a workspace owner.')
        : typeof text === 'string'
          ? text
          : t('settings.customerArea.screens.giveFailed', 'That didn’t work. Try again.'),
      'error'
    );
  };
  const rolesLine = (screen: Screen) => {
    const changing = screen.roles.filter((r) => !r.has && r.skipped === null);
    const staying = screen.roles.filter((r) => !r.has && r.skipped !== null);
    const names = (list: Screen['roles']) =>
      list
        .map((r) =>
          r.signup
            ? t('settings.customerArea.screens.signupRole', '{name} (new customers get it)', { name: r.name })
            : t('settings.customerArea.screens.roleCount', '{name} ({count} customers)', { name: r.name, count: String(r.customers) })
        )
        .join(language === 'ar' ? '، ' : ', ');
    return [
      changing.length > 0
        ? t('settings.customerArea.screens.confirmChanging', 'We add {name} to these customer roles: {roles}.', { name: screenName(screen), roles: names(changing) })
        : '',
      staying.length > 0
        ? t(
            'settings.customerArea.screens.confirmStaying',
            'These stay as they are, because your team or other workspaces also use them: {roles}. Change them in Settings › Roles if you need to.',
            { roles: names(staying) }
          )
        : '',
    ]
      .filter((x) => x !== '')
      .join(' ');
  };
  const address = data.handle ? `${typeof window === 'undefined' ? '' : window.location.origin}/@${data.handle}` : null;

  const save = async () => {
    setSaving(true);
    setProblem(null);
    const { error, response } = await api.PUT('/api/v1/settings/customer-area', { body: current });
    setSaving(false);
    if (response.ok) {
      setDraft(null);
      refetch();
      addToast(t('settings.customerArea.saved', 'Customer area saved'), 'success');
    } else {
      const text = (error as { error?: unknown } | undefined)?.error;
      setProblem(typeof text === 'string' ? text : t('settings.customerArea.saveFailed', 'The settings weren’t saved. Try again.'));
    }
  };

  return (
    <div className="space-y-6 pb-24">
      <AdminHeader
        title={t('settings.customerArea.title', 'Customer area')}
        description={t('settings.customerArea.description', 'Where your customers sign in to see their bookings, bills and messages with you.')}
      />

      <Card>
        <CardHeader>
          <CardTitle>{t('settings.customerArea.onTitle', 'Customers can sign in')}</CardTitle>
          <CardDescription>
            {t('settings.customerArea.onBody', 'When this is off, your page still offers booking and chat, and nothing your customers did is removed.')}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <label className="flex min-h-10 items-center gap-3">
            <Switch checked={current.enabled} onCheckedChange={(enabled) => update({ enabled })} data-testid="customer-area-enabled" />
            <span>{current.enabled ? t('settings.customerArea.on', 'On') : t('settings.customerArea.off', 'Off')}</span>
          </label>
          {address && (
            <div className="flex flex-wrap items-center gap-2">
              <bdi dir="ltr" className="rounded-control border border-input bg-muted px-3 py-2 font-mono text-sm">
                {address}
              </bdi>
              <Button
                variant="outline"
                onClick={() => {
                  void navigator.clipboard?.writeText(address);
                  addToast(t('settings.customerArea.copied', 'Copied'), 'success');
                }}
              >
                {t('settings.customerArea.copy', 'Copy')}
              </Button>
              <Button asChild variant="outline">
                <a href={`/@${data.handle}`} target="_blank" rel="noreferrer">
                  {t('settings.customerArea.open', 'Open')}
                </a>
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('settings.customerArea.tabsTitle', 'Tabs')}</CardTitle>
          <CardDescription>{t('settings.customerArea.tabsBody', 'Home and Account always show. A tab shows only when the customer has something in it, or when you take bookings or messages.')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {TABS.map((tab) => {
            const shown = !current.hidden_tabs.includes(tab);
            return (
              <label key={tab} className="flex min-h-10 items-center gap-3">
                <Switch
                  checked={shown}
                  onCheckedChange={(on) => update({ hidden_tabs: on ? current.hidden_tabs.filter((x) => x !== tab) : [...current.hidden_tabs, tab] })}
                  data-testid={`customer-area-tab-${tab}`}
                />
                <span>{label[tab]}</span>
              </label>
            );
          })}
        </CardContent>
      </Card>

      {data.screens.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>{t('settings.customerArea.screens.title', 'From your plugins')}</CardTitle>
            <CardDescription>
              {t('settings.customerArea.screens.body', 'Screens your plugins add for customers. A customer sees one only when their role includes it.')}
            </CardDescription>
          </CardHeader>
          <CardContent className="divide-y divide-border">
            {data.screens.map((screen) => {
              const on = !current.hidden_screens.includes(screen.id);
              const canGive = screen.roles.some((r) => !r.has && r.skipped === null);
              return (
                <div key={screen.id} className="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0" data-testid={`customer-area-screen-${screen.id}`}>
                  <label className="flex min-h-10 flex-1 items-center gap-3">
                    <Switch
                      checked={on}
                      onCheckedChange={(next) => {
                        setScreen(screen.id, next);
                        if (next && canGive) setGiving(screen);
                      }}
                      data-testid={`customer-area-screen-switch-${screen.id}`}
                    />
                    <span className="min-w-0">
                      <span className="block">{screenName(screen)}</span>
                      <span className="block text-sm text-muted-foreground">
                        {screen.plugin}
                        {' · '}
                        {!on
                          ? t('settings.customerArea.screens.off', 'Off: no customer sees it')
                          : screen.customers_have
                            ? t('settings.customerArea.screens.have', 'Your customers can open it')
                            : canGive
                              ? t('settings.customerArea.screens.lack', 'Your customers can’t open it yet')
                              : t('settings.customerArea.screens.lackShared', 'Only customers whose role includes it can open it')}
                      </span>
                    </span>
                  </label>
                  {on && canGive && (
                    <Button variant="outline" onClick={() => setGiving(screen)} data-testid={`customer-area-screen-give-${screen.id}`}>
                      {t('settings.customerArea.screens.give', 'Give your customers this screen')}
                    </Button>
                  )}
                </div>
              );
            })}
          </CardContent>
        </Card>
      )}

      <ConfirmAction
        open={giving !== null}
        onOpenChange={(open) => {
          if (!open) setGiving(null);
        }}
        title={giving ? t('settings.customerArea.screens.confirmTitle', 'Let your customers open {name}?', { name: screenName(giving) }) : ''}
        description={giving ? rolesLine(giving) : ''}
        confirmLabel={t('settings.customerArea.screens.confirm', 'Give it to them')}
        cancelLabel={t('settings.customerArea.screens.cancel', 'Not now')}
        variant="default"
        onConfirm={async () => {
          if (giving) await give(giving);
          setGiving(null);
        }}
      />

      <Card>
        <CardHeader>
          <CardTitle>{t('settings.customerArea.wordsTitle', 'Your words')}</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-4 md:grid-cols-2">
          <Input
            label={t('settings.customerArea.welcomeEn', 'Welcome line (English)')}
            value={current.welcome_en}
            maxLength={300}
            onChange={(e) => update({ welcome_en: e.target.value })}
          />
          <Input
            label={t('settings.customerArea.welcomeAr', 'Welcome line (Arabic)')}
            value={current.welcome_ar}
            maxLength={300}
            dir="rtl"
            onChange={(e) => update({ welcome_ar: e.target.value })}
          />
          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="how-to-pay-en">
              {t('settings.customerArea.howEn', 'How to pay (English)')}
            </label>
            <Textarea id="how-to-pay-en" value={current.how_to_pay_en} maxLength={2000} rows={4} onChange={(e) => update({ how_to_pay_en: e.target.value })} />
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium" htmlFor="how-to-pay-ar">
              {t('settings.customerArea.howAr', 'How to pay (Arabic)')}
            </label>
            <Textarea id="how-to-pay-ar" value={current.how_to_pay_ar} maxLength={2000} rows={4} dir="rtl" onChange={(e) => update({ how_to_pay_ar: e.target.value })} />
          </div>
          <p className="text-sm text-muted-foreground md:col-span-2">
            {t('settings.customerArea.howHint', 'Shown on an invoice with something to pay, for example your bank details or CliQ alias.')}
          </p>
        </CardContent>
      </Card>

      {problem && (
        <Alert variant="destructive">
          <AlertDescription>{problem}</AlertDescription>
        </Alert>
      )}

      <SaveBar
        dirty={dirty}
        summary={t('settings.customerArea.changed', 'Customer area changed')}
        discardLabel={t('settings.customerArea.discard', 'Discard')}
        saveLabel={t('settings.customerArea.save', 'Save changes')}
        saving={saving}
        onDiscard={() => {
          setDraft(null);
          setProblem(null);
        }}
        onSave={() => void save()}
      />
    </div>
  );
}
