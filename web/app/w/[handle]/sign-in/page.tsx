'use client';

import { use, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { areaHref } from '@/lib/customer-area';

/**
 * `/@handle/sign-in` (CA.5): the layout shows the branded front door with
 * Sign in to anyone not yet in; a customer already in goes to Home.
 */
export default function AreaSignIn({ params }: { params: Promise<{ handle: string }> }) {
  const { handle } = use(params);
  const router = useRouter();
  useEffect(() => {
    router.replace(areaHref(handle.toLowerCase(), 'home'));
  }, [router, handle]);
  return null;
}
