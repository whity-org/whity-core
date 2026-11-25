'use client';

import { use, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { areaHref } from '@/lib/customer-area';

/**
 * `/@handle`: a signed-in customer goes straight to Home (CA.1). Everyone
 * else sees the front door, which the layout draws instead of this page.
 */
export default function AreaIndex({ params }: { params: Promise<{ handle: string }> }) {
  const { handle } = use(params);
  const router = useRouter();
  useEffect(() => {
    router.replace(areaHref(handle.toLowerCase(), 'home'));
  }, [router, handle]);
  return null;
}
