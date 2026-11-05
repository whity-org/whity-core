'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useTranslation } from '@whity/features/i18n';
import { Tabs, TabsList, TabsTrigger } from '@whity/ui/tabs';

/** The Store and Installed tabs under the Plugins header (A41, S.10). */
export function PluginsTabs() {
  const t = useTranslation('admin');
  const pathname = usePathname() ?? '';
  const value = pathname.startsWith('/admin/plugins/store') ? 'store' : 'installed';

  return (
    <Tabs value={value} className="mb-6">
      <TabsList variant="line">
        <TabsTrigger value="store" asChild>
          <Link href="/admin/plugins/store">{t('plugins.tab.store', 'Store')}</Link>
        </TabsTrigger>
        <TabsTrigger value="installed" asChild>
          <Link href="/admin/plugins">{t('plugins.tab.installed', 'Installed')}</Link>
        </TabsTrigger>
      </TabsList>
    </Tabs>
  );
}
