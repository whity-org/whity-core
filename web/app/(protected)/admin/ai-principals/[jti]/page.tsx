'use client';

import { useParams } from 'next/navigation';
import { AgentGrantScreen } from '@/components/agents/agent-grant-screen';

/** One agent credential's grant (WHIT-810). The screen is shared with workspace agents. */
export default function AgentCredentialGrantPage() {
  const params = useParams<{ jti: string }>();
  return <AgentGrantScreen subject={{ kind: 'credential', jti: params?.jti ?? '' }} backHref="/admin/ai-principals" />;
}
