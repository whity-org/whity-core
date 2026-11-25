import type { Block } from './blocks';

/** The block of one type: `BlockOf<'heading'>` is a heading block. */
export type BlockOf<T extends Block['type']> = Extract<Block, { type: T }>;

/** A block's props: everything but its `type`. */
export type BlockProps<T extends Block['type']> = Omit<BlockOf<T>, 'type'>;

/**
 * Build a block, checked by the compiler against its type (SDK 1.165, D8):
 *
 * ```ts
 * const screen = [
 *   block('heading', { level: 2, text: "Today's orders" }),
 *   block('dataTable', { source: '/api/cake-orders/today', columns: [{ key: 'name', label: 'Cake' }] }),
 * ];
 * ```
 *
 * A missing required prop or a misspelt one is a type error, not a block the
 * host drops when it loads. The host still validates every tree; this only
 * moves the mistakes earlier.
 */
export function block<T extends Block['type']>(type: T, props: BlockProps<T>): BlockOf<T> {
  return { type, ...props } as unknown as BlockOf<T>;
}
