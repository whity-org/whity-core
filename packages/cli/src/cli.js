import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { parseArgs } from 'node:util';
import { realDeps } from './deps.js';
import { CliError } from './errors.js';
import { install } from './commands/install.js';
import { update } from './commands/update.js';

const HELP = `whity: install and update a self-hosted Whity on Docker.

Usage:
  whity install [version]    Install Whity with Docker. Default: the latest release.
  whity update [version]     Back up, then update to a release. Default: the latest.
  whity --version            Print the version of this tool.

Options for install and update:
  --dir <path>               Install folder. Default: ./whity

Options for install:
  --url <url>                The address people will use. Default: http://localhost:<web port>
  --web-port <port>          Port for the web app. Default: 3100
  --api-port <port>          Port for the API. Default: 8100
  --bind <address>           Address to publish ports on. Default: 127.0.0.1
  --admin-email <email>      Administrator address. Default: system@whity.local

Options for update:
  --force                    Update even though an earlier update did not finish.

Planned, not in this version:
  whity login                Sign in to an instance and work through its API.
  whity plugin               Create, check and package plugins.

The server's own tool, bin/whity-cli, runs inside the containers. This tool calls it.
`;

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
      deps.log(`Error: ${error.message}`);
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
    throw new CliError(`${error.message}\nRun "whity --help" for usage.`, 2);
  }
  const { values, positionals } = parsed;
  const [command, version, ...extra] = positionals;

  if (values.version) {
    deps.log(toolVersion());
    return 0;
  }
  if (values.help || !command) {
    deps.log(HELP);
    return 0;
  }
  if (PLANNED.has(command)) {
    throw new CliError(`"whity ${command}" is planned but is not in this version.`, 2);
  }
  if (command !== 'install' && command !== 'update') {
    throw new CliError(`Unknown command "${command}". Run "whity --help" for usage.`, 2);
  }
  if (extra.length > 0) {
    throw new CliError(`Unexpected argument "${extra[0]}". Run "whity --help" for usage.`, 2);
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
