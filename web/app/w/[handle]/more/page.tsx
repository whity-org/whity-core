'use client';

/**
 * More (WHIT-1424, CA.12): on a phone with more than five tabs, the tabs that
 * don't fit the bar, Account first as in PW.2.
 */

import Link from 'next/link';
import { useCustomerArea } from '@/lib/customer-area';
import { Card, CardContent } from '@whity/ui/card';
import { Icon } from '@whity/ui/icon';
import { useTranslation } from '@whity/features/i18n';
import { PageTitle } from '@/components/customer-area/area-parts';
import { PHONE_TABS, useCustomerTabs } from '@/components/customer-area/customer-shell';
import type { CustomerAreaData } from '@/lib/customer-area';

export default function CustomerMore() {
  const { phase } = useCustomerArea();
  return phase.kind === 'ready' ? <MoreList area={phase.area} /> : null;
}

function MoreList({ area }: { area: CustomerAreaData }) {
  const t = useTranslation('customer');
  const tabs = useCustomerTabs(area);
  const rest = tabs.length > PHONE_TABS ? tabs.slice(PHONE_TABS - 1) : [];
  const ordered = [...rest.filter((tab) => tab.id === 'account'), ...rest.filter((tab) => tab.id !== 'account')];

  return (
    <div className="space-y-5">
      <PageTitle>{t('tab.more', 'More')}</PageTitle>
      <Card>
        <CardContent className="divide-y divide-border p-0">
          {ordered.map((tab) => (
            <Link key={tab.id} href={tab.href} className="flex min-h-12 items-center gap-3 px-4 py-3 hover:bg-muted" data-testid="customer-more-row">
              <Icon name={tab.icon} size={20} className="text-muted-foreground" />
              <span className="flex-1">{tab.label}</span>
              {tab.count > 0 && (
                <bdi dir="ltr" className="text-sm text-muted-foreground">
                  {tab.count}
                </bdi>
              )}
            </Link>
          ))}
        </CardContent>
      </Card>
    </div>
  );
}
