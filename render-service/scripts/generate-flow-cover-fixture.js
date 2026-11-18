#!/usr/bin/env node
'use strict';

/**
 * An approval certificate in front of a form printout (`coverPages`): the
 * certificate Whity prints when a route approves a form response, at the
 * largest size core ever sends (every text at its cap, four people, six
 * approvals, the QR), followed by three pages of printout.
 *
 * Only a real Chromium can show that such a certificate fits its page and
 * that the pages after it number from 1. `scripts/verify-flow-cover.js`
 * reads both back out of the PDF.
 *
 *   node generate-flow-cover-fixture.js --direction rtl --out cover-rtl.json
 *   node generate-flow-cover-fixture.js --direction ltr --variant overflow --out over.json
 *
 * The `overflow` variant has a certificate far longer than a page: the render
 * must refuse it (422) rather than print a cover that runs on.
 *
 * The caps mirror src/Core/Form/Print/ApprovalCertificate.php.
 */

const fs = require('node:fs');

const CAPS = { title: 80, subject: 160, conditions: 150, rows: 4, rowValue: 64, approvals: 6, stage: 40, name: 30, outcome: 24 };

function parseArgs(argv) {
  const args = { direction: 'ltr', variant: 'fit', out: null };
  for (let i = 2; i < argv.length; i += 1) {
    if (argv[i] === '--direction') args.direction = argv[i + 1];
    if (argv[i] === '--variant') args.variant = argv[i + 1];
    if (argv[i] === '--out') args.out = argv[i + 1];
  }
  return args;
}

/** A 1x1 PNG: the workspace's crest. */
const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/** Text of exactly `n` characters made of `word`. */
const fill = (word, n) => (word + ' ').repeat(Math.ceil(n / (word.length + 1))).slice(0, n).trim();

const WORDS = {
  rtl: {
    uni: 'جامعة المثال', board: 'مجلس أخلاقيات البحث العلمي', title: 'شهادة الموافقة الأخلاقية',
    certify: 'تشهد هذه الوثيقة بأنّ الطلب المبيّن أدناه قد رُوجع من قِبَل مجلس أخلاقيات البحث العلمي في جامعة المثال وتمّت الموافقة عليه وفق الشروط المذكورة. امسح الرمز للتحقق من صحة هذه الشهادة.',
    word: 'دراسة', facts: ['المرجع', 'القرار', 'تاريخ القرار', 'الشروط'], decision: 'موافقة مشروطة', person: 'الباحث', inFull: '(النص الكامل في القرار في الصفحة الأخيرة.)',
    heads: ['الموافقات'], scan: 'امسح للتحقق', stage: 'لجنة الكلية', outcome: 'توصية بالموافقة',
    para: 'يصف هذا القسم منهجية البحث وأدواته وطريقة جمع البيانات وتحليلها.',
  },
  ltr: {
    uni: 'Example University', board: 'Institutional Review Board (IRB)', title: 'Certificate of Ethics Approval',
    certify: 'This is to certify that the Institutional Review Board of Example University has reviewed the application below and approved it, subject to the conditions stated. Scan the code to check that this certificate is genuine.',
    word: 'Study', facts: ['Reference', 'Decision', 'Decided', 'Conditions'], decision: 'Conditionally approved', person: 'Researcher', inFull: '(In full in the decision on the last page.)',
    heads: ['Approvals'], scan: 'Scan to verify', stage: 'Faculty Research Ethics Committee', outcome: 'Recommend approval',
    para: 'This section describes the research design, its instruments and how the data will be collected and analysed.',
  },
};

function build({ direction, variant }) {
  const w = WORDS[direction === 'rtl' ? 'rtl' : 'ltr'];
  const over = variant === 'overflow';
  const cover = [
    { type: 'letterhead', layout: 'centred', start: { name: w.uni, lines: [w.board], image: { src: PNG, alt: w.uni, heightMm: 14 } } },
    { type: 'heading', level: 1, unnumbered: true, inContents: false, text: `COVER-SENTINEL ${w.title}`.slice(0, CAPS.title) },
    { type: 'paragraph', text: w.certify },
    { type: 'heading', level: 3, unnumbered: true, inContents: false, text: fill(w.word, CAPS.subject) },
    {
      type: 'decisionBlock', status: 'conditional', word: w.decision,
      rows: [
        { label: w.facts[0], value: 'IRB-AAU 1/1/2026-2027', mono: true },
        { label: w.facts[2], value: '18 Nov 2026' },
        { label: w.facts[3], value: `${fill(w.word, CAPS.conditions)} ${w.inFull}` },
        ...Array.from({ length: CAPS.rows }, (_, i) => ({ label: `${w.person} ${i + 1}`, value: fill(w.word, CAPS.rowValue) })),
      ],
      qr: { value: 'https://app.whity.dev/verify/7f82e40c5f9a38c52b1148ffa923368314259e96a1995abaa8ee204f0b7cf43d', caption: w.scan, reference: '7F82-E40C-5F9A' },
    },
    {
      type: 'approvals', title: w.heads[0],
      rows: Array.from({ length: CAPS.approvals }, () => ({ status: 'approved', word: fill(w.outcome, CAPS.outcome), name: fill(w.word, CAPS.name), role: fill(w.stage, CAPS.stage), at: '18 Nov 2026, 13:14' })),
    },
  ];
  if (over) {
    for (let i = 0; i < 40; i += 1) cover.push({ type: 'paragraph', text: w.para });
  }
  const body = [{ type: 'letterhead', layout: 'centred', start: { name: w.uni } }];
  for (let i = 0; i < 90; i += 1) body.push({ type: 'paragraph', text: `${w.para} ${i + 1}` });

  return {
    title: w.title,
    direction: direction === 'rtl' ? 'rtl' : 'ltr',
    lang: direction === 'rtl' ? 'ar' : 'en',
    page: { widthMm: 210, heightMm: 297, margin: { topMm: 22, rightMm: 16, bottomMm: 22, leftMm: 16 } },
    theme: { accent: '#1f3a93' },
    coverPages: 1,
    // Latin tokens in both directions, so the check reads them the same way.
    header: { start: 'HEAD-SENTINEL', fromPage: 2 },
    footer: { start: 'PG {{page}}/{{pages}}' },
    content: [...cover, { type: 'pageBreak' }, ...body],
  };
}

if (require.main === module) {
  const args = parseArgs(process.argv);
  const json = JSON.stringify(build(args));
  if (args.out) fs.writeFileSync(args.out, json);
  else process.stdout.write(json);
}

module.exports = { build, CAPS };
