#!/usr/bin/env node
'use strict';

/**
 * Checks, in a REAL Chromium, that blocks taller than a page are split or
 * scaled rather than refusing the whole document (WHIT-1370).
 *
 * The Jest suite proves the markup carries its split points; only a browser
 * can prove the paginator uses them. For the fixture in
 * generate-flow-tall-blocks-fixture.js, in both directions, it asserts:
 *
 *   1. The paginator reports no problems (a refused render would have them).
 *   2. No page's content runs past its content box.
 *   3. Every numbered word of every long text is printed exactly once, and in
 *      order: what was split was neither lost nor repeated.
 *   4. Every row, option, fact, approval and list item is printed exactly once.
 *   5. A form question that ran onto a new page starts it with its "continued"
 *      line, and a split table repeats its header row.
 *   6. The tall image was scaled into one page.
 *
 * Usage: node scripts/check-flow-tall-blocks.js
 * Exit code 0 only when every assertion holds in both directions.
 */

const loadPuppeteer = async () => {
  const mod = await import('puppeteer-core');

  return mod.default ?? mod;
};

const { normaliseFlowDocument } = require('../src/flow/document');
const { buildFlowHtml } = require('../src/flow/html');
const { build, TEXTS } = require('./generate-flow-tall-blocks-fixture');

/** Runs in the page: what was printed, page by page. */
function measure() {
  const pages = Array.prototype.slice.call(document.querySelectorAll('#flow-body-pages .flow-page'));
  return {
    problems: (window.__FLOW_RESULT__ && window.__FLOW_RESULT__.problems) || [],
    pageCount: window.__FLOW_RESULT__ ? window.__FLOW_RESULT__.pageCount : 0,
    pages: pages.map((page) => {
      const box = page.querySelector('.flow-page-content');
      const rect = box.getBoundingClientRect();
      let lowest = rect.top;
      Array.prototype.forEach.call(box.children, (child) => {
        lowest = Math.max(lowest, child.getBoundingClientRect().bottom);
      });
      const first = box.firstElementChild;
      const carries = Array.prototype.map.call(box.querySelectorAll('.ff-q-carry'), (c) => c.textContent.trim());
      // Only the answers, not the carried "continued" line above them.
      const clone = box.cloneNode(true);
      Array.prototype.forEach.call(clone.querySelectorAll('.ff-q-carry'), (c) => c.parentNode.removeChild(c));
      const images = Array.prototype.map.call(box.querySelectorAll('img'), (img) => {
        const r = img.getBoundingClientRect();
        return { top: r.top - rect.top, bottom: r.bottom - rect.top, height: r.height };
      });
      const tableTails = Array.prototype.map.call(box.querySelectorAll('[data-flow-split="tail"] thead'), (h) => h.textContent.trim());
      return {
        units: Array.prototype.map.call(box.children, (c) => `${c.getAttribute('data-flow-unit')}:${c.getAttribute('data-flow-split') || ''}:${c.textContent.slice(0, 30)}`),
        height: rect.height,
        overrun: lowest - rect.bottom,
        // Text nodes joined with a space, so "a0900" and a following "1.2" stay apart.
        text: (() => {
          const parts = [];
          const walker = document.createTreeWalker(clone, NodeFilter.SHOW_TEXT, null);
          for (let n = walker.nextNode(); n; n = walker.nextNode()) parts.push(n.data);
          return parts.join(' ');
        })(),
        firstUnit: first ? first.getAttribute('data-flow-unit') : null,
        carries,
        images,
        tableTails,
      };
    }),
  };
}

function tokensIn(text, prefix) {
  // No leading \b: text nodes join with no space between them.
  const re = new RegExp(`${prefix}\\d{4}(?!\\d)`, 'g');
  return text.match(re) || [];
}

function assertResult(result, direction, failures) {
  const tag = `[${direction}]`;
  if (result.problems.length > 0) {
    failures.push(`${tag} the paginator reported problems: ${JSON.stringify(result.problems)}`);
  }
  result.pages.forEach((page, i) => {
    if (page.overrun > 1) {
      failures.push(`${tag} page ${i + 1} runs ${page.overrun.toFixed(1)}px past its content box`);
    }
  });
  const all = result.pages.map((p) => p.text).join('\n');

  // 3. Long texts: every numbered word once, in order.
  for (const [prefix, count] of Object.entries(TEXTS)) {
    const found = tokensIn(all, prefix);
    const expected = [];
    for (let i = 1; i <= count; i += 1) expected.push(`${prefix}${String(i).padStart(4, '0')}`);
    if (found.join(' ') !== expected.join(' ')) {
      const missing = expected.filter((w) => !found.includes(w)).slice(0, 5);
      failures.push(`${tag} text "${prefix}": printed ${found.length} of ${count} words in order (first missing: ${missing.join(', ') || 'none; order or repeats differ'})`);
    }
  }
  // 4. Items: each exactly once.
  for (const [prefix, count] of [['r', 60], ['t', 70], ['o', 60], ['f', 24], ['p', 40], ['l', 30], ['k', 24]]) {
    const found = tokensIn(all, prefix);
    const unique = new Set(found);
    if (found.length !== count || unique.size !== count) {
      failures.push(`${tag} items "${prefix}": printed ${found.length} (${unique.size} different), expected ${count} once each`);
    }
  }
  if (!all.includes('zEND')) {
    failures.push(`${tag} the last paragraph is missing`);
  }
  // 5. Continuations say what they continue.
  const carries = result.pages.flatMap((p) => p.carries);
  for (const number of ['1.1', '1.3', '2.1', '2.2', '2.3']) {
    if (!carries.some((c) => c.startsWith(number) && /\S+\s*\S/.test(c))) {
      failures.push(`${tag} question ${number} ran onto a new page without its "continued" line (carries: ${JSON.stringify(carries.slice(0, 8))})`);
    }
  }
  if (!carries.some((c) => c.startsWith('2.1') && /continued|تابع/.test(c))) {
    failures.push(`${tag} the continued line of 2.1 has no "continued" word (it uses the document's own label)`);
  }
  if (!result.pages.some((p) => p.tableTails.length > 0)) {
    failures.push(`${tag} no split table repeated its header row`);
  }
  // 6. The tall image fits one page.
  const images = result.pages.flatMap((p, i) => p.images.map((img) => ({ ...img, page: i + 1, pageHeight: p.height })));
  if (images.length === 0) {
    failures.push(`${tag} the tall image was not printed`);
  }
  images.forEach((img) => {
    if (img.bottom > img.pageHeight + 1) {
      failures.push(`${tag} the image on page ${img.page} runs past the page (${img.bottom.toFixed(0)} > ${img.pageHeight.toFixed(0)})`);
    }
  });
}

async function main() {
  const puppeteer = await loadPuppeteer();
  const browser = await puppeteer.launch({
    executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '/usr/bin/chromium',
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  });

  const failures = [];
  const summary = {};
  try {
    for (const direction of ['ltr', 'rtl']) {
      const doc = normaliseFlowDocument(build({ direction }));
      const page = await browser.newPage();
      await page.setContent(buildFlowHtml(doc), { waitUntil: 'load', timeout: 60000 });
      await page.waitForFunction('window.__FLOW_READY__ === true', { timeout: 120000 });
      const error = await page.evaluate(() => window.__FLOW_ERROR__ || null);
      if (error) {
        failures.push(`[${direction}] pagination threw in the page: ${error}`);
        await page.close();
        continue;
      }
      const result = await page.evaluate(measure);
      assertResult(result, direction, failures);
      summary[direction] = { pages: result.pageCount, problems: result.problems.length };
      if (process.env.TALL_DEBUG) console.log(JSON.stringify(result.pages.map((p) => p.units), null, 1));
      await page.close();
    }
  } finally {
    await browser.close();
  }

  /* eslint-disable no-console */
  console.log(JSON.stringify(summary, null, 2));
  if (failures.length > 0) {
    console.error(`\n${failures.length} failure(s):`);
    for (const failure of failures.slice(0, 40)) console.error(`  ${failure}`);
    console.error('RESULT: FAIL');
    process.exit(1);
  }
  console.log('RESULT: PASS');
  /* eslint-enable no-console */
}

main().catch((err) => {
  // eslint-disable-next-line no-console
  console.error(err && err.stack ? err.stack : err);
  process.exit(2);
});
