'use client';

import { PersonaEditor } from '@/components/agents/persona-editor';

/** A new workspace agent, from scratch (WHIT-820). */
export default function NewAgentPage() {
  return <PersonaEditor agent={null} />;
}
