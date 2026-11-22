'use strict';

/**
 * Design 3.4 form printouts (FP.1-FP.12): the blocks in `DocFormBlocks`, the
 * letterhead layouts and the footer's marks and QR.
 *
 * What matters most:
 *
 * - A PAYLOAD WITHOUT THESE BLOCKS PRINTS AS BEFORE: the new blocks add markup
 *   only for themselves.
 * - EVERYTHING A PERSON TYPED IS ESCAPED: answers come from the public.
 * - A DRAWN SIGNATURE IS PATH DATA, never markup.
 */

const { validateFlowPayload, normaliseFlowDocument } = require('../src/flow/document');
const { buildFlowHtml, renderBlock } = require('../src/flow/html');

const doc = (content, extra = {}) => ({ content, ...extra });
const valid = (block) => validateFlowPayload(doc([block]));

function body(payload) {
  const out = buildFlowHtml(normaliseFlowDocument(payload));
  return out.slice(out.indexOf('<div id="flow-source"'), out.indexOf('<div id="flow-pages">'));
}

function boot(payload) {
  const out = buildFlowHtml(normaliseFlowDocument(payload));
  const start = out.indexOf('window.__FLOW_DOC__ = ') + 'window.__FLOW_DOC__ = '.length;
  return JSON.parse(out.slice(start, out.indexOf(';</script>', start)).replace(/\\u003c/g, '<').replace(/\\u003e/g, '>').replace(/\\u0026/g, '&'));
}

const question = (answer, extra = {}) => ({ type: 'formQuestion', number: '1.1', text: 'Project title', answer, ...extra });

describe('existing documents', () => {
  test('print the same blocks as before: the form stylesheet only styles ff-* and new letterhead classes', () => {
    const markup = renderBlock({ type: 'paragraph', text: 'Dear customer' }, 'ltr');
    expect(markup).toBe('<p class="flow-paragraph" data-flow-unit="paragraph">Dear customer</p>');
    const letterhead = renderBlock({ type: 'letterhead', start: { name: 'Example Bakery' }, end: { title: 'Invoice' } }, 'ltr');
    expect(letterhead).not.toContain('flow-letterhead-centred');
    expect(letterhead).not.toContain('flow-letterhead-bilingual');
  });
});

describe('section headings (FP.4)', () => {
  test.each(['number', 'mark', 'eyebrow', 'rail', 'tab', 'plane'])('style %s draws its own heading, kept with what follows', (style) => {
    const block = { type: 'formSection', style, number: '4', title: 'Regulated substances', eyebrow: 'Section 04 of 09', rail: { current: 4, total: 9 } };
    expect(valid(block)).toBeNull();
    const html = body(doc([block]));
    expect(html).toContain(`ff-style-${style}`);
    expect(html).toContain('data-keep-with-next="1"');
    expect(html).toContain('Regulated substances');
  });

  test('B is the standard: two squares carrying the number, drawn as shapes', () => {
    const html = body(doc([{ type: 'formSection', number: '1', title: 'Project' }]));
    expect(html).toContain('ff-style-mark');
    expect(html).toMatch(/<span class="ff-mk" aria-hidden="true"><i><\/i><i><bdi>1<\/bdi><\/i><\/span>/);
  });

  test('D marks the sections before, the current one and those after', () => {
    const html = body(doc([{ type: 'formSection', style: 'rail', number: '3', title: 'x', rail: { current: 3, total: 5 } }]));
    expect(html).toContain('<i class="ff-on"></i><i class="ff-on"></i><i class="ff-now"></i><i class=""></i><i class=""></i>');
  });

  test('a section answered N/A throughout is one line that is never split (FP.9)', () => {
    const block = { type: 'formSection', number: '4', title: 'Regulated substances', notApplicable: { label: 'Not applicable to this project', summary: 'All 9 questions answered N/A.' } };
    expect(valid(block)).toBeNull();
    const html = body(doc([block]));
    expect(html).toContain('ff-is-na');
    expect(html).toContain('Not applicable to this project');
    expect(html).toContain('All 9 questions answered N/A.');
    expect(html).toContain('data-keep-together="1"');
  });

  test('refuses an unknown style and a rail past its end', () => {
    expect(valid({ type: 'formSection', style: 'G', number: '1', title: 'x' })).toMatch(/style/);
    expect(valid({ type: 'formSection', style: 'rail', number: '1', title: 'x', rail: { current: 6, total: 5 } })).toMatch(/rail/);
  });
});

describe('questions (FP.8, FP.9)', () => {
  test('a short answer is one unit with the number, question and guidance', () => {
    const block = question({ kind: 'text', paragraphs: ['Sleep and exams'] }, { guidance: 'As on your ID card.' });
    expect(valid(block)).toBeNull();
    const html = body(doc([block]));
    expect(html).toContain('data-flow-unit="formQuestion"');
    expect(html).toContain('<bdi>1.1</bdi>');
    expect(html).toContain('As on your ID card.');
    expect(html).toContain('data-keep-together="1"');
  });

  test('a long answer flows: the question keeps with its first paragraph and each paragraph carries "continued"', () => {
    const block = question({ kind: 'text', paragraphs: ['One.', 'Two.'], wordCount: '142 of 300 words' }, { continued: 'continued' });
    const html = body(doc([block]));
    expect(html).toContain('ff-q-head');
    expect(html).toContain('data-keep-with-next="1"');
    expect((html.match(/data-flow-unit="paragraph"/g) || []).length).toBe(2);
    expect((html.match(/data-flow-continued="/g) || []).length).toBe(2);
    expect(html).toContain('ff-cont');
    expect(html).toContain('142 of 300 words');
  });

  test('without the countAndContinued option the caller sends no continued line, and none rides along', () => {
    const html = body(doc([question({ kind: 'text', paragraphs: ['One.', 'Two.'] })]));
    expect(html).not.toContain('data-flow-continued');
  });

  test('every option prints with a drawn box, the chosen ones crossed (FP.3)', () => {
    const block = question({ kind: 'choices', options: [{ text: 'Students', chosen: true }, { text: 'Staff', chosen: false }] });
    expect(valid(block)).toBeNull();
    const html = body(doc([block]));
    expect((html.match(/class="ff-box"/g) || []).length).toBe(2);
    expect((html.match(/M3 3L9 9M9 3L3 9/g) || []).length).toBe(1);
    expect(html).toContain('<span class="ff-off" data-flow-item="1">');
  });

  test('Yes / No / N/A as a word with a mark, and in three columns', () => {
    const rows = [{ text: 'Consent form', value: 'yes', word: 'Yes' }, { text: 'JFDA', value: 'na', word: 'N/A' }, { text: 'Poster', value: null, word: 'Not answered' }];
    expect(valid(question({ kind: 'yesNoRows', rows }))).toBeNull();
    const words = body(doc([question({ kind: 'yesNoRows', rows })]));
    expect(words).toContain('ff-word ff-ok');
    expect(words).toContain('ff-word ff-muted');
    expect(words).toContain('<span class="ff-empty">Not answered</span>');
    const three = body(doc([question({ kind: 'yesNoRows', style: 'threeColumns', headers: { item: 'Item', yes: 'Yes', no: 'No', na: 'N/A' }, rows })]));
    expect(three).toContain('ff-yn3');
    expect((three.match(/ff-pick/g) || []).length).toBe(2);
  });

  test('an unanswered question prints what the caller says, in grey', () => {
    expect(body(doc([question({ kind: 'empty', text: 'Not answered' })]))).toContain('<div class="ff-a ff-empty">Not answered</div>');
  });

  test('facts and a table answer', () => {
    expect(valid(question({ kind: 'facts', items: [{ label: 'Name', value: 'Dr Ahmad Khalil' }, { label: 'Staff ID', value: 'EU-04418', mono: true }] }))).toBeNull();
    expect(valid(question({ kind: 'table', columns: ['Name', 'Email'], rows: [['Awss Haddad', 'a@example.edu']] }))).toBeNull();
    expect(body(doc([question({ kind: 'table', columns: ['Name'], rows: [['Awss Haddad']] })]))).toContain('<table class="ff-table">');
  });

  test('two columns put the question at the start and the answer at the end (engine need 11)', () => {
    const html = body(doc([question({ kind: 'yesNo', value: 'no', word: 'No' }, { layout: 'row' })]));
    expect(html).toContain('ff-q-row');
    expect(html).toContain('ff-row-a');
  });

  test('escapes what people typed', () => {
    const html = body(doc([question({ kind: 'text', paragraphs: ['<img src=x onerror=alert(1)>'] }, { text: '<b>q</b>' })]));
    expect(html).not.toContain('<img src=x');
    expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;');
    expect(html).toContain('&lt;b&gt;q&lt;/b&gt;');
  });

  test('refuses an unknown answer kind and a choice without its state', () => {
    expect(valid(question({ kind: 'slider' }))).toMatch(/kind/);
    expect(valid(question({ kind: 'choices', options: [{ text: 'A' }] }))).toMatch(/options/);
    expect(valid(question({ kind: 'yesNo', value: 'maybe', word: 'Maybe' }))).toMatch(/yes\|no\|na/);
  });
});

describe('blocks taller than a page (WHIT-1370)', () => {
  /*
   * AAU IRB's application was refused whole because question 9.1, twenty
   * Yes / No rows kept together, was taller than an A4 page. Only a browser
   * can prove the paginator now splits such a block (render-tier CI runs
   * scripts/check-flow-tall-blocks.js); this proves the markup gives it the
   * places to split, and that nothing else about the markup moved.
   */
  const items = (html) => (html.match(/data-flow-item="1"/g) || []).length;
  const { build, TEXTS } = require('../scripts/generate-flow-tall-blocks-fixture');

  test('a question is still kept together while it fits, and carries the line its continuation starts with', () => {
    const html = body(doc([question({ kind: 'yesNoRows', rows: [{ text: 'Consent form', value: 'yes', word: 'Yes · Attachment 1' }] }, { continued: '(continued)' })]));
    expect(html).toContain('data-keep-together="1"');
    expect(html).toContain('data-flow-tail-continued="');
    expect(html).toContain('ff-qn-continued');
    expect(html).toContain('(continued)');
    // Not on the question itself: only a continuation starts with it.
    expect(html).not.toContain(' data-flow-continued="');
  });

  test('without the caller’s word the continuation uses the document’s own "continued"', () => {
    const html = body(doc([question({ kind: 'facts', items: [{ label: 'Name', value: 'Dr Ahmad Khalil' }] })]));
    expect(html).toContain('data-flow-label=&quot;continued&quot;');
    const script = require('node:fs').readFileSync(require.resolve('../src/flow/assets/paginate.js'), 'utf8');
    expect(script).toContain("doc.labels[el.getAttribute('data-flow-label')]");
  });

  test('every row, option, fact, table row and approval is a place to split', () => {
    const rows = Array.from({ length: 20 }, (_, i) => ({ text: `Attachment ${i + 1}`, value: 'yes', word: `Yes · Attachment ${i + 1}` }));
    expect(items(body(doc([question({ kind: 'yesNoRows', rows })])))).toBe(20);
    const three = body(doc([question({ kind: 'yesNoRows', style: 'threeColumns', headers: { item: 'Item', yes: 'Yes', no: 'No', na: 'N/A' }, rows })]));
    expect(items(three)).toBe(20);
    // The column heads print again above the rows continued on the next page.
    expect((three.match(/class="ff-h" data-flow-repeat="1"/g) || []).length).toBe(4);
    expect(items(body(doc([question({ kind: 'choices', options: [{ text: 'A', chosen: true }, { text: 'B', chosen: false }] })])))).toBe(2);
    expect(items(body(doc([question({ kind: 'facts', items: [{ label: 'A', value: '1' }, { label: 'B', value: '2' }, { label: 'C', value: '3' }] })])))).toBe(3);
    const table = body(doc([question({ kind: 'table', columns: ['Drug', 'Dose'], rows: [['A', '5 mg'], ['B', '10 mg']] })]));
    expect(items(table)).toBe(2);
    expect(table).toContain('<thead data-flow-repeat="1">');
    const approvals = body(doc([{ type: 'approvals', title: 'Approvals', rows: [{ status: 'approved', word: 'Approved', name: 'Awss Haddad' }, { status: 'approved', word: 'Approved', name: 'Ahmad Khalil' }], notCounted: 'Not counted: Emad Nasser' }]));
    expect(items(approvals)).toBe(3);
    expect(items(body(doc([{ type: 'notice', text: 'Read before you start.' }])))).toBe(1);
  });

  test('lists, fields, facts and exam choices split between their items too', () => {
    expect(items(body(doc([{ type: 'list', items: ['One', 'Two', 'Three'] }])))).toBe(3);
    expect(items(body(doc([{ type: 'fields', fields: [{ label: 'Name' }, { label: 'Date' }] }])))).toBe(2);
    expect(items(body(doc([{ type: 'facts', items: [{ label: 'A', value: '1' }, { label: 'B', value: '2' }] }])))).toBe(2);
    expect(items(body(doc([{ type: 'question', text: 'Pick one', choices: { items: [{ label: 'a', text: 'Yes' }, { label: 'b', text: 'No' }] } }])))).toBe(2);
  });

  test('a long single answer in two columns flows like any long answer instead of being one unit', () => {
    const long = 'word '.repeat(200).trim();
    const html = body(doc([question({ kind: 'text', paragraphs: [long] }, { layout: 'row', continued: '(continued)' })]));
    expect(html).not.toContain('ff-q-row');
    expect(html).toContain('ff-q-head');
    expect(html).toContain('data-flow-unit="paragraph"');
    // A short one keeps its two columns; the question column is kept empty on a continuation.
    const short = body(doc([question({ kind: 'yesNo', value: 'no', word: 'No' }, { layout: 'row' })]));
    expect(short).toContain('<div class="ff-row-q" data-flow-slot="1">');
  });

  test('the paginator splits a unit that fits no page instead of refusing it', () => {
    const script = require('node:fs').readFileSync(require.resolve('../src/flow/assets/paginate.js'), 'utf8');
    expect(script).toContain('var mustSplit = tallerThanPage || isFirstOnPage;');
    expect(script).toContain('function splitItems(node, container, limit, firstOnPage)');
    expect(script).toContain('function splitTextCells(item, limit)');
    expect(script).toContain('function scaleImagesToFit(node, limit)');
  });

  test('the regression fixture is a valid payload in both directions, with every long text numbered', () => {
    for (const direction of ['ltr', 'rtl']) {
      const payload = build({ direction });
      expect(validateFlowPayload(payload)).toBeNull();
      const text = JSON.stringify(payload);
      for (const [prefix, count] of Object.entries(TEXTS)) {
        expect(text).toContain(`${prefix}${String(count).padStart(4, '0')}`);
      }
    }
  });
});

describe('signatures (FP.7)', () => {
  const drawing = { viewBox: [200, 60], paths: ['M10 40 C 40 10, 60 10, 80 40 L 190 35'] };

  test('confirmed in Whity beside the drawn signature, as path data', () => {
    const block = { type: 'signatureRow', name: 'Dr Ahmad Khalil', role: 'Principal investigator', confirmed: 'Confirmed in Whity · 2 Nov 2026, 09:12', drawing };
    expect(valid(block)).toBeNull();
    const html = body(doc([block]));
    expect(html).toContain('<path d="M10 40 C 40 10, 60 10, 80 40 L 190 35"/>');
    expect(html).toContain('Confirmed in Whity · 2 Nov 2026, 09:12');
  });

  test('none drawn: a dashed line and the caller\'s words', () => {
    const html = body(doc([{ type: 'signatureRow', name: 'Awss Haddad', noDrawing: 'No drawn signature' }]));
    expect(html).toContain('ff-pad ff-dash');
    expect(html).toContain('No drawn signature');
  });

  test('confirmed only has no pad; paper lines add lines to sign', () => {
    expect(body(doc([{ type: 'signatureRow', name: 'A', style: 'confirmedOnly', drawing }]))).not.toContain('ff-pad');
    expect(body(doc([{ type: 'signatureRow', name: 'A', style: 'paper', paper: { signature: 'Signature', date: 'Date' } }]))).toContain('ff-siglines');
  });

  test('refuses anything but path data', () => {
    expect(valid({ type: 'signatureRow', name: 'A', drawing: { viewBox: [200, 60], paths: ['M0 0"/><script>alert(1)</script>'] } })).toMatch(/paths/);
    expect(valid({ type: 'signatureRow', name: 'A', drawing: { viewBox: [0, 60], paths: ['M0 0'] } })).toMatch(/viewBox/);
  });
});

describe('decision, approvals and office details (FP.5, FP.6)', () => {
  test('the decision line carries the status and the office details', () => {
    const block = { type: 'decisionLine', status: 'approved', word: 'Approved', items: [{ label: 'Reference', value: 'REC-EU 14/3/2026-27', mono: true }], note: 'Full decision on page 6' };
    expect(valid(block)).toBeNull();
    const html = body(doc([block]));
    expect(html).toContain('ff-word ff-ok');
    expect(html).toContain('<span class="ff-mono">REC-EU 14/3/2026-27</span>');
  });

  test('the decision block draws the verification QR', () => {
    const block = { type: 'decisionBlock', status: 'conditional', word: 'Conditionally approved', rows: [{ label: 'Conditions', value: 'None' }], qr: { value: 'https://app.whity.dev/verify/abc', caption: 'Scan to verify' } };
    expect(valid(block)).toBeNull();
    const html = body(doc([block]));
    expect(html).toContain('ff-dec');
    expect(html).toContain('<svg');
    expect(html).toContain('ff-warn');
  });

  test('approvals as a list with the "Not counted" line, or as cards', () => {
    const rows = [{ status: 'approved', word: 'Approved', name: 'Prof. Amro Saleh', role: 'Chair', at: '12 Nov 2026, 10:14' }];
    const list = { type: 'approvals', title: 'Committee approvals', rows, notCounted: 'Not counted: Dr Emad Nasser, the project\'s co-supervisor.' };
    expect(valid(list)).toBeNull();
    const html = body(doc([list]));
    expect(html).toContain('ff-appr');
    expect(html).toContain('Not counted: Dr Emad Nasser');
    expect(body(doc([{ type: 'approvals', style: 'cards', rows }]))).toContain('ff-sigcard');
  });

  test('the office strip holds one to four fields', () => {
    expect(valid({ type: 'officeStrip', title: 'For office use', items: [{ label: 'Received', value: '2 Nov 2026' }] })).toBeNull();
    expect(valid({ type: 'officeStrip', items: Array(5).fill({ label: 'a', value: 'b' }) })).toMatch(/1-4/);
  });

  test('refuses an unknown status', () => {
    expect(valid({ type: 'decisionLine', status: 'maybe', word: 'Maybe' })).toMatch(/status/);
  });
});

describe('letterhead layouts (3.4)', () => {
  test('centred and bilingual', () => {
    const centred = { type: 'letterhead', layout: 'centred', start: { name: 'Example University', lines: ['REC'] } };
    expect(valid(centred)).toBeNull();
    expect(body(doc([centred]))).toContain('flow-letterhead-centred');
    const bilingual = { type: 'letterhead', layout: 'bilingual', start: { name: 'Example University' }, alt: { name: 'جامعة المثال', lines: ['لجنة أخلاقيات البحث العلمي'] } };
    expect(valid(bilingual)).toBeNull();
    const html = body(doc([bilingual]));
    expect(html).toContain('flow-letterhead-bilingual');
    expect(html).toContain('dir="rtl" lang="ar"');
    expect(valid({ type: 'letterhead', layout: 'diagonal' })).toMatch(/layout/);
  });
});

describe('the footer (FP.10)', () => {
  test('carries the client\'s marks and a small QR drawn on the server', () => {
    const payload = doc([{ type: 'paragraph', text: 'x' }], { footer: { start: 'REC · {{page}} of {{pages}}', end: { marks: [{ text: 'ISO 9001:2015' }], qr: { value: 'https://app.whity.dev/verify/abc' } } } });
    expect(validateFlowPayload(payload)).toBeNull();
    const footer = boot(payload).footer;
    expect(footer.end.marks).toEqual([{ text: 'ISO 9001:2015' }]);
    expect(footer.end.qr.svg).toContain('<svg');
    expect(footer.end.qr.value).toBeUndefined();
  });

  test('refuses more than three marks and a mark from the web', () => {
    expect(validateFlowPayload(doc([], { footer: { end: { marks: Array(4).fill({ text: 'x' }) } } }))).toMatch(/up to 3/);
    expect(validateFlowPayload(doc([{ type: 'paragraph', text: 'x' }], { footer: { end: { marks: [{ image: { src: 'https://example.com/iso.png' } }] } } }))).toMatch(/image/);
  });
});

describe('a cover in front (coverPages): the approval certificate', () => {
  const cover = [{ type: 'heading', level: 1, unnumbered: true, text: 'Certificate of approval' }, { type: 'pageBreak' }, { type: 'paragraph', text: 'Printout' }];

  test('is accepted with a page break after it, and reaches the paginator', () => {
    const payload = doc(cover, { coverPages: 1, footer: { start: 'Page {{page}} of {{pages}}' } });
    expect(validateFlowPayload(payload)).toBeNull();
    expect(normaliseFlowDocument(payload).coverPages).toBe(1);
    expect(boot(payload).coverPages).toBe(1);
  });

  test('a document without one prints as before', () => {
    expect(normaliseFlowDocument(doc(cover)).coverPages).toBe(0);
    expect(boot(doc(cover)).coverPages).toBe(0);
  });

  test('refuses a cover with no page break, more than two pages, or beside generated front matter', () => {
    expect(validateFlowPayload(doc([cover[0], cover[2]], { coverPages: 1 }))).toMatch(/pageBreak after the cover/);
    expect(validateFlowPayload(doc(cover, { coverPages: 3 }))).toMatch(/coverPages/);
    expect(validateFlowPayload(doc(cover, { coverPages: 1.5 }))).toMatch(/coverPages/);
    expect(validateFlowPayload(doc(cover, { coverPages: 1, frontMatter: [{ kind: 'contents' }] }))).toMatch(/cannot be used together/);
    expect(validateFlowPayload(doc(cover, { coverPages: 0, frontMatter: [{ kind: 'contents' }] }))).toBeNull();
  });

  test('the paginator leaves the cover without bands, numbers from the page after it, and refuses a cover that runs over', () => {
    const { readFileSync } = require('node:fs');
    const script = readFileSync(require.resolve('../src/flow/assets/paginate.js'), 'utf8');
    expect(script).toContain('var cover = Math.min(doc.coverPages || 0, pages.length);');
    expect(script).toContain('var pageNumber = i + 1 - cover;');
    expect(script).toMatch(/the cover runs over/);
  });

  test('the largest certificate core sends validates in both directions', () => {
    const { build } = require('../scripts/generate-flow-cover-fixture');
    for (const direction of ['ltr', 'rtl']) {
      const payload = build({ direction, variant: 'fit' });
      expect(validateFlowPayload(payload)).toBeNull();
      expect(payload.coverPages).toBe(1);
    }
  });
});
