'use strict';

/**
 * The document faces (WHIT-733 part 2): Readex Pro for titles and Geist Mono
 * for codes, embedded in every flowing page as the app declares them. Whether
 * Chromium then prints with them is proved on a real PDF in the render-tier CI
 * job ("Flowing render embeds Readex Pro and Geist Mono").
 */

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { FONT_FACES, fontFaces, FACES } = require('../src/flow/fonts');
const { buildFlowHtml } = require('../src/flow/html');
const { normaliseFlowDocument } = require('../src/flow/document');

describe('document fonts', () => {
  test('every face is found in the repository and embedded as woff2', () => {
    const { css, missing } = fontFaces();

    expect(missing).toEqual([]);
    expect(css.match(/@font-face/g)).toHaveLength(FACES.length);
    expect(css).toContain("font-family: 'Readex Pro';");
    expect(css).toContain("font-family: 'Geist Mono';");
    expect(css).toContain("src: url(data:font/woff2;base64,d09GMg"); // the woff2 signature, wOF2
  });

  test('the Arabic face of Readex Pro is limited to Arabic, and comes after the Latin one', () => {
    const { css } = fontFaces();
    const latin = css.indexOf('font-weight: 600;');
    const arabic = css.indexOf('unicode-range: U+0600-06FF');

    expect(latin).toBeGreaterThan(-1);
    expect(arabic).toBeGreaterThan(latin);
    expect(css.match(/unicode-range/g)).toHaveLength(1);
  });

  test('the faces match the app’s own declarations', () => {
    const tokens = fs.readFileSync(path.resolve(__dirname, '../../packages/tokens/generated/fonts.css'), 'utf8');
    for (const face of FACES) {
      expect(tokens).toContain(`url('../fonts/${face.file}')`);
    }
    expect(tokens).toContain(FACES.find((f) => f.range).range);
  });

  test('a missing file is skipped, not fatal', () => {
    const empty = fs.mkdtempSync(path.join(os.tmpdir(), 'whity-fonts-'));
    const { css, missing } = fontFaces(empty);

    expect(css).toBe('');
    expect(missing).toEqual(FACES.map((f) => f.file));
  });

  test('every flowing page carries the faces ahead of the stylesheet that names them', () => {
    const html = buildFlowHtml(normaliseFlowDocument({
      page: { preset: 'a4' },
      content: [{ type: 'heading', level: 1, text: 'Order confirmation' }],
    }));

    expect(FONT_FACES).not.toBe('');
    expect(html).toContain(FONT_FACES);
    expect(html.indexOf(FONT_FACES)).toBeLessThan(html.indexOf('--flow-display:'));
  });
});
