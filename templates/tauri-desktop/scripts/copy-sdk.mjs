// Copies the repository's plugin SDK (sdk/src) into php-host/sdk/src (WHIT-1054).
//
// A device has no network and no Composer, so the contract types a plugin
// `implements` must sit on disk beside the offline host, and Tauri bundles
// php-host/ as it is. The copy used to be committed and re-vendored by hand,
// which is how it once fell three SDK releases behind (#849). Now it is made
// here, fresh, before every `dev` and `build`, and it is not committed.
import { cpSync, existsSync, rmSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const template = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const source = resolve(template, '../../sdk/src');
const target = resolve(template, 'php-host/sdk/src');

if (!existsSync(source)) {
  console.error(`The plugin SDK isn't at ${source}. Build the desktop app from a whity-core checkout.`);
  process.exit(1);
}

rmSync(target, { recursive: true, force: true });
cpSync(source, target, { recursive: true });
console.log(`Copied the plugin SDK into ${target}`);
