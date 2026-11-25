'use client';

/**
 * Settings › Customer area (WHIT-1416, design section 41 §12, CA.22): whether
 * the workspace's customers can sign in to their own area at /@handle, which
 * core tabs show, the line on their Home and How to pay, in English and
 * Arabic, and the address to share.
 *
 * Read with settings:read; saving needs settings:write, which the server
 * checks. No card yet (Q14): built to section 21, with the save bar.
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
import { useTranslation } from '@whity/features/i18n';
import type { components } from '@whity/api-client';

type Settings = components['schemas']['CustomerAreaSettings'];
const TABS = ['bookings', 'bills', 'messages'] as const;
type TabId = (typeof TABS)[number];
type Draft = Pick<Settings, 'enabled' | 'welcome_en' | 'welcome_ar' | 'how_to_pay_en' | 'how_to_pay_ar'> & { hidden_tabs: TabId[] };

export default function CustomerAreaSettingsPage() {
  const t = useTranslation('admin');
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
