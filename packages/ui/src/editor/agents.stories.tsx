import type { Meta, StoryObj } from "@storybook/react-vite"

import { AgentPanel } from "./agent-panel"
import type { EditorAgentRequest, EditorAgents } from "./agents"
import { EDITOR_LABELS } from "./labels"

/**
 * Editor M6, the EditorAgents card: Ask an agent (dashed, nothing has happened
 * yet), a request while it runs with Stop, one that's done, and the sheet with
 * no agent connected. Example data follows the card (the Billing assistant,
 * for Amro).
 */
const meta = {
  title: "Editor/Agents",
  parameters: { designCard: { width: 600, height: 520 } },
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

const noop = async () => undefined

const CHOICES = [
  { id: 7, name: "Billing assistant" },
  { id: null, name: EDITOR_LABELS.agentAny },
]

const RUNNING: EditorAgentRequest = {
  id: "41",
  request: "Write a short, polite paragraph for customers whose invoices are more than 30 days overdue",
  status: "running",
  agent: { id: "agent:3", name: "Billing assistant" },
  title: "Reading 3 overdue invoices",
  progress: { done: 1, total: 3 },
  at: "2026-11-12T14:06:00Z",
}

const DONE: EditorAgentRequest = {
  ...RUNNING,
  id: "40",
  status: "done",
  title: "Suggested 2 blocks",
  progress: null,
  detail: "Accept or reject them on the rail.",
}

function agents(over: Partial<EditorAgents>): EditorAgents {
  return { choices: CHOICES, requests: [], connectHref: "#", onAsk: noop, onStop: noop, ...over }
}

export const Asking: Story = {
  render: () => (
    <AgentPanel
      agents={agents({})}
      labels={EDITOR_LABELS}
      me="Amro"
      start={{ request: "Write a short, polite paragraph for customers whose invoices are more than 30 days overdue" }}
      onClose={() => undefined}
    />
  ),
}

export const Rewrite: Story = {
  render: () => (
    <AgentPanel
      agents={agents({})}
      labels={EDITOR_LABELS}
      me="Amro"
      start={{ request: EDITOR_LABELS.agentRewriteRequest, quote: "Failure to pay will result in legal action", anchorBlockId: "p1" }}
      onClose={() => undefined}
    />
  ),
}

export const Running: Story = {
  render: () => <AgentPanel agents={agents({ requests: [RUNNING, DONE] })} labels={EDITOR_LABELS} me="Amro" onClose={() => undefined} />,
}

export const NoAgentConnected: Story = {
  render: () => <AgentPanel agents={agents({ choices: [] })} labels={EDITOR_LABELS} me="Amro" onClose={() => undefined} />,
}
