'use client';

/** /calendar/meetings: the formal meetings list (WHIT-1115). The screen is `MeetingsScreen`. */

import { Suspense } from 'react';
import { MeetingsScreen } from '@/components/meetings/meetings-screen';

export default function MeetingsPage() {
  return (
    <Suspense>
      <MeetingsScreen />
    </Suspense>
  );
}
