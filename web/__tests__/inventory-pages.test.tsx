/**
 * Inventory P1b and P2b (WHIT-1232, WHIT-1233, design section 31): the Stock
 * tab shows each item against each place with a figure below zero or under
 * its reorder level in red, the item page lists stock by place and the history
 * with Undo, counts and transfers have their tabs and pages, waste has its
 * page, and a person who only reads sees no buttons that change anything.
 */

import React, { Suspense } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

jest.mock('next/navigation', () => ({
  useRouter: () => ({ push: jest.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast: jest.fn() }) }));
jest.mock('@/lib/branding-context', () => ({ useWorkspaceCurrency: () => 'USD' }));

let permissions: string[] = ['inventory:read', 'inventory:write', 'stock:adjust'];
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ has: (p: string) => permissions.includes(p), hasPermission: (p: string) => permissions.includes(p), loading: false }),
}));

const GET = jest.fn();
const POST = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...a: unknown[]) => GET(...a),
    POST: (...a: unknown[]) => POST(...a),
    PATCH: jest.fn(),
    PUT: jest.fn(),
    DELETE: jest.fn(),
  },
}));

import InventoryPage from '@/app/(protected)/inventory/page';
import InventoryItemPage from '@/app/(protected)/inventory/items/[id]/page';
import InventoryCountPage from '@/app/(protected)/inventory/counts/[id]/page';
import WasteAndAdjustmentsPage from '@/app/(protected)/inventory/adjustments/page';
import { nameIn, quantityText } from '@/lib/inventory/api';

const ok = (data: unknown) => ({ data, error: undefined, response: new Response(null, { status: 200 }) });

const kg = { id: 2, code: 'kg', name: 'kg', names: { en: 'kg', ar: 'كغ' }, dimension: 'mass', decimals: 3, to_base_num: 1, to_base_den: 1, seeded: true, archived: false };
const flour = {
  id: 7,
  name: 'Flour',
  names: { en: 'Flour', ar: 'طحين' },
  description: '',
  kind: 'stocked',
  category_id: null,
  base_unit: kg,
  tracking: 'none',
  expires: false,
  sale_price_minor: 1600,
  purchase_price_minor: null,
  currency: 'USD',
  tax_category: '',
  variants: [{ id: 70, item_id: 7, sku: 'FLR-25', options: {}, barcodes: [{ code: '6251234567890', unit: null, symbology: 'ean13' }], sale_price_minor: null, purchase_price_minor: null, on_hand: '448.000', archived: false }],
  units: [{ unit: 'bag', per: '25', purpose: 'purchase', is_default: false }],
  on_hand: '448.000',
  fields: {},
  archived: false,
  created_at: '2026-11-19 08:00:00',
  updated_at: '2026-11-19 08:00:00',
};
const place = (id: number, en: string) => ({ id, parent_id: null, kind: 'warehouse', virtual_role: null, name: en, names: { en }, code: '', ou_id: null, address: '', is_active: true, created_at: '', updated_at: '' });
const level = (location_id: number, on_hand: string) => ({ item_id: 7, variant_id: 70, sku: 'FLR-25', location_id, lot_id: null, on_hand, reserved: '0.000', available: on_hand, unit: 'kg', decimals: 3, updated_at: '' });
const move = {
  id: 501, item_id: 7, variant_id: 70, sku: 'FLR-25', lot_id: null, from_location_id: 1, to_location_id: 99, quantity: '2.000', unit: 'kg', decimals: 3,
  entered_quantity: null, entered_unit: null, unit_cost_minor: null, currency: null, source_type: 'adjustment', source_id: null, reason_code: 'spoiled',
  note: '', batch_key: 'abcdef0123456789abcdef01', reverses_move_id: null, posted_by_profile_id: 11, posted_by_name: 'Ibrahim Mansour', agent_id: null,
  on_behalf_of_profile_id: null, occurred_at: '2026-11-19 08:20:00', posted_at: '2026-11-19 08:20:00',
};

const reasons = [
  { id: 1, code: 'spoiled', name: 'Spoiled', names: { en: 'Spoiled', ar: 'فاسد' }, direction: 'out', is_waste: true, is_seeded: true, archived: false },
  { id: 2, code: 'unsold', name: 'End of day, unsold', names: { en: 'End of day, unsold' }, direction: 'out', is_waste: true, is_seeded: true, archived: false },
  { id: 3, code: 'found', name: 'Found', names: { en: 'Found' }, direction: 'in', is_waste: false, is_seeded: true, archived: false },
];
const rule = {
  id: 9, item_id: 7, item_name: 'Flour', variant_id: 70, sku: 'FLR-25', location_id: 3, unit: 'kg', decimals: 3, min_quantity: '50.000',
  order_up_to: '200.000', supplier_contact_id: null, lead_days: null, is_active: true, is_below: true, below_since: null, available: '-2.000', suggested_quantity: '202.000',
};
const operation = (over: Record<string, unknown>) => ({
  id: 40, kind: 'count', number: 'CNT-0001', status: 'draft', location_id: 3, from_location_id: null, to_location_id: null, category_id: null,
  reason_code: 'count_correction', note: '', is_blind: false, value_minor: null, currency: null, route_id: null, batch_key: null, sent_batch_key: null,
  source_plugin: null, created_by_profile_id: 11, created_by_name: 'Ibrahim Mansour', agent_id: null, on_behalf_of_profile_id: null,
  done_by_profile_id: null, done_by_name: null, occurred_at: null, submitted_at: null, sent_at: null, done_at: null, cancelled_at: null,
  created_at: '2026-11-19 08:00:00', updated_at: '2026-11-19 08:00:00', line_count: 1, counted_count: 0,
  lines: [{ id: 400, variant_id: 70, item_id: 7, item_name: 'Flour', sku: 'FLR-25', unit: 'kg', decimals: 3, reason_code: '', note: '',
    value_minor: null, currency: 'USD', counted: null, expected: null, difference: null, counted_at: null, counted_by_profile_id: null }],
  ...over,
});

function routeGet(path: string) {
  switch (path) {
    case '/api/v1/inventory/units':
      return ok({ data: [kg] });
    case '/api/v1/inventory/locations':
      return ok({ data: [place(1, 'Central kitchen'), place(3, 'Sweifieh'), { ...place(99, 'Loss and waste'), kind: 'virtual', virtual_role: 'loss' }] });
    case '/api/v1/inventory/stock':
      return ok({ data: [level(1, '450.000'), level(3, '-2.000')] });
    case '/api/v1/inventory/items':
      return ok({ data: [flour], pagination: { page: 1, perPage: 50, total: 1, totalPages: 1 } });
    case '/api/v1/inventory/items/{id}':
      return ok({ data: flour });
    case '/api/v1/inventory/stock/moves':
      return ok({ data: [move], next_before_id: null });
    case '/api/v1/inventory/reasons':
      return ok({ data: reasons });
    case '/api/v1/inventory/reorder-rules':
      // Two places with a level, one under it: "Low at Sweifieh".
      return ok({ data: [rule, { ...rule, id: 10, location_id: 1, is_below: false, available: '450.000', suggested_quantity: null }] });
    case '/api/v1/inventory/settings':
      return ok({ data: { approve_adjustments_above: '25', approve_counts_above: '25', adjustment_route_template_id: null, count_route_template_id: null, transfers_one_step: false } });
    case '/api/v1/inventory/operations/{id}':
      return ok({ data: operation({}) });
    default:
      return ok({ data: [] });
  }
}

beforeEach(() => {
  jest.clearAllMocks();
  permissions = ['inventory:read', 'inventory:write', 'stock:adjust'];
  GET.mockImplementation(async (path: string, opts?: { params?: { query?: { virtual?: string } } }) => {
    if (path === '/api/v1/inventory/locations' && opts?.params?.query?.virtual !== 'true') {
      return ok({ data: [place(1, 'Central kitchen'), place(3, 'Sweifieh')] });
    }
    return routeGet(path);
  });
  POST.mockResolvedValue(ok({ data: { batch_key: 'b', moves: [], repeated: false } }));
});

describe('the inventory helpers', () => {
  it('drops trailing zeros and reads names in the reader\'s language', () => {
    expect(quantityText('448.000')).toBe('448');
    expect(quantityText('2.500')).toBe('2.5');
    expect(quantityText('-0.000')).toBe('0');
    expect(nameIn({ en: 'Flour', ar: 'طحين' }, 'Flour', 'ar')).toBe('طحين');
    expect(nameIn({ en: 'Flour' }, 'Flour', 'ar')).toBe('Flour');
    expect(nameIn(undefined, 'Flour', null)).toBe('Flour');
  });
});

describe('/inventory', () => {
  it('shows each item against each real place, below zero in red', async () => {
    render(<InventoryPage />);
    // In the table and, under its reorder level, in the Below reorder card.
    expect((await screen.findAllByText('Flour')).length).toBe(2);
    expect(screen.getByText('Central kitchen')).toBeInTheDocument();
    expect(screen.queryByText('Loss and waste')).toBeNull();
    const negative = screen.getByText((_, el) => el?.tagName === 'BDI' && el.textContent?.startsWith('-2') === true);
    expect(negative.className).toContain('text-destructive');
    expect(screen.getByTestId('inventory-new-item')).toBeInTheDocument();
  });

  it('offers nothing to change to someone who only reads', async () => {
    permissions = ['inventory:read'];
    render(<InventoryPage />);
    // In the table and, under its reorder level, in the Below reorder card.
    expect((await screen.findAllByText('Flour')).length).toBe(2);
    expect(screen.queryByTestId('inventory-new-item')).toBeNull();
    expect(screen.queryByTestId('inventory-count')).toBeNull();
    expect(screen.queryByTestId('inventory-transfer')).toBeNull();
  });

  it('puts Count stock and Transfer beside New item, and tells which place is low (TplInventory)', async () => {
    permissions = ['inventory:read', 'inventory:write', 'stock:adjust', 'stock:count', 'stock:transfer'];
    render(<InventoryPage />);
    expect(await screen.findByText('Low at Sweifieh')).toBeInTheDocument();
    expect(screen.getByTestId('inventory-count')).toHaveTextContent('Count stock');
    expect(screen.getByTestId('inventory-transfer')).toHaveTextContent('Transfer');
    // The tabs come before the numbers, as on the card.
    const tabs = screen.getByRole('tablist');
    const below = screen.getByText('Below reorder');
    expect(tabs.compareDocumentPosition(below) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.queryByText('Below zero')).toBeNull();
  });

  it('lists counts on their tab and opens a count to keep counting', async () => {
    GET.mockImplementation(async (path: string, opts?: { params?: { query?: { kind?: string; virtual?: string } } }) => {
      if (path === '/api/v1/inventory/operations') return ok({ data: opts?.params?.query?.kind === 'count' ? [operation({ lines: undefined })] : [] });
      if (path === '/api/v1/inventory/locations' && opts?.params?.query?.virtual !== 'true') {
        return ok({ data: [place(1, 'Central kitchen'), place(3, 'Sweifieh')] });
      }
      return routeGet(path);
    });
    render(<InventoryPage />);
    fireEvent.mouseDown(await screen.findByRole('tab', { name: 'Counts' }));
    fireEvent.click(screen.getByRole('tab', { name: 'Counts' }));
    expect(await screen.findByText('CNT-0001')).toBeInTheDocument();
    expect(screen.getByText('Counting')).toBeInTheDocument();
  });
});

describe('/inventory/counts/[id]', () => {
  it('saves what was counted and finishes the count', async () => {
    permissions = ['inventory:read', 'stock:count', 'stock:adjust'];
    const PUT = jest.requireMock('@/lib/api/client').api.PUT as jest.Mock;
    PUT.mockResolvedValue(ok({ data: operation({}) }));
    POST.mockResolvedValue(ok({ data: operation({ status: 'done' }) }));
    await act(async () => {
      render(
        <Suspense fallback={null}>
          <InventoryCountPage params={Promise.resolve({ id: '40' })} />
        </Suspense>
      );
    });
    fireEvent.change(await screen.findByLabelText(/Counted, Flour/), { target: { value: '440' } });
    fireEvent.click(screen.getByTestId('count-finish'));
    await waitFor(() =>
      expect(PUT).toHaveBeenCalledWith('/api/v1/inventory/counts/{id}/lines', expect.objectContaining({ body: { lines: [{ variant_id: 70, counted: '440' }] } }))
    );
    await waitFor(() => expect(POST).toHaveBeenCalledWith('/api/v1/inventory/counts/{id}/finish', expect.anything()));
  });
});

describe('/inventory/adjustments', () => {
  it('lists waste with its reason, value and state, and the approval rule', async () => {
    GET.mockImplementation(async (path: string, opts?: { params?: { query?: { kind?: string } } }) => {
      if (path === '/api/v1/inventory/operations' && opts?.params?.query?.kind === 'adjustment') {
        return ok({
          data: [
            operation({
              id: 41, kind: 'adjustment', number: 'ADJ-0001', status: 'waiting_approval', reason_code: 'spoiled', value_minor: 4200, currency: 'USD',
              lines: [{ ...operation({}).lines[0], id: 410, change: '-1.000', reason_code: '', value_minor: 4200 }],
            }),
          ],
        });
      }
      return routeGet(path);
    });
    render(<WasteAndAdjustmentsPage />);
    expect(await screen.findByText('Spoiled')).toBeInTheDocument();
    expect(screen.getAllByText('Waiting for approval').length).toBeGreaterThan(0);
    expect(screen.getByTestId('waste-rules')).toHaveTextContent(/Over \u2066\$25\.00\u2069 a line/);
    expect(screen.getByTestId('waste-rows')).toHaveTextContent('Ibrahim Mansour');
  });
});

async function renderItemPage() {
  await act(async () => {
    render(
      <Suspense fallback={null}>
        <InventoryItemPage params={Promise.resolve({ id: '7' })} />
      </Suspense>
    );
  });
}

describe('/inventory/items/[id]', () => {
  it('lists stock by place, the codes, and the history with Undo', async () => {
    await renderItemPage();
    expect(await screen.findByTestId('item-stock')).toBeInTheDocument();
    expect(screen.getByText('6251234567890')).toBeInTheDocument();
    expect(screen.getByText(/Ibrahim Mansour/)).toBeInTheDocument();
    expect(screen.getByText(/Spoiled/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Undo/ }));
    await waitFor(() =>
      expect(POST).toHaveBeenCalledWith('/api/v1/inventory/stock/batches/{key}/undo', expect.objectContaining({ params: { path: { key: move.batch_key } } }))
    );
  });

  it('hides Change stock and Undo without stock:adjust', async () => {
    permissions = ['inventory:read'];
    await renderItemPage();
    expect(await screen.findByTestId('item-history')).toBeInTheDocument();
    expect(screen.queryByTestId('item-adjust')).toBeNull();
    expect(screen.queryByRole('button', { name: /Undo/ })).toBeNull();
    expect(screen.queryByTestId('item-edit')).toBeNull();
  });
});
