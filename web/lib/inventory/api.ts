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

export const INVENTORY_READ = 'inventory:read';
export const INVENTORY_WRITE = 'inventory:write';
export const STOCK_ADJUST = 'stock:adjust';

export const ITEM_KINDS = ['stocked', 'consumable', 'service', 'made'] as const;
export type ItemKind = (typeof ITEM_KINDS)[number];

/** Reasons an adjustment takes, by direction (register N5). */
export const REASONS_OUT = ['expired', 'damaged', 'spoiled', 'used_in_house', 'sample', 'theft', 'count_correction', 'other'] as const;
export const REASONS_IN = ['found', 'count_correction', 'returned', 'other'] as const;

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
