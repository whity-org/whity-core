'use strict';

/**
 * The blocks a FORM PRINTOUT needs (design 3.4, FP.1-FP.12; the specs are the
 * `DocFormBlocks` card, the engine needs `handoff/print/FORM-PRINTOUTS.md`).
 *
 * A form response prints as a record, and with an approval route it is also
 * the certificate. The caller (core's form printer) has already decided every
 * word: which options were chosen, what "Not answered" says, who is "Not
 * counted". This file only checks the shapes and draws them, so the same
 * payload always prints the same page.
 *
 * What matters most:
 *
 * - NOTHING HERE CHANGES AN EXISTING BLOCK. These are new block types and new
 *   CSS classes; a payload that uses none of them prints exactly as before,
 *   which is what keeps every stored template's next print unchanged.
 * - EVERY CALLER STRING IS ESCAPED AND ISOLATED, as for every other block:
 *   answers are typed by the public.
 * - MARKS ARE DRAWN, NOT TYPED. The boxes (☒ / ☐), ticks and the Whity mark of
 *   section style B are inline SVG or CSS shapes, so they print as vectors and
 *   never depend on a font having the glyph.
 * - A DRAWN SIGNATURE IS PATH DATA, never an SVG document: an SVG from a caller
 *   could carry script or a remote reference; a list of `d` strings checked
 *   against the path grammar can't.
 */

const { escapeHtml, isolateForeignRuns } = require('./bidi');

/** FP.4: A number, B the Whity mark, C eyebrow, D rail, E tab, F plane. */
const SECTION_STYLES = new Set(['number', 'mark', 'eyebrow', 'rail', 'tab', 'plane']);
const ANSWER_KINDS = new Set(['text', 'empty', 'choices', 'yesNo', 'yesNoRows', 'facts', 'table']);
const YES_NO = new Set(['yes', 'no', 'na']);
const STATUSES = new Set(['approved', 'conditional', 'revisions', 'rejected', 'declined', 'waiting', 'recommended']);
const SIGNATURE_STYLES = new Set(['drawn', 'confirmedOnly', 'paper']);
const LETTERHEAD_LAYOUTS = new Set(['start', 'centred', 'bilingual']);

const MAX_PARAGRAPHS = 400;
const MAX_TEXT = 20000;
const MAX_OPTIONS = 60;
const MAX_ROWS = 200;
const MAX_FACT_ITEMS = 24;
const MAX_TABLE_COLUMNS = 8;
const MAX_RAIL = 30;
const MAX_STROKES = 200;
const MAX_STROKE_LENGTH = 20000;
const MAX_APPROVERS = 40;
const MAX_ITEMS = 8;
const MAX_QR_VALUE = 512;
/** An SVG path's `d`: commands and numbers only. */
const PATH_DATA = /^[MmLlHhVvCcSsQqTtZz0-9.,\s-]+$/;

const FORM_BLOCK_TYPES = [
  'formTitle',
  'formSection',
  'formQuestion',
  'notice',
  'signatureRow',
  'decisionLine',
  'decisionBlock',
  'approvals',
  'officeStrip',
];

function isPlainObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
function isString(value) {
  return typeof value === 'string';
}
function optionalString(value) {
  return value === undefined || typeof value === 'string';
}
function short(value, max) {
  return typeof value === 'string' && value.length <= max;
}
function stringItems(items, max) {
  return Array.isArray(items) && items.length <= max && items.every(isString);
}
function labelled(items, max) {
  return Array.isArray(items) && items.length <= max
    && items.every((it) => isPlainObject(it) && isString(it.label) && isString(it.value)
      && (it.mono === undefined || typeof it.mono === 'boolean')
      && (it.status === undefined || STATUSES.has(it.status)));
}

/* ----------------------------------------------------------------- checks */

function validateAnswer(a, at) {
  if (!isPlainObject(a) || !ANSWER_KINDS.has(a.kind)) {
    return `"${at}.kind" must be one of: ${[...ANSWER_KINDS].join(', ')}`;
  }
  switch (a.kind) {
    case 'text':
      if (!Array.isArray(a.paragraphs) || a.paragraphs.length === 0 || a.paragraphs.length > MAX_PARAGRAPHS
        || !a.paragraphs.every((p) => short(p, MAX_TEXT))) {
        return `"${at}.paragraphs" must be 1-${MAX_PARAGRAPHS} strings`;
      }
      if (!optionalString(a.wordCount)) return `"${at}.wordCount" must be a string`;
      if (a.mono !== undefined && typeof a.mono !== 'boolean') return `"${at}.mono" must be true or false`;
      return null;
    case 'empty':
      return isString(a.text) ? null : `"${at}.text" must say what an empty answer prints`;
    case 'choices':
      if (!Array.isArray(a.options) || a.options.length === 0 || a.options.length > MAX_OPTIONS
        || !a.options.every((o) => isPlainObject(o) && isString(o.text) && typeof o.chosen === 'boolean')) {
        return `"${at}.options" must be 1-${MAX_OPTIONS} {text, chosen}`;
      }
      return null;
    case 'yesNo':
      if (!YES_NO.has(a.value) || !isString(a.word)) return `"${at}" must be {value: yes|no|na, word}`;
      return null;
    case 'yesNoRows':
      if (a.style !== undefined && a.style !== 'word' && a.style !== 'threeColumns') {
        return `"${at}.style" must be word or threeColumns`;
      }
      if (!Array.isArray(a.rows) || a.rows.length === 0 || a.rows.length > MAX_ROWS
        || !a.rows.every((r) => isPlainObject(r) && isString(r.text) && (r.value === null || YES_NO.has(r.value)) && optionalString(r.word))) {
        return `"${at}.rows" must be 1-${MAX_ROWS} {text, value: yes|no|na|null, word}`;
      }
      if (a.headers !== undefined && (!isPlainObject(a.headers)
        || !['item', 'yes', 'no', 'na'].every((k) => optionalString(a.headers[k])))) {
        return `"${at}.headers" must be {item, yes, no, na} strings`;
      }
      return null;
    case 'facts':
      return labelled(a.items, MAX_FACT_ITEMS) && a.items.length > 0 ? null : `"${at}.items" must be 1-${MAX_FACT_ITEMS} {label, value}`;
    case 'table':
      if (!stringItems(a.columns, MAX_TABLE_COLUMNS) || a.columns.length === 0) {
        return `"${at}.columns" must be 1-${MAX_TABLE_COLUMNS} strings`;
      }
      if (!Array.isArray(a.rows) || a.rows.length > MAX_ROWS || !a.rows.every((r) => stringItems(r, MAX_TABLE_COLUMNS))) {
        return `"${at}.rows" must be up to ${MAX_ROWS} rows of strings`;
      }
      return null;
    default:
      return null;
  }
}

function validateDrawing(d, at) {
  if (!isPlainObject(d) || !Array.isArray(d.viewBox) || d.viewBox.length !== 2
    || !d.viewBox.every((n) => Number.isFinite(n) && n > 0 && n <= 10000)) {
    return `"${at}.viewBox" must be [width, height]`;
  }
  if (!Array.isArray(d.paths) || d.paths.length === 0 || d.paths.length > MAX_STROKES
    || !d.paths.every((p) => isString(p) && p.length <= MAX_STROKE_LENGTH && PATH_DATA.test(p))) {
    return `"${at}.paths" must be 1-${MAX_STROKES} SVG path strings`;
  }
  return null;
}

function validateQr(qr, at) {
  if (!isPlainObject(qr) || !isString(qr.value) || qr.value === '' || qr.value.length > MAX_QR_VALUE) {
    return `"${at}.value" must be 1-${MAX_QR_VALUE} characters`;
  }
  if (!optionalString(qr.caption) || !optionalString(qr.reference)) return `"${at}.caption" and "${at}.reference" must be strings`;
  return null;
}

/** A form block's own shape; null when it is fine. */
function validateFormPrintBlock(block, i) {
  const at = `content[${i}]`;
  switch (block.type) {
    case 'formTitle':
      return isString(block.title) && block.title !== '' && optionalString(block.byline) ? null : `"${at}" must be {title, byline?}`;
    case 'formSection': {
      if (block.style !== undefined && !SECTION_STYLES.has(block.style)) {
        return `"${at}.style" must be one of: ${[...SECTION_STYLES].join(', ')}`;
      }
      if (!isString(block.title) || !short(block.number ?? '', 8) || !optionalString(block.eyebrow) || !optionalString(block.note)) {
        return `"${at}" must be {number, title, eyebrow?, note?} strings`;
      }
      if (block.notApplicable !== undefined && (!isPlainObject(block.notApplicable)
        || !isString(block.notApplicable.label) || !optionalString(block.notApplicable.summary))) {
        return `"${at}.notApplicable" must be {label, summary?}`;
      }
      if (block.rail !== undefined && (!isPlainObject(block.rail)
        || !Number.isInteger(block.rail.total) || block.rail.total < 1 || block.rail.total > MAX_RAIL
        || !Number.isInteger(block.rail.current) || block.rail.current < 1 || block.rail.current > block.rail.total)) {
        return `"${at}.rail" must be {current, total} with 1 <= current <= total <= ${MAX_RAIL}`;
      }
      return null;
    }
    case 'formQuestion':
      if (!isString(block.text) || !short(String(block.number ?? ''), 12) || !optionalString(block.guidance)) {
        return `"${at}" must be {number, text, guidance?}`;
      }
      if (block.layout !== undefined && block.layout !== 'stacked' && block.layout !== 'row') {
        return `"${at}.layout" must be stacked or row`;
      }
      if (block.continued !== undefined && !isString(block.continued)) return `"${at}.continued" must be a string`;
      return validateAnswer(block.answer, `${at}.answer`);
    case 'notice':
      return optionalString(block.label) && isString(block.text) ? null : `"${at}" must be {label?, text}`;
    case 'signatureRow': {
      if (!isString(block.name) || !optionalString(block.role) || !optionalString(block.confirmed)
        || !optionalString(block.waiting) || !optionalString(block.noDrawing)) {
        return `"${at}" must be {name, role?, confirmed?, waiting?, noDrawing?} strings`;
      }
      if (block.style !== undefined && !SIGNATURE_STYLES.has(block.style)) {
        return `"${at}.style" must be one of: ${[...SIGNATURE_STYLES].join(', ')}`;
      }
      if (block.paper !== undefined && (!isPlainObject(block.paper) || !isString(block.paper.signature) || !optionalString(block.paper.date))) {
        return `"${at}.paper" must be {signature, date?}`;
      }
      return block.drawing === undefined ? null : validateDrawing(block.drawing, `${at}.drawing`);
    }
    case 'decisionLine':
      if (!STATUSES.has(block.status) || !isString(block.word) || !labelled(block.items || [], MAX_ITEMS) || !optionalString(block.note)) {
        return `"${at}" must be {status, word, items: [{label, value}], note?}`;
      }
      return null;
    case 'decisionBlock':
      if (!STATUSES.has(block.status) || !isString(block.word) || !labelled(block.rows || [], 12)) {
        return `"${at}" must be {status, word, rows: [{label, value}]}`;
      }
      return block.qr === undefined ? null : validateQr(block.qr, `${at}.qr`);
    case 'approvals':
      if (block.style !== undefined && block.style !== 'list' && block.style !== 'cards') {
        return `"${at}.style" must be list or cards`;
      }
      if (!optionalString(block.title) || !optionalString(block.notCounted)) return `"${at}.title" and "${at}.notCounted" must be strings`;
      if (!Array.isArray(block.rows) || block.rows.length > MAX_APPROVERS
        || !block.rows.every((r) => isPlainObject(r) && STATUSES.has(r.status) && isString(r.word) && isString(r.name)
          && optionalString(r.role) && optionalString(r.at))) {
        return `"${at}.rows" must be up to ${MAX_APPROVERS} {status, word, name, role?, at?}`;
      }
      return null;
    case 'officeStrip':
      if (!optionalString(block.title) || !labelled(block.items, 4) || block.items.length === 0) {
        return `"${at}" must be {title?, items: 1-4 {label, value}}`;
      }
      return null;
    default:
      return null;
  }
}

/** The 3.4 letterhead layouts (`letterhead.layout`), and the bilingual side. */
function validateLetterheadLayout(block, at, validateImage) {
  if (block.layout !== undefined && !LETTERHEAD_LAYOUTS.has(block.layout)) {
    return `"${at}.layout" must be one of: ${[...LETTERHEAD_LAYOUTS].join(', ')}`;
  }
  if (block.alt !== undefined) {
    const part = block.alt;
    if (!isPlainObject(part) || !optionalString(part.name) || (part.lines !== undefined && !stringItems(part.lines, 6))) {
      return `"${at}.alt" must be {name, lines}`;
    }
    if (part.image !== undefined) return validateImage(part.image, `${at}.alt.image`);
  }
  return null;
}

/* ---------------------------------------------------------------- drawing */

const t = (text, direction) => isolateForeignRuns(String(text), direction);

/** ☒ / ☐ as vectors (FP.3): 3.4 mm box, 0.25 mm rule. */
function box(chosen) {
  const cross = chosen ? '<path d="M3 3L9 9M9 3L3 9" stroke="currentColor" stroke-width="1.1" stroke-linecap="round"/>' : '';
  return `<svg class="ff-box" viewBox="0 0 12 12" aria-hidden="true"><rect x="0.6" y="0.6" width="10.8" height="10.8" rx="1" fill="none" stroke="currentColor" stroke-width="0.9"/>${cross}</svg>`;
}

/** The marks beside a decision: ✓, ✗ and — for N/A, drawn. */
function mark(kind) {
  if (kind === 'yes' || kind === 'approved' || kind === 'recommended') {
    return '<svg class="ff-mark" viewBox="0 0 12 12" aria-hidden="true"><path d="M2 6.5L4.8 9.2L10 3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }
  if (kind === 'no' || kind === 'rejected' || kind === 'declined') {
    return '<svg class="ff-mark" viewBox="0 0 12 12" aria-hidden="true"><path d="M3 3L9 9M9 3L3 9" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
  }
  if (kind === 'conditional' || kind === 'revisions') {
    return '<svg class="ff-mark" viewBox="0 0 12 12" aria-hidden="true"><circle cx="6" cy="6" r="4.3" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M6 3.6V6.6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/><circle cx="6" cy="8.4" r=".7" fill="currentColor"/></svg>';
  }
  if (kind === 'waiting') return '<span class="ff-dot" aria-hidden="true">·</span>';
  return '<span class="ff-dash" aria-hidden="true">—</span>';
}

function tone(kind) {
  if (kind === 'yes' || kind === 'approved' || kind === 'recommended') return 'ff-ok';
  if (kind === 'no' || kind === 'rejected' || kind === 'declined') return 'ff-no';
  if (kind === 'conditional' || kind === 'revisions') return 'ff-warn';
  return 'ff-muted';
}

function word(kind, text, direction) {
  return `<span class="ff-word ${tone(kind)}">${mark(kind)}<span>${t(text, direction)}</span></span>`;
}

function numberCell(number) {
  return `<span class="ff-qn-n"><bdi>${escapeHtml(String(number ?? ''))}</bdi></span>`;
}

function questionHead(block, direction, extra) {
  const guidance = block.guidance ? `<div class="ff-guidance">${t(block.guidance, direction)}</div>` : '';
  return `<div class="ff-qn">${numberCell(block.number)}<span class="ff-qn-text">${t(block.text, direction)}${extra || ''}</span></div>${guidance}`;
}

function unit(type, cls, inner, attrs) {
  return `<div class="ff ${cls}" data-flow-unit="${type}"${attrs || ''}>${inner}</div>`;
}

function renderFacts(items, direction) {
  return '<div class="ff-facts">' + items.map((it) =>
    `<div><span>${t(it.label, direction)}</span><b${it.mono ? ' class="ff-mono"' : ''}>${t(it.value, direction)}</b></div>`).join('') + '</div>';
}

function renderShortAnswer(a, direction) {
  switch (a.kind) {
    case 'text':
      return `<div class="ff-a${a.mono ? ' ff-mono' : ''}">${a.paragraphs.map((p) => `<p>${t(p, direction)}</p>`).join('')}</div>`
        + (a.wordCount ? `<div class="ff-wc">${t(a.wordCount, direction)}</div>` : '');
    case 'empty':
      return `<div class="ff-a ff-empty">${t(a.text, direction)}</div>`;
    case 'choices':
      return '<div class="ff-ch">' + a.options.map((o) =>
        `<span class="${o.chosen ? 'ff-on' : 'ff-off'}">${box(o.chosen)}<span dir="auto">${t(o.text, direction)}</span></span>`).join('') + '</div>';
    case 'yesNo':
      return `<div class="ff-a">${word(a.value, a.word, direction)}</div>`;
    case 'yesNoRows': {
      if (a.style === 'threeColumns') {
        const h = a.headers || {};
        const head = `<span class="ff-h">${t(h.item || '', direction)}</span><span class="ff-h">${t(h.yes || '', direction)}</span>`
          + `<span class="ff-h">${t(h.no || '', direction)}</span><span class="ff-h">${t(h.na || '', direction)}</span>`;
        const dot = (on) => (on ? '<b class="ff-pick" aria-hidden="true"></b>' : '');
        return `<div class="ff-yn3">${head}${a.rows.map((r) => `<span>${t(r.text, direction)}</span>`
          + `<span>${dot(r.value === 'yes')}</span><span>${dot(r.value === 'no')}</span><span>${dot(r.value === 'na')}</span>`).join('')}</div>`;
      }
      return a.rows.map((r) => `<div class="ff-ynr"><span>${t(r.text, direction)}</span>`
        + `<b>${r.value === null ? `<span class="ff-empty">${t(r.word || '', direction)}</span>` : word(r.value, r.word || '', direction)}</b></div>`).join('');
    }
    case 'facts':
      return renderFacts(a.items, direction);
    case 'table': {
      const head = a.columns.map((c) => `<th>${t(c, direction)}</th>`).join('');
      const rows = a.rows.map((r) => `<tr>${r.map((c) => `<td>${t(c, direction)}</td>`).join('')}</tr>`).join('');
      return `<table class="ff-table"><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table>`;
    }
    default:
      return '';
  }
}

/**
 * A question. Short answers are one unit that never splits (DocFormBlocks
 * sheet 5). A long text answer flows: the question is its own unit kept with
 * the first paragraph, each paragraph is a unit the paginator may split at a
 * line, and each carries the question's "continued" line, which the paginator
 * prints at the top of a page the answer runs onto (FP.8, engine need 6).
 */
function renderQuestion(block, direction) {
  const a = block.answer;
  if (block.layout === 'row' && a.kind !== 'table' && !(a.kind === 'text' && a.paragraphs.length > 1)) {
    return unit('formQuestion', 'ff-q ff-q-row', `<div class="ff-row-q">${questionHead(block, direction)}</div><div class="ff-row-a">${renderShortAnswer(a, direction)}</div>`, ' data-keep-together="1"');
  }
  const long = a.kind === 'text' && (a.paragraphs.length > 1 || a.paragraphs[0].length > 600);
  if (!long) {
    return unit('formQuestion', 'ff-q', questionHead(block, direction) + renderShortAnswer(a, direction), ' data-keep-together="1"');
  }
  const continued = block.continued
    ? `<div class="ff-qn ff-qn-continued">${numberCell(block.number)}<span class="ff-qn-text">${t(block.text, direction)} <span class="ff-cont">${t(block.continued, direction)}</span></span></div>`
    : '';
  const carry = continued ? ` data-flow-continued="${escapeHtml(continued)}"` : '';
  let out = unit('formQuestion', 'ff-q ff-q-head', questionHead(block, direction), ' data-keep-with-next="1"');
  for (const p of a.paragraphs) {
    out += `<p class="flow-paragraph ff-a-p${a.mono ? ' ff-mono' : ''}" data-flow-unit="paragraph"${carry}>${t(p, direction)}</p>`;
  }
  if (a.wordCount) {
    out += `<div class="ff ff-wc ff-wc-unit" data-flow-unit="formWordCount">${t(a.wordCount, direction)}</div>`;
  }
  return out;
}

/** FP.4: the section heading in one of six styles; a section all N/A as one line (FP.9). */
function renderSection(block, direction) {
  const style = block.style || 'mark';
  const n = escapeHtml(String(block.number ?? ''));
  const title = `<b>${t(block.title, direction)}</b>`;
  const na = block.notApplicable;
  const end = na ? `<span class="ff-na">${t(na.label, direction)}</span>` : '';
  let head;
  switch (style) {
    case 'number':
      head = `<div class="ff-sA"><span class="ff-sn"><bdi>${n}</bdi></span>${title}${end}</div>`;
      break;
    case 'eyebrow':
      head = `<div class="ff-sC"><span class="ff-eyebrow"><bdi>${t(block.eyebrow || n, direction)}</bdi></span><div class="ff-sC-t">${title}${end}</div></div>`;
      break;
    case 'rail': {
      const rail = block.rail
        ? `<div class="ff-rail">${Array.from({ length: block.rail.total }, (_, i) =>
          `<i class="${i + 1 < block.rail.current ? 'ff-on' : i + 1 === block.rail.current ? 'ff-now' : ''}"></i>`).join('')}</div>`
        : '<div class="ff-rail"><i class="ff-now"></i></div>';
      head = `<div class="ff-sD"><div class="ff-sD-t"><span class="ff-sn"><bdi>${n}</bdi></span>${title}${end}</div>${rail}</div>`;
      break;
    }
    case 'tab':
      head = `<div class="ff-sE"><span class="ff-tab"><bdi>${t(block.eyebrow || n, direction)}</bdi></span><div class="ff-sE-t">${title}${end}</div></div>`;
      break;
    case 'plane':
      head = `<div class="ff-sF"><span class="ff-sn"><bdi>${n}</bdi></span>${title}${end}</div>`;
      break;
    default:
      // B: two offset rounded squares carrying the number (6.8 mm, 1.9 mm radius, 0.7 mm outline).
      head = `<div class="ff-sB"><span class="ff-mk" aria-hidden="true"><i></i><i><bdi>${n}</bdi></i></span>${title}${end}</div>`;
  }
  const note = block.note ? `<div class="ff-snote">${t(block.note, direction)}</div>` : '';
  const summary = na && na.summary ? `<div class="ff-snote ff-na-summary">${t(na.summary, direction)}</div>` : '';
  const attrs = na ? ' data-keep-together="1"' : ' data-keep-with-next="1"';
  return unit('formSection', `ff-sec ff-style-${style}${na ? ' ff-is-na' : ''}`, head + note + summary, attrs);
}

function renderSignature(block, direction) {
  const role = block.role ? `<span>${t(block.role, direction)}</span>` : '';
  const conf = block.confirmed
    ? `<span class="ff-conf">${mark('yes')}${t(block.confirmed, direction)}</span>`
    : block.waiting ? `<span class="ff-conf ff-wait">${mark('waiting')}${t(block.waiting, direction)}</span>` : '';
  const who = `<div><b>${t(block.name, direction)}</b>${role}${conf}</div>`;
  const style = block.style || 'drawn';
  let pad = '';
  if (style !== 'confirmedOnly') {
    if (block.drawing) {
      const [w, h] = block.drawing.viewBox;
      const paths = block.drawing.paths.map((d) => `<path d="${escapeHtml(d)}"/>`).join('');
      pad = `<div class="ff-pad"><svg viewBox="0 0 ${Number(w)} ${Number(h)}" preserveAspectRatio="xMidYMid meet" aria-hidden="true">`
        + `<g fill="none" stroke="#1f2a5a" stroke-width="${Math.max(1, Number(w) / 160).toFixed(2)}" stroke-linecap="round" stroke-linejoin="round">${paths}</g></svg></div>`;
    } else {
      pad = `<div class="ff-pad ff-dash"><em>${t(block.noDrawing || '', direction)}</em></div>`;
    }
  }
  const paper = style === 'paper' && block.paper
    ? `<div class="ff-siglines"><div><i></i><span>${t(block.paper.signature, direction)}</span></div>`
      + `${block.paper.date ? `<div><i></i><span>${t(block.paper.date, direction)}</span></div>` : ''}</div>`
    : '';
  return unit('signatureRow', `ff-sig${style === 'confirmedOnly' ? ' ff-sig-plain' : ''}`, `<div class="ff-sig-row">${who}${pad}</div>${paper}`, ' data-keep-together="1"');
}

function dl(rows, direction) {
  return '<dl>' + rows.map((r) => `<dt>${t(r.label, direction)}</dt><dd${r.mono ? ' class="ff-mono"' : ''}>${t(r.value, direction)}</dd>`).join('') + '</dl>';
}

function renderFormBlock(block, direction, renderQrMarkup) {
  switch (block.type) {
    case 'formTitle':
      return unit('formTitle', 'ff-title', `<h1>${t(block.title, direction)}</h1>${block.byline ? `<p>${t(block.byline, direction)}</p>` : ''}`, ' data-keep-together="1"');
    case 'formSection':
      return renderSection(block, direction);
    case 'formQuestion':
      return renderQuestion(block, direction);
    case 'notice':
      return unit('notice', 'ff-notice', `${block.label ? `<b>${t(block.label, direction)}</b>` : ''}<div>${t(block.text, direction)}</div>`, ' data-keep-together="1"');
    case 'signatureRow':
      return renderSignature(block, direction);
    case 'decisionLine': {
      const items = (block.items || []).map((it) =>
        `<span><span class="ff-k">${t(it.label, direction)}</span> <span${it.mono ? ' class="ff-mono"' : ''}>${t(it.value, direction)}</span></span>`).join('');
      const note = block.note ? `<div class="ff-dline-note">${t(block.note, direction)}</div>` : '';
      return unit('decisionLine', 'ff-dline', `<div class="ff-dline-row"><span class="ff-st">${word(block.status, block.word, direction)}</span>${items}</div>${note}`, ' data-keep-together="1"');
    }
    case 'decisionBlock': {
      const qr = block.qr
        ? `<div class="ff-dec-qr">${renderQrMarkup(block.qr.value)}${block.qr.caption ? `<div class="ff-qrl">${t(block.qr.caption, direction)}</div>` : ''}`
          + `${block.qr.reference ? `<div class="ff-qrl ff-mono">${t(block.qr.reference, direction)}</div>` : ''}</div>`
        : '';
      return unit('decisionBlock', `ff-dec${qr ? '' : ' ff-dec-noqr'}`, `<div><div class="ff-big ${tone(block.status)}">${mark(block.status)}<span>${t(block.word, direction)}</span></div>${dl(block.rows || [], direction)}</div>${qr}`, ' data-keep-together="1"');
    }
    case 'approvals': {
      const title = block.title ? `<div class="ff-sub">${t(block.title, direction)}</div>` : '';
      const nc = block.notCounted ? `<div class="ff-nc">${t(block.notCounted, direction)}</div>` : '';
      if (block.style === 'cards') {
        const cards = block.rows.map((r) => `<div class="ff-sigcard"><b>${t(r.name, direction)}</b>${r.role ? `<span>${t(r.role, direction)}</span>` : ''}`
          + `${word(r.status, r.word, direction)}${r.at ? `<span>${t(r.at, direction)}</span>` : ''}</div>`).join('');
        return unit('approvals', 'ff-approvals', `${title}<div class="ff-cards">${cards}</div>${nc}`, ' data-keep-together="1"');
      }
      const rows = block.rows.map((r) => `<div class="ff-appr">${word(r.status, r.word, direction)}`
        + `<span>${t(r.name, direction)}${r.role ? `, ${t(r.role, direction)}` : ''}</span><span class="ff-when">${t(r.at || '—', direction)}</span></div>`).join('');
      return unit('approvals', 'ff-approvals', `${title}${rows}${nc}`, ' data-keep-together="1"');
    }
    case 'officeStrip': {
      const items = block.items.map((it) => `<div><span>${t(it.label, direction)}</span>`
        + `${it.status ? word(it.status, it.value, direction) : `<b${it.mono ? ' class="ff-mono"' : ''}>${t(it.value, direction)}</b>`}</div>`).join('');
      return unit('officeStrip', 'ff-ostrip', `${block.title ? `<div class="ff-ostrip-t">${t(block.title, direction)}</div>` : ''}<div class="ff-ostrip-g ff-ostrip-${block.items.length}">${items}</div>`, ' data-keep-together="1"');
    }
    default:
      return '';
  }
}

module.exports = {
  FORM_BLOCK_TYPES,
  SECTION_STYLES,
  LETTERHEAD_LAYOUTS,
  PATH_DATA,
  validateFormPrintBlock,
  validateLetterheadLayout,
  renderFormBlock,
};
