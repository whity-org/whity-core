<?php

declare(strict_types=1);

/**
 * CI SDK version-collision guard (#873).
 *
 * A version collision is VERTICAL: each branch agrees with itself, and only
 * history can show the clash.
 *
 * #871 and #872 were built in parallel and both set Sdk::VERSION to 1.30.0.
 * Both branches were internally consistent, and both were wrong relative to
 * HISTORY. Whichever merged second silently overwrote the first's version
 * number with a different meaning, and nothing in CI said a word.
 *
 * The damage is not cosmetic. Sdk::VERSION is the value a plugin's
 * getSdkConstraint() is evaluated against ({@see PluginRequirementsGate}), so
 * two releases both called 1.30.0 leave a plugin declaring `^1.30` bound to
 * whichever meaning survived the race, with no way to tell which one it got.
 * The desktop template's SDK copy then ships that ambiguity to devices,
 * where a plugin that cannot resolve its contract cannot even be DECLARED
 * (#849) — a failure that surfaces on hardware, offline, after the fact.
 *
 * Three checks, none of which subsumes the others:
 *
 *   1. THE THREE PINS AGREE. The SDK states its version in three places — the
 *      Sdk.php header docblock ("SDK identity (vX.Y)"), the newest entry in
 *      sdk/CHANGELOG.md (`## X.Y`, the ledger since 1.162, decision D7; before
 *      that it was the Sdk.php docblock), and the VERSION constant — and once
 *      only the constant was gated.
 *      #872 merged with the constant at 1.30.0, a 1.30 ledger entry, and line 8
 *      still reading "SDK identity (v1.29)": the file contradicted itself in its
 *      own opening sentence. That is not carelessness, it is what partial
 *      enforcement produces. A red test teaches you to update the pin it
 *      watches; the unwatched pin rots, and it rots in the ledger a plugin
 *      author reads to decide which constraint to write.
 *
 *   2. THE LEDGER RUNS NEWEST-FIRST, strictly. sdk/CHANGELOG.md puts the
 *      newest minor at the top. (Until 1.162 there were two ledgers running in
 *      opposite directions, the Sdk.php docblock and an assertion message in
 *      SdkPackageContractTest; one file replaced both.) An entry out of order
 *      reads as though one PR's feature shipped in the other's release — which
 *      is precisely how a collision gets "resolved" into a wrong but plausible
 *      file. The ledger is therefore checked for STRICT monotonicity, which also
 *      makes a DUPLICATE entry an error: two 1.30 entries in one ledger is the exact
 *      residue left behind when two branches that both claimed 1.30 get merged
 *      by hand, and it is the only trace of the collision that survives an
 *      up-to-date branch (see check 3's note on why the vertical check alone
 *      cannot catch that case).
 *
 *   3. THE DECLARED VERSION MOVED FORWARD AND IS UNUSED. Against the merge base
 *      with develop, not against a sibling tree: if the version changed it must
 *      be strictly GREATER than the base's, and it must not already appear
 *      anywhere in develop's history of this file. The second half is the one
 *      that catches #873 directly — a branch cut before a rival landed sees an
 *      old base version, so "greater than base" passes happily while the number
 *      it picked is already spoken for on develop.
 *
 * WHY AN UNCHANGED Sdk.php PASSES SILENTLY. Most PRs do not touch the SDK, and
 * a guard that fires on them is a guard that gets deleted. So when the declared
 * version equals the merge base's, there is nothing vertical to compare and
 * check 3 is satisfied by construction — checks 1 and 2 still run, because they
 * read the file as it stands rather than as it changed, and a file that already
 * contradicts itself should not be allowed to stay that way just because this
 * PR was not the one that broke it.
 *
 * WHY IT FAILS CLOSED WHEN IT CANNOT SEE HISTORY. actions/checkout defaults to
 * depth 1. In a shallow clone `git log develop -- sdk/src/Sdk.php` answers with
 * whatever few commits came along, which is a SMALLER version set than the real
 * one, and a smaller set means a collision that exists in history is reported
 * as unused. That is the same shape of silent false green this whole issue is
 * about, so a guard that cannot prove it can see develop's history refuses to
 * pass rather than guessing. The workflow gives this job fetch-depth: 0; the
 * check below exists so that removing it fails loudly instead of quietly.
 *
 * NOT CHECKED HERE, deliberately:
 *   - the desktop template's copy — it is made from sdk/src at every desktop
 *     build (WHIT-1054), so its pins are this file's pins.
 *   - sdk/composer.json's `version` — SdkPackageContractTest already pins it to
 *     Sdk::VERSION, and a second copy of that assertion is a second thing to
 *     keep in step.
 *
 * NOT WIRED INTO scripts/ci-local.sh, unlike most of the guards beside it, and
 * deliberately: `ci-local.sh --clean` runs against a `git archive` export of
 * HEAD, which by design carries no .git. A guard whose entire subject is
 * history has nothing to read in that tree — it would fail closed there,
 * correctly and uselessly, on every local run. Run it in a real checkout.
 *
 * Usage:  php scripts/ci-sdk-version-collision.php
 */

$projectRoot = dirname(__DIR__);
$sdkFileRelative = 'sdk/src/Sdk.php';
$sdkFile = $projectRoot . '/' . $sdkFileRelative;
$changelogFileRelative = 'sdk/CHANGELOG.md';
$changelogFile = $projectRoot . '/' . $changelogFileRelative;

/**
 * The branch every SDK version number is unique against. `develop` is the
 * integration branch: main only ever receives what develop already carried, so
 * a version that is free on develop is free everywhere.
 */
const BASE_BRANCH = 'develop';

/**
 * Run git and return [exit code, stdout lines]. stderr is folded into the
 * output so a failure explains itself in the message this script prints.
 *
 * @param  list<string> $args
 * @return array{0: int, 1: list<string>}
 */
function git(string $projectRoot, array $args): array
{
    $command = 'git -C ' . escapeshellarg($projectRoot);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);

    return [$status, array_values($output)];
}

function fail(string $summary, string $detail, string $remediation): never
{
    fwrite(STDERR, 'FAIL: ' . $summary . "\n\n");
    if ($detail !== '') {
        fwrite(STDERR, $detail . "\n\n");
    }
    fwrite(STDERR, $remediation . "\n");
    exit(1);
}

/**
 * The MAJOR.MINOR a version string identifies. The ledgers and the header
 * docblock name minors ("1.34"); the constant carries a full semver
 * ("1.34.0", and historically a patch such as "1.1.1"). Comparing the three
 * pins on the minor is therefore comparing what they each actually claim.
 */
function minorOf(string $version): string
{
    $parts = explode('.', $version);

    return $parts[0] . '.' . ($parts[1] ?? '0');
}

/** The VERSION constant declared by a given Sdk.php source text, or null. */
function declaredVersion(string $source): ?string
{
    if (preg_match('/const\s+VERSION\s*=\s*\'(\d+\.\d+(?:\.\d+)?)\'/', $source, $m) !== 1) {
        return null;
    }

    return $m[1];
}

// ---------------------------------------------------------------------------
// 0. The file itself
// ---------------------------------------------------------------------------

if (!is_file($sdkFile)) {
    fail(
        "there is no {$sdkFileRelative} to check.",
        '',
        "This guard reads the canonical SDK identity file. If it moved, move this script's path with it."
    );
}

$source = (string) file_get_contents($sdkFile);
$version = declaredVersion($source);

if ($version === null) {
    fail(
        "could not read Sdk::VERSION out of {$sdkFileRelative}.",
        '',
        "The constant must read exactly:\n\n"
        . "    public const VERSION = '1.2.3';\n\n"
        . 'Every gate that evaluates a plugin\'s SDK constraint reads that constant, so an '
        . "unparseable one is not a formatting nit.\n"
    );
}

$minor = minorOf($version);
$failures = [];

// ---------------------------------------------------------------------------
// 1. The three pins agree: Sdk.php header, changelog, constant
// ---------------------------------------------------------------------------

// The header docblock's opening sentence: " * SDK identity (v1.34)."
if (preg_match('/SDK identity \(v(\d+\.\d+)\)/', $source, $m) !== 1) {
    $failures[] = sprintf(
        '  [header]    %s has no "SDK identity (vX.Y)" sentence to check. It is the version a plugin '
        . 'author reads first; it is a pin whether or not anything enforces it.',
        $sdkFileRelative
    );
} elseif ($m[1] !== $minor) {
    $failures[] = sprintf(
        '  [header]    the header docblock says "SDK identity (v%s)" while the constant says %s. '
        . 'The file contradicts itself in its own opening sentence (this is #872 exactly).',
        $m[1],
        $version
    );
}

/**
 * The minors in sdk/CHANGELOG.md, in the order the file states them: each
 * entry is a `## X.Y` heading (SDK 1.162, decision D7). A heading that is not a
 * bare version (the file's own title, a note) is not an entry.
 *
 * @return list<string>
 */
function changelogVersions(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    preg_match_all('/^## (\d+\.\d+)\s*$/m', str_replace("\r\n", "\n", (string) file_get_contents($path)), $matches);

    return $matches[1];
}

// ---------------------------------------------------------------------------
// 2. Each ledger runs in its own direction, strictly
// ---------------------------------------------------------------------------

/**
 * @param  list<string> $versions
 * @return list<string> the ordering violations, as human sentences
 */
function monotonicityFailures(array $versions, string $direction, string $where): array
{
    $operator = $direction === 'ascending' ? '>' : '<';
    $problems = [];

    for ($i = 1, $count = count($versions); $i < $count; $i++) {
        $previous = $versions[$i - 1];
        $current = $versions[$i];

        if ($previous === $current) {
            $problems[] = sprintf(
                '  [ledger]    %s lists %s twice. Two entries under one version number is what a '
                . 'hand-merged collision leaves behind — the two features are not distinguishable '
                . 'by the constraint a plugin writes.',
                $where,
                $current
            );
            continue;
        }

        if (!version_compare($current, $previous, $operator)) {
            $problems[] = sprintf(
                '  [ledger]    %s is %s (oldest %s), but %s follows %s. An entry out of order reads '
                . 'as though one release shipped another\'s feature.',
                $where,
                $direction,
                $direction === 'ascending' ? 'first' : 'last',
                $current,
                $previous
            );
        }
    }

    return $problems;
}

$ledger = changelogVersions($changelogFile);

// A one-entry ledger is not a short ledger, it is a parse that stopped working:
// monotonicity is vacuous on a single element, so an extraction that silently
// degraded would report OK forever. The ledger has carried well over a hundred
// entries since 1.0 and cannot shrink — the policy is additive.
if (count($ledger) < 2) {
    $failures[] = sprintf(
        '  [ledger]    found %d entr(ies) in %s. It is the record a plugin author reads to pick a '
        . 'constraint, and it only grows — so this is a file that was reformatted (or removed) out '
        . 'from under the guard, not a short one. Each entry is a `## X.Y` heading.',
        count($ledger),
        $changelogFileRelative
    );
} else {
    // NEWEST-FIRST: the newest entry is the FIRST one.
    $failures = array_merge($failures, monotonicityFailures($ledger, 'descending', $changelogFileRelative));

    if ($ledger[0] !== $minor) {
        $failures[] = sprintf(
            '  [ledger]    %s is NEWEST-FIRST, so its first entry is the release being shipped — but '
            . 'that entry is %s while the constant says %s. A bump with no changelog entry ships a '
            . 'version number nothing explains.',
            $changelogFileRelative,
            $ledger[0],
            $version
        );
    }
}

if ($failures !== []) {
    fail(
        sprintf('the SDK version %s is not stated consistently.', $version),
        implode("\n", $failures),
        "The SDK pins its version in THREE places and they must agree:\n\n"
        . "    1. the Sdk.php header docblock  — \" * SDK identity (v{$minor}).\"\n"
        . "    2. the first entry in {$changelogFileRelative} — \"## {$minor}\" (NEWEST-FIRST)\n"
        . "    3. the constant                 — \"public const VERSION = '{$version}';\"\n\n"
        . "The entry for this release goes at the TOP of the changelog, above the one before it. An\n"
        . "entry out of order is how one PR's feature ends up documented as another PR's release, so\n"
        . "the order is asserted rather than remembered.\n"
    );
}

// ---------------------------------------------------------------------------
// 3. The declared version moved forward, and nothing on develop used it
// ---------------------------------------------------------------------------

[$status, $output] = git($projectRoot, ['rev-parse', '--git-dir']);
if ($status !== 0) {
    fail(
        'this is not a git checkout, so the version cannot be compared against history.',
        implode("\n", $output),
        "This guard is vertical by definition — it compares the declared version against what develop\n"
        . "already shipped. Without history there is nothing to compare, and passing anyway would be\n"
        . "the silent false green #873 is about. Run it from a git checkout.\n"
    );
}

// Shallow first, and fail on it, because a shallow clone does not error — it
// answers with a SMALLER history than the real one, so a collision reads as
// unused. actions/checkout defaults to depth 1; the job that runs this passes
// fetch-depth: 0.
[$status, $output] = git($projectRoot, ['rev-parse', '--is-shallow-repository']);
if ($status === 0 && ($output[0] ?? '') === 'true') {
    fail(
        'the checkout is SHALLOW, so develop\'s version history is not visible.',
        '',
        "A shallow clone answers `git log develop -- {$sdkFileRelative}` with only the commits it\n"
        . "happens to carry, which is a smaller set of used versions than the real one — so a version\n"
        . "that IS already taken would be reported as free. That is a false green of exactly the kind\n"
        . "this guard exists to remove, so it refuses to answer instead.\n\n"
        . "In CI, give the job:\n\n"
        . "    - uses: actions/checkout@v7\n"
        . "      with:\n"
        . "        fetch-depth: 0\n\n"
        . "Locally:  git fetch --unshallow\n"
    );
}

// origin/develop when there is a remote (CI, and any normal clone), the local
// branch when there is not.
$baseRef = null;
foreach (['refs/remotes/origin/' . BASE_BRANCH, 'refs/heads/' . BASE_BRANCH] as $candidate) {
    [$status] = git($projectRoot, ['rev-parse', '--verify', '--quiet', $candidate]);
    if ($status === 0) {
        $baseRef = $candidate;
        break;
    }
}

if ($baseRef === null) {
    fail(
        sprintf('neither origin/%s nor %s is present in this checkout.', BASE_BRANCH, BASE_BRANCH),
        '',
        sprintf(
            "%s is the branch SDK version numbers are unique against, so without it this guard has no\n"
            . "reference to check against and fails rather than passing blind. A CI checkout with\n"
            . "fetch-depth: 0 fetches every branch, so this normally means the fetch was narrowed\n"
            . "(fetch-depth, or a single-branch clone).\n\n"
            . "Locally:  git fetch origin %s\n",
            BASE_BRANCH,
            BASE_BRANCH
        )
    );
}

[$status, $output] = git($projectRoot, ['merge-base', 'HEAD', $baseRef]);
if ($status !== 0 || ($output[0] ?? '') === '') {
    fail(
        sprintf('HEAD and %s have no common ancestor this checkout can see.', $baseRef),
        implode("\n", $output),
        "The merge base is what says whether this branch CHANGED the version or merely carries it, so\n"
        . "without one there is no question to answer. Fetch the full history (fetch-depth: 0 in CI,\n"
        . "`git fetch --unshallow` locally) and run it again.\n"
    );
}
$mergeBase = $output[0];

[$status, $output] = git($projectRoot, ['show', $mergeBase . ':' . $sdkFileRelative]);
$baseVersion = $status === 0 ? declaredVersion(implode("\n", $output)) : null;

if ($status === 0 && $baseVersion === null) {
    fail(
        sprintf('%s at the merge base %s declares no readable VERSION.', $sdkFileRelative, substr($mergeBase, 0, 8)),
        '',
        "The base version is the whole basis of the forward-only comparison. This is decidable and it\n"
        . "did not decide, so it fails rather than skipping the check.\n"
    );
}

// The file did not exist at the merge base at all (a branch cut from before the
// SDK was extracted, or the file's introducing commit). Nothing to compare
// backwards to, and every version is therefore "new" — the collision check
// below still applies, which is the half that matters.
$baseKnown = $baseVersion !== null;

if ($baseKnown && $baseVersion === $version) {
    printf(
        "OK: SDK %s is stated consistently in all three pins; %d entries newest-first in %s; "
        . "version unchanged from the merge base %s, so there is nothing to "
        . "compare against %s's history.\n",
        $version,
        count($ledger),
        $changelogFileRelative,
        substr($mergeBase, 0, 8),
        BASE_BRANCH
    );
    exit(0);
}

if ($baseKnown && !version_compare($version, $baseVersion, '>')) {
    fail(
        sprintf('the SDK version went BACKWARDS: %s at the merge base, %s here.', $baseVersion, $version),
        '',
        "The SDK's versioning policy is additive and forward-only: a plugin resolves its constraint\n"
        . "against whatever the host reports, so a version that moves backwards makes an already-\n"
        . "shipped constraint unsatisfiable on a host that is strictly newer.\n\n"
        . sprintf("    merge base with %s:  %s (%s)\n", BASE_BRANCH, $baseVersion, substr($mergeBase, 0, 8))
        . sprintf("    declared here:       %s\n", $version)
    );
}

// Every version this file has ever declared on develop, mapped to the commit
// that first declared it. `git log -- path` on a merge-commit history reports
// the branch commits themselves, so both sides of a parallel pair are in here.
[$status, $commits] = git($projectRoot, ['log', '--format=%H', '--reverse', $baseRef, '--', $sdkFileRelative]);
if ($status !== 0) {
    fail(
        sprintf('could not read %s\'s history of %s.', BASE_BRANCH, $sdkFileRelative),
        implode("\n", $commits),
        "Without the history there is no collision check, and passing anyway is the failure mode this\n"
        . "guard exists for.\n"
    );
}

/** @var array<string, string> version => "sha subject" of the commit that first declared it */
$usedVersions = [];
$unreadable = 0;

foreach ($commits as $sha) {
    if ($sha === '') {
        continue;
    }

    [$showStatus, $showOutput] = git($projectRoot, ['show', $sha . ':' . $sdkFileRelative]);
    if ($showStatus !== 0) {
        continue;
    }

    $seen = declaredVersion(implode("\n", $showOutput));
    if ($seen === null) {
        // A commit from before the constant existed. Counted and reported
        // rather than ignored, but not fatal: it is not decidable and not
        // fixable, and refusing to run over ancient history would just mean
        // the guard never runs.
        $unreadable++;
        continue;
    }

    if (isset($usedVersions[$seen])) {
        continue;
    }

    [, $subject] = git($projectRoot, ['log', '-1', '--format=%h %s', $sha]);
    $usedVersions[$seen] = $subject[0] ?? substr($sha, 0, 8);
}

if (isset($usedVersions[$version])) {
    fail(
        sprintf('SDK version %s is ALREADY USED on %s.', $version, BASE_BRANCH),
        sprintf(
            "  [collision] %s was first declared by:\n\n      %s\n\n"
            . "  This branch declares it again with a different meaning. Whichever of the two merges\n"
            . "  second wins, and a plugin declaring ^%s then resolves against a contract nobody can\n"
            . "  identify from the constraint — on a device, offline, with no way to ask (#849).",
            $version,
            $usedVersions[$version],
            $minor
        ),
        sprintf(
            "Renumber this branch to the next unused minor and move its changelog entry with it:\n\n"
            . "    1. sdk/src/Sdk.php — the header \"SDK identity (vX.Y)\" and the VERSION constant\n"
            . "    2. %s — the `## X.Y` heading, which stays FIRST (NEWEST-FIRST)\n"
            . "    3. sdk/composer.json — the `version` field\n"
            . "    4. tests/Sdk/SdkPackageContractTest.php — the version it pins\n"
            . "    5. nothing for the desktop template: it copies sdk/src at build (WHIT-1054)\n\n"
            . "Highest version on %s: %s. The lowest free minor above it is %s.\n",
            $changelogFileRelative,
            BASE_BRANCH,
            (static function (array $used): string {
                $versions = array_keys($used);
                usort($versions, 'version_compare');

                return $versions === [] ? '(none)' : (string) end($versions);
            })($usedVersions),
            (static function (array $used): string {
                $versions = array_keys($used);
                usort($versions, 'version_compare');
                $highest = $versions === [] ? '0.0' : (string) end($versions);
                $parts = explode('.', $highest);

                return $parts[0] . '.' . ((int) ($parts[1] ?? 0) + 1) . '.0';
            })($usedVersions)
        )
    );
}

printf(
    "OK: SDK %s is stated consistently in all three pins; %d changelog entries newest-first; "
    . "and it is a forward, unused version — %s at the merge base %s, and %d version(s) "
    . "across %d commit(s) of %s history, none of them %s.%s\n",
    $version,
    count($ledger),
    $baseKnown ? $baseVersion : '(the file did not exist)',
    substr($mergeBase, 0, 8),
    count($usedVersions),
    count(array_filter($commits, static fn (string $sha): bool => $sha !== '')),
    BASE_BRANCH,
    $version,
    $unreadable > 0 ? sprintf(' (%d older commit(s) declared no readable VERSION)', $unreadable) : ''
);
exit(0);
