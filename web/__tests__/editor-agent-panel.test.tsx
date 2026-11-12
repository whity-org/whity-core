/**
 * Editor milestone M6 (EditorAgents): the Ask an agent sheet. Whity hosts no
 * model, so with no agent connected it says how to connect one; asking sends
 * the request with the selection; a waiting or running request shows with
 * Stop, and a finished one doesn't.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { AgentPanel } from '@whity/ui/editor/agent-panel';
import type { EditorAgentRequest, EditorAgents } from '@whity/ui/editor/agents';
import { EDITOR_LABELS } from '@whity/ui/editor/labels';

function agents(over: Partial<EditorAgents> = {}): EditorAgents {
  return {
    choices: [
      { id: 7, name: 'Billing assistant' },
      { id: null, name: EDITOR_LABELS.agentAny },
    ],
    requests: [],
    connectHref: '/admin/my-agents',
    onAsk: jest.fn().mockResolvedValue(undefined),
    onStop: jest.fn().mockResolvedValue(undefined),
    ...over,
  };
}

const request = (over: Partial<EditorAgentRequest>): EditorAgentRequest => ({
  id: '41',
  request: 'Shorten the terms',
  status: 'running',
  agent: { id: 'agent:3', name: 'Billing assistant' },
  title: 'Reading the terms',
  progress: { done: 1, total: 3 },
  at: '2026-11-12T10:00:00Z',
  ...over,
});

it('says how to connect an agent when none is connected', () => {
  render(<AgentPanel agents={agents({ choices: [] })} labels={EDITOR_LABELS} onClose={() => undefined} />);

  expect(screen.getByText(EDITOR_LABELS.agentNoneConnected)).toBeInTheDocument();
  expect(screen.getByRole('link', { name: EDITOR_LABELS.agentConnect })).toHaveAttribute('href', '/admin/my-agents');
  expect(screen.queryByRole('button', { name: EDITOR_LABELS.agentAskButton })).not.toBeInTheDocument();
});

it('asks the first of my agents, with the selection, and says it writes only suggestions for me', async () => {
  const a = agents();
  render(
    <AgentPanel
      agents={a}
      labels={EDITOR_LABELS}
      me="Amro Saleh"
      start={{ request: 'Rewrite this', quote: '30 days', anchorBlockId: 'b1' }}
      onClose={() => undefined}
    />
  );

  expect(screen.getByText('Writes only suggestions, for Amro Saleh')).toBeInTheDocument();
  expect(screen.getByText('“30 days”')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: EDITOR_LABELS.agentAskButton }));

  await waitFor(() =>
    expect(a.onAsk).toHaveBeenCalledWith({ agentId: 7, request: 'Rewrite this', anchorBlockId: 'b1', quote: '30 days' })
  );
});

it('offers Stop while a request runs and not once it is done', () => {
  const a = agents({ requests: [request({}), request({ id: '40', status: 'done', title: null, detail: 'Two suggestions' })] });
  render(<AgentPanel agents={a} labels={EDITOR_LABELS} me="Amro Saleh" onClose={() => undefined} />);

  const stops = screen.getAllByRole('button', { name: EDITOR_LABELS.agentStop });
  expect(stops).toHaveLength(1);
  fireEvent.click(stops[0]);
  expect(a.onStop).toHaveBeenCalledWith('41');
  expect(screen.getByText('Reading the terms')).toBeInTheDocument();
  expect(screen.getByText('Two suggestions')).toBeInTheDocument();
});

it('shows a queued request as waiting for the agent', () => {
  render(
    <AgentPanel
      agents={agents({ requests: [request({ status: 'queued', agent: null, title: null, progress: null })] })}
      labels={EDITOR_LABELS}
      onClose={() => undefined}
    />
  );

  expect(screen.getByText(EDITOR_LABELS.agentWaiting)).toBeInTheDocument();
  expect(screen.getAllByText(EDITOR_LABELS.agentAny).length).toBeGreaterThan(0);
});

it('works from the keyboard: Ctrl+Enter asks, Escape closes', async () => {
  const a = agents();
  const onClose = jest.fn();
  render(<AgentPanel agents={a} labels={EDITOR_LABELS} start={{ request: 'Check the totals' }} onClose={onClose} />);

  const field = screen.getByLabelText(EDITOR_LABELS.agentRequest);
  expect(field).toHaveFocus();
  fireEvent.keyDown(field, { key: 'Enter', ctrlKey: true });
  await waitFor(() => expect(a.onAsk).toHaveBeenCalledWith(expect.objectContaining({ request: 'Check the totals' })));

  fireEvent.keyDown(field, { key: 'Escape' });
  expect(onClose).toHaveBeenCalled();
});
