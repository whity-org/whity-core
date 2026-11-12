'use client';

/** /chat — team chat, people and agents together (CH.1). The screen is `ChatScreen`; `?c=` opens a chat. */

import { Suspense } from 'react';
import { ChatScreen } from '@/components/chat/chat-screen';

export default function ChatPage() {
  return (
    <Suspense fallback={null}>
      <ChatScreen />
    </Suspense>
  );
}
