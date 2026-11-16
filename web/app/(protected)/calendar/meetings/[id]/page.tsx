'use client';

/** /calendar/meetings/{id}: one formal meeting, its agenda, attendance and decisions (WHIT-1115). */

import { use } from 'react';
import { notFound } from 'next/navigation';
import { MeetingScreen } from '@/components/meetings/meeting-screen';

export default function MeetingPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const meetingId = Number(id);
  if (!Number.isInteger(meetingId) || meetingId < 1) notFound();
  return <MeetingScreen id={meetingId} />;
}
