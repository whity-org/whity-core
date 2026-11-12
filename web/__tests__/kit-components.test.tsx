/**
 * Foundation F4 kit components: the seeded avatar (M.34), the board, the time
 * grid, the thread with agent members, the KPI strip and the column matcher.
 */
import { fireEvent, render, screen, within } from '@testing-library/react';
import { Avatar } from '@whity/ui/avatar';
import { avatarPattern, brandHue } from '@whity/ui/avatar-pattern';
import { Board, BoardCard, BoardColumn, BoardSlot } from '@whity/ui/board';
import { ColumnMatcher } from '@whity/ui/column-matcher';
import { KpiStrip } from '@whity/ui/kpi-strip';
import { AgentWorking, Message, MessageProposal, MessageThread, SystemLine } from '@whity/ui/message-thread';
import { TimeGrid } from '@whity/ui/time-grid';

describe('avatar pattern (M.34)', () => {
  it('draws the same picture for the same seed and hue, and another for another seed', () => {
    expect(avatarPattern('whity-id-42', 255)).toBe(avatarPattern('whity-id-42', 255));
    expect(avatarPattern('whity-id-42', 255)).not.toBe(avatarPattern('whity-id-43', 255));
    expect(avatarPattern('whity-id-42', 255)).toMatch(/^<svg viewBox="0 0 40 40"/);
  });

  it('reads Whity Blue as hue 255 and keeps every hue in range', () => {
    expect(brandHue('#0961bb')).toBeGreaterThanOrEqual(254);
    expect(brandHue('#0961bb')).toBeLessThanOrEqual(257);
    for (const hex of ['#1a7f5a', '#b74c05', '#9d2398', '#fff', '000']) {
      const hue = brandHue(hex);
      expect(hue).toBeGreaterThanOrEqual(0);
      expect(hue).toBeLessThanOrEqual(360);
    }
  });

  it('shows the pattern for a person without a photo, and keeps the name for assistive technology', () => {
    render(<Avatar name="Awss Haddad" pattern="awss" hue={255} />);
    const avatar = screen.getByRole('img', { name: 'Awss Haddad' });
    expect(avatar.querySelector('[data-slot="avatar-pattern"] svg')).not.toBeNull();
    expect(avatar).not.toHaveTextContent('AH');
  });

  it('never gives an agent a pattern: agents keep the robot face', () => {
    render(<Avatar name="Stock assistant" kind="agent" pattern="x" />);
    expect(screen.getByRole('img', { name: 'Stock assistant (agent)' }).querySelector('[data-slot="avatar-pattern"]')).toBeNull();
  });
});

describe('board', () => {
  it('lists cards under a named column, marks the gate, and opens a task', () => {
    const onOpen = jest.fn();
    const onAdd = jest.fn();
    render(
      <Board>
        <BoardColumn title="Awaiting review" count={2} gate addLabel="Add task" onAdd={onAdd}>
          <BoardCard
            title="Supplier quotes for dates"
            labels={[{ name: 'Supplies', color: '#1a7f5a' }]}
            checks={{ met: 3, total: 3 }}
            due="12 Nov"
            assignee={{ name: 'Stock assistant', kind: 'agent' }}
            onOpen={onOpen}
          />
          <BoardSlot />
        </BoardColumn>
      </Board>,
    );
    const column = screen.getByRole('region', { name: 'Awaiting review' });
    expect(column).toHaveAttribute('data-gate');
    expect(within(column).getAllByRole('listitem')).toHaveLength(1);
    expect(screen.getByRole('img', { name: '3 of 3 checks met' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Supplier quotes for dates' }));
    expect(onOpen).toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Add task' }));
    expect(onAdd).toHaveBeenCalled();
  });
});

describe('time grid', () => {
  it('places blocks by time in their column, with the state and the times in words', () => {
    render(
      <TimeGrid
        label="Sunday 8 November"
        startHour={9}
        endHour={15}
        now="10:40"
        columns={[
          { id: 'ibrahim', header: 'Ibrahim Mansour' },
          { id: 'room', header: 'Tasting room' },
        ]}
        blocks={[
          { id: 'a', column: 'ibrahim', start: '09:30', end: '10:15', title: 'Awss Haddad', state: 'checked-in' },
          { id: 'b', column: 'ibrahim', start: '12:30', end: '13:15', title: 'Request: Emad Shami', state: 'request' },
        ]}
      />,
    );
    const grid = screen.getByRole('group', { name: 'Sunday 8 November' });
    const blocks = within(grid).getAllByRole('listitem');
    expect(blocks).toHaveLength(2);
    expect(blocks[0]).toHaveAttribute('data-state', 'checked-in');
    expect(blocks[0]).toHaveStyle({ top: '34px' });
    expect(blocks[1]).toHaveTextContent('12:30–13:15');
    expect(grid.querySelector('[data-slot="time-grid-now"]')).toHaveStyle({ top: `${(100 / 60) * 64}px` });
  });
});

describe('message thread', () => {
  it('marks what an agent writes, and shows joining and work as lines', () => {
    render(
      <MessageThread label="Ramadan boxes">
        <SystemLine icon="robot">Ahmad added Stock assistant on 6 Nov.</SystemLine>
        <Message author={{ name: 'Ahmad Khalil' }} time="09:02">
          How many gift boxes do we have?
        </Message>
        <Message
          author={{ name: 'Stock assistant', kind: 'agent' }}
          onBehalfOf="Amro"
          time="09:06"
          attachment={<MessageProposal title="Purchase order · 300 gift boxes" status="Waiting for Amro" />}
        >
          I’ve prepared the order.
        </Message>
        <AgentWorking author={{ name: 'Marketing assistant', kind: 'agent' }}>Marketing assistant is drafting</AgentWorking>
      </MessageThread>,
    );
    const log = screen.getByRole('log', { name: 'Ramadan boxes' });
    const [person, agent] = within(log).getAllByRole('article');
    expect(person).toHaveAttribute('data-kind', 'person');
    expect(within(person).queryByText('Agent')).toBeNull();
    expect(agent).toHaveAttribute('data-kind', 'agent');
    expect(within(agent).getByText('Agent')).toBeInTheDocument();
    expect(within(agent).getByText('for Amro')).toBeInTheDocument();
    expect(within(agent).getByText('Purchase order · 300 gift boxes')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Marketing assistant is drafting');
  });
});

describe('KPI strip and column matcher', () => {
  it('shows each number with its label and note', () => {
    render(<KpiStrip items={[{ label: 'Sales', value: 'JOD 31,880.000', note: '+12% on September', tone: 'good' }]} />);
    expect(screen.getByText('Sales')).toBeInTheDocument();
    expect(screen.getByText('JOD 31,880.000')).toHaveAttribute('dir', 'ltr');
    expect(screen.getByText('+12% on September')).toBeInTheDocument();
  });

  it('pairs each file column with its Whity field and says what happened', () => {
    render(
      <ColumnMatcher
        rows={[
          { source: 'Phone', example: '0795550101', target: 'Mobile', status: 'fixed', statusLabel: 'Matched, +962 added' },
          { source: 'Balance', example: '312', target: 'Opening balance', status: 'check', statusLabel: 'Check: no decimals' },
        ]}
      />,
    );
    const rows = screen.getAllByRole('row');
    expect(rows).toHaveLength(3);
    expect(within(rows[1]).getByText('Phone')).toBeInTheDocument();
    expect(within(rows[1]).getByText('Matched, +962 added')).toBeInTheDocument();
    expect(rows[2]).toHaveAttribute('data-status', 'check');
  });
});
