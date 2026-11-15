'use client';

/**
 * `/settings`: the Settings area's own root (WHIT-1081).
 *
 * On a phone it is the settings menu (PwSettings; the area draws it). On a
 * computer the sub-nav is always beside the page, so it opens Profile.
 * Old links with `?tab=…` (and `#notifications`) go to the page that now
 * holds what that tab held.
 */

import { Suspense, useEffect } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { MY_SETTINGS_HREF, redirectForOldTab } from '@/lib/settings/my-settings';

function SettingsHome() {
  const router = useRouter();
  const params = useSearchParams();
  const tab = params.get('tab');

  useEffect(() => {
    const target = redirectForOldTab(tab, window.location.hash);
    if (target) {
      router.replace(target);
      return;
    }
    if (window.matchMedia('(min-width: 640px)').matches) router.replace(MY_SETTINGS_HREF.profile);
  }, [router, tab]);

  return null;
}

export default function SettingsHomePage() {
  return (
    <Suspense fallback={null}>
      <SettingsHome />
    </Suspense>
  );
}
