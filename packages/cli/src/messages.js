/**
 * Everything the tool says to a person. Nothing else in src/ holds user-facing
 * text, and test/messages.test.js enforces that.
 *
 * Keep this file plain ASCII, which Windows PowerShell 5.1's console prints
 * correctly. That console also garbles Arabic and doesn't lay it out right to
 * left, so (WHIT-840) English and plain marks there. Where the console handles
 * Unicode (Linux, macOS, Windows Terminal) the install speaks as the
 * SiteInstallerTerminal card draws it (SU.7): check marks, and Arabic when the
 * system language is Arabic, from messages-ar.js (WHIT-1044).
 */
import { ar } from './messages-ar.js';

/** Whether this console prints Unicode: not the classic Windows console. */
export function unicodeConsole(env = process.env, platform = process.platform) {
  return platform !== 'win32' || Boolean(env.WT_SESSION) || env.TERM_PROGRAM === 'vscode';
}

/** Arabic when the system language is Arabic and the console can show it. */
export function wantsArabic(env = process.env, platform = process.platform) {
  const lang = env.LC_ALL || env.LC_MESSAGES || env.LANG || '';
  return /^ar([_.-]|$)/i.test(lang) && unicodeConsole(env, platform);
}

/** The marks the card uses (check, cross, ellipsis, middle dot), or plain ones. */
export const MARK = unicodeConsole()
  ? { ok: '\u2713', no: '\u2717', warn: '!', doing: '\u2026', dot: '\u00b7' }
  : { ok: 'ok', no: 'x', warn: '!', doing: '..', dot: '-' };

const en = {
  help: () => `whity: install and update a self-hosted Whity on Docker.

Usage:
  whity install [version]    Install Whity with Docker. Default: the latest release.
                             On a terminal it asks for the web address and your email.
  whity update [version]     Back up, then update to a release. Default: the latest.
  whity --version            Print the version of this tool.

Options for install and update:
  --dir <path>               Install folder. Default: ./whity

Options for install:
  --domain <name>            The web address for Whity on this server, for example
                             office.example.jo. The installer gets its certificate.
  --email <address>          The first owner's email.
  --port <port>              The HTTPS port with --domain, when 443 is taken. Default: 443
  --verbose                  Show each Docker command's own output.
  --url <url>                Instead of --domain: the address people will use,
                             behind a proxy you run. Default: http://localhost:<web port>
  --web-port <port>          Port for the web app. Default: 3100
  --api-port <port>          Port for the API. Default: 8100
  --bind <address>           Address to publish ports on. Default: 127.0.0.1
  --admin-email <email>      Same as --email. Default: system@whity.local

Options for update:
  --force                    Update even though an earlier update did not finish.

Planned, not in this version:
  whity login                Sign in to an instance and work through its API.
  whity plugin               Create, check and package plugins.

The server's own tool, bin/whity-cli, runs inside the containers. This tool calls it.
`,

  // Command line
  error: (message) => `${MARK.no} ${message}`,
  badArguments: (reason) => `${reason}\nRun "whity --help" for usage.`,
  planned: (command) => `"whity ${command}" is planned but is not in this version.`,
  unknownCommand: (command) => `Unknown command "${command}". Run "whity --help" for usage.`,
  unexpectedArgument: (argument) => `Unexpected argument "${argument}". Run "whity --help" for usage.`,

  // Docker
  dockerMissing: () => 'Docker isn\'t installed\n  Install it, then run this again. On a Linux server, with the official script:\n'
    + '  curl -fsSL https://get.docker.com | sh\n  On Windows and macOS, install Docker Desktop.\n  Nothing was changed.',
  dockerStopped: (detail) => `Docker is installed but not running. Start it, then run this again.\n${detail}`,
  composeMissing: () => 'Docker Compose v2 was not found. Install the docker compose plugin, then run this again.',
  composeTooOld: (found) => `Docker Compose v2 or newer is needed. Found ${found}.`,
  pullingImage: (image) => `  ${image}`,
  pullFailed: (image, detail) => `Could not pull ${image}.\n${detail}`,
  pullDeniedHint: () => '\nThe images are public, so this usually means an old ghcr.io login. Run: docker logout ghcr.io',

  // Releases
  badVersion: (input) => `"${input}" is not a release version. Use a form like v0.2.22.`,
  unsupportedVersion: (oldest, version) => `This tool supports ${oldest} and newer, not ${version}.`,
  latestUnknown: (url) => `Could not find the latest release at ${url}. Check the connection, or name a version.`,
  downloadFailed: (url, reason) => `Could not download ${url}: ${reason}`,

  // Install (SiteInstallerTerminal, SU.7)
  header: () => `Whity Core installer  ${MARK.dot}  free and open source (AGPL-3.0)`,
  done: (label, detail) => `${MARK.ok} ${label}${detail ? `   ${detail}` : ''}`,
  doing: (label) => `${MARK.doing} ${label}`,
  warning: (label, todo, command) => `${MARK.warn} ${label}\n  ${todo}${command ? `\n  ${command}` : ''}`,
  checkedServer: () => 'Checked this server',
  serverFacts: ({ os, cpus, memGb, diskGb }) => `${os} ${MARK.dot} ${cpus} CPUs ${MARK.dot} ${memGb} GB`
    + (diskGb === null ? '' : ` ${MARK.dot} ${diskGb} GB free`),
  dockerFound: () => 'Docker found',
  askDomain: () => 'Web address for Whity',
  askDomainExample: () => '(e.g. office.example.jo; leave empty to try it on this computer)',
  askEmail: () => 'Your email (first owner)',
  downloading: (version) => `Downloading Whity Core ${version.replace(/^v/, '')}`,
  downloaded: (version) => `Downloaded Whity Core ${version.replace(/^v/, '')}`,
  starting: () => 'Starting the database and the app',
  started: () => 'Started the database and the app',
  gettingCertificate: (domain) => `Getting a certificate for ${domain}`,
  gotCertificate: (domain) => `Got a certificate for ${domain}`,
  noCertificate: (domain) => `No certificate for ${domain} yet`,
  noCertificateTodo: (domain, port) => `Whity is running, but Let's Encrypt couldn't reach this server at ${domain}. `
    + `Check its A record points here and ports 80 and ${port} are open to the internet, then:`,
  makingOwner: () => 'Making your owner account',
  madeOwner: () => 'Made your owner account',
  lowMemory: () => 'Less than 2 GB of memory',
  lowMemoryTodo: () => 'Whity will run, but slowly. 4 GB is better.',
  ready: () => 'Whity is ready.',
  readyNoCertificate: () => 'Whity is running; it opens once the certificate is in.',
  openAt: (url) => `  Open   ${url}`,
  signInAs: (email, envPath) => `  Sign in as ${email} with the INITIAL_SYSTEM_ADMIN_PASSWORD in ${envPath}, and change it after you sign in.`,
  keepEnv: () => '  Back up that .env file: it holds the keys your data is encrypted with.',
  updateLater: () => '  Update later with: whity update',

  badPort: (flag) => `${flag} must be a port number.`,
  badUrl: (url) => `--url "${url}" is not a valid address.`,
  badDomain: (domain) => `"${domain}" isn't a web address\n  Give a domain name like office.example.jo, without http:// or a path.\n  Nothing was changed.`,
  badEmail: (email) => `"${email}" isn't an email address\n  Give the address the owner signs in with.\n  Nothing was changed.`,
  domainUnresolved: (domain) => `${domain} doesn't point anywhere yet\n  Add an A record for ${domain} with this server's address `
    + 'at your domain provider, wait a few minutes, then run this again.\n  Nothing was changed.',
  domainAndUrl: () => 'Give --domain or --url, not both.',
  httpsNeeded: (url) => `Signing in needs HTTPS on any address other than localhost, so ${url} wouldn't work.\n`
    + '  Give the domain instead and the installer gets the certificate: whity install --domain your-address',
  alreadyInstalled: (dir) => `Whity is already installed in ${dir}\n  Update it instead:\n  whity update\n  Nothing was changed.`,
  portInUse: (port) => `Port ${port} is in use\n  Stop the program using it, or choose another with --web-port or --api-port.\n  Nothing was changed.`,
  serverPortInUse: (port) => (port === 80
    ? 'Port 80 is in use\n  Let\'s Encrypt needs it to issue the certificate. Stop the program using it, then run this again.\n  Nothing was changed.'
    : `Port ${port} is in use\n  Stop the program using it, or run the installer with another port, for example:\n  --port 8443\n  Nothing was changed.`),
  installContinuing: (version) => `  Continuing the unfinished install of ${version}`,
  labelPullOthers: () => 'Pulling the other images',
  labelStartDatabase: () => 'Starting the database',
  labelMigrations: () => 'Migrations',
  labelSeed: () => 'Seed',
  labelStartWhity: () => 'Starting Whity',
  labelOwner: () => 'Finding the owner account',
  installStepFailed: (label, detail, envPath) => `${label} failed.\n${detail}\n`
    + `Fix the cause, then run the same "whity install" again to continue: it keeps ${envPath}.`,
  installChecksFailed: (reason, dir) => `${reason}\nSee what happened with: docker compose logs (in ${dir}).\n`
    + 'Then run the same "whity install" again to continue.',

  // Update
  noInstall: (dir) => `No install found in ${dir}. Use --dir to point at one, or run: whity install`,
  noVersionInEnv: (envPath) => `${envPath} has no WHITY_VERSION, so this tool can't tell what is installed.`,
  alreadyCurrent: (version) => `Already on ${version}. Nothing to do.`,
  noDowngrade: (to, from) => `${to} is older than the installed ${from}. Migrations only go forward, so downgrading isn't possible. To go back, restore a backup.`,
  versionMismatch: (envPath, from, running) => `${envPath} says ${from} but Whity reports ${running}. Sort that out first, or add --force.`,
  updatePlan: (from, to) => `  ${from} -> ${to}`,
  updateStep1: () => '[1/8] Checking Docker and the installed version',
  updateStep2: (to) => `[2/8] Checking the compose file of ${to}`,
  updateStep3: (to) => `[3/8] Pulling ${to} (Whity keeps running)`,
  updateStep4: () => '[4/8] Stopping Whity, except the database',
  updateStep5: () => '[5/8] Backing up the database and proving the backup restores',
  updateStep6: () => '[6/8] Running migrations',
  updateStep7: (to) => `[7/8] Starting Whity ${to}`,
  updateStep8: () => '[8/8] Checking /api/health, /web-build and the services',
  settingsNeedChange: (to, detail) => `${to} needs a change to your settings first. Nothing has been changed.\n${detail}`,
  pullLeftRunning: (reason, from) => `${reason}\n\nNothing has been changed. Whity is still running ${from}.`,
  servicesUnreadable: (detail) => `Could not read the services.\n${detail}`,
  stopFailed: (detail) => `Could not stop Whity. Nothing has been changed.\n${detail}`,
  databaseDidNotStart: (detail) => `The database did not start.\n${detail}`,
  backupLeftRunning: (reason, from) => `${reason}\n\nNothing has been changed. Whity is back on ${from}.`,
  migrationsFailed: (detail) => `Migrations failed.\n${detail}`,
  startFailed: (detail) => `Whity did not start.\n${detail}`.trim(),
  earlierUnfinished: (from, to) => `An earlier update from ${from} to ${to} did not finish.`,
  forceHint: () => '\n\nIf you fixed the cause and want to try again without restoring, add --force.',
  restore: ({ reason, from, to, steps }) => `${reason}\n\n`
    + `The update from ${from} to ${to} did not finish and Whity may be down.\n`
    + `To go back to ${from} and the data from before the update, run these one at a time\n`
    + '(they are the same in PowerShell, bash and zsh):\n\n'
    + steps.map((line) => `  ${line}`).join('\n'),
  updated: (to, backupPath) => `
Whity is now on ${to}.
  Backup from before the update: ${backupPath}`,

  // Backup
  backupStepFailed: (step, detail) => `The backup failed at "${step}".\n${detail}`,
  backupStepCount: (table, db) => `count ${table} in ${db}`,
  backupStepCopy: () => 'copy the dump into postgres',
  backupStepRead: () => 'read the dump',
  backupStepCreate: () => 'create a scratch database',
  backupStepRestore: () => 'restore into the scratch database',
  backupStepDump: () => 'pg_dump',
  backupNoTableData: () => 'The backup failed: the dump holds no table data.',
  backupRowsDiffer: (table, live, copy) => `The backup failed: ${table} has ${live} rows live but ${copy} in the backup.`,
  backupNoRows: (table) => `The backup failed: ${table} has no rows.`,
  backupEmptyFile: () => 'The backup failed: pg_dump wrote an empty file.',
  backupProved: (dump, bytes, tables, counts) => `  ${dump}: ${bytes} bytes, ${tables} tables restored, ${counts.join(', ')}`,

  // Checks
  checksFailed: (problems) => `The checks did not pass:\n${problems.map((p) => `  - ${p}`).join('\n')}`,
  httpStatus: (url, status) => `${url} answered HTTP ${status}`,
  noAnswer: (url, reason) => `${url} did not answer: ${reason}`,
  healthStatus: (status) => `/api/health status is "${status}"`,
  healthVersion: (reported, expected) => `/api/health reports ${reported}, expected ${expected}`,
  webVersion: (reported, expected) => `/web-build reports ${reported}, expected ${expected}`,
  pendingMigrations: (count) => `/api/build reports ${count} pending migrations`,
  servicesUnlisted: (detail) => `Could not list the services: ${detail}`,
  servicesNone: () => 'No services are running',
  servicesUninspected: (detail) => `Could not read the services: ${detail}`,
  serviceDown: (service, status) => `${service} is ${status}`,
  serviceRestarting: (service) => `${service} keeps restarting`,
  renderNoAnswer: (detail) => `The render service did not answer: ${detail}`,
  renderVersion: (reported, expected) => `The render service reports ${reported}, expected ${expected}`,
  renderSecretAdded: () => '      Added RENDER_SHARED_SECRET to .env, so documents can print (the render service had none)',
};

/** The catalogue in the reader's language; anything not translated stays English. */
export const m = wantsArabic() ? { ...en, ...ar(MARK) } : en;
