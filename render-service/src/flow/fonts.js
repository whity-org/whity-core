'use strict';

/**
 * The document faces the image doesn't ship (WHIT-733 part 2): Readex Pro 600
 * for document titles (3.1, E.35) and Geist Mono for codes (3.4), the same
 * OFL-licensed files and the same @font-face rules the app uses
 * (packages/tokens/generated/fonts.css). The body face, Noto Sans and Noto
 * Sans Arabic, comes from the image's fonts-noto-core.
 *
 * Embedded as data URIs: the page is built from a string, so a file: or
 * http: font URL wouldn't load, and the PDF must not depend on a network.
 * Read once at module load. The files come from RENDER_FONT_DIR (the image
 * copies them to /app/fonts), else packages/tokens/fonts in the repository.
 * A missing file is skipped with a warning, and the stacks fall back to the
 * body face and DejaVu Sans Mono as they did before.
 */

const fs = require('fs');
const path = require('path');

// The Arabic range of Readex Pro, as packages/tokens declares it.
const ARABIC_RANGE = 'U+0600-06FF,U+0750-077F,U+0870-088E,U+0890-0891,U+0897-08E1,U+08E3-08FF,U+200C-200E,U+2010-2011,U+204F,U+2E41,U+FB50-FDFF,U+FE70-FE74,U+FE76-FEFC';

// In this order, as packages/tokens declares them: the Arabic face after the
// Latin one, so its unicode-range wins for Arabic text.
const FACES = [
  { family: 'Geist Mono', weight: 400, file: 'geist-mono-400.woff2' },
  { family: 'Geist Mono', weight: 500, file: 'geist-mono-500.woff2' },
  { family: 'Readex Pro', weight: 600, file: 'readex-pro-latin-600.woff2' },
  { family: 'Readex Pro', weight: 600, file: 'readex-pro-arabic-600.woff2', range: ARABIC_RANGE },
];

function fontDir() {
  return process.env.RENDER_FONT_DIR || path.resolve(__dirname, '../../../packages/tokens/fonts');
}

/**
 * The @font-face rules for every face whose file is present.
 *
 * @param {string} [dir]
 * @returns {{css: string, missing: string[]}}
 */
function fontFaces(dir = fontDir()) {
  const rules = [];
  const missing = [];
  for (const face of FACES) {
    let bytes;
    try {
      bytes = fs.readFileSync(path.join(dir, face.file));
    } catch {
      missing.push(face.file);
      continue;
    }
    rules.push(
      '@font-face {\n'
      + `  font-family: '${face.family}';\n`
      + '  font-style: normal;\n'
      + `  font-weight: ${face.weight};\n`
      + '  font-display: block;\n'
      + `  src: url(data:font/woff2;base64,${bytes.toString('base64')}) format('woff2');\n`
      + (face.range ? `  unicode-range: ${face.range};\n` : '')
      + '}',
    );
  }

  return { css: rules.join('\n'), missing };
}

const loaded = fontFaces();
if (loaded.missing.length > 0) {
  console.warn(`[flow] document fonts not found in ${fontDir()}, falling back: ${loaded.missing.join(', ')}`);
}

module.exports = { FONT_FACES: loaded.css, fontFaces, FACES };
