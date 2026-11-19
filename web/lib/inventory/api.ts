/**
 * The inventory API as the web uses it (WHIT-1232, design section 31). Types
 * come from the generated schema; every call goes through the typed `api`
 * client, which carries the session cookie and refreshes it.
 *
 * ERRORS. A 403 on a read becomes `ForbiddenError` (the access-denied state);
 * a refused write keeps the server's sentence for 409 and 422, which says what
 * to change (a duplicate SKU, the plan's item limit, a missing reason).
 */

import type { components } from '@whity/api-client';
import { api } from '@/lib/api/client';
import { ForbiddenError } from '@/lib/forbidden';

type Schemas = components['schemas'];

export type InventoryItem = Schemas['InventoryItem'];
export type InventoryItemRequest = Schemas['InventoryItemRequest'];
export type InventoryVariant = Schemas['InventoryVariant'];
export type InventoryUnit = Schemas['InventoryUnit'];
export type InventoryCategory = Schemas['InventoryCategory'];
export type InventoryLocation = Schemas['InventoryLocation'];
export type InventoryLocationRequest = Schemas['InventoryLocationRequest'];
export type InventoryLevel = Schemas['InventoryLevel'];
export type InventoryMove = Schemas['InventoryMove'];
export type InventoryBatch = Schemas['InventoryBatch'];
export type InventoryAdjustmentRequest = Schemas['InventoryAdjustmentRequest'];
export type InventoryPagination = Schemas['Pagination'];
export type InventoryOperation = Schemas['InventoryOperation'];
export type InventoryOperationLine = Schemas['InventoryOperationLine'];
export type InventoryReason = Schemas['InventoryReason'];
export type InventoryReorderRule = Schemas['InventoryReorderRule'];
export type InventorySettings = Schemas['InventorySettings'];
export type InventoryTransferRequest = Schemas['InventoryTransferRequest'];
export type InventoryCountRequest = Schemas['InventoryCountRequest'];
export type InventoryOperationAdjustmentRequest = Schemas['InventoryOperationAdjustmentRequest'];
export type InventoryPurchaseOrder = Schemas['InventoryPurchaseOrder'];
export type InventoryPurchaseOrderLine = Schemas['InventoryPurchaseOrderLine'];
export type InventoryPurchaseOrderRequest = Schemas['InventoryPurchaseOrderRequest'];
export type InventorySupplierItem = Schemas['InventorySupplierItem'];
export type PurchaseOrderStatus = InventoryPurchaseOrder['status'];
export type OperationKind = InventoryOperation['kind'];
export type OperationStatus = InventoryOperation['status'];

export const INVENTORY_READ = 'inventory:read';
export const INVENTORY_WRITE = 'inventory:write';
export const STOCK_ADJUST = 'stock:adjust';
export const STOCK_COUNT = 'stock:count';
export const STOCK_TRANSFER = 'stock:transfer';
export const INVENTORY_SETTINGS = 'inventory:settings';
export const PURCHASES_READ = 'purchases:read';
export const PURCHASES_WRITE = 'purchases:write';

export const ITEM_KINDS = ['stocked', 'consumable', 'service', 'made'] as const;
export type ItemKind = (typeof ITEM_KINDS)[number];

/** The workspace's reasons that take stock this way (register N5, WHIT-1233). */
export function reasonsFor(reasons: InventoryReason[], direction: 'out' | 'in'): InventoryReason[] {
  return reasons.filter((r) => !r.archived && (r.direction === 'both' || r.direction === direction));
}

/** A refused inventory request, with the HTTP status the screen branches on. */
export class InventoryRequestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly serverMessage: string | null,
    public readonly code: string | null = null
  ) {
    super(message);
    this.name = 'InventoryRequestError';
  }
}

interface Result {
  data?: unknown;
  error?: unknown;
  response: Response;
}

function serverText(error: unknown): string | null {
  const text = (error as { error?: unknown } | null | undefined)?.error;
  return typeof text === 'string' && text !== '' ? text : null;
}

function serverCode(error: unknown): string | null {
  const details = (error as { details?: { code?: unknown } } | null | undefined)?.details;
  return typeof details?.code === 'string' ? details.code : null;
}

function readBody<T>(result: Result, fallback: string): T {
  if (result.response.status === 403) throw new ForbiddenError();
  if (!result.response.ok) {
    throw new InventoryRequestError(fallback, result.response.status, serverText(result.error), serverCode(result.error));
  }
  return result.data as T;
}

function read<T>(result: Result, fallback: string): T {
  return readBody<{ data: T }>(result, fallback).data;
}

function send<T>(result: Result, fallback: string): T {
  if (!result.response.ok) {
    throw new InventoryRequestError(fallback, result.response.status, serverText(result.error), serverCode(result.error));
  }
  return (result.data as { data: T }).data;
}

/** The language a paper prints in: its template's, not the reader's. */
export type PrintLanguage = 'en' | 'ar';

/** A printed paper: its PDF, or none when this server makes no PDFs (it is still issued). */
export interface Printed {
  pdf: Blob | null;
  documentId: number;
  number: string | null;
}

async function printed(result: Result, fallback: string): Promise<Printed> {
  if (result.response.status === 403) throw new ForbiddenError();
  if (!result.response.ok) {
    throw new InventoryRequestError(fallback, result.response.status, serverText(result.error), serverCode(result.error));
  }
  if (result.response.status === 202) {
    const body = JSON.parse(await (result.data as Blob).text()) as { data: { document_id: number; number: string | null } };
    return { pdf: null, documentId: body.data.document_id, number: body.data.number };
  }
  return { pdf: result.data as Blob, documentId: Number(result.response.headers.get('X-Document-Id') ?? 0), number: null };
}

/** Saves a printed PDF under its name. */
export function savePdf(blob: Blob, name: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${name || 'document'}.pdf`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Drops the empty values (null, undefined, '', false) so they are not sent. */
function query(params: Record<string, string | number | boolean | null | undefined>): never {
  const out: Record<string, string | number | boolean> = {};
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '' && value !== false) out[key] = value;
  }
  return out as never;
}

export interface ItemListParams {
  q?: string;
  kind?: ItemKind | null;
  categoryId?: number | null;
  archived?: boolean;
  page?: number;
  perPage?: number;
}

export interface ItemPage {
  data: InventoryItem[];
  pagination: InventoryPagination;
}

export const inventoryApi = {
  units: async () => read<InventoryUnit[]>(await api.GET('/api/v1/inventory/units'), 'The units could not be loaded'),
  categories: async () =>
    read<InventoryCategory[]>(await api.GET('/api/v1/inventory/categories'), 'The categories could not be loaded'),
  createCategory: async (name: string) =>
    send<InventoryCategory>(
      await api.POST('/api/v1/inventory/categories', { body: { name } }),
      'The category could not be added'
    ),

  items: async (p: ItemListParams = {}) =>
    readBody<ItemPage>(
      await api.GET('/api/v1/inventory/items', {
        params: {
          query: query({
            q: p.q?.trim(),
            kind: p.kind,
            category_id: p.categoryId,
            archived: p.archived === true ? 'true' : null,
            page: p.page,
            per_page: p.perPage,
          }),
        },
      }),
      'The items could not be loaded'
    ),
  item: async (id: number) =>
    read<InventoryItem>(await api.GET('/api/v1/inventory/items/{id}', { params: { path: { id } } }), 'The item could not be loaded'),
  lookup: async (code: string) => {
    const result = await api.GET('/api/v1/inventory/items/lookup', { params: { query: { code } } });
    if (result.response.status === 404) return null;
    return read<{ item: InventoryItem; variant_id: number; unit: string }>(result, 'The code could not be looked up');
  },
  createItem: async (body: InventoryItemRequest) =>
    send<InventoryItem>(await api.POST('/api/v1/inventory/items', { body }), 'The item could not be added'),
  updateItem: async (id: number, body: InventoryItemRequest) =>
    send<InventoryItem>(
      await api.PATCH('/api/v1/inventory/items/{id}', { params: { path: { id } }, body }),
      'The item could not be saved'
    ),
  archiveItem: async (id: number) =>
    send<InventoryItem>(
      await api.POST('/api/v1/inventory/items/{id}/archive', { params: { path: { id } }, body: {} as never }),
      'The item could not be archived'
    ),
  restoreItem: async (id: number) =>
    send<InventoryItem>(
      await api.POST('/api/v1/inventory/items/{id}/restore', { params: { path: { id } }, body: {} as never }),
      'The item could not be brought back'
    ),

  locations: async (opts: { virtual?: boolean; inactive?: boolean } = {}) =>
    read<InventoryLocation[]>(
      await api.GET('/api/v1/inventory/locations', {
        params: { query: query({ virtual: opts.virtual ? 'true' : null, inactive: opts.inactive ? 'true' : null }) },
      }),
      'The places could not be loaded'
    ),
  createLocation: async (body: InventoryLocationRequest) =>
    send<InventoryLocation>(await api.POST('/api/v1/inventory/locations', { body }), 'The place could not be added'),
  updateLocation: async (id: number, body: InventoryLocationRequest) =>
    send<InventoryLocation>(
      await api.PATCH('/api/v1/inventory/locations/{id}', { params: { path: { id } }, body }),
      'The place could not be saved'
    ),

  levels: async (p: { itemId?: number; locationId?: number; nonzero?: boolean } = {}) =>
    read<InventoryLevel[]>(
      await api.GET('/api/v1/inventory/stock', {
        params: { query: query({ item_id: p.itemId, location_id: p.locationId, nonzero: p.nonzero ? 'true' : null }) },
      }),
      'The stock could not be loaded'
    ),
  moves: async (p: { itemId?: number; locationId?: number; beforeId?: number | null; limit?: number } = {}) =>
    readBody<{ data: InventoryMove[]; next_before_id?: number | null }>(
      await api.GET('/api/v1/inventory/stock/moves', {
        params: { query: query({ item_id: p.itemId, location_id: p.locationId, before_id: p.beforeId, limit: p.limit }) },
      }),
      'The stock history could not be loaded'
    ),
  adjust: async (body: InventoryAdjustmentRequest) =>
    send<InventoryBatch>(await api.POST('/api/v1/inventory/stock/adjustments', { body }), 'The stock could not be changed'),
  undo: async (key: string) =>
    send<InventoryBatch>(
      await api.POST('/api/v1/inventory/stock/batches/{key}/undo', { params: { path: { key } }, body: {} as never }),
      'The change could not be undone'
    ),

  // ── operations: adjustments, counts, transfers (WHIT-1233) ──
  operations: async (
    p: { kind?: OperationKind; status?: OperationStatus; locationId?: number; orderId?: number; lines?: boolean; limit?: number } = {}
  ) =>
    read<InventoryOperation[]>(
      await api.GET('/api/v1/inventory/operations', {
        params: {
          query: query({
            kind: p.kind,
            status: p.status,
            location_id: p.locationId,
            order_id: p.orderId,
            lines: p.lines ? 'true' : null,
            limit: p.limit,
          }),
        },
      }),
      'The stock changes could not be loaded'
    ),
  operation: async (id: number) =>
    read<InventoryOperation>(
      await api.GET('/api/v1/inventory/operations/{id}', { params: { path: { id } } }),
      'This stock change could not be loaded'
    ),
  createAdjustment: async (body: InventoryOperationAdjustmentRequest) =>
    send<InventoryOperation>(await api.POST('/api/v1/inventory/adjustments', { body }), 'The stock could not be changed'),
  submitAdjustment: async (id: number) =>
    send<InventoryOperation>(
      await api.POST('/api/v1/inventory/adjustments/{id}/submit', { params: { path: { id } }, body: {} as never }),
      'The adjustment could not be sent'
    ),
  cancelAdjustment: async (id: number) =>
    send<InventoryOperation>(
      await api.POST('/api/v1/inventory/adjustments/{id}/cancel', { params: { path: { id } }, body: {} as never }),
      'The adjustment could not be cancelled'
    ),
  startCount: async (body: InventoryCountRequest) =>
    send<InventoryOperation>(await api.POST('/api/v1/inventory/counts', { body }), 'The count could not be started'),
  recordCount: async (id: number, lines: { variant_id: number; counted: string; unit?: string }[]) =>
    send<InventoryOperation>(
      await api.PUT('/api/v1/inventory/counts/{id}/lines', { params: { path: { id } }, body: { lines } }),
      'What you counted could not be saved'
    ),
  finishCount: async (id: number) =>
    send<InventoryOperation>(
      await api.POST('/api/v1/inventory/counts/{id}/finish', { params: { path: { id } }, body: {} as never }),
      'The count could not be finished'
    ),
  cancelCount: async (id: number) =>
    send<InventoryOperation>(
      await api.POST('/api/v1/inventory/counts/{id}/cancel', { params: { path: { id } }, body: {} as never }),
      'The count could not be cancelled'
    ),
  createTransfer: async (body: InventoryTransferRequest) =>
    send<InventoryOperation>(await api.POST('/api/v1/inventory/transfers', { body }), 'The transfer could not be sent'),
  receiveTransfer: async (id: number, lines: { line_id: number; received: string }[] = []) =>
    send<InventoryOperation>(
      await api.POST('/api/v1/inventory/transfers/{id}/receive', { params: { path: { id } }, body: { lines } }),
      'The transfer could not be received'
    ),
  cancelTransfer: async (id: number) =>
    send<InventoryOperation>(
      await api.POST('/api/v1/inventory/transfers/{id}/cancel', { params: { path: { id } }, body: {} as never }),
      'The transfer could not be cancelled'
    ),

  // ── reasons, reorder levels, settings ──
  reasons: async () => read<InventoryReason[]>(await api.GET('/api/v1/inventory/reasons'), 'The reasons could not be loaded'),
  reorderRules: async (p: { variantId?: number; locationId?: number; below?: boolean } = {}) =>
    read<InventoryReorderRule[]>(
      await api.GET('/api/v1/inventory/reorder-rules', {
        params: { query: query({ variant_id: p.variantId, location_id: p.locationId, below: p.below ? 'true' : null }) },
      }),
      'The reorder levels could not be loaded'
    ),
  setReorderRule: async (body: { variant_id: number; location_id: number; min_quantity: string; order_up_to?: string | null }) =>
    send<InventoryReorderRule>(await api.PUT('/api/v1/inventory/reorder-rules', { body }), 'The reorder level could not be saved'),
  deleteReorderRule: async (id: number) =>
    send<{ deleted: boolean }>(
      await api.DELETE('/api/v1/inventory/reorder-rules/{id}', { params: { path: { id } } }),
      'The reorder level could not be removed'
    ),
  settings: async () => read<InventorySettings>(await api.GET('/api/v1/inventory/settings'), 'The inventory settings could not be loaded'),

  // ── purchase orders and supplier prices (WHIT-1234) ──
  purchaseOrders: async (p: { status?: PurchaseOrderStatus; contactId?: number; limit?: number } = {}) =>
    read<InventoryPurchaseOrder[]>(
      await api.GET('/api/v1/inventory/purchase-orders', {
        params: { query: query({ status: p.status, contact_id: p.contactId, limit: p.limit }) },
      }),
      'The purchase orders could not be loaded'
    ),
  purchaseOrder: async (id: number) =>
    read<InventoryPurchaseOrder>(
      await api.GET('/api/v1/inventory/purchase-orders/{id}', { params: { path: { id } } }),
      'The purchase order could not be loaded'
    ),
  createPurchaseOrder: async (body: InventoryPurchaseOrderRequest) =>
    send<InventoryPurchaseOrder>(await api.POST('/api/v1/inventory/purchase-orders', { body }), 'The purchase order could not be saved'),
  submitPurchaseOrder: async (id: number) =>
    send<InventoryPurchaseOrder>(
      await api.POST('/api/v1/inventory/purchase-orders/{id}/submit', { params: { path: { id } }, body: {} as never }),
      'The purchase order could not be sent'
    ),
  cancelPurchaseOrder: async (id: number) =>
    send<InventoryPurchaseOrder>(
      await api.POST('/api/v1/inventory/purchase-orders/{id}/cancel', { params: { path: { id } }, body: {} as never }),
      'The purchase order could not be cancelled'
    ),
  closePurchaseOrderLine: async (id: number, line: number) =>
    send<InventoryPurchaseOrder>(
      await api.POST('/api/v1/inventory/purchase-orders/{id}/lines/{line}/close', { params: { path: { id, line } }, body: {} as never }),
      'The line could not be closed'
    ),
  receivePurchaseOrder: async (id: number, lines: { line_id: number; quantity: string }[], allowOver = false, note?: string) =>
    send<InventoryPurchaseOrder>(
      await api.POST('/api/v1/inventory/purchase-orders/{id}/receive', {
        params: { path: { id } },
        body: { lines, allow_over: allowOver, note },
      }),
      'What arrived could not be received'
    ),
  supplierItems: async (p: { itemId?: number; contactId?: number } = {}) =>
    read<InventorySupplierItem[]>(
      await api.GET('/api/v1/inventory/supplier-items', { params: { query: query({ item_id: p.itemId, contact_id: p.contactId }) } }),
      'The supplier prices could not be loaded'
    ),
  setSupplierItem: async (body: {
    variant_id: number;
    contact_id: number;
    unit?: string;
    price_minor: number;
    currency?: string;
    supplier_sku?: string;
    is_preferred?: boolean;
  }) => send<InventorySupplierItem>(await api.PUT('/api/v1/inventory/supplier-items', { body }), 'The supplier price could not be saved'),
  // ── printing (IV.20, WHIT-1234) ──
  printPurchaseOrder: async (id: number, lang: PrintLanguage) =>
    printed(
      await api.POST('/api/v1/inventory/purchase-orders/{id}/print', { params: { path: { id } }, body: { lang }, parseAs: 'blob' }),
      'The purchase order could not be printed'
    ),
  printReceipt: async (id: number, lang: PrintLanguage) =>
    printed(
      await api.POST('/api/v1/inventory/receipts/{id}/print', { params: { path: { id } }, body: { lang }, parseAs: 'blob' }),
      'The goods received note could not be printed'
    ),
  printTemplates: async () =>
    read<Record<string, { en: number; ar: number }>>(await api.GET('/api/v1/inventory/print-templates'), 'The print templates could not be loaded'),
  /** A sheet of labels, one per variant, from the workspace's item label sheet. */
  printLabels: async (variantIds: number[], lang: PrintLanguage): Promise<Blob> => {
    const templates = await inventoryApi.printTemplates();
    const id = templates['inventory.item-labels']?.[lang];
    if (id === undefined) throw new InventoryRequestError('The label sheet could not be found', 404, null);
    const { data, error, response } = await api.POST('/api/v1/document-sources/{id}/print-labels', {
      params: { path: { id } },
      body: { records: variantIds.map((v) => `inventory.variant:${v}`) },
      parseAs: 'blob',
    });
    if (response.status === 403) throw new ForbiddenError();
    if (data === undefined || !response.ok) {
      throw new InventoryRequestError('The labels could not be printed', response.status, serverText(error), serverCode(error));
    }
    return data as Blob;
  },

  deleteSupplierItem: async (id: number) =>
    send<{ deleted: boolean }>(
      await api.DELETE('/api/v1/inventory/supplier-items/{id}', { params: { path: { id } } }),
      'The supplier price could not be removed'
    ),
};

/** The sentence to show for a refused request. */
export function inventoryErrorText(error: unknown): string {
  if (error instanceof InventoryRequestError) {
    return (error.status === 422 || error.status === 409) && error.serverMessage !== null ? error.serverMessage : error.message;
  }
  return error instanceof Error ? error.message : String(error);
}

/** A name in the reader's language: theirs, then the original, then any. */
export function nameIn(names: Record<string, string> | undefined, original: string, language: string | null): string {
  const own = language === null ? undefined : names?.[language];
  if (typeof own === 'string' && own !== '') return own;
  if (original !== '') return original;
  const any = Object.values(names ?? {}).find((v) => v !== '');
  return any ?? '';
}

/** "24.000" as "24" and "2.500" as "2.5": quantities read best without trailing zeros. */
export function quantityText(decimal: string): string {
  if (!decimal.includes('.')) return decimal;
  const trimmed = decimal.replace(/0+$/, '').replace(/\.$/, '');
  return trimmed === '-0' ? '0' : trimmed;
}
