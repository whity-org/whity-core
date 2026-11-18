#!/usr/bin/env node
'use strict';

/**
 * Checks a PDF made from scripts/generate-flow-cover-fixture.js against
 * itself, never asking the renderer:
 *
 *   1. the cover (COVER-SENTINEL) is on page 1 and nowhere else, so it fit
 *      its one page;
 *   2. page 1 carries no running header or footer;
 *   3. the pages after it number from 1 ("PG 1/n" on physical page 2 … "PG n/n"
 *      on the last), and the running header starts on its second page, as the
 *      printout's `fromPage: 2` asks, counted after the cover.
 *
 *   node scripts/verify-flow-cover.js --pdf cover.pdf
 *
 * Exit code 0 only when every check passes.
 */

const { pageLines } = require('./verify-flow-pdf');

async function loadPdf(file) {
  const fs = require('node:fs');
  const pdfjs = await import('pdfjs-dist/legacy/build/pdf.mjs');
  return pdfjs.getDocument({ data: new Uint8Array(fs.readFileSync(file)), useSystemFonts: false, isEvalSupported: false }).promise;
}

async function verify(file) {
  const doc = await loadPdf(file);
  const problems = [];
  const pages = doc.numPages;
  const numbered = pages - 1;
  for (let p = 1; p <= pages; p += 1) {
    const text = (await pageLines(doc, p)).map((l) => l.text).join('\n');
    const hasCover = text.includes('COVER-SENTINEL');
    const footer = text.match(/PG\s*(\d+)\s*\/\s*(\d+)/);
    const header = text.includes('HEAD-SENTINEL');
    if (p === 1) {
      if (!hasCover) problems.push('page 1 is not the cover');
      if (footer) problems.push(`the cover carries a footer (${footer[0]})`);
      if (header) problems.push('the cover carries a running header');
      continue;
    }
    if (hasCover) problems.push(`the cover runs onto page ${p}`);
    const expected = `${p - 1}/${numbered}`;
    if (!footer || `${footer[1]}/${footer[2]}` !== expected) {
      problems.push(`page ${p} should read PG ${expected}, reads ${footer ? footer[0] : 'nothing'}`);
    }
    if (header !== p - 1 >= 2) {
      problems.push(`page ${p} ${header ? 'carries' : 'lacks'} the running header`);
    }
  }
  if (pages < 3) problems.push(`expected a cover and at least two printout pages, got ${pages}`);
  return { pages, problems };
}

if (require.main === module) {
  const at = process.argv.indexOf('--pdf');
  verify(process.argv[at + 1]).then(({ pages, problems }) => {
    if (problems.length > 0) {
      console.error(`cover check failed (${pages} pages):\n  ${problems.join('\n  ')}`);
      process.exit(1);
    }
    console.log(`cover check passed: 1 cover page and ${pages - 1} numbered pages`);
  }).catch((err) => {
    console.error(err);
    process.exit(1);
  });
}

module.exports = { verify };
