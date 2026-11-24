import { test } from 'node:test';
import assert from 'node:assert/strict';
import { BLOCK_CONTRACT, BLOCK_TYPES } from '../src/contract.generated.ts';
import { block } from '../src/builder.ts';
import { normalizeText, resolveText } from '../src/localized.ts';
import { CHILD_SLOTS, findBlocks, walkBlocks } from '../src/testing.ts';
import type { Block } from '../src/blocks.ts';

test('the builder makes the block it names', () => {
  assert.deepEqual(block('heading', { level: 2, text: "Today's orders" }), { type: 'heading', level: 2, text: "Today's orders" });
  // @ts-expect-error a heading needs a level
  block('heading', { text: 'No level' });
  // @ts-expect-error a prop the contract doesn't have
  block('divider', { colour: 'red' });
});

test('the walker knows every child slot the contract has', () => {
  const slots = new Set(BLOCK_TYPES.flatMap((type) => [...BLOCK_CONTRACT[type].slots]));

  assert.deepEqual([...slots].sort(), [...CHILD_SLOTS].sort());
});

test('the walker visits every block, with its path, in every slot', () => {
  const tree: Block[] = [
    block('card', { title: 'Orders', children: [block('heading', { level: 2, text: 'Today' })] }),
    block('accessGate', {
      id: 'can-refund',
      check: { method: 'POST', endpoint: '/api/cake-orders/refund' },
      children: [block('button', { label: 'Refund', href: '/admin/x/refund' })],
      otherwise: [block('text', { value: 'Ask a manager to refund.' })],
    }),
  ];
  const seen: string[] = [];
  walkBlocks(tree, (node, path) => seen.push(`${path} ${node.type}`));

  assert.deepEqual(seen, [
    '[0] card',
    '[0].children[0] heading',
    '[1] accessGate',
    '[1].children[0] button',
    '[1].otherwise[0] text',
  ]);
  assert.deepEqual(findBlocks(tree, 'text').map((f) => f.path), ['[1].otherwise[0]']);
});

test('bilingual text resolves and normalizes as the PHP SDK does', () => {
  assert.equal(resolveText({ ar: 'فاتورة', en: 'Invoice' }, 'ar-JO'), 'فاتورة');
  assert.equal(resolveText({ ar: 'فاتورة', en: 'Invoice' }, 'fr'), 'Invoice');
  assert.equal(resolveText({ ar: 'فاتورة' }, 'en'), 'فاتورة');
  assert.equal(resolveText({ en: '  ' }, 'en'), null);
  assert.equal(resolveText(null, 'en'), null);

  assert.deepEqual(normalizeText({ ar: ' فاتورة ', en: '' }), { ar: 'فاتورة' });
  assert.deepEqual(normalizeText({ en: null }), {});
  assert.equal(normalizeText({ en: 'Invoice', fr: 'Facture' }), null);
  assert.equal(normalizeText({ en: 3 }), null);
  assert.equal(normalizeText('Invoice'), null);
});
