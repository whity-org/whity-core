import { randomBytes } from 'node:crypto';
import { readFileSync, renameSync, writeFileSync } from 'node:fs';

export function parseEnv(text) {
  const values = {};
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith('#') || !line.includes('=')) continue;
    const at = line.indexOf('=');
    const value = line.slice(at + 1).trim();
    values[line.slice(0, at).trim()] = value.replace(/^(["'])(.*)\1$/, '$2');
  }
  return values;
}

export function readEnv(path) {
  return parseEnv(readFileSync(path, 'utf8'));
}

/** Changes one setting and keeps every other line, including comments. */
export function setEnvValue(path, key, value) {
  const lines = readFileSync(path, 'utf8').split(/\r?\n/);
  const at = lines.findIndex((line) => line.trim().startsWith(`${key}=`));
  if (at === -1) lines.splice(0, 0, `${key}=${value}`);
  else lines[at] = `${key}=${value}`;
  const temp = `${path}.tmp`;
  writeFileSync(temp, lines.join('\n'), { mode: 0o600 });
  renameSync(temp, path);
}

function hex(bytes) {
  return randomBytes(bytes).toString('hex');
}

/** The text of a new install's .env. All generated values are hex, so nothing in them needs quoting. */
export function newEnvFile({ version, project, url, webPort, apiPort, bind, adminEmail }) {
  return `# Whity settings and secrets. Keep this file private and never commit it.
# Back it up too: a database backup alone can't decrypt what ENCRYPTION_KEY protects.
COMPOSE_PROJECT_NAME=${project}
COMPOSE_PROFILES=render
WHITY_VERSION=${version}

APP_ENV=production
APP_URL=${url}
CORS_ALLOWED_ORIGINS=${url}

BIND_ADDRESS=${bind}
WEB_PORT_HOST=${webPort}
APP_PORT_HOST=${apiPort}

DB_USER=whity
DB_NAME=whity_core
DB_PASSWORD=${hex(24)}

JWT_SECRET=${hex(32)}
ENCRYPTION_KEY=${hex(32)}
RENDER_SHARED_SECRET=${hex(32)}

# Used once, when the administrator account is created.
INITIAL_SYSTEM_ADMIN_EMAIL=${adminEmail}
INITIAL_SYSTEM_ADMIN_PASSWORD=${hex(16)}
`;
}
