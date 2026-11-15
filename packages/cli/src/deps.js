import { spawn } from 'node:child_process';
import { lookup } from 'node:dns/promises';
import { closeSync, openSync, readFileSync, statfsSync } from 'node:fs';
import { createServer } from 'node:net';
import { cpus, totalmem, type } from 'node:os';
import { createInterface } from 'node:readline/promises';

/**
 * Runs docker without a shell, so no argument is ever reinterpreted by
 * PowerShell, cmd or sh. A dump goes straight to a file descriptor, which keeps
 * it byte-exact: PowerShell 5.1's `>` re-encodes binary output.
 *
 * @param {string[]} args
 * @param {{cwd?: string, env?: object, stream?: boolean, stdoutFile?: string}} options
 *   `stream` shows output live; otherwise it is captured and returned.
 */
function exec(args, { cwd, env, stream = false, stdoutFile } = {}) {
  return new Promise((done) => {
    // A dump holds customer data and password hashes, so only its owner may read it.
    const fd = stdoutFile ? openSync(stdoutFile, 'w', 0o600) : null;
    const stdout = fd ?? (stream ? 'inherit' : 'pipe');
    const child = spawn('docker', args, {
      cwd,
      env,
      stdio: ['ignore', stdout, stream ? 'inherit' : 'pipe'],
    });
    let out = '';
    let err = '';
    child.stdout?.on('data', (chunk) => { out += chunk; });
    child.stderr?.on('data', (chunk) => { err += chunk; });
    // A process that can't start emits both `error` and `close`.
    let finished = false;
    const finish = (result) => {
      if (finished) return;
      finished = true;
      if (fd !== null) closeSync(fd);
      done(result);
    };
    child.on('error', (error) => finish({ code: 127, stdout: out, stderr: error.message }));
    child.on('close', (code) => finish({ code: code ?? 1, stdout: out, stderr: err }));
  });
}

/** Whether a host port can still be bound. */
function portIsFree(port, host) {
  return new Promise((done) => {
    const server = createServer();
    server.once('error', () => done(false));
    server.listen(port, host, () => server.close(() => done(true)));
  });
}

/** The system's name, as the card shows it ("Ubuntu 24.04"). */
function systemName() {
  try {
    const release = readFileSync('/etc/os-release', 'utf8');
    const name = /^NAME="?([^"\n]*)/m.exec(release)?.[1];
    const version = /^VERSION_ID="?([^"\n]*)/m.exec(release)?.[1];
    if (name) return version ? `${name} ${version}` : name;
  } catch {
    // Not Linux, or no os-release.
  }
  return { Darwin: 'macOS', Windows_NT: 'Windows', Linux: 'Linux' }[type()] ?? type();
}

/** What the install shows about this machine: system, CPUs, memory and free disk. */
function serverFacts() {
  let diskGb = null;
  try {
    const disk = statfsSync('.');
    diskGb = Math.floor((disk.bavail * disk.bsize) / 1024 ** 3);
  } catch {
    // Unknown on this system; the line leaves it out.
  }
  return { os: systemName(), cpus: cpus().length, memGb: Math.round(totalmem() / 1024 ** 3), diskGb };
}

/** Whether a name resolves to an address at all. */
async function resolves(name) {
  try {
    await lookup(name);
    return true;
  } catch {
    return false;
  }
}

export function realDeps() {
  const tty = Boolean(process.stdout.isTTY);
  let showing = false;
  // A step under way shows on one line, which the step's result then replaces.
  const clear = () => {
    if (showing) process.stdout.write('\r\x1b[K');
    showing = false;
  };
  return {
    exec,
    portIsFree,
    resolves,
    serverFacts,
    ...(process.stdin.isTTY && tty ? { ask } : {}),
    status: (line) => {
      if (!tty) return;
      clear();
      process.stdout.write(line);
      showing = true;
    },
    fetch: globalThis.fetch,
    log: (line) => {
      clear();
      console.log(line);
    },
    sleep: (ms) => new Promise((done) => setTimeout(done, ms)),
    now: () => new Date(),
    processEnv: process.env,
  };
}

/** One question on the terminal, as the card draws it: "? Question  (hint) > answer". */
async function ask(question, hint) {
  const prompt = `? ${question}${hint ? `  ${hint}` : ''} \u203a `;
  const rl = createInterface({ input: process.stdin, output: process.stdout });
  try {
    return (await rl.question(prompt)).trim();
  } finally {
    rl.close();
  }
}
