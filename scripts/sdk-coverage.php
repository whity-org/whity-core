<?php

declare(strict_types=1);

/**
 * Plugin SDK coverage matrix: validate docs/sdk-coverage/coverage.json and
 * render docs/wiki/Plugin-SDK-Coverage.md from it.
 *
 *   php scripts/sdk-coverage.php           validate, then write the page
 *   php scripts/sdk-coverage.php --check   validate, and fail if the page is stale
 *   php scripts/sdk-coverage.php --release 0.2.27
 *                                          stamp core_version and today's date, then write
 *                                          (release-prepare runs this)
 *
 * The JSON is the single source of truth. The page is generated so that it
 * cannot disagree with the data, and docs.whity.dev and whity.dev both read
 * from here.
 *
 * WHY THE CHECKS. An SDK minor that does not touch the matrix is how the matrix
 * goes stale, so --check fails when:
 *
 *   1. `sdk_version` is not Sdk::VERSION. Bumping the SDK means editing this file.
 *   2. A minor in the Sdk.php ledger is not named by any `since` in the matrix.
 *      Saying which area the new minor moved is the edit that matters; a bare
 *      version bump without it still fails.
 *   3. A `since` names a minor the ledger does not have.
 *   4. The data is malformed (unknown group, rating, plan or area reference).
 *   5. The generated page is not what this script would write.
 *
 * Reads files only: no Composer, no database, no git. Runs in CI and in
 * release-prepare.
 */

$root = dirname(__DIR__);
$dataPath = $root . '/docs/sdk-coverage/coverage.json';
$pagePath = $root . '/docs/wiki/Plugin-SDK-Coverage.md';
$sdkPath = $root . '/sdk/src/Sdk.php';
$args = array_slice($argv, 1);
$check = in_array('--check', $args, true);
$releaseAt = array_search('--release', $args, true);
$release = $releaseAt === false ? null : ($args[$releaseAt + 1] ?? '');
if ($release !== null && preg_match('/^\d+\.\d+\.\d+$/', $release) !== 1) {
    fwrite(STDERR, "--release needs a version such as 0.2.27
");
    exit(1);
}

/** @return never */
function failWith(array $errors): void
{
    foreach ($errors as $e) {
        fwrite(STDERR, (getenv('GITHUB_ACTIONS') === 'true' ? '::error::' : 'ERROR: ') . $e . PHP_EOL);
    }
    fwrite(STDERR, PHP_EOL . 'Fix docs/sdk-coverage/coverage.json, then run: php scripts/sdk-coverage.php' . PHP_EOL);
    exit(1);
}

$raw = @file_get_contents($dataPath);
if ($raw === false) {
    failWith(["cannot read {$dataPath}"]);
}
try {
    $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    failWith(['coverage.json is not valid JSON: ' . $e->getMessage()]);
}

if ($release !== null) {
    $data['core_version'] = $release;
    $data['updated'] = gmdate('Y-m-d');
    file_put_contents(
        $dataPath,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "
"
    );
}

$sdkSource = (string) file_get_contents($sdkPath);
if (preg_match('/const\s+VERSION\s*=\s*\'(\d+\.\d+\.\d+)\'/', $sdkSource, $m) !== 1) {
    failWith(['cannot find Sdk::VERSION in sdk/src/Sdk.php']);
}
$sdkVersion = $m[1];

// Same ledger reading as scripts/ci-sdk-version-collision.php: the class
// docblock, flattened, where each entry opens with "X.Y (".
$ledger = [];
if (preg_match('#/\*\*(.*?)\*/#s', $sdkSource, $m) === 1) {
    $flat = (string) preg_replace('/\s+/', ' ', (string) preg_replace('/^\s*\*\s?/m', '', $m[1]));
    preg_match_all('/(?<![\d.])(\d+\.\d+) \(/', $flat, $mm);
    $ledger = array_values(array_unique($mm[1]));
}
if ($ledger === []) {
    failWith(['cannot read the version ledger in sdk/src/Sdk.php']);
}

$errors = [];
$ratings = ['full', 'partial', 'none'];
$levels = ['low', 'medium', 'high'];

if (($data['sdk_version'] ?? null) !== $sdkVersion) {
    $errors[] = sprintf(
        'sdk_version is %s but Sdk::VERSION is %s. An SDK minor must update the coverage matrix in the same pull request: '
        . 'say which areas it moved (a `since` entry) and bump sdk_version.',
        json_encode($data['sdk_version'] ?? null),
        $sdkVersion
    );
}

$groups = [];
foreach ($data['groups'] ?? [] as $g) {
    $groups[$g['id']] = $g['name'];
}

$releases = array_map(static fn (array $r): string => (string) $r['version'], $data['roadmap']['releases'] ?? []);
$areas = [];
$sinceSeen = [];
foreach ($data['areas'] ?? [] as $i => $a) {
    $id = $a['id'] ?? "#{$i}";
    if (isset($areas[$id])) {
        $errors[] = "area {$id} is listed twice";
    }
    $areas[$id] = $a;
    foreach (['id', 'group', 'name', 'rating', 'has', 'missing', 'plan'] as $k) {
        if (!array_key_exists($k, $a)) {
            $errors[] = "area {$id} has no `{$k}`";
        }
    }
    if (!isset($groups[$a['group'] ?? ''])) {
        $errors[] = "area {$id} names unknown group " . json_encode($a['group'] ?? null);
    }
    if (!in_array($a['rating'] ?? null, $ratings, true)) {
        $errors[] = "area {$id} has rating " . json_encode($a['rating'] ?? null) . '; use full, partial or none';
    }
    if (!in_array($a['plan'] ?? null, $releases, true)) {
        $errors[] = "area {$id} plans for " . json_encode($a['plan'] ?? null) . ', which is not a roadmap release';
    }
    if (($a['rating'] ?? null) !== 'full' && ($a['missing'] ?? []) === []) {
        $errors[] = "area {$id} is rated " . ($a['rating'] ?? '?') . ' but lists nothing missing';
    }
    foreach ($a['has'] ?? [] as $h) {
        $since = $h['since'] ?? null;
        if ($since === null) {
            continue;
        }
        if (!in_array($since, $ledger, true)) {
            $errors[] = "area {$id}: `since` {$since} is not an SDK minor in the Sdk.php ledger";
        }
        $sinceSeen[$since] = true;
    }
}

foreach ($ledger as $minor) {
    if (!isset($sinceSeen[$minor])) {
        $errors[] = "SDK {$minor} is in the Sdk.php ledger but no area names it in `since`. Add what it gave plugins to the area it moved.";
    }
}

foreach ($data['roadmap']['releases'] ?? [] as $r) {
    foreach ($r['items'] ?? [] as $item) {
        $iid = $item['id'] ?? '?';
        foreach ($item['areas'] ?? [] as $ref) {
            if (!isset($areas[$ref])) {
                $errors[] = "roadmap item {$iid} names unknown area {$ref}";
            }
        }
        foreach (['value', 'risk'] as $k) {
            if (!in_array($item[$k] ?? null, $levels, true)) {
                $errors[] = "roadmap item {$iid} has {$k} " . json_encode($item[$k] ?? null) . '; use low, medium or high';
            }
        }
    }
}

if ($errors !== []) {
    failWith($errors);
}

$page = render($data, $groups, $areas);

if ($check) {
    $current = @file_get_contents($pagePath);
    if ($current !== $page) {
        failWith(['docs/wiki/Plugin-SDK-Coverage.md is out of date with coverage.json.']);
    }
    $counts = array_count_values(array_map(static fn (array $a): string => $a['rating'], $areas));
    printf(
        "SDK coverage matrix OK: SDK %s, %d areas (%d full, %d partial, %d none), all %d ledger minors placed.\n",
        $sdkVersion,
        count($areas),
        $counts['full'] ?? 0,
        $counts['partial'] ?? 0,
        $counts['none'] ?? 0,
        count($ledger)
    );
    exit(0);
}

file_put_contents($pagePath, $page);
echo "Wrote docs/wiki/Plugin-SDK-Coverage.md\n";

/**
 * @param array<string, mixed>               $data
 * @param array<string, string>              $groups
 * @param array<string, array<string, mixed>> $areas
 */
function render(array $data, array $groups, array $areas): string
{
    $esc = static fn (string $s): string => str_replace('|', '\\|', $s);
    $issue = static fn (int $n): string => "[#{$n}](https://github.com/whity-org/whity-core/issues/{$n})";
    $label = ['full' => 'Full', 'partial' => 'Partial', 'none' => 'None'];

    $counts = array_count_values(array_map(static fn (array $a): string => $a['rating'], $areas));
    $out = [];
    $out[] = '# Plugin SDK Coverage';
    $out[] = '';
    $out[] = '<!-- Generated by scripts/sdk-coverage.php from docs/sdk-coverage/coverage.json. Do not edit by hand. -->';
    $out[] = '';
    $out[] = sprintf(
        'How far a plugin can read, change, react to and extend each part of Whity core through the plugin SDK, as of SDK %s and core %s (%s). '
        . 'Of %d areas, %d are full, %d partial and %d none.',
        $data['sdk_version'],
        $data['core_version'],
        $data['updated'],
        count($areas),
        $counts['full'] ?? 0,
        $counts['partial'] ?? 0,
        $counts['none'] ?? 0
    );
    $out[] = '';
    foreach ($data['ratings'] as $r => $meaning) {
        $out[] = "- **{$label[$r]}**: {$meaning}";
    }
    $out[] = '';
    $out[] = 'Areas marked **Needs a design** have gaps that could cross workspaces, skip permissions or move money, so they get a design before an SDK export. '
        . 'Every SDK minor updates this matrix in the same pull request; CI checks it against the version ledger in `sdk/src/Sdk.php`.';
    $out[] = '';
    $out[] = '## Summary';
    $out[] = '';
    $out[] = '| Area | Coverage | Needs a design | Planned |';
    $out[] = '| --- | --- | --- | --- |';
    foreach ($groups as $gid => $gname) {
        foreach ($areas as $a) {
            if ($a['group'] !== $gid) {
                continue;
            }
            $out[] = sprintf(
                '| [%s](#%s) | %s | %s | %s |',
                $esc($a['name']),
                $a['id'],
                $label[$a['rating']],
                ($a['security'] ?? null) !== null ? 'Yes' : '',
                $a['plan']
            );
        }
    }

    foreach ($groups as $gid => $gname) {
        $out[] = '';
        $out[] = "## {$gname}";
        foreach ($areas as $a) {
            if ($a['group'] !== $gid) {
                continue;
            }
            $out[] = '';
            $out[] = "<a id=\"{$a['id']}\"></a>";
            $out[] = '';
            $out[] = "### {$a['name']}: {$label[$a['rating']]}";
            $out[] = '';
            if ($a['has'] !== []) {
                $out[] = '**In the SDK**';
                $out[] = '';
                foreach ($a['has'] as $h) {
                    $out[] = '- ' . $h['what'] . ($h['since'] !== null ? " (SDK {$h['since']})" : ' (host)');
                }
                $out[] = '';
            }
            if ($a['missing'] !== []) {
                $out[] = '**Missing**';
                $out[] = '';
                foreach ($a['missing'] as $miss) {
                    $out[] = "- {$miss}";
                }
                $out[] = '';
            }
            if (($a['security'] ?? null) !== null) {
                $out[] = "**Needs a design.** {$a['security']}";
                $out[] = '';
            }
            $tail = "Planned: {$a['plan']}.";
            if (($a['issues'] ?? []) !== []) {
                $tail .= ' Tracked in ' . implode(', ', array_map($issue, $a['issues'])) . '.';
            }
            $out[] = $tail;
        }
    }

    $out[] = '';
    $out[] = '## Roadmap';
    $out[] = '';
    $out[] = 'Ordered by value to plugin authors, then by risk. Items marked design first get a reviewed design before any code.';
    if (isset($data['roadmap']['hardening'])) {
        $h = $data['roadmap']['hardening'];
        $out[] = '';
        $out[] = "**Hardening ({$h['release']}).** {$h['summary']}";
    }
    foreach ($data['roadmap']['releases'] as $r) {
        $out[] = '';
        $out[] = "### {$r['version']}: {$r['theme']}";
        $out[] = '';
        $out[] = '| Item | Areas | Value | Risk | Design first | Issue |';
        $out[] = '| --- | --- | --- | --- | --- | --- |';
        foreach ($r['items'] as $item) {
            $out[] = sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $esc($item['title']),
                implode(', ', array_map(static fn (string $id): string => "[{$areas[$id]['name']}](#{$id})", $item['areas'])),
                $item['value'],
                $item['risk'],
                ($item['design_first'] ?? false) ? 'Yes' : '',
                implode(', ', array_map($issue, $item['issues'] ?? []))
            );
        }
    }
    $out[] = '';

    return implode("\n", $out);
}
