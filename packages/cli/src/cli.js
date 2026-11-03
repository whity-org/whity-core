import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { parseArgs } from 'node:util';
import { realDeps } from './deps.js';
import { CliError } from './errors.js';
import { m } from './messages.js';
import { install } from './commands/install.js';
import { update } from './commands/update.js';

const PLANNED = new Set(['login', 'logout', 'documents', 'agents', 'approvals', 'mcp', 'plugin']);

const OPTIONS = {
  dir: { type: 'string', default: 'whity' },
  url: { type: 'string' },
  'web-port': { type: 'string' },
  'api-port': { type: 'string' },
  bind: { type: 'string' },
  'admin-email': { type: 'string' },
  force: { type: 'boolean', default: false },
  help: { type: 'boolean', short: 'h', default: false },
  version: { type: 'boolean', short: 'v', default: false },
};

function toolVersion() {
  const pkg = new URL('../package.json', import.meta.url);
  return JSON.parse(readFileSync(pkg, 'utf8')).version;
}

export async function main(argv, deps = realDeps()) {
  try {
    return await run(argv, deps);
  } catch (error) {
    if (error instanceof CliError) {
      deps.log('');
      deps.log(m.error(error.message));
      return error.exitCode;
    }
    throw error;
  }
}

async function run(argv, deps) {
  let parsed;
  try {
    parsed = parseArgs({ args: argv, options: OPTIONS, allowPositionals: true });
  } catch (error) {
    throw new CliError(m.badArguments(error.message), 2);
  }
  const { values, positionals } = parsed;
  const [command, version, ...extra] = positionals;

  if (values.version) {
    deps.log(toolVersion());
    return 0;
  }
  if (values.help || !command) {
    deps.log(m.help());
    return 0;
  }
  if (PLANNED.has(command)) {
    throw new CliError(m.planned(command), 2);
  }
  if (command !== 'install' && command !== 'update') {
    throw new CliError(m.unknownCommand(command), 2);
  }
  if (extra.length > 0) {
    throw new CliError(m.unexpectedArgument(extra[0]), 2);
  }

  const dir = resolve(values.dir);
  if (command === 'install') {
    await install({
      dir,
      version,
      url: values.url,
      webPort: values['web-port'],
      apiPort: values['api-port'],
      bind: values.bind,
      adminEmail: values['admin-email'],
    }, deps);
  } else {
    await update({ dir, version, force: values.force }, deps);
  }
  return 0;
}
