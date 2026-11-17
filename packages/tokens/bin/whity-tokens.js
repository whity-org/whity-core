#!/usr/bin/env node
'use strict';

/**
 * whity-tokens copy <folder> [--no-fonts] [--no-logos]
 *
 * Writes the Whity site tokens into a folder a static site serves (WHIT-1055):
 *   <folder>/tokens.css   generated/site.css, with fonts read from ./fonts/
 *   <folder>/fonts/       the font files and their licences
 *   <folder>/logos/       the brand logo set (SVG, PNG, ICO)
 *
 * Sites run it at build time so their copy always matches the installed
 * package, instead of keeping a hand-made copy in their own repository.
 */

const fs = require('fs');
const path = require('path');

const PACKAGE_DIR = path.join(__dirname, '..');

function usage() {
  return 'Usage: whity-tokens copy <folder> [--no-fonts] [--no-logos]';
}

function copyDir(from, to) {
  fs.mkdirSync(to, { recursive: true });
  let count = 0;
  for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
    const src = path.join(from, entry.name);
    const dest = path.join(to, entry.name);
    if (entry.isDirectory()) {
      count += copyDir(src, dest);
    } else if (entry.isFile()) {
      fs.copyFileSync(src, dest);
      count += 1;
    }
  }
  return count;
}

function copy(folder, { fonts = true, logos = true } = {}) {
  const target = path.resolve(folder);
  fs.mkdirSync(target, { recursive: true });

  const css = fs
    .readFileSync(path.join(PACKAGE_DIR, 'generated', 'site.css'), 'utf8')
    .replace(/url\((['"]?)\.\.\/fonts\//g, 'url($1fonts/');
  fs.writeFileSync(path.join(target, 'tokens.css'), css, 'utf8');
  const written = ['tokens.css'];

  if (fonts) {
    const n = copyDir(path.join(PACKAGE_DIR, 'fonts'), path.join(target, 'fonts'));
    written.push(`fonts/ (${n} files)`);
  }
  if (logos) {
    const n = copyDir(path.join(PACKAGE_DIR, 'logos'), path.join(target, 'logos'));
    written.push(`logos/ (${n} files)`);
  }
  return { target, written };
}

function main(argv) {
  const [command, folder, ...flags] = argv;
  if (command !== 'copy' || !folder || folder.startsWith('--')) {
    console.error(usage());
    return 2;
  }
  const unknown = flags.filter((f) => f !== '--no-fonts' && f !== '--no-logos');
  if (unknown.length > 0) {
    console.error(`Unknown option ${unknown[0]}. ${usage()}`);
    return 2;
  }
  const { target, written } = copy(folder, {
    fonts: !flags.includes('--no-fonts'),
    logos: !flags.includes('--no-logos'),
  });
  console.log(`Whity tokens copied to ${target}: ${written.join(', ')}`);
  return 0;
}

if (require.main === module) {
  process.exitCode = main(process.argv.slice(2));
}

module.exports = { copy, main };
