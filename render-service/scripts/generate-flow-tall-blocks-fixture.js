#!/usr/bin/env node
'use strict';

/**
 * Blocks taller than a page (WHIT-1370).
 *
 * A form printout was refused whole because one question - a 20-row Yes / No
 * checklist - was taller than an A4 page and was never split. Nothing a person
 * types should be able to do that. This document is made of nothing but such
 * blocks, each taller than a page on its own:
 *
 *   - a long text answer (one paragraph) and the same in the two-column layout
 *   - a form question whose table answer has one row taller than a page
 *   - a document table with one row taller than a page
 *   - Yes / No rows (word style and three columns), choices and facts
 *   - a notice, a list, a fields panel and an approvals list
 *   - a figure whose image is far taller than a page
 *
 * Every word of every long text is numbered (`a0001`, `a0002` ...), so the
 * check can prove that what was split was not also lost or printed twice.
 *
 *   node generate-flow-tall-blocks-fixture.js --direction ltr --out tall.json
 */

const fs = require('node:fs');

/** `count` numbered words with a prefix: "a0001 a0002 …". */
function words(prefix, count) {
  const out = [];
  for (let i = 1; i <= count; i += 1) {
    out.push(`${prefix}${String(i).padStart(4, '0')}`);
  }
  return out.join(' ');
}

/** A tall, narrow SVG: 40 x 1600 user units, far taller than any page. */
const TALL_SVG =
  'data:image/svg+xml;base64,' +
  Buffer.from(
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="8000" viewBox="0 0 40 1600">' +
      '<rect x="0" y="0" width="40" height="1600" fill="#1f3a93"/><rect x="5" y="5" width="30" height="1590" fill="#ffffff"/></svg>'
  ).toString('base64');

/** The numbered texts the check looks for, by prefix. */
const TEXTS = {
  a: 900, // long answer
  b: 500, // long answer, two-column layout
  c: 1500, // a table answer's tall cell
  d: 1500, // a document table's tall cell
  e: 600, // a notice
};

function build({ direction = 'ltr' } = {}) {
  const rtl = direction === 'rtl';
  const yesNoRows = [];
  for (let i = 1; i <= 60; i += 1) {
    yesNoRows.push({ text: `r${String(i).padStart(4, '0')} Supporting document number ${i} for this application`, value: i % 3 === 0 ? 'no' : 'yes', word: i % 3 === 0 ? 'No' : `Yes · Attachment ${i}` });
  }
  const threeRows = [];
  for (let i = 1; i <= 70; i += 1) {
    threeRows.push({ text: `t${String(i).padStart(4, '0')} Item to confirm`, value: ['yes', 'no', 'na'][i % 3] });
  }
  const options = [];
  for (let i = 1; i <= 60; i += 1) {
    options.push({ text: `o${String(i).padStart(4, '0')} An option long enough to take a line of its own on the page`, chosen: i % 7 === 0 });
  }
  const facts = [];
  for (let i = 1; i <= 24; i += 1) {
    facts.push({ label: `f${String(i).padStart(4, '0')}`, value: words(`v${i}x`, 30) });
  }
  const approvals = [];
  for (let i = 1; i <= 40; i += 1) {
    approvals.push({ status: 'approved', word: 'Approved', name: `p${String(i).padStart(4, '0')} Committee member`, role: 'Committee', at: '22 Nov 2026, 16:35' });
  }
  const listItems = [];
  for (let i = 1; i <= 30; i += 1) {
    listItems.push(`l${String(i).padStart(4, '0')} A long instruction line that wraps across the page more than once at this size, so thirty cannot fit on one page.`);
  }
  const fields = [];
  for (let i = 1; i <= 24; i += 1) {
    fields.push({ label: `k${String(i).padStart(4, '0')} Field`, span: 'full' });
  }

  return {
    title: 'Tall blocks',
    direction: rtl ? 'rtl' : 'ltr',
    lang: rtl ? 'ar' : 'en',
    page: { widthMm: 210, heightMm: 297, margin: { topMm: 22, rightMm: 16, bottomMm: 22, leftMm: 16 } },
    footer: { center: 'Page {{page}} of {{pages}}' },
    content: [
      { type: 'formTitle', title: 'Human Research Ethics Application' },
      { type: 'formSection', number: '1', title: 'Long answers' },
      { type: 'formQuestion', number: '1.1', text: 'Background to the project', continued: '(continued)', answer: { kind: 'text', paragraphs: [words('a', TEXTS.a)] } },
      { type: 'formQuestion', number: '1.2', text: 'Research design', layout: 'row', answer: { kind: 'text', paragraphs: [words('b', TEXTS.b)] } },
      { type: 'formQuestion', number: '1.3', text: 'Drugs to identify', answer: { kind: 'table', columns: ['Drug', 'Details', 'Dose'], rows: [['Drug A', words('c', TEXTS.c), '5 mg'], ['Drug B', 'Short', '10 mg']] } },
      { type: 'table', caption: 'A table with one tall row', columns: ['Item', 'Notes'], rows: [['One', words('d', TEXTS.d)], ['Two', 'Short']] },
      { type: 'notice', label: 'Notice', text: words('e', TEXTS.e) },
      { type: 'formSection', number: '2', title: 'Long lists' },
      { type: 'formQuestion', number: '2.1', text: 'Attachments', answer: { kind: 'yesNoRows', rows: yesNoRows } },
      { type: 'formQuestion', number: '2.2', text: 'Confirmations', answer: { kind: 'yesNoRows', style: 'threeColumns', headers: { item: 'Item', yes: 'Yes', no: 'No', na: 'N/A' }, rows: threeRows } },
      { type: 'formQuestion', number: '2.3', text: 'Activities', answer: { kind: 'choices', options } },
      { type: 'formQuestion', number: '2.4', text: 'Details', answer: { kind: 'facts', items: facts } },
      { type: 'list', title: 'Instructions', items: listItems },
      { type: 'fields', title: 'For the office', fields },
      { type: 'approvals', title: 'Approvals', rows: approvals, notCounted: 'Not counted: n0001 Dr Example, named as Project supervisor' },
      { type: 'figure', src: TALL_SVG, alt: 'A tall image', caption: 'A tall image', keepTogether: true },
      { type: 'paragraph', text: 'zEND The last line of the document.' },
    ],
  };
}

module.exports = { build, TEXTS };

if (require.main === module) {
  const args = { direction: 'ltr', out: null };
  for (let i = 2; i < process.argv.length; i += 1) {
    if (process.argv[i] === '--direction') args.direction = process.argv[i + 1];
    if (process.argv[i] === '--out') args.out = process.argv[i + 1];
  }
  const json = JSON.stringify(build(args));
  if (args.out) {
    fs.writeFileSync(args.out, json);
  } else {
    process.stdout.write(json);
  }
}
