'use client';

/** /boards/[board] — one board: To do, Doing, Awaiting review and Done (A10, TplBoard). */

import { useParams } from 'next/navigation';
import { BoardScreen } from '@/components/tasks/board-screen';

export default function BoardPage() {
  const board = useParams<{ board: string }>()?.board ?? '';
  return <BoardScreen board={decodeURIComponent(board)} />;
}
