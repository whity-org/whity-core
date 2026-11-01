/**
 * Everything the tool says to a person. Nothing else in src/ holds user-facing
 * text, and test/messages.test.js enforces that.
 *
 * English only, as a deliberate exception to the rule that every user-facing
 * string has English and Arabic: Windows PowerShell 5.1's console garbles Arabic
 * and doesn't lay it out right to left, and that is a console this tool has to
 * work in. The install and update guide is in both languages instead. Decided by
 * the owner for 0.1.0 (WHIT-840). To add Arabic later, add a second catalogue
 * with these same keys and choose between them here.
 *
 * Keep this file plain ASCII, which that console prints correctly.
 */
export const m = {
  help: () => `whity: install and update a self-hosted Whity on Docker.

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
`,

  // Command line
  error: (message) => `Error: ${message}`,
  badArguments: (reason) => `${reason}\nRun "whity --help" for usage.`,
  planned: (command) => `"whity ${command}" is planned but is not in this version.`,
  unknownCommand: (command) => `Unknown command "${command}". Run "whity --help" for usage.`,
  unexpectedArgument: (argument) => `Unexpected argument "${argument}". Run "whity --help" for usage.`,

  // Docker
  dockerMissing: () => 'Docker was not found. Install Docker (Docker Desktop on Windows and macOS), then run this again.',
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

  // Install
  badPort: (flag) => `${flag} must be a port number.`,
  badUrl: (url) => `--url "${url}" is not a valid address.`,
  httpsNeeded: (url) => `Signing in needs HTTPS on any address other than localhost, so ${url} wouldn't work.\n`
    + 'Put a reverse proxy with a certificate in front of Whity and pass --url https://your-address.',
  alreadyInstalled: (dir) => `${dir} already holds an install. To move it to a newer release, run: whity update`,
  portInUse: (port) => `Port ${port} is in use. Choose another with --web-port or --api-port.`,
  installContinuing: (version) => `  Continuing the unfinished install of ${version}`,
  installStep1: () => '[1/6] Checking Docker',
  installStep2: (dir) => `[2/6] Writing ${dir}`,
  installStep3: (version) => `[3/6] Pulling ${version}`,
  installStep4: () => '[4/6] Starting the database',
  installStep5: () => '[5/6] Running migrations and seed',
  installStep6: () => '[6/6] Starting Whity and checking it',
  labelPullOthers: () => 'Pulling the other images',
  labelStartDatabase: () => 'Starting the database',
  labelMigrations: () => 'Migrations',
  labelSeed: () => 'Seed',
  labelStartWhity: () => 'Starting Whity',
  installStepFailed: (label, detail, envPath) => `${label} failed.\n${detail}\n`
    + `Fix the cause, then run the same "whity install" again to continue: it keeps ${envPath}.`,
  installChecksFailed: (reason, dir) => `${reason}\nSee what happened with: docker compose logs (in ${dir}).\n`
    + 'Then run the same "whity install" again to continue.',
  installed: ({ version, url, email, envPath }) => `
Whity ${version} is running.
  Sign in at: ${url}/login
  Email:      ${email}
  Password:   the INITIAL_SYSTEM_ADMIN_PASSWORD value in ${envPath}

Change that password after you sign in, and back up the .env file.
It holds the keys your data is encrypted with.`,

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
};
