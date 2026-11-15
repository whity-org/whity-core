/**
 * E.23a: pasting a per-document part. In a saved block's original, a copied
 * part gets a new key (one new key per key copied), so it is a second part;
 * pasted anywhere else, the mark is dropped.
 */
import { getSchema } from '@tiptap/core';
import { Fragment, Slice } from '@tiptap/pm/model';

import { documentExtensions } from '@whity/ui/editor/extensions';
import { rekeyPasted } from '@whity/ui/editor/schema';

const schema = getSchema(documentExtensions());

function slice(): Slice {
  const part = (text: string, key: string) => schema.text(text, [schema.marks.perDocument.create({ key })]);
  return new Slice(
    Fragment.from([
      schema.nodes.paragraph.create(null, [schema.text('Signed by '), part('Amro Saleh', 'pd_signer01'), schema.text(' on '), part('12 November', 'pd_signdate')]),
      schema.nodes.paragraph.create(null, [schema.text('Again: '), part('Amro Saleh', 'pd_signer01')]),
    ]),
    0,
    0
  );
}

const keys = (s: Slice) => {
  const out: (string | null)[] = [];
  s.content.descendants((node) => {
    if (node.isText) out.push((node.marks.find((m) => m.type.name === 'perDocument')?.attrs.key as string | undefined) ?? null);
  });
  return out;
};

it('in the original, a pasted part gets a new key, the same one wherever the copied key appeared', () => {
  const [, signer, , date, , again] = keys(rekeyPasted(slice(), true));
  expect(signer).toMatch(/^pd_[A-Za-z0-9_-]{8}$/);
  expect(signer).not.toBe('pd_signer01');
  expect(again).toBe(signer);
  expect(date).not.toBe(signer);
  expect(date).not.toBe('pd_signdate');
});

it('anywhere else, the per-document mark is dropped and the text stays', () => {
  const pasted = rekeyPasted(slice(), false);
  expect(keys(pasted).every((k) => k === null)).toBe(true);
  expect(pasted.content.textBetween(0, pasted.content.size, '|')).toBe('Signed by Amro Saleh on 12 November|Again: Amro Saleh');
});
