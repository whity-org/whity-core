'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { takeCustomerAreaReturn } from '@/lib/customer-area-return';

/**
 * After a provider sign-in lands on the app's home, go on to the customer
 * area page the person asked for (WHIT-1416, CA.5). Renders nothing.
 */
export function CustomerAreaReturn() {
  const router = useRouter();
  useEffect(() => {
    const path = takeCustomerAreaReturn();
    if (path !== null) router.replace(path);
  }, [router]);
  return null;
}
