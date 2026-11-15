'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';

/**
 * The settings menu as its own page is a phone page (PwSettings): the Settings
 * area draws it. From 640px up the area already shows its menu beside General,
 * so this sends a wide window there.
 */
export default function SettingsMenuPage() {
  const router = useRouter();
  useEffect(() => {
    if (window.matchMedia('(min-width: 640px)').matches) router.replace('/admin/settings');
  }, [router]);
  return null;
}
