'use client';

import { useState } from 'react';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { LoadProblem } from '@/components/load-problem';
import { throwUnlessOk } from '@/lib/forbidden';
import {
  formatAmount,
  formatCount,
  shownDimensions,
  usageOverBy,
  usagePercent,
  type UsageMeter,
  type UsageReport,
} from '@/lib/billing/usage';
import { Alert, AlertDescription, AlertTitle } from '@whity/ui/alert';
import { Badge } from '@whity/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@whity/ui/select';
import { cn } from '@whity/ui/utils';
import { useDateDisplay } from '@whity/features/datetime';
import { useTranslation } from '@whity/features/i18n';

/**
 * USAGE (section 33 §5, BM.17, SC.14): every meter the workspace is counted
 * on, its base, and whether it is near or past it, for this month or an
 * earlier one.
 *
 * For the people who can see Billing. Past a base is amber with stripes and a
 * word, never red and never colour alone; near (80%) is a quiet badge. Nothing
 * is ever refused or removed for being past a base (BM.4), and the page says
 * so. A meter with no limit (every meter on a self-hosted install, BM.3) shows
 * what was used and "No limit".
 *
 * Numbers stay Western digits and left to right in both languages.
 */
export default function UsagePage() {
  const t = useTranslation('admin');
  const dates = useDateDisplay();
  const [period, setPeriod] = useState<string | null>(null);

  const { data, loading, error, forbidden, refetch } = useFetch(async () => {
    const { data: body, response } = await api.GET('/api/v1/usage', {
      params: { query: period === null ? {} : { period } },
    });
    throwUnlessOk(response, 'Failed to load usage');
    return (body as { data: UsageReport }).data;
  }, [period]);

  const name = (meter: string): string => {
    switch (meter) {
      case 'seats':
        return t('billing.usage.meter.seats', 'Staff');
      case 'customers':
        return t('billing.usage.meter.customers', 'Customers');
      case 'inventory.items':
        return t('billing.usage.meter.items', 'Items in inventory');
      case 'invoices':
        return t('billing.usage.meter.invoices', 'Invoices issued');
      case 'storage.bytes':
        return t('billing.usage.meter.storage', 'Storage');
      case 'messages.outbound':
        return t('billing.usage.meter.messages', 'Messages sent');
      case 'agents':
        return t('billing.usage.meter.agents', 'Agent runs');
      case 'api.calls':
        return t('billing.usage.meter.api', 'API and MCP calls');
      default:
        return meter;
    }
  };

  const help = (meter: string): string | null => {
    switch (meter) {
      case 'seats':
        return t('billing.usage.help.seats', 'People who work in the workspace. Agents and customers don’t count.');
      case 'customers':
        return t('billing.usage.help.customers', 'People you served this month. Contacts are always unlimited.');
      case 'inventory.items':
        return t('billing.usage.help.items', 'Each size or colour counts. Archived items don’t.');
      case 'invoices':
        return t('billing.usage.help.invoices', 'Invoices given a number this month.');
      case 'storage.bytes':
        return t('billing.usage.help.storage', 'Files, documents and attachments kept for the workspace.');
      case 'messages.outbound':
        return t('billing.usage.help.messages', 'Messages to customers on every channel. Team chat doesn’t count.');
      case 'agents':
        return t('billing.usage.help.agents', 'A run is what one agent does in the 30 minutes from its first step. AI tokens count only when Whity supplies the AI model, which it doesn’t yet.');
      case 'api.calls':
        return t('billing.usage.help.api', 'MCP calls from your own AI, and calls from any tool that isn’t one of Whity’s apps. Using Whity’s apps doesn’t count.');
      default:
        return null;
    }
  };

  const dimension = (key: string): string => {
    switch (key) {
      case 'email':
        return t('billing.usage.dimension.email', 'Email');
      case 'webchat':
        return t('billing.usage.dimension.webchat', 'Web chat');
      case 'whatsapp':
        return t('billing.usage.dimension.whatsapp', 'WhatsApp');
      case 'sms':
        return t('billing.usage.dimension.sms', 'SMS');
      case 'runs':
        return t('billing.usage.dimension.runs', 'Runs');
      case 'tool_calls':
        return t('billing.usage.dimension.toolCalls', 'Steps');
      case 'tokens_in':
        return t('billing.usage.dimension.tokensIn', 'Tokens read');
      case 'tokens_out':
        return t('billing.usage.dimension.tokensOut', 'Tokens written');
      case 'mcp':
        return t('billing.usage.dimension.mcp', 'MCP');
      case 'api':
        return t('billing.usage.dimension.api', 'API');
      default:
        return key;
    }
  };

  const monthLabel = (yyyymm: string): string => {
    const [y, m] = yyyymm.split('-').map(Number);
    return dates.calendarLabel(new Date(Date.UTC(y, m - 1, 15)).toISOString(), 'monthYear') ?? yyyymm;
  };

  const meters = data?.meters ?? [];
  const passed = meters.some((m) => m.state === 'over');
  const near = !passed && meters.some((m) => m.state === 'near');

  return (
    <div className="space-y-6" data-testid="usage-page">
      <AdminHeader
        title={t('billing.usage.title', 'Usage')}
        description={t('billing.usage.description', 'What your workspace used, against what your plan includes.')}
      />

      {error && (
        <LoadProblem
          forbidden={forbidden}
          message={t('billing.usage.error.load', 'Failed to load usage')}
          onRetry={() => void refetch()}
          area={t('billing.usage.area', 'usage')}
        />
      )}

      {data && (
        <div className="flex flex-wrap items-center gap-3">
          <Select value={data.period} onValueChange={(value) => setPeriod(value)}>
            <SelectTrigger aria-label={t('billing.usage.month', 'Month')} className="w-56" data-testid="usage-month">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {data.periods.map((p) => (
                <SelectItem key={p} value={p}>
                  {monthLabel(p)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {data.current && data.resets_at && (
            <span className="text-sm text-muted-foreground">
              {t('billing.usage.resets', 'Monthly counts start again on {date}', { date: dates.date(data.resets_at) ?? '' })}
            </span>
          )}
        </div>
      )}

      {passed && (
        <Alert variant="warning" data-testid="usage-passed">
          <AlertTitle>{t('billing.usage.passed.title', 'Your workspace has gone past what its plan includes')}</AlertTitle>
          <AlertDescription>
            {t('billing.usage.passed.description', 'Everything keeps working and nothing is removed. You’ll be able to add more when you need it.')}
          </AlertDescription>
        </Alert>
      )}
      {near && (
        <Alert variant="info" data-testid="usage-near">
          <AlertTitle>{t('billing.usage.near.title', 'Your workspace is close to what its plan includes')}</AlertTitle>
          <AlertDescription>
            {t('billing.usage.near.description', 'Nothing changes when you pass it: everything keeps working and you’ll be told.')}
          </AlertDescription>
        </Alert>
      )}

      {loading && !data && (
        <p className="text-sm text-muted-foreground" role="status">
          {t('billing.usage.loading', 'Reading your usage…')}
        </p>
      )}

      {data && (
        <ul className="divide-y divide-border rounded-card border border-border bg-card text-sm" data-testid="usage-meters">
          {meters.map((m) => (
            <MeterRow
              key={m.meter}
              meter={m}
              name={name(m.meter)}
              help={help(m.meter)}
              dimension={dimension}
              current={data.current}
              t={t}
            />
          ))}
        </ul>
      )}
    </div>
  );
}

function MeterRow({
  meter,
  name,
  help,
  dimension,
  current,
  t,
}: {
  meter: UsageMeter;
  name: string;
  help: string | null;
  dimension: (key: string) => string;
  current: boolean;
  t: ReturnType<typeof useTranslation>;
}) {
  // An earlier month is measured on its highest reading for held totals.
  const shown = meter.kind === 'snapshot' && !current ? meter.peak : meter.used;
  const split = shownDimensions(meter);

  return (
    <li
      className="grid min-h-12 items-center gap-x-6 gap-y-2 p-4 sm:grid-cols-[minmax(0,1fr)_220px_auto]"
      data-testid={`usage-${meter.meter}`}
    >
      <div className="grid gap-0.5">
        <span className="font-medium text-foreground">{name}</span>
        {help && <span className="text-muted-foreground">{help}</span>}
        {split.length > 0 && (
          <span dir="ltr" className="text-xs text-muted-foreground tabular-nums">
            {split.map(([key, n]) => `${dimension(key)} ${formatCount(n)}`).join(' · ')}
          </span>
        )}
      </div>
      <div className="grid gap-1.5">
        {meter.base !== null && (
          <div
            role="meter"
            aria-label={name}
            aria-valuemin={0}
            aria-valuemax={meter.base}
            aria-valuenow={shown}
            className="h-2 overflow-hidden rounded-full bg-muted"
          >
            <i
              className={cn(
                'block h-full rounded-full',
                meter.state === 'over'
                  ? 'bg-warning bg-[repeating-linear-gradient(135deg,transparent_0_4px,color-mix(in_srgb,var(--card)_45%,transparent)_4px_8px)]'
                  : 'bg-primary'
              )}
              style={{ width: `${usagePercent({ used: shown, base: meter.base })}%` }}
            />
          </div>
        )}
        <span dir="ltr" className="justify-self-start text-xs text-muted-foreground tabular-nums">
          {meter.base === null
            ? t('billing.usage.usedNoLimit', '{used} used, no limit', { used: formatAmount(meter, shown) })
            : t('billing.usage.usedOf', '{used} of {base}', {
                used: formatAmount(meter, shown),
                base: formatAmount(meter, meter.base),
              })}
        </span>
      </div>
      <div className="justify-self-start sm:justify-self-end">
        {meter.state === 'over' && (
          <Badge variant="warning" icon="alert">
            {t('billing.usage.overBy', '{n} over', { n: formatAmount(meter, usageOverBy({ used: shown, base: meter.base })) })}
          </Badge>
        )}
        {meter.state === 'near' && <Badge variant="outline">{t('billing.usage.nearBadge', 'Near')}</Badge>}
      </div>
    </li>
  );
}
