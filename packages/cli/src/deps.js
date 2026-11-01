import { spawn } from 'node:child_process';
import { closeSync, openSync } from 'node:fs';
import { createServer } from 'node:net';

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
    const finish = (result) => {
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

export function realDeps() {
  return {
    exec,
    portIsFree,
    fetch: globalThis.fetch,
    log: (line) => console.log(line),
    sleep: (ms) => new Promise((done) => setTimeout(done, ms)),
    now: () => new Date(),
    processEnv: process.env,
  };
}
