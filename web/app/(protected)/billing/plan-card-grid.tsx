'use client';

import type { ReactNode } from 'react';
import { Badge } from '@whity/ui/badge';
import { Icon } from '@whity/ui/icon';
import { ToggleGroup } from '@whity/ui/toggle-group';
import { useTranslation } from '@whity/features/i18n';
import { formatMoney } from '@whity/ui/money/currency';
import { formatBytes } from '@/lib/billing/usage';
import {
  featureLines,
  yearlySaving,
  type BillingPeriod,
  type FeatureLine,
  type PlanCard,
} from '@/lib/billing/plan-cards';

const NUMBER = new Intl.NumberFormat('en-US');

/**
 * Monthly or Yearly, above the cards (owner 23 Nov 2026). Shown only when a
 * plan is sold yearly; the tint marks the pressed choice, never a fill (7.3).
 */
export function PeriodSwitch({
  period,
  onChange,
}: {
  period: BillingPeriod;
  onChange: (period: BillingPeriod) => void;
}) {
  const t = useTranslation('admin');

  return (
    <ToggleGroup
      type="single"
      required
      value={period}
      label={t('billing.plans.period', 'Billing period')}
      options={[
        { value: 'month', label: t('billing.plans.monthly', 'Monthly') },
        { value: 'year', label: t('billing.plans.yearly', 'Yearly') },
      ]}
      onChange={(next) => {
        if (next === 'month' || next === 'year') onChange(next);
      }}
      className="w-fit"
    />
  );
}

/**
 * One card per plan: name, what it is for, the price for the chosen period,
 * what it comes with, and the caller's button at the bottom so the buttons
 * line up across the row.
 */
export function PlanCardGrid({
  cards,
  action,
  testId,
}: {
  cards: PlanCard[];
  action: (card: PlanCard) => ReactNode;
  testId?: string;
}) {
  const t = useTranslation('admin');

  const featureText = (line: FeatureLine): string => {
    if (line.kind === 'feature') {
      switch (line.key) {
        case 'documents.form_printouts':
          return t('billing.plans.feature.formPrintouts', 'Form printouts');
        case 'brand.remove_badge':
          return t('billing.plans.feature.removeBadge', 'No “Built on Whity” badge');
        case 'brand.custom_domain':
          return t('billing.plans.feature.customDomain', 'Your own web address');
        case 'brand.own_email_sender':
          return t('billing.plans.feature.emailSender', 'Emails from your own address');
        case 'brand.own_app':
          return t('billing.plans.feature.ownApp', 'Your own app in the stores');
        case 'sso.tenant_idp':
          return t('billing.plans.feature.sso', 'Single sign-on');
        case 'storage.custom_backend':
          return t('billing.plans.feature.ownStorage', 'Your own file storage');
        case 'mcp.access':
          return t('billing.plans.feature.mcp', 'Agents through MCP');
        default:
          return line.key;
      }
    }

    const n = line.key === 'storage.quota_bytes' ? formatBytes(line.value) : NUMBER.format(line.value);
    switch (line.key) {
      case 'members.max':
        return line.unlimited
          ? t('billing.plans.limit.staffUnlimited', 'Unlimited staff')
          : t('billing.plans.limit.staff', '{n} staff', { n });
      case 'customers.per_month':
        return line.unlimited
          ? t('billing.plans.limit.customersUnlimited', 'Unlimited customers')
          : t('billing.plans.limit.customers', '{n} customers a month', { n });
      case 'inventory.items.max':
        return line.unlimited
          ? t('billing.plans.limit.itemsUnlimited', 'Unlimited items')
          : t('billing.plans.limit.items', '{n} items', { n });
      case 'invoices.per_month':
        return line.unlimited
          ? t('billing.plans.limit.invoicesUnlimited', 'Unlimited invoices')
          : t('billing.plans.limit.invoices', '{n} invoices a month', { n });
      case 'storage.quota_bytes':
        return line.unlimited
          ? t('billing.plans.limit.storageUnlimited', 'Unlimited storage')
          : t('billing.plans.limit.storage', '{n} storage', { n });
      case 'messages.outbound.per_month':
        return line.unlimited
          ? t('billing.plans.limit.messagesUnlimited', 'Unlimited messages')
          : t('billing.plans.limit.messages', '{n} messages a month', { n });
      case 'agents.runs.per_month':
        return line.unlimited
          ? t('billing.plans.limit.agentRunsUnlimited', 'Unlimited agent runs')
          : t('billing.plans.limit.agentRuns', '{n} agent runs a month', { n });
      case 'api.calls.per_month':
        return line.unlimited
          ? t('billing.plans.limit.apiUnlimited', 'Unlimited API and MCP calls')
          : t('billing.plans.limit.api', '{n} API and MCP calls a month', { n });
      default:
        return line.key;
    }
  };

  const per = (card: PlanCard): string => {
    const base = card.price.billing_period === 'year'
      ? t('billing.plans.perYear', 'per year')
      : card.price.billing_period === 'half_year'
        ? t('billing.plans.perHalfYear', 'every 6 months')
        : t('billing.plans.perMonth', 'per month');
    if (card.price.is_per_seat) return `${base} · ${t('billing.plans.perSeat', 'per seat')}`;
    if (card.price.is_per_device) return `${base} · ${t('billing.plans.perDevice', 'per device')}`;
    return base;
  };

  return (
    <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-testid={testId}>
      {cards.map((card) => {
        const saving = yearlySaving(card);
        const lines = featureLines(card.entitlements);
        return (
          <li
            key={card.plan_key}
            data-testid={`plan-card-${card.plan_key}`}
            className={
              'flex flex-col gap-4 rounded-card border bg-card p-5 ' +
              (card.is_current ? 'border-primary' : 'border-border')
            }
          >
            <div className="space-y-1">
              <div className="flex items-center justify-between gap-2">
                <h3 className="text-lg font-medium">{card.name}</h3>
                {card.is_current && (
                  <Badge variant="info">{t('billing.plans.current', 'Your plan')}</Badge>
                )}
              </div>
              {card.description !== null && (
                <p className="text-sm text-muted-foreground">{card.description}</p>
              )}
            </div>

            <div className="space-y-1">
              <p>
                <bdi className="font-mono text-2xl font-medium" dir="ltr">
                  {formatMoney(card.price.unit_amount, card.price.currency)}
                </bdi>{' '}
                <span className="text-sm text-muted-foreground">{per(card)}</span>
              </p>
              {saving > 0 && (
                <p className="text-sm text-success-text">
                  {t('billing.plans.saving', 'Save {amount} a year', {
                    amount: formatMoney(saving, card.price.currency),
                  })}
                </p>
              )}
              {card.periodFallback && card.price.unit_amount > 0 && (
                <p className="text-sm text-muted-foreground">
                  {t('billing.plans.monthlyOnly', 'Billed monthly only')}
                </p>
              )}
            </div>

            {lines.length > 0 && (
              <ul className="space-y-2 border-t border-border pt-4 text-sm">
                {lines.map((line) => (
                  <li key={line.key} className="flex items-start gap-2">
                    <Icon name="check" className="mt-0.5 size-4 shrink-0 text-success-text" />
                    <span>{featureText(line)}</span>
                  </li>
                ))}
              </ul>
            )}

            <div className="mt-auto pt-2">{action(card)}</div>
          </li>
        );
      })}
    </ul>
  );
}
