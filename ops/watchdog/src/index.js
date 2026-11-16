/**
 * The external watchdog — a Cloudflare Worker that watches a Whity deployment
 * from OUTSIDE it.
 *
 * WHY THIS EXISTS, AND WHY IT CANNOT LIVE ON THE BOX.
 * --------------------------------------------------
 * whity-core already ships a health collector (`health:watch`) and a public
 * status page, and on 2026-09-09 they failed in the only way that mattered: a
 * host restart left the database down, the collector DID detect it and logged
 * every failed pass for three hours — and could tell nobody, because the only
 * place it can write is the database that was missing, and the status page
 * reads from that same database.
 *
 * A monitor whose escalation path shares a failure domain with the thing it
 * monitors cannot report that thing failing. No amount of work inside the
 * deployment fixes that; the alarm has to be rung from somewhere the outage
 * cannot reach. That is this Worker's whole reason for being: it runs on
 * Cloudflare's edge, holds its state in KV, and touches nothing on the host.
 *
 * WHAT IT WATCHES
 * ---------------
 *  1. LIVENESS, by probing the deployment over the public internet. It probes
 *     BOTH tiers, because the 2026-09-09 outage was invisible from one of them:
 *     the Next.js tier happily answered 200 for three hours while every API
 *     call behind it failed. Probing only the homepage would have reported a
 *     healthy site throughout the outage. `/api/health` is the honest probe —
 *     it returns 503 with `db_connected: false` when the database is
 *     unreachable — and reporting the two separately makes the alert itself
 *     diagnostic: "web up, api down" names the failure on arrival.
 *
 *  2. BACKUP FRESHNESS, as a DEAD-MAN'S SWITCH. The backup job pings this
 *     Worker after each success; the alarm fires on SILENCE. This is the only
 *     shape that catches the actual 2026-09-09 failure, where the scheduled
 *     backup did not run at all: a check that inspects a report cannot notice a
 *     report that was never made, so absence has to be the trigger.
 *
 * ALERTS FIRE ON TRANSITIONS, NOT ON TICKS. A monitor that repeats itself every
 * two minutes gets muted, and a muted monitor is an absent one. This alerts
 * when a target goes down, and again when it recovers (with how long it was
 * out) — and says nothing in between.
 *
 * NOTHING HERE IS HARDCODED that a deployment might reasonably want different:
 * probe targets, the consecutive-failure threshold, timeouts, the backup
 * staleness window and the liveness-summary interval are all configuration,
 * with defaults chosen to be sensible rather than mandatory.
 */

/**
 * Defaults for every tunable. Overridable per deployment via `[vars]` in
 * wrangler.toml, so a deployment with a slower link or a twice-daily backup
 * changes configuration rather than code.
 */
const DEFAULTS = {
  // Two consecutive failures before crying wolf. One probe can fail for
  // reasons that are nobody's problem — a dropped packet, an edge hiccup, a
  // worker restarting mid-request. With a 2-minute cron this still means the
  // alert lands within about five minutes of a real outage, against three
  // hours of silence for the incident this was built after.
  failuresBeforeAlert: 2,

  // A probe that hangs must not hold the whole run. Well under the cron
  // interval, so a stuck target cannot delay the checks that follow it.
  probeTimeoutMs: 10000,

  // Nightly backup plus slack for a late start. Matches the 26h threshold the
  // repo's existing WhityBackupStale Prometheus rule already uses — one number
  // to reason about, not two that drift apart.
  backupMaxAgeHours: 26,

  // A weekly "still watching" note. WHY AT ALL: every check here is silent
  // when healthy, which is indistinguishable from a Worker whose cron trigger
  // was removed, whose account expired, or that was never deployed. Without a
  // positive signal, this monitor's own death is silent — the exact failure it
  // was built to end. Weekly is rare enough not to become noise.
  summaryIntervalHours: 168,

  // How many days the status-page history bar covers. 90 is the convention
  // on public status pages; the bucket document stays tiny at that size.
  historyDays: 90,
};

export default {
  async scheduled(event, env, ctx) {
    ctx.waitUntil(runChecks(env));
  },

  async fetch(request, env) {
    return handleRequest(request, env);
  },
};

// ── configuration ───────────────────────────────────────────────────────────

function config(env) {
  const num = (name, fallback) => {
    const raw = env[name];
    if (raw === undefined || raw === null || raw === '') return fallback;
    const parsed = Number(raw);
    // A typo in a var must not silently disable a check by parsing to NaN and
    // comparing false against everything.
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
  };

  let targets;
  try {
    targets = JSON.parse(env.PROBE_TARGETS || '[]');
  } catch {
    targets = [];
  }

  // WHERE CORE REPORTS ON ITSELF. The edge can only probe what has a public
  // hostname, which leaves out everything interesting inside the deployment:
  // the queue worker, the scheduler, the render service. Core already measures
  // those (`health:watch` writes them, /api/v1/status publishes them) — this
  // reads that verdict so those components alert like any other target.
  //
  // Without it the blind spot is real rather than theoretical: on 2026-09-14
  // /api/v1/status reported `queue: down` at 0.2% uptime while this page said
  // "All systems operational", because the only thing watched on that host was
  // /api/health, which checks the database and nothing else.
  const componentsUrl = (env.COMPONENTS_URL || '').trim();

  const normalised = (Array.isArray(targets) ? targets : [])
    .filter((t) => t && t.name && (t.url || t.component))
    // A component target has no URL of its own; it is one field of the feed.
    // Give it the feed's URL so an alert still says where to look.
    .map((t) => (t.component && !t.url ? { ...t, url: componentsUrl } : t))
    .filter((t) => t.url);

  return {
    targets: normalised,
    componentsUrl,
    failuresBeforeAlert: num('FAILURES_BEFORE_ALERT', DEFAULTS.failuresBeforeAlert),
    probeTimeoutMs: num('PROBE_TIMEOUT_MS', DEFAULTS.probeTimeoutMs),
    backupMaxAgeHours: num('BACKUP_MAX_AGE_HOURS', DEFAULTS.backupMaxAgeHours),
    summaryIntervalHours: num('SUMMARY_INTERVAL_HOURS', DEFAULTS.summaryIntervalHours),
    historyDays: num('HISTORY_DAYS', DEFAULTS.historyDays),
  };
}

// ── the scheduled run ───────────────────────────────────────────────────────

async function runChecks(env) {
  const cfg = config(env);

  if (cfg.targets.length === 0) {
    // Loud rather than quiet: a watchdog with nothing to watch looks identical
    // to a healthy one, and would report success forever.
    console.error('watchdog: PROBE_TARGETS is empty or unparseable — nothing is being watched');
  }

  // ONE fetch for every component target, not one each: they are all fields of
  // the same document, and polling it four times would be four chances to get
  // four different answers about the same instant.
  const wantsComponents = cfg.targets.some((t) => t.component);
  const feed = wantsComponents ? await fetchComponents(cfg.componentsUrl, cfg.probeTimeoutMs) : null;

  const results = [];
  for (const target of cfg.targets) {
    const probe = target.component ? componentProbe(feed, target.component) : null;
    if (probe?.skip) {
      // Neither observed nor failed. No alert, no state write, no history row.
      console.log(`watchdog: skipping ${target.name} — ${probe.detail}`);
      continue;
    }
    results.push(await checkTarget(env, cfg, target, probe));
  }

  await recordHistory(env, cfg, results);
  await checkHeartbeats(env, cfg);
  await maybeSendSummary(env, cfg, results);

  // WHEN this ran, recorded last so it only advances on a run that completed.
  // The public status page leads with the age of this timestamp: a page that
  // says "all systems operational" from data three hours old is the same lie
  // as a monitor that cannot report, just better presented.
  await writeJson(env, 'meta:last-run', { at: Date.now() });
}

/**
 * Probe one target and alert only when its state CHANGES.
 */
async function checkTarget(env, cfg, target, precomputed) {
  const key = `state:${target.name}`;
  // `stored` is kept separate from the defaults below because "nothing has ever
  // been recorded for this target" and "recorded, and healthy" are different
  // facts that the write-skip further down must not confuse. See there.
  const stored = await readJson(env, key);
  const state = stored || {
    alertedStatus: 'up',
    consecutiveFailures: 0,
    downSince: null,
  };

  // A component target's verdict was read out of the aggregated feed already;
  // everything below — the change detection, the write-skip, the alert text —
  // is identical either way, which is the point of passing the result in
  // rather than teaching this function a second way to ask.
  const probe = precomputed || (await probeOnce(target.url, cfg.probeTimeoutMs, target.expectStatus));

  if (probe.ok) {
    if (state.alertedStatus === 'down') {
      const outage = state.downSince ? humanDuration(Date.now() - state.downSince) : 'an unknown period';
      await notify(
        env,
        `✅ *${target.name} recovered*\n${target.url}\nDown for ${outage}.`
      );
    }

    // WRITE ONLY ON CHANGE. Cloudflare KV allows 1,000 writes a day on the free
    // plan, and this Worker was writing one key per target per run plus
    // meta:last-run — 2,880 a day at the */2 schedule, which is nearly three
    // times the allowance. A healthy target whose state is identical to last
    // time has nothing to record, so the steady state now costs no writes at
    // all and the budget is spent on changes, which are the only thing anyone
    // reads this store to learn.
    //
    // A TARGET WITH NO STORED STATE IS A CHANGE, even though it is healthy.
    // `state` above falls back to an up-and-clean object when KV holds nothing,
    // so without the `stored` check a newly added target matches "unchanged" on
    // every pass and is therefore never written — leaving /status and the public
    // page reporting `unknown` forever for a target that is being probed
    // successfully every two minutes. Observed on 2026-09-11 when docs and pay
    // were added: both stayed "Unknown" on the page while their history bars
    // filled in green, because recordHistory writes on a new day and this did
    // not write at all. The first observation is the one that has to be saved.
    const unchanged =
      stored !== null &&
      state.alertedStatus === 'up' &&
      (state.consecutiveFailures || 0) === 0 &&
      !state.downSince;

    if (!unchanged) {
      await writeJson(env, key, {
        alertedStatus: 'up',
        consecutiveFailures: 0,
        downSince: null,
        lastOkAt: Date.now(),
      });
    }
    return { name: target.name, ok: true };
  }

  const failures = (state.consecutiveFailures || 0) + 1;
  // The moment of the FIRST failure, not of the alert. Otherwise every reported
  // outage is short by the threshold window, which is the one number an
  // operator reads the alert to learn.
  const downSince = state.downSince || Date.now();

  const shouldAlert = failures >= cfg.failuresBeforeAlert && state.alertedStatus !== 'down';

  await writeJson(env, key, {
    alertedStatus: shouldAlert ? 'down' : state.alertedStatus,
    consecutiveFailures: failures,
    downSince,
    lastOkAt: state.lastOkAt ?? null,
  });

  if (shouldAlert) {
    await notify(
      env,
      `🔴 *${target.name} is DOWN*\n${target.url}\n${probe.detail}\n` +
        `Failed ${failures} checks in a row.`
    );
  }

  return { name: target.name, ok: false, detail: probe.detail };
}

/**
 * A single HTTP probe.
 *
 * Treats a connection failure and an error status as the same kind of event —
 * both mean "a user asking for this right now does not get it" — but reports
 * them differently, because "connection refused" and "503 degraded" send an
 * operator to different places.
 */
async function probeOnce(url, timeoutMs, expectStatus) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, {
      signal: controller.signal,
      redirect: 'manual',
      headers: { 'user-agent': 'whity-watchdog/1' },
      // The point is to observe the origin, not Cloudflare's memory of it.
      cf: { cacheTtl: 0, cacheEverything: false },
    });

    const expected = expectStatus || 200;
    if (response.status !== expected) {
      let hint = '';
      try {
        const body = await response.text();
        if (body) hint = ` — ${body.slice(0, 200)}`;
      } catch {
        // A body we cannot read does not make the status less true.
      }
      return { ok: false, detail: `HTTP ${response.status} (expected ${expected})${hint}` };
    }

    return { ok: true };
  } catch (error) {
    const reason = error?.name === 'AbortError' ? `no response in ${timeoutMs}ms` : String(error?.message || error);
    return { ok: false, detail: `unreachable: ${reason}` };
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Read core's own component report once per run.
 *
 * Returns either the parsed component map or the reason it could not be had —
 * never a partial answer, because "the feed said nothing about the queue" and
 * "the feed was unreachable" send an operator somewhere different.
 */
async function fetchComponents(url, timeoutMs) {
  if (!url) return { ok: false, detail: 'COMPONENTS_URL is not set' };

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const response = await fetch(url, {
      signal: controller.signal,
      headers: { 'user-agent': 'whity-watchdog/1', accept: 'application/json' },
      cf: { cacheTtl: 0, cacheEverything: false },
    });
    if (response.status !== 200) {
      return { ok: false, detail: `component feed returned HTTP ${response.status}` };
    }

    const body = await response.json();
    const list = Array.isArray(body?.components) ? body.components : null;
    if (!list) {
      return { ok: false, detail: 'component feed had no components array' };
    }

    const byKey = {};
    for (const c of list) {
      if (c && typeof c.key === 'string') byKey[c.key] = c;
    }
    return { ok: true, byKey };
  } catch (error) {
    const reason = error?.name === 'AbortError' ? `no response in ${timeoutMs}ms` : String(error?.message || error);
    return { ok: false, detail: `component feed unreachable: ${reason}` };
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Turn one field of that report into the same shape probeOnce returns.
 *
 * An unreadable feed fails EVERY component that depends on it rather than
 * leaving them silently unobserved. It costs a few extra lines in an alert
 * during a host-level outage — where api and web are already failing and the
 * operator is already looking — and it buys the case that matters: the feed
 * alone breaking, which would otherwise freeze four components on their last
 * known verdict with nobody told the readings had stopped.
 */
function componentProbe(feed, key) {
  if (!feed || !feed.ok) {
    return { ok: false, detail: feed?.detail || 'component feed unavailable' };
  }

  const component = feed.byKey[key];
  if (!component) {
    // Core stopped reporting this component. Absence is not health.
    return { ok: false, detail: `component "${key}" is not in the feed` };
  }

  const status = String(component.status || 'unknown');
  if (status === 'operational') return { ok: true };

  // UNKNOWN is not a fault. The application reports it when a probe could not
  // measure something — an unconfigured render tier, a schedule that has never
  // run — and paging someone because a component was never measured is how a
  // monitor gets muted. It is not recorded as healthy either: the target is
  // skipped for this pass entirely, which leaves the history bar a GAP, the
  // same thing it draws for a day nobody looked at.
  if (status === 'unknown') {
    return { skip: true, detail: 'the application reports this component as not measured' };
  }

  // `degraded` counts as a failure here deliberately: this page has two states
  // and the honest place to put "working, but not properly" is the failing one.
  const name = component.name ? `${component.name} ` : '';
  return { ok: false, detail: `${name}reported "${status}" by the application` };
}

// ── history ─────────────────────────────────────────────────────────────────

/** UTC day key. One bucket per calendar day, per target. */
function dayKey(at = Date.now()) {
  return new Date(at).toISOString().slice(0, 10);
}

/**
 * Record one day per target: was it observed, and did anything fail.
 *
 * DELIBERATELY NOT AN UPTIME PERCENTAGE. Computing one honestly needs a write
 * on every check, which at a two-minute schedule is thousands a day against a
 * 1,000-a-day allowance — and it would be a percentage of the checks that
 * happened to run, not of the day, which is a different and more flattering
 * number than it appears. A day is therefore recorded as one of three things:
 * observed and clean, observed with a failure, or NOT OBSERVED.
 *
 * That third state is the important one. The checks here do not run reliably —
 * the Cloudflare cron fires erratically — so there will be gaps, and a gap is
 * the absence of evidence rather than evidence of health. Drawing it green
 * would be the same lie as a status page reporting "operational" from readings
 * it stopped collecting.
 *
 * COSTS ONE WRITE PER TARGET PER DAY in the healthy case: the bucket is only
 * written when the day is new, or when the first failure of that day flips it.
 */
async function recordHistory(env, cfg, results) {
  const today = dayKey();
  const keepDays = cfg.historyDays;

  for (const r of results) {
    const key = `history:${r.name}`;
    const doc = (await readJson(env, key)) || { days: {} };
    const existing = doc.days[today];

    // Nothing new: the day is already recorded, and already at least as bad as
    // this check. Skipping the write is the whole point of the shape.
    if (existing !== undefined && (existing === 'down' || r.ok)) continue;

    doc.days[today] = r.ok ? 'up' : 'down';

    // Prune outside the window so the document cannot grow without bound.
    const cutoff = dayKey(Date.now() - keepDays * 86400 * 1000);
    for (const d of Object.keys(doc.days)) {
      if (d < cutoff) delete doc.days[d];
    }

    await writeJson(env, key, doc);
  }
}

// ── the dead-man's switch ───────────────────────────────────────────────────

/**
 * Alert on SILENCE from a job that is supposed to report in.
 *
 * Two distinct states, deliberately, mirroring the repo's existing
 * WhityBackupStale / WhityBackupMetricMissing split: a job that HAS reported
 * and then stopped is a job that broke, while one that has NEVER reported is
 * usually one that was never wired up. Collapsing them into "stale" sends an
 * operator hunting for a regression in something that never ran.
 */
async function checkHeartbeats(env, cfg) {
  const name = 'backup';
  const beat = await readJson(env, `beat:${name}`);
  const stateKey = `beatstate:${name}`;
  const state = (await readJson(env, stateKey)) || { alertedStatus: 'ok' };

  const maxAgeMs = cfg.backupMaxAgeHours * 3600 * 1000;

  if (!beat || !beat.at) {
    if (state.alertedStatus !== 'missing') {
      await notify(
        env,
        `⚠️ *No ${name} has ever reported in*\n` +
          `The watchdog has never received a ${name} heartbeat, so either the job is not ` +
          `wired up to send one or it has never succeeded.`
      );
      await writeJson(env, stateKey, { alertedStatus: 'missing' });
    }
    return;
  }

  const age = Date.now() - beat.at;

  if (age > maxAgeMs) {
    if (state.alertedStatus !== 'stale') {
      await notify(
        env,
        `🔴 *${name} is overdue*\n` +
          `Last success ${humanDuration(age)} ago (expected within ${cfg.backupMaxAgeHours}h).\n` +
          `The job did not run, or ran and failed.`
      );
      await writeJson(env, stateKey, { alertedStatus: 'stale' });
    }
    return;
  }

  if (state.alertedStatus !== 'ok') {
    // THE RECOVERY HAS TO KEEP THE DISTINCTION THE ALARM MADE. "Never reported"
    // and "went stale" are deliberately different warnings, because they send
    // an operator on different hunts — and collapsing them back into one
    // recovery message undoes that. Observed on 2026-09-11: a backup that had
    // never once reported to this watchdog was announced as "reporting again",
    // which says it had been working and stopped. It had never started.
    const firstEver = state.alertedStatus === 'missing';
    await notify(
      env,
      firstEver
        ? `✅ *${name} is reporting for the first time*\nLast success ${humanDuration(age)} ago. The dead-man's switch is now armed.`
        : `✅ *${name} is reporting again*\nLast success ${humanDuration(age)} ago.`
    );
    await writeJson(env, stateKey, { alertedStatus: 'ok' });
  }
}

// ── the liveness summary ────────────────────────────────────────────────────

/**
 * Proof that the watchdog itself is alive. See DEFAULTS.summaryIntervalHours
 * for why a monitor that is silent when healthy needs one.
 */
async function maybeSendSummary(env, cfg, results) {
  const key = 'meta:last-summary';
  const last = await readJson(env, key);
  const dueAfterMs = cfg.summaryIntervalHours * 3600 * 1000;

  if (last && last.at && Date.now() - last.at < dueAfterMs) return;

  // Do not announce "all is well" on the first run after deploy, before a
  // single check has happened — that would be a claim with nothing behind it.
  if (!last) {
    await writeJson(env, key, { at: Date.now() });
    return;
  }

  const beat = await readJson(env, 'beat:backup');
  const lines = results.map((r) => `${r.ok ? '✅' : '🔴'} ${r.name}`).join('\n');
  const backupLine = beat?.at
    ? `✅ backup — last success ${humanDuration(Date.now() - beat.at)} ago`
    : '⚠️ backup — never reported';

  await notify(env, `👋 *Watchdog still watching*\n${lines}\n${backupLine}`);
  await writeJson(env, key, { at: Date.now() });
}

// ── the HTTP surface ────────────────────────────────────────────────────────

async function handleRequest(request, env) {
  const url = new URL(request.url);

  // POST /beat/<name> — a job reporting a success.
  //
  // AUTHENTICATED, and that is not ceremony. An open heartbeat endpoint lets
  // anybody who finds the URL silence the backup alarm forever by sending a
  // beat, which turns the safety net into a thing that reports success while
  // nothing is being backed up.
  if (request.method === 'POST' && url.pathname.startsWith('/beat/')) {
    const provided = (request.headers.get('authorization') || '').replace(/^Bearer\s+/i, '');
    if (!env.HEARTBEAT_SECRET || !timingSafeEqual(provided, env.HEARTBEAT_SECRET)) {
      return new Response('unauthorized\n', { status: 401 });
    }

    const name = url.pathname.slice('/beat/'.length).replace(/[^a-z0-9_-]/gi, '');
    if (!name) return new Response('bad heartbeat name\n', { status: 400 });

    await writeJson(env, `beat:${name}`, { at: Date.now() });
    return new Response('ok\n', { status: 200 });
  }

  // POST /alert — let anything outside this Worker raise an alarm through it.
  //
  // WHY THIS EXISTS. On 2026-09-10 two critical unauthenticated RCE advisories
  // were found live on the public deployment. Nothing detected them: they were
  // published against a dependency version that had not changed, so no build
  // ran, no test failed, and no probe could see it — a vulnerable server
  // answers 200 exactly like a patched one. They surfaced only because an
  // unrelated pull request happened to run `npm audit`.
  //
  // A liveness probe structurally cannot catch that. What can is a scheduled
  // job re-checking known advisories against unchanged code — and that job
  // needs somewhere to shout. This endpoint is that somewhere, so there is one
  // alerting path to configure and audit rather than one per source.
  if (request.method === 'POST' && url.pathname === '/alert') {
    const provided = (request.headers.get('authorization') || '').replace(/^Bearer\s+/i, '');
    if (!env.HEARTBEAT_SECRET || !timingSafeEqual(provided, env.HEARTBEAT_SECRET)) {
      return new Response('unauthorized\n', { status: 401 });
    }

    let payload;
    try {
      payload = await request.json();
    } catch {
      return new Response('expected a JSON body\n', { status: 400 });
    }

    const source = String(payload?.source ?? 'unknown').slice(0, 60);
    const text = String(payload?.text ?? '').slice(0, 3000);
    if (!text) return new Response('an alert with no text says nothing\n', { status: 400 });

    // Deliberately NOT deduplicated the way probe alerts are. A caller here has
    // already decided this is worth sending; suppressing it on the Worker's own
    // judgement would drop the one message somebody needed.
    const result = await notify(env, `🚨 *${source}*\n${text}`);

    // REPORT WHAT ACTUALLY HAPPENED. Answering "sent" whether or not Telegram
    // accepted it would make this endpoint a thing that claims success without
    // checking — and a caller that trusted it would believe somebody had been
    // told when nobody had. The status code carries it too, so a shell script
    // using `curl --fail` finds out without parsing anything.
    return Response.json(result, { status: result.delivered ? 200 : 502 });
  }

  // POST /run — force a check cycle now, instead of waiting for the cron.
  //
  // Earns its place twice. It is the only way to tell "the checks are broken"
  // apart from "the schedule is not firing" — two failures that look identical
  // from outside, since both leave the state empty and say nothing. And it lets
  // a change be verified on deploy rather than on faith, which for a component
  // whose healthy state is silence is the difference between working and
  // merely appearing to.
  //
  // Authenticated, because a forced run sends real alerts.
  if (request.method === 'POST' && url.pathname === '/run') {
    const provided = (request.headers.get('authorization') || '').replace(/^Bearer\s+/i, '');
    if (!env.HEARTBEAT_SECRET || !timingSafeEqual(provided, env.HEARTBEAT_SECRET)) {
      return new Response('unauthorized\n', { status: 401 });
    }

    try {
      // Awaited rather than handed to waitUntil: the caller asked for a result,
      // and a run whose errors vanish into the background is exactly what made
      // this endpoint necessary.
      await runChecks(env);
      return Response.json({ ran: true });
    } catch (error) {
      return Response.json({ ran: false, error: String(error?.stack || error) }, { status: 500 });
    }
  }

  // GET /status — for a human wondering what this thing currently believes.
  // Unauthenticated and deliberately thin: it publishes no URL, no secret and
  // no detail beyond up/down, which is already visible to anyone who can load
  // the site itself.
  //
  // `status` is the one word the sites' footers read (section 29):
  // "ok" | "degraded" | "down". Stale readings are "degraded", never "ok".
  if (request.method === 'GET' && url.pathname === '/status') {
    const cfg = config(env);
    const out = { status: 'ok', targets: {}, backup: null };

    for (const target of cfg.targets) {
      const state = await readJson(env, `state:${target.name}`);
      out.targets[target.name] = state ? state.alertedStatus : 'unknown';
    }

    const lastRun = await readJson(env, 'meta:last-run');
    const stale = !lastRun?.at || Date.now() - lastRun.at > STALE_AFTER_MS;
    const verdict = overallOf(Object.values(out.targets).map((status) => ({ status })), stale);
    out.status = verdict === 'ok' ? 'ok' : verdict === 'down' ? 'down' : 'degraded';

    const beat = await readJson(env, 'beat:backup');
    out.backup = beat?.at ? { lastSuccessAgeSeconds: Math.round((Date.now() - beat.at) / 1000) } : null;

    // The websites read this from another origin, and it holds nothing private.
    return Response.json(out, { headers: { 'access-control-allow-origin': '*', 'cache-control': 'no-store, max-age=0' } });
  }

  // GET / — the public status page, and /ar/ in Arabic.
  //
  // Served from the Worker, from KV, on a hostname that resolves through
  // Cloudflare's edge. It shares nothing with the systems it reports on: not
  // the database, not Docker, not the tunnel, not the machine. The status page
  // that already ships inside whity-core reads the same database that goes
  // down, which is why it showed nothing at all through a three-hour outage.
  if (request.method === 'GET' && (url.pathname === '/' || url.pathname === '/index.html')) {
    return renderStatusPage(env, config(env), 'en');
  }
  if (request.method === 'GET' && ['/ar', '/ar/', '/ar/index.html'].includes(url.pathname)) {
    return renderStatusPage(env, config(env), 'ar');
  }

  return new Response('not found\n', { status: 404 });
}

// ── the public status page ──────────────────────────────────────────────────
//
// The SiteStatus design (WS.2, docs/design/sites/health.whity.dev): one plain
// sentence on top, every part with its days of history, what is happening now,
// in English at / and Arabic at /ar/.
//
// EVERYTHING IS INLINE. The page requests no stylesheet, font or image, from
// whity.dev or from anywhere else: a status page that loads assets from the
// system it reports on goes blank in exactly the outage it exists to explain.
// The colours are the design tokens (docs/design/tokens.json) copied in; the
// fonts are named in case a visitor already has them, and the fallbacks do the
// real work. (Cloudflare injects its cookieless Web Analytics beacon at the
// edge; nothing here waits on it.)

const STALE_AFTER_MS = 15 * 60 * 1000;

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** Human-facing names, so the page never shows an internal key. */
const LABELS = {
  api: { en: 'API', ar: 'واجهة البرمجة' },
  web: { en: 'The app', ar: 'التطبيق' },
  site: { en: 'Website', ar: 'الموقع' },
  docs: { en: 'Documentation', ar: 'التوثيق' },
  pay: { en: 'Payments', ar: 'المدفوعات' },
  id: { en: 'Whity ID', ar: 'Whity ID' },
  database: { en: 'Database', ar: 'قاعدة البيانات' },
  queue: { en: 'Background jobs', ar: 'المهام في الخلفية' },
  scheduler: { en: 'Scheduled tasks', ar: 'المهام المجدولة' },
  render: { en: 'Document rendering', ar: 'إخراج المستندات' },
};

/**
 * What each part IS, for a reader who does not know the architecture: a
 * customer deciding whether to wait learns nothing from "pay: Down" unless the
 * page says what pay is. A hostname where there is one, plain words otherwise.
 */
const DESCRIPTIONS = {
  api: { en: 'app.whity.dev/api', ar: 'app.whity.dev/api' },
  web: { en: 'app.whity.dev', ar: 'app.whity.dev' },
  site: { en: 'whity.dev', ar: 'whity.dev' },
  docs: { en: 'docs.whity.dev', ar: 'docs.whity.dev' },
  pay: { en: 'pay.whity.dev', ar: 'pay.whity.dev' },
  id: { en: 'id.whity.dev', ar: 'id.whity.dev' },
  database: { en: 'Where your data is kept', ar: 'حيث تُحفظ بياناتك' },
  queue: { en: 'Emails, imports and exports', ar: 'الرسائل والاستيراد والتصدير' },
  scheduler: { en: 'Reminders, reports, routines', ar: 'التذكيرات والتقارير والروتين' },
  render: { en: 'PDFs and receipts', ar: 'ملفات PDF والإيصالات' },
};

/** Every visible string, in both languages. Numbers stay Western digits. */
const TEXT = {
  en: {
    title: 'Whity status',
    status: 'Status',
    otherLang: 'العربية',
    otherHref: '/ar/',
    working: 'Working',
    notWorking: 'Not working',
    notChecked: 'Not checked yet',
    forDuration: (d) => `for ${d}`,
    okTitle: 'Everything is working',
    okBody: (age) => `Checked ${age} ago.`,
    staleTitle: 'Status unknown',
    staleBody: (age) =>
      age === null
        ? 'No check has finished yet, so these readings are not current. Treat them as unknown.'
        : `The last check finished ${age} ago, so these readings are not current. Treat them as unknown.`,
    allDownTitle: 'Whity isn’t working',
    allDownBody: 'None of its parts are answering. We post updates here.',
    someDownTitle: (n, names) => (n === 1 ? `${names[0]} isn’t working` : `${n} parts of Whity aren’t working`),
    someDownBody: (names, since) => `${list(names, 'en')} ${names.length === 1 ? 'has' : 'have'} been stopped since ${since}. Everything else works.`,
    unknownTitle: 'Some parts haven’t been checked yet',
    unknownBody: 'Everything checked so far works.',
    uptime: (pct) => `${pct}% of checked days`,
    noHistory: 'No history yet',
    daysAgo: (wide, narrow) => `<span><span class="wide">${wide}</span><span class="narrow">${narrow}</span> days ago</span>`,
    today: 'Today',
    observed: (o, b) =>
      o === 0 ? 'No history yet' : `${o} day${o === 1 ? '' : 's'} observed · ${b === 0 ? 'no failures' : `${b} with failures`}`,
    barLabel: (summary, days) => `${summary} over the last ${days} days`,
    segLabel: { up: 'no failures', down: 'failure recorded', none: 'not observed' },
    backups: 'Backups',
    backupsDetail: 'Every night',
    backupOk: 'On time',
    backupLate: 'Late',
    backupNever: 'Never reported',
    happening: 'What’s happening',
    incTitle: (name) => `${name} isn’t working`,
    incSince: (since, age) => `Since ${since} · checked ${age} ago`,
    apart: 'This page runs apart from Whity, so it stays up when Whity doesn’t.',
  },
  ar: {
    title: 'حالة Whity',
    status: 'الحالة',
    otherLang: 'English',
    otherHref: '/',
    working: 'يعمل',
    notWorking: 'متوقف',
    notChecked: 'لم يُفحص بعد',
    forDuration: (d) => `منذ ${d}`,
    okTitle: 'كل شيء يعمل',
    okBody: (age) => `آخر فحص قبل ${age}.`,
    staleTitle: 'الحالة غير معروفة',
    staleBody: (age) =>
      age === null
        ? 'لم يكتمل أي فحص بعد، لذا هذه القراءات ليست حالية. اعتبرها غير معروفة.'
        : `اكتمل آخر فحص قبل ${age}، لذا هذه القراءات ليست حالية. اعتبرها غير معروفة.`,
    allDownTitle: 'Whity لا يعمل',
    allDownBody: 'لا يستجيب أي من أجزائه. ننشر التحديثات هنا.',
    someDownTitle: (n, names) =>
      n === 1 ? `${names[0]} لا يعمل` : n === 2 ? 'جزءان من Whity لا يعملان' : n <= 10 ? `${n} أجزاء من Whity لا تعمل` : `${n} جزءاً من Whity لا تعمل`,
    someDownBody: (names, since) => `${list(names, 'ar')} ${names.length === 1 ? 'متوقف' : 'متوقفة'} منذ ${since}. كل ما عدا ذلك يعمل.`,
    unknownTitle: 'لم تُفحص بعض الأجزاء بعد',
    unknownBody: 'كل ما فُحص حتى الآن يعمل.',
    uptime: (pct) => `${pct}% من الأيام المفحوصة`,
    noHistory: 'لا يوجد سجل بعد',
    daysAgo: (wide, narrow) => `<span>قبل <span class="wide">${wide}</span><span class="narrow">${narrow}</span> يوماً</span>`,
    today: 'اليوم',
    observed: (o, b) => (o === 0 ? 'لا يوجد سجل بعد' : `${o} يوم مفحوص · ${b === 0 ? 'بلا أعطال' : `${b} مع أعطال`}`),
    barLabel: (summary, days) => `${summary} خلال آخر ${days} يوماً`,
    segLabel: { up: 'بلا أعطال', down: 'سُجّل عطل', none: 'لم يُفحص' },
    backups: 'النسخ الاحتياطي',
    backupsDetail: 'كل ليلة',
    backupOk: 'في الموعد',
    backupLate: 'متأخر',
    backupNever: 'لم يُبلَّغ عنه قط',
    happening: 'ما الذي يحدث',
    incTitle: (name) => `${name} لا يعمل`,
    incSince: (since, age) => `منذ ${since} · آخر فحص قبل ${age}`,
    apart: 'هذه الصفحة تعمل بمعزل عن Whity، لذا تبقى متاحة حتى لو تعطل.',
  },
};

function list(names, lang) {
  if (names.length <= 1) return names.join('');
  const and = lang === 'ar' ? ' و' : ' and ';
  const sep = lang === 'ar' ? '، ' : ', ';
  return names.slice(0, -1).join(sep) + and + names[names.length - 1];
}

/** "3h 30m" in English; the same figures with Arabic unit letters in Arabic. */
function durationIn(lang, ms) {
  const d = humanDuration(ms);
  if (lang !== 'ar') return d;
  return d.replace(/(\d+)d/g, '$1 ي').replace(/(\d+)h/g, '$1 س').replace(/(\d+)m/g, '$1 د').replace(/(\d+)s/g, '$1 ث');
}

/** A day in Amman time, with Western digits ("3 October", "3 تشرين الأول"). */
function dateIn(lang, at, withTime = false) {
  const opts = { day: 'numeric', month: 'long', timeZone: 'Asia/Amman' };
  if (withTime) Object.assign(opts, { hour: '2-digit', minute: '2-digit', hour12: false });
  try {
    return new Intl.DateTimeFormat(lang === 'ar' ? 'ar-JO-u-nu-latn' : 'en-GB', opts).format(new Date(at));
  } catch {
    return new Date(at).toISOString().slice(0, withTime ? 16 : 10).replace('T', ' ');
  }
}

/** The overall verdict, shared by the page and the /status JSON. */
function overallOf(components, stale) {
  const anyDown = components.some((c) => c.status === 'down');
  const anyUnknown = components.some((c) => c.status === 'unknown');
  const allDown = components.length > 0 && components.every((c) => c.status === 'down');
  if (stale) return 'stale';
  if (allDown) return 'down';
  if (anyDown) return 'partial';
  if (anyUnknown) return 'unknown';
  return 'ok';
}

const LOGO = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 94.942 29.257" width="95" height="29" role="img" aria-label="Whity"><path style="fill:var(--logo-mark)" d="M5.0 7.0H7.0V5.0C7.0 2.790860891342163 8.790861129760742 1.0 11.0 1.0H19.0C21.209138870239258 1.0 23.0 2.790860891342163 23.0 5.0V13.0C23.0 15.209138870239258 21.209138870239258 17.0 19.0 17.0H17.0V19.0C17.0 21.209138870239258 15.209138870239258 23.0 13.0 23.0H5.0C2.790860891342163 23.0 1.0 21.209138870239258 1.0 19.0V11.0C1.0 8.790861129760742 2.790860891342163 7.0 5.0 7.0Z"/><path fill="#ffffff" d="M11.0 3.0H19.0C20.104570388793945 3.0 21.0 3.895430564880371 21.0 5.0V13.0C21.0 14.104569435119629 20.104570388793945 15.0 19.0 15.0H11.0C9.895430564880371 15.0 9.0 14.104569435119629 9.0 13.0V5.0C9.0 3.895430564880371 9.895430564880371 3.0 11.0 3.0Z"/><path fill="currentColor" d="M81.21 12.06L81.15 11.90L81.14 11.66L81.16 11.50L81.22 11.35L81.31 11.21L81.42 11.10L81.56 11.01L81.71 10.94L81.95 10.91L83.91 10.91L84.06 10.94L84.21 11.00L84.40 11.14L84.50 11.26L84.58 11.40L87.39 18.06L87.77 19.07L88.10 18.07L90.44 11.45L90.50 11.31L90.59 11.19L90.70 11.08L90.83 11.00L90.98 10.94L91.13 10.91L93.21 10.91L93.45 10.97L93.65 11.09L93.81 11.27L93.91 11.49L93.94 11.64L93.94 11.80L93.88 12.03L89.28 23.00L87.40 27.74L87.33 27.89L87.23 28.01L87.11 28.12L86.89 28.22L86.65 28.26L85.14 28.26L84.98 28.24L84.75 28.16L84.62 28.07L84.46 27.89L84.39 27.75L84.34 27.60L84.33 27.44L84.35 27.28L84.39 27.13L86.16 22.99ZM74.56 10.91L74.58 10.89L74.59 8.58L74.65 8.35L74.77 8.14L74.88 8.03L75.01 7.94L75.16 7.88L75.32 7.85L77.10 7.85L77.33 7.91L77.47 7.98L77.59 8.08L77.73 8.27L77.81 8.50L77.83 8.66L77.83 10.91L79.51 10.91L79.74 10.94L79.89 11.00L80.08 11.15L80.22 11.34L80.31 11.56L80.32 11.72L80.32 13.08L80.29 13.24L80.22 13.38L80.08 13.57L79.96 13.67L79.82 13.75L79.59 13.81L77.83 13.81L77.83 22.27L77.77 22.50L77.65 22.70L77.53 22.82L77.40 22.90L77.18 22.98L77.02 23.00L75.39 23.00L75.24 22.98L75.08 22.94L74.94 22.86L74.77 22.70L74.68 22.57L74.60 22.35L74.58 22.19L74.58 13.81L73.13 13.81L72.90 13.78L72.68 13.67L72.50 13.51L72.42 13.38L72.34 13.16L72.32 13.00L72.32 11.64L72.36 11.48L72.42 11.34L72.56 11.15L72.68 11.05L72.82 10.97L73.05 10.91ZM67.68 8.28L67.52 8.25L67.37 8.20L67.17 8.07L67.02 7.88L66.93 7.66L66.90 7.45L66.91 5.81L66.95 5.57L67.06 5.36L67.17 5.25L67.30 5.15L67.52 5.06L67.68 5.04L69.38 5.04L69.61 5.08L69.82 5.19L69.94 5.30L70.07 5.50L70.12 5.65L70.15 5.81L70.15 7.51L70.10 7.74L69.99 7.95L69.88 8.07L69.75 8.16L69.61 8.23L69.46 8.27ZM70.15 22.19L70.13 22.35L70.09 22.50L70.01 22.64L69.91 22.76L69.79 22.86L69.65 22.94L69.50 22.98L69.34 23.00L67.71 23.00L67.48 22.97L67.26 22.86L67.09 22.70L67.00 22.57L66.92 22.35L66.90 22.19L66.91 11.64L66.97 11.41L67.09 11.21L67.26 11.05L67.40 10.97L67.56 10.92L67.71 10.91L69.34 10.91L69.50 10.92L69.72 11.00L69.91 11.15L70.05 11.34L70.13 11.56L70.15 11.72ZM53.45 23.00L53.29 22.98L53.14 22.94L53.00 22.86L52.88 22.76L52.74 22.57L52.68 22.42L52.64 22.27L52.64 6.82L52.70 6.59L52.82 6.38L53.00 6.22L53.22 6.12L53.45 6.09L55.13 6.09L55.36 6.15L55.50 6.22L55.63 6.32L55.77 6.51L55.83 6.66L55.86 6.82L55.86 12.43L56.22 12.05L56.60 11.73L57.10 11.38L57.55 11.13L58.14 10.89L58.61 10.76L59.09 10.68L59.60 10.66L60.23 10.69L60.83 10.79L61.38 10.96L61.75 11.13L62.16 11.39L62.56 11.74L62.82 12.05L63.12 12.53L63.36 13.07L63.54 13.65L63.63 14.12L63.72 14.84L63.75 15.62L63.75 22.27L63.71 22.42L63.65 22.57L63.51 22.76L63.39 22.86L63.25 22.94L63.02 23.00L61.31 23.00L61.16 22.98L61.00 22.94L60.86 22.86L60.74 22.76L60.64 22.64L60.54 22.42L60.50 22.19L60.50 15.72L60.46 15.21L60.38 14.77L60.24 14.39L60.09 14.13L59.88 13.88L59.65 13.71L59.33 13.56L58.98 13.48L58.65 13.45L58.16 13.46L57.81 13.51L57.42 13.62L57.06 13.78L56.78 13.96L56.49 14.22L56.26 14.51L56.07 14.83L55.97 15.13L55.91 15.44L55.89 15.75L55.89 22.19L55.87 22.35L55.79 22.57L55.70 22.70L55.53 22.86L55.39 22.94L55.23 22.98L55.07 23.00ZM31.64 7.00L31.88 7.04L32.10 7.14L32.28 7.30L32.36 7.43L32.42 7.58L34.98 16.27L35.18 17.09L35.36 18.12L35.59 17.09L35.93 15.96L38.29 9.83L38.41 9.62L38.59 9.45L38.81 9.34L39.05 9.31L40.76 9.31L40.93 9.33L41.08 9.37L41.23 9.45L41.35 9.56L41.45 9.69L41.52 9.83L43.86 16.05L44.18 17.04L44.43 18.00L44.57 17.16L44.89 15.92L47.33 7.58L47.39 7.44L47.48 7.30L47.65 7.14L47.79 7.06L48.03 7.00L49.95 7.00L50.11 7.04L50.26 7.10L50.45 7.24L50.55 7.36L50.65 7.58L50.69 7.81L50.65 8.05L46.23 22.43L46.17 22.57L46.03 22.76L45.91 22.86L45.77 22.94L45.54 23.00L44.06 23.00L43.83 22.97L43.68 22.90L43.49 22.76L43.39 22.64L43.31 22.50L39.76 14.00L36.43 22.48L36.36 22.63L36.20 22.81L36.06 22.90L35.84 22.98L35.68 23.00L34.14 23.00L33.91 22.94L33.77 22.86L33.65 22.76L33.55 22.64L33.45 22.43L29.03 8.05L29.00 7.89L29.01 7.66L29.06 7.50L29.13 7.36L29.23 7.24L29.36 7.14L29.57 7.04L29.81 7.00Z"/></svg>`;

async function renderStatusPage(env, cfg, lang = 'en') {
  const L = TEXT[lang] || TEXT.en;
  const lastRun = await readJson(env, 'meta:last-run');
  const dataAge = lastRun?.at ? Date.now() - lastRun.at : null;

  // THE FIRST THING ESTABLISHED, BEFORE ANY COMPONENT IS REPORTED. If the
  // checks stopped running, every "working" below is a claim about the past
  // dressed up as the present, so the page says so instead of reassuring.
  const stale = dataAge === null || dataAge > STALE_AFTER_MS;

  const components = [];
  for (const target of cfg.targets) {
    const state = await readJson(env, `state:${target.name}`);
    const history = await readJson(env, `history:${target.name}`);
    components.push({
      name: LABELS[target.name]?.[lang] || target.name,
      detail: DESCRIPTIONS[target.name]?.[lang] || '',
      status: state?.alertedStatus ?? 'unknown',
      downSince: state?.alertedStatus === 'down' ? state?.downSince ?? null : null,
      days: history?.days ?? {},
    });
  }

  const beat = await readJson(env, 'beat:backup');
  const backupAge = beat?.at ? Date.now() - beat.at : null;
  const backupOk = backupAge !== null && backupAge <= cfg.backupMaxAgeHours * 3600 * 1000;

  // ONE PLAIN SENTENCE on top, and what it means underneath.
  const verdict = overallOf(components, stale);
  const down = components.filter((c) => c.status === 'down');
  const firstDown = down.map((c) => c.downSince).filter(Boolean).sort((a, b) => a - b)[0] ?? Date.now();
  const banner = {
    stale: { kind: 'warn', title: L.staleTitle, body: L.staleBody(dataAge === null ? null : durationIn(lang, dataAge)) },
    down: { kind: 'bad', title: L.allDownTitle, body: L.allDownBody },
    partial: {
      kind: 'warn',
      title: L.someDownTitle(down.length, down.map((c) => c.name)),
      body: L.someDownBody(down.map((c) => c.name), dateIn(lang, firstDown)),
    },
    unknown: { kind: 'warn', title: L.unknownTitle, body: L.unknownBody },
    ok: { kind: 'ok', title: L.okTitle, body: L.okBody(durationIn(lang, dataAge ?? 0)) },
  }[verdict];

  const stateOf = (s) =>
    s === 'up' ? ['is-ok', L.working] : s === 'down' ? ['is-down', L.notWorking] : ['is-warn', L.notChecked];

  // How much of the record the narrow (phone) layout shows. Paired with the
  // `max-width: 560px` rule below — change both together or the caption stops
  // matching the bar.
  const narrowDays = Math.min(30, cfg.historyDays);
  const hiddenSegs = cfg.historyDays - narrowDays;

  /**
   * The history bar: one segment per day, oldest first.
   *
   * THREE STATES, NOT TWO. A day with no recorded check is drawn as a GAP, not
   * as green: an unobserved day is the absence of evidence rather than evidence
   * of health, and colouring it green would be the same untruth as a status
   * page reporting "working" from readings it stopped collecting.
   *
   * A phone cannot show 90 days (90 segments want ~358px and get 230-300px),
   * so the narrow layout shows the most recent days and its caption counts
   * only those. Every segment stays in the DOM, so a screen reader gets the
   * whole record either way.
   */
  const history = (days) => {
    const segs = [];
    let observed = 0;
    let bad = 0;
    let narrowObserved = 0;
    let narrowBad = 0;

    for (let i = cfg.historyDays - 1; i >= 0; i--) {
      const d = dayKey(Date.now() - i * 86400 * 1000);
      const v = days[d];
      const cls = v === 'up' ? 'ok' : v === 'down' ? 'down' : 'gap';
      if (v) observed++;
      if (v === 'down') bad++;
      if (i < narrowDays) {
        if (v) narrowObserved++;
        if (v === 'down') narrowBad++;
      }
      segs.push(`<i class="seg ${cls}" title="${d} · ${L.segLabel[v === 'up' ? 'up' : v === 'down' ? 'down' : 'none']}"></i>`);
    }

    const summary = L.observed(observed, bad);
    const narrowSummary = L.observed(narrowObserved, narrowBad);
    const pct = observed === 0 ? null : ((observed - bad) / observed) * 100;
    const uptime = pct === null ? L.noHistory : L.uptime(pct === 100 ? '100' : pct.toFixed(1));

    return {
      uptime,
      bar: `<div class="s-bars" role="img" aria-label="${escapeHtml(L.barLabel(summary, cfg.historyDays))}">${segs.join('')}</div>
        <div class="s-barlab">${L.daysAgo(cfg.historyDays, narrowDays)}<span class="sum"><span class="wide">${escapeHtml(summary)}</span><span class="narrow">${escapeHtml(narrowSummary)}</span></span><span>${L.today}</span></div>`,
    };
  };

  const rows = components
    .map((c) => {
      const [cls, word] = stateOf(c.status);
      const h = history(c.days);
      const since = c.downSince ? ` <span class="since">${escapeHtml(L.forDuration(durationIn(lang, Date.now() - c.downSince)))}</span>` : '';
      return `
      <div>
        <header>
          <div><b>${escapeHtml(c.name)}</b>${c.detail ? `<small dir="auto">${escapeHtml(c.detail)}</small>` : ''}</div>
          <div class="end"><span class="s-state ${cls}">${word}${since}</span><small dir="auto">${escapeHtml(h.uptime)}</small></div>
        </header>
        ${h.bar}
      </div>`;
    })
    .join('');

  const [bCls, bWord] = backupAge === null ? ['is-warn', L.backupNever] : backupOk ? ['is-ok', L.backupOk] : ['is-down', L.backupLate];
  const backupRow = `
      <div>
        <header>
          <div><b>${L.backups}</b><small>${L.backupsDetail}</small></div>
          <div class="end"><span class="s-state ${bCls}">${bWord}</span></div>
        </header>
      </div>`;

  const incidents = down
    .map(
      (c) => `<div class="s-inc"><b>${escapeHtml(L.incTitle(c.name))}</b>${
        c.downSince ? `<small>${escapeHtml(L.incSince(dateIn(lang, c.downSince, true), durationIn(lang, dataAge ?? 0)))}</small>` : ''
      }</div>`
    )
    .join('');

  const html = `<!doctype html>
<html lang="${lang}" dir="${lang === 'ar' ? 'rtl' : 'ltr'}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>${L.title}</title>
<link rel="alternate" hreflang="${lang === 'ar' ? 'en' : 'ar'}" href="${L.otherHref}">
<style>
  /* Tokens (docs/design/tokens.json): light, then dark by the system setting. */
  :root{
    --background:oklch(0.95 0 0);--foreground:oklch(0.145 0 0);--card:oklch(1 0 0);
    --muted:oklch(0.97 0 0);--muted-foreground:oklch(0.52 0 0);--border:oklch(0.922 0 0);
    --destructive:#cc2827;--success:#0a7e3a;--warning:#f2b036;
    --success-text:#087736;--success-tint:#e0fae4;--warning-text:#9a5505;--warning-tint:#fef4df;
    --destructive-tint:#fde8e7;--logo-mark:#0961bb;
    --font-sans:'Noto Sans','Noto Sans Arabic',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
    --font-arabic:'Noto Sans Arabic','Noto Sans',sans-serif;
    color-scheme:light;
  }
  @media (prefers-color-scheme:dark){:root{
    --background:oklch(0.145 0 0);--foreground:oklch(0.985 0 0);--card:oklch(0.205 0 0);
    --muted:oklch(0.269 0 0);--muted-foreground:oklch(0.708 0 0);--border:oklch(1 0 0 / 0.1);
    --destructive:#f66d67;--success:#5ac576;--warning:#f7b83d;
    --success-text:#5ac576;--success-tint:#14361d;--warning-text:#f7b83d;--warning-tint:#432f07;
    --destructive-tint:#3d1514;--logo-mark:#59a0f9;
    color-scheme:dark;
  }}
  *,*::before,*::after{box-sizing:border-box}
  body{margin:0;background:var(--background);color:var(--foreground);font-family:var(--font-sans);font-size:17px;line-height:1.6}
  [dir="rtl"] body{font-family:var(--font-arabic)}
  a{color:inherit;text-decoration:none}
  h1,h2,p{margin:0}
  .s-nav{position:sticky;top:0;z-index:10;background:var(--background);border-bottom:1px solid var(--border)}
  .s-wrap{max-width:860px;margin:0 auto;padding:0 24px;display:flex;align-items:center;gap:16px;height:68px}
  .s-logo{display:inline-flex;align-items:center;gap:10px;font-weight:600}
  .s-logo svg{height:28px;width:auto;display:block}
  .s-logo small{font-weight:500;color:var(--muted-foreground);font-size:15px;padding-inline-start:10px;border-inline-start:1px solid var(--border)}
  .s-lang{margin-inline-start:auto;color:var(--muted-foreground);font-size:15px;min-height:48px;display:inline-flex;align-items:center;padding:0 6px}
  .s-lang:hover{color:var(--foreground)}
  .s-status{max-width:860px;margin:0 auto;padding:40px 24px 64px;display:grid;gap:22px}
  .s-banner{border-radius:16px;padding:18px 20px;display:grid;gap:4px}
  .s-banner.is-ok{background:var(--success-tint)}
  .s-banner.is-warn{background:var(--warning-tint)}
  .s-banner.is-bad{background:var(--destructive-tint)}
  .s-banner h1{font-size:20px;font-weight:600;line-height:1.35}
  .s-comp{border:1px solid var(--border);border-radius:16px;background:var(--card)}
  .s-comp>div{padding:16px 18px;border-bottom:1px solid var(--border);display:grid;gap:10px}
  .s-comp>div:last-child{border-bottom:0}
  .s-comp header{display:flex;justify-content:space-between;align-items:center;gap:12px}
  .s-comp header small{display:block;color:var(--muted-foreground);font-size:14.5px}
  .s-comp header .end{text-align:end}
  .s-comp header .end small{font-size:13px}
  .s-state{font-size:14px;font-weight:600;display:inline-flex;gap:6px;align-items:center}
  .s-state::before{content:"";width:10px;height:10px;border-radius:50%;background:currentColor;flex:none}
  .s-state.is-ok{color:var(--success-text)}
  .s-state.is-down{color:var(--destructive)}
  .s-state.is-warn{color:var(--warning-text)}
  .s-state .since{font-weight:400;color:var(--muted-foreground)}
  .s-bars{display:flex;gap:2px;height:30px}
  .seg{flex:1 1 0;min-width:2px;border-radius:2px;display:block}
  .seg.ok{background:var(--success);opacity:.85}
  .seg.down{background:var(--destructive)}
  /* A day nobody looked at is NOT green: inert and low-contrast, "no data". */
  .seg.gap{background:var(--muted)}
  .s-barlab{display:flex;justify-content:space-between;gap:12px;font-size:14px;color:var(--muted-foreground)}
  .s-barlab .sum{text-align:center}
  .s-barlab .narrow{display:none}
  .s-inc{border-inline-start:3px solid var(--destructive);padding-inline-start:14px;display:grid;gap:4px}
  .s-inc small{color:var(--muted-foreground);font-size:15px}
  h2{font-size:22px;font-weight:600}
  .s-foot{font-size:15px;color:var(--muted-foreground);display:grid;gap:8px}
  .s-foot nav{display:flex;flex-wrap:wrap;gap:4px 20px}
  .s-foot a{text-decoration:underline;text-underline-offset:3px;min-height:32px;display:inline-flex;align-items:center}
  .s-foot a:hover{color:var(--foreground)}
  @media (max-width:560px){
    .s-wrap,.s-status{padding-inline:16px}
    .s-logo small{display:none}
${hiddenSegs > 0 ? `    .seg:nth-child(-n+${hiddenSegs}){display:none}
    .s-barlab .wide{display:none}
    .s-barlab .narrow{display:inline}
` : ''}    .s-bars{gap:3px}
    .seg{min-width:4px;border-radius:3px}
    .s-barlab{font-size:13px;flex-wrap:wrap}
    .s-barlab .sum{order:3;flex-basis:100%;text-align:start}
  }
</style>
</head>
<body>
<header class="s-nav"><div class="s-wrap">
  <a class="s-logo" href="https://whity.dev">${LOGO}<small>${L.status}</small></a>
  <a class="s-lang" href="${L.otherHref}" lang="${lang === 'ar' ? 'en' : 'ar'}">${L.otherLang}</a>
</div></header>
<main class="s-status">
  <div class="s-banner is-${banner.kind}" role="status"><h1>${escapeHtml(banner.title)}</h1><p>${escapeHtml(banner.body)}</p></div>
  <div class="s-comp">${rows}${backupRow}
  </div>
  ${incidents ? `<h2>${L.happening}</h2>${incidents}` : ''}
  <footer class="s-foot">
    <p>${L.apart}</p>
    <nav><a href="https://whity.dev/">whity.dev</a><a href="https://docs.whity.dev/">docs.whity.dev</a><a href="/status" dir="ltr">JSON: health.whity.dev/status</a></nav>
  </footer>
</main>
</body>
</html>`;

  return new Response(html, {
    status: 200,
    headers: {
      'content-type': 'text/html; charset=utf-8',
      // Never cache a status page. A cached "working" outliving the outage it
      // was captured before is the failure this exists to prevent.
      'cache-control': 'no-store, max-age=0',
      'content-language': lang,
    },
  });
}

// ── plumbing ────────────────────────────────────────────────────────────────

async function readJson(env, key) {
  try {
    return await env.WATCHDOG_STATE.get(key, { type: 'json' });
  } catch {
    return null;
  }
}

async function writeJson(env, key, value) {
  await env.WATCHDOG_STATE.put(key, JSON.stringify(value));
}

/**
 * Constant-time-ish comparison for the heartbeat secret. Length is compared
 * first and leaks only that, which is not the secret.
 */
function timingSafeEqual(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

function humanDuration(ms) {
  const seconds = Math.max(0, Math.round(ms / 1000));
  if (seconds < 60) return `${seconds}s`;
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes}m`;
  const hours = Math.floor(minutes / 60);
  const remainder = minutes % 60;
  if (hours < 24) return remainder ? `${hours}h ${remainder}m` : `${hours}h`;
  const days = Math.floor(hours / 24);
  return `${days}d ${hours % 24}h`;
}

/**
 * Send to Telegram.
 *
 * A failure to deliver is logged and swallowed: an alert we could not send
 * must not abort the run and take the remaining checks — and the recording of
 * their state — down with it.
 */
async function notify(env, text) {
  if (!env.TELEGRAM_BOT_TOKEN || !env.TELEGRAM_CHAT_ID) {
    console.error('watchdog: telegram is not configured; alert dropped:', text);
    return { delivered: false, reason: 'telegram is not configured' };
  }

  try {
    const response = await fetch(`https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        chat_id: env.TELEGRAM_CHAT_ID,
        text,
        parse_mode: 'Markdown',
        disable_web_page_preview: true,
      }),
    });

    // TELEGRAM ANSWERS 200 WITH `ok: false`. A transport-level check alone
    // would call a rejected message delivered — wrong chat id, a revoked
    // token, a bot blocked by the user — which is the failure this component
    // exists to end, reproduced inside it.
    const body = await response.json().catch(() => null);
    if (!response.ok || !body?.ok) {
      const reason = body?.description || `HTTP ${response.status}`;
      console.error('watchdog: telegram rejected the alert:', reason);
      return { delivered: false, reason };
    }

    return { delivered: true };
  } catch (error) {
    const reason = String(error?.message || error);
    console.error('watchdog: telegram unreachable', reason);
    return { delivered: false, reason };
  }
}

// Exported for the unit tests, which exercise the state machine directly
// rather than through a live Worker.
export const __test__ = { humanDuration, timingSafeEqual, config, probeOnce, checkTarget, runChecks, fetchComponents, componentProbe, checkHeartbeats, handleRequest, renderStatusPage, recordHistory, dayKey, overallOf };
