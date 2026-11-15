'use client';

/**
 * The phone's old settings menu address. The menu is the Settings area's own
 * root since WHIT-1081, with your own settings above the workspace's.
 */

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { SETTINGS_HOME_HREF } from '@/lib/settings/my-settings';

export default function SettingsMenuPage() {
  const router = useRouter();
  useEffect(() => {
    router.replace(SETTINGS_HOME_HREF);
  }, [router]);
  return null;
}
