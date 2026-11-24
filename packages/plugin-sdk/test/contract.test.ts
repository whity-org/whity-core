import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { BLOCK_CONTRACT, BLOCK_TYPES, SDK_VERSION, type BlockType } from '../src/contract.generated.ts';
import type { Block } from '../src/blocks.ts';

/*
 * The hand-written block types against the contract generated from the PHP SDK.
 * The checks below are TYPES: `npm run typecheck` fails, naming the block, when
 * an interface has a prop the contract doesn't, lacks one it has, makes a
 * required prop optional, or when a block type exists on one side only.
 */

type Contract = typeof BLOCK_CONTRACT;
type Props<T extends BlockType> = keyof Contract[T]['props'] & string;
type RequiredProps<T extends BlockType> = {
  [K in Props<T>]: Contract[T]['props'][K] extends { required: true } ? K : never;
}[Props<T>];
type Slots<T extends BlockType> = Contract[T]['slots'][number];
type Of<T extends BlockType> = Extract<Block, { type: T }>;

/**
 * Props the HOST adds to the tree it serves, which a plugin never writes and so
 * the contract (what a plugin may declare) doesn't list. Each one is named in
 * the interface's own comment.
 */
type HostAdded = {
  secretField: 'endpoint';
};
type HostAddedTo<T extends BlockType> = T extends keyof HostAdded ? HostAdded[T] : never;

type Own<T extends BlockType> = Exclude<keyof Of<T>, 'type' | Slots<T> | HostAddedTo<T>> & string;
type RequiredKeys<O> = { [K in keyof O]-?: object extends Pick<O, K> ? never : K }[keyof O];
type Same<A, B> = [A] extends [B] ? ([B] extends [A] ? true : false) : false;

type Check<T extends BlockType> = [Of<T>] extends [never]
  ? 'no interface for this block type'
  : Same<Own<T>, Props<T>> extends true
    ? [RequiredProps<T>] extends [RequiredKeys<Of<T>>]
      ? true
      : { requiredHereButOptional: Exclude<RequiredProps<T>, RequiredKeys<Of<T>>> }
    : { onlyInTheInterface: Exclude<Own<T>, Props<T>>; onlyInTheContract: Exclude<Props<T>, Own<T>> };

// Each entry must be `true`; tsc prints the block and the difference otherwise.
const everyBlockMatches: { [T in BlockType]: true } = null as unknown as { [T in BlockType]: Check<T> };
// A block type in the union that the contract doesn't know.
const noTypeTheContractLacks: [Exclude<Block['type'], BlockType>] extends [never] ? true : Exclude<Block['type'], BlockType> = true;
void everyBlockMatches;
void noTypeTheContractLacks;

test('the generated contract lists every block type once', () => {
  assert.equal(new Set(BLOCK_TYPES).size, BLOCK_TYPES.length);
  assert.ok(BLOCK_TYPES.length >= 57, 'a floor, so an empty contract cannot pass');
  for (const type of BLOCK_TYPES) {
    const rule = BLOCK_CONTRACT[type];
    assert.equal(rule.container, rule.slots.length > 0, `${type}: a container has a child slot, a leaf has none`);
  }
});

test('the package moves in lockstep with the PHP SDK', () => {
  const php = JSON.parse(readFileSync(new URL('../../../sdk/composer.json', import.meta.url), 'utf8')) as { version: string };
  const npm = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string };

  assert.equal(SDK_VERSION, php.version);
  assert.equal(npm.version, php.version);
});
