/**
 * Document numbers and states on screen, and the numbering setting (WHIT-905).
 */
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { DocumentNumber, DocumentStateBadge } from '@/app/(protected)/admin/document-library/document-item';
import type { DocumentRow } from '@/app/(protected)/admin/document-library/types';
import { NumberingDialog, numberingSample } from '@/app/(protected)/admin/document-templates/numbering-dialog';
import type { TemplateRow } from '@/app/(protected)/admin/document-templates/types';

function row(overrides: Partial<DocumentRow>): DocumentRow {
  return {
    id: 1,
    template_name: 'Invoice',
    title: 'Invoice for Example Bakery',
    origin_ou_id: null,
    created_by: null,
    created_at: '2026-11-05 10:00:00',
    content_url: null,
    artifacts: [],
    ...overrides,
  };
}

const template: TemplateRow = {
  id: 7,
  kind: 'template',
  title: 'Invoice',
  lang: 'en',
  record_type: null,
  own_fields: [],
  draft_version: 1,
  published_version: 1,
  scope: 'tenant',
  required_permission: null,
  owner_ou_id: null,
  created_by: 1,
  updated_at: '2026-11-05 10:00:00',
  starter_key: null,
  legacy_template_id: null,
  numbering: null,
};

describe('the number', () => {
  it('shows left to right, in the code font', () => {
    render(<DocumentNumber number="INV-2026-0142" />);
    const number = screen.getByTestId('library-number');
    expect(number).toHaveTextContent('INV-2026-0142');
    expect(number).toHaveAttribute('dir', 'ltr');
    expect(number).toHaveClass('font-mono');
  });

  it('shows nothing for a document with no number', () => {
    const { container } = render(<DocumentNumber number={null} />);
    expect(container).toBeEmptyDOMElement();
  });
});

describe('the state', () => {
  it.each([
    ['draft', 'Draft'],
    ['in_review', 'In review'],
    ['superseded', 'Replaced'],
    ['withdrawn', 'Withdrawn'],
  ] as const)('labels %s', (state, label) => {
    render(<DocumentStateBadge row={row({ state })} />);
    expect(screen.getByText(label)).toBeInTheDocument();
  });

  it('says nothing for an issued document, which is nearly all of them', () => {
    const { container } = render(<DocumentStateBadge row={row({ state: 'issued' })} />);
    expect(container).toBeEmptyDOMElement();
  });

  it('still shows withdrawn from an older server that sends no state', () => {
    render(<DocumentStateBadge row={row({ withdrawn_at: '2026-11-05 10:00:00' })} />);
    expect(screen.getByTestId('library-withdrawn')).toBeInTheDocument();
  });
});

describe('the format preview', () => {
  const now = new Date('2026-03-09T10:00:00Z');

  it('fills in the tokens', () => {
    expect(numberingSample('INV-{YYYY}-{SEQ:4}', now)).toBe('INV-2026-0001');
    expect(numberingSample('{YY}/{MM}/{SEQ}', now)).toBe('26/03/1');
  });

  it('has nothing to show with no running number', () => {
    expect(numberingSample('INV-{YYYY}', now)).toBeNull();
  });
});

describe('the numbering dialog', () => {
  function setup(numbering: TemplateRow['numbering'] = null, ok = true) {
    const apiClient = jest.fn(async () => ({
      ok,
      json: async () => ({ error: 'numbering.pattern must contain {SEQ} exactly once' }),
    })) as unknown as Parameters<typeof NumberingDialog>[0]['apiClient'];
    const onSaved = jest.fn();
    render(
      <NumberingDialog
        row={{ ...template, numbering }}
        name="Invoice"
        apiClient={apiClient}
        addToast={jest.fn()}
        onClose={jest.fn()}
        onSaved={onSaved}
      />
    );

    return { apiClient: apiClient as unknown as jest.Mock, onSaved };
  }

  it('saves a format, with when it applies', async () => {
    const { apiClient, onSaved } = setup();
    await userEvent.type(screen.getByLabelText('Number format'), 'INV-{{YYYY}-{{SEQ:4}');
    expect(screen.getByTestId('numbering-sample')).toHaveTextContent(`INV-${new Date().getUTCFullYear()}-0001`);

    await userEvent.click(screen.getByRole('button', { name: 'Save numbering' }));

    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    const [url, init] = apiClient.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/api/v1/document-sources/7');
    expect(init.method).toBe('PATCH');
    expect(JSON.parse(String(init.body))).toEqual({ numbering: { pattern: 'INV-{YYYY}-{SEQ:4}', assign: 'on_issue' } });
  });

  it('won\'t save a format with no running number', async () => {
    setup();
    await userEvent.type(screen.getByLabelText('Number format'), 'INV');
    expect(screen.getByTestId('numbering-needs-sequence')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save numbering' })).toBeDisabled();
  });

  it('clearing the format sends null', async () => {
    const { apiClient, onSaved } = setup({ pattern: 'INV-{SEQ}', assign: 'on_issue' });
    await userEvent.clear(screen.getByLabelText('Number format'));
    await userEvent.click(screen.getByRole('button', { name: 'Save numbering' }));

    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    const [, init] = apiClient.mock.calls[0] as [string, RequestInit];
    expect(JSON.parse(String(init.body))).toEqual({ numbering: null });
  });

  it('shows the server\'s sentence when it refuses', async () => {
    const { onSaved } = setup(null, false);
    await userEvent.type(screen.getByLabelText('Number format'), 'A-{{SEQ}');
    await userEvent.click(screen.getByRole('button', { name: 'Save numbering' }));

    expect(await screen.findByText('numbering.pattern must contain {SEQ} exactly once')).toBeInTheDocument();
    expect(onSaved).not.toHaveBeenCalled();
  });
});
