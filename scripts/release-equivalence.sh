#!/usr/bin/env bash
# Has a green CI run already proved this release's tree? (WHIT-1340)
#
#   scripts/release-equivalence.sh <release-commit> <version>
#   scripts/release-equivalence.sh --self-test
#   scripts/release-equivalence.sh --bump-only <from> <to>
#
# release.yml asks this before it runs PHPUnit, PHPStan, the plugin guards and
# the frontend suites. When the answer is yes, it skips them: they already
# passed on the same code, and running them again cost seven minutes of every
# release. Everything else in release.yml still runs.
#
# It prints `skip=true` or `skip=false` and a `reason=` line, and appends both
# to $GITHUB_OUTPUT when that is set. It FAILS CLOSED: any doubt, a missing
# run, a renamed job, an API error, an unexpected file, and the answer is
# `skip=false`, so the full suites run. It never exits non-zero for a "no"; the
# release must not fail because the shortcut is unavailable.
#
# WHAT COUNTS AS PROOF
#
# A run of the Automated Tests workflow (automated-tests.yml) that concluded
# `success`, in which every one of these jobs, in its latest attempt, concluded
# `success` (a `skipped` job proves nothing):
#
#   CI gate
#   Tests shard N/T (SQLite...)            every shard 1..T   PHPUnit
#   Static analysis, guards & plugin smoke                    PHPStan, plugin
#                                                             smoke, plugin
#                                                             frontend guard
#   Frontend types & generated-artifact drift                 drift, tokens,
#                                                             registry, tsc
#   Frontend unit tests (Jest)
#   Frontend lint, production build & Storybook               lint, next build
#
# These are the PR gate's versions of what release.yml's `verify` and `web`
# jobs run (that workflow's header says they are in parity). The PostgreSQL
# migrate, seed and idempotency run is not on this list because release.yml
# always runs it.
#
# WHICH COMMITS MAY PROVIDE IT, in order. The release commit is S.
#
#   A. The release PR's head (the normal path). S is the merge commit of the
#      release/X.Y.Z -> main PR, so its second parent R is the PR head and its
#      first parent is main before the merge. A pull_request run tests the
#      merge of R into main as main was at the time. Accepted when:
#        - S has exactly two parents and R's tree is IDENTICAL to S's;
#        - main before the merge (S^1) is an ancestor of R. main only moves
#          forward, so whatever main was when the run started was an ancestor
#          of R too, and merging an ancestor into R changes nothing: the run
#          tested R's tree, which is S's tree;
#        - S is the merge commit of a pull request based on main whose head
#          commit is R, every pull request ever opened from that head branch
#          is based on main (so no run of R tested a merge into another base),
#          and the run's event is pull_request on that head branch.
#
#   B. The develop commit the release was cut from. R's subject is
#      "release: <version>" and R has one parent, D. Accepted when:
#        - D is an ancestor of origin/develop;
#        - the run's event is push (a push run tests D itself);
#        - S differs from D ONLY in the files the release-prepare workflow
#          writes, and in each only as that workflow changes it:
#            src/Core/CoreVersion.php     only the VERSION = '...' value
#            public/openapi.json          only .info.version
#            docs/sdk-coverage/coverage.json  only .core_version, .updated
#            packages/*/package.json      only .version and @whity/* ranges
#            package-lock.json            only the workspace packages'
#                                         .version and @whity/* ranges
#            CHANGELOG.md, packages/*/CHANGELOG.md,
#            docs/wiki/Plugin-SDK-Coverage.md   release notes, read by no suite
#            changelog.d/*.md, .changeset/*.md  deleted, nothing else
#          Any other path, any other kind of change, and B does not apply.
#
#   C. S itself, for a push run (a hand-pushed tag on a commit main already
#      ran CI for).
#
# Needs git with the history of S and origin/develop, jq, and gh with
# GH_TOKEN (actions: read) and GITHUB_REPOSITORY.

set -uo pipefail

WORKFLOW_FILE='automated-tests.yml'

emit() {
  local skip="$1" reason="$2"
  echo "skip=${skip}"
  echo "reason=${reason}"
  if [ -n "${GITHUB_OUTPUT:-}" ]; then
    { echo "skip=${skip}"; echo "reason=${reason}"; } >> "$GITHUB_OUTPUT"
  fi
  exit 0
}

full() {
  echo "::notice::The full suites will run: $1"
  emit false "$1"
}

# Reads "name<TAB>conclusion" lines (one per job, latest attempt) on stdin and
# succeeds only when every job on the list above concluded success.
jobs_prove_the_suites() {
  awk -F '\t' '
    { concl[$1] = $2 }
    $1 ~ /^Tests shard [0-9]+\/[0-9]+ / {
      split(substr($1, 13), p, "[/ ]")
      shard = p[1] + 0; total = p[2] + 0
      if (total < 1 || (seen_total && total != seen_total)) bad = 1
      seen_total = total
      if ($2 == "success") ok_shard[shard] = 1; else bad = 1
    }
    END {
      n = split("CI gate|Static analysis, guards & plugin smoke|Frontend types & generated-artifact drift|Frontend unit tests (Jest)|Frontend lint, production build & Storybook", need, "|")
      for (i = 1; i <= n; i++) if (concl[need[i]] != "success") { print "missing or not green: " need[i] > "/dev/stderr"; bad = 1 }
      if (!seen_total) { print "no PHPUnit shard jobs" > "/dev/stderr"; bad = 1 }
      for (s = 1; s <= seen_total; s++) if (!ok_shard[s]) { print "shard " s " not green" > "/dev/stderr"; bad = 1 }
      exit bad ? 1 : 0
    }'
}

# Prints the id of a successful Automated Tests run for commit $1 whose event
# is $2 (and, when $3 is given, whose head branch is $3) and whose jobs prove
# the suites. Prints nothing when there is none.
proving_run() {
  local commit="$1" event="$2" branch="${3:-}" runs id head jobs
  runs=$(gh api "repos/${GITHUB_REPOSITORY}/actions/workflows/${WORKFLOW_FILE}/runs?head_sha=${commit}&event=${event}&status=success&per_page=30" \
    --jq '.workflow_runs[] | select(.conclusion == "success") | "\(.id) \(.head_branch)"') || return 0
  while read -r id head; do
    [ -n "$id" ] || continue
    if [ -n "$branch" ] && [ "$head" != "$branch" ]; then
      continue
    fi
    jobs=$(gh api --paginate "repos/${GITHUB_REPOSITORY}/actions/runs/${id}/jobs?filter=latest&per_page=100" \
      --jq '.jobs[] | [.name, (.conclusion // "none")] | @tsv') || continue
    if printf '%s\n' "$jobs" | jobs_prove_the_suites; then
      echo "$id"
      return 0
    fi
  done <<< "$runs"
}

# jq programs that erase exactly what release-prepare changes in a file.
JQ_NORM_DEPS='def nd: if type == "object" then with_entries(if (.key | startswith("@whity/")) then .value = "" else . end) else . end;
def np: del(.version)
  | (if has("dependencies") then .dependencies |= nd else . end)
  | (if has("devDependencies") then .devDependencies |= nd else . end)
  | (if has("peerDependencies") then .peerDependencies |= nd else . end)
  | (if has("optionalDependencies") then .optionalDependencies |= nd else . end);'

same_after() {
  # same_after <from> <to> <path> <jq program>
  local a b
  a=$(git show "$1:$3" 2>/dev/null | jq -S "$4" 2>/dev/null) || return 1
  b=$(git show "$2:$3" 2>/dev/null | jq -S "$4" 2>/dev/null) || return 1
  [ -n "$a" ] && [ "$a" = "$b" ]
}

# Succeeds when $2 differs from $1 only as release-prepare changes things.
differs_only_by_the_bump() {
  local from="$1" to="$2" status path rest a b
  local diff
  diff=$(git diff --no-renames --name-status "$from" "$to") || return 1
  [ -n "$diff" ] || return 0
  while IFS=$'\t' read -r status path rest; do
    case "$path" in
      src/Core/CoreVersion.php)
        [ "$status" = "M" ] || return 1
        a=$(git show "$from:$path" | sed -E "s/VERSION = '[^']*'/VERSION = ''/")
        b=$(git show "$to:$path" | sed -E "s/VERSION = '[^']*'/VERSION = ''/")
        [ "$a" = "$b" ] || { echo "$path changed beyond VERSION" >&2; return 1; } ;;
      public/openapi.json)
        [ "$status" = "M" ] && same_after "$from" "$to" "$path" '.info.version = ""' \
          || { echo "$path changed beyond info.version" >&2; return 1; } ;;
      docs/sdk-coverage/coverage.json)
        [ "$status" = "M" ] && same_after "$from" "$to" "$path" 'del(.core_version, .updated)' \
          || { echo "$path changed beyond the release stamp" >&2; return 1; } ;;
      package-lock.json)
        [ "$status" = "M" ] && same_after "$from" "$to" "$path" "${JQ_NORM_DEPS}"'
          del(.version)
          | .packages |= with_entries(
              if .key == "" then .value |= del(.version)
              elif (.key | test("^(web|packages/[^/]+|templates/[^/]+)$")) then .value |= np
              else . end)' \
          || { echo "$path changed beyond workspace versions" >&2; return 1; } ;;
      packages/*/package.json)
        [ "$status" = "M" ] && same_after "$from" "$to" "$path" "${JQ_NORM_DEPS} np" \
          || { echo "$path changed beyond its version" >&2; return 1; } ;;
      CHANGELOG.md | packages/*/CHANGELOG.md | docs/wiki/Plugin-SDK-Coverage.md)
        ;;
      changelog.d/*.md | .changeset/*.md)
        [ "$status" = "D" ] || { echo "$path was not just deleted" >&2; return 1; } ;;
      *)
        echo "$path is not a file the release bump writes" >&2
        return 1 ;;
    esac
  done <<< "$diff"
  return 0
}

self_test() {
  local fail=0
  t() { if printf "$2" | jobs_prove_the_suites 2>/dev/null; then r=yes; else r=no; fi
        if [ "$r" != "$1" ]; then echo "FAIL: expected $1 for: $3"; fail=1; else echo "ok: $3"; fi; }
  local good='CI gate\tsuccess\nStatic analysis, guards & plugin smoke\tsuccess\nFrontend types & generated-artifact drift\tsuccess\nFrontend unit tests (Jest)\tsuccess\nFrontend lint, production build & Storybook\tsuccess\nTests shard 1/3 (SQLite, coverage)\tsuccess\nTests shard 2/3 (SQLite, coverage)\tsuccess\nTests shard 3/3 (SQLite, coverage)\tsuccess\n'
  t yes "$good" "every job green"
  t no  "${good//Tests shard 2\/3 (SQLite, coverage)\\tsuccess/Tests shard 2\/3 (SQLite, coverage)\\tskipped}" "a skipped shard"
  t no  "${good//Tests shard 3\/3 (SQLite, coverage)\\tsuccess\\n/}" "a missing shard"
  t no  "${good//Frontend unit tests (Jest)\\tsuccess/Frontend unit tests (Jest)\\tskipped}" "a skipped frontend job"
  t no  "${good//CI gate\\tsuccess\\n/}" "no CI gate"
  t no  'CI gate\tsuccess\n' "only the gate"
  return $fail
}

if [ "${1:-}" = "--self-test" ]; then
  self_test
  exit $?
fi

# Rule B's file check alone, for trying it on a real release commit:
#   scripts/release-equivalence.sh --bump-only <develop-commit> <release-commit>
if [ "${1:-}" = "--bump-only" ]; then
  if differs_only_by_the_bump "${2:?}" "${3:?}"; then echo "only the bump"; exit 0; fi
  echo "more than the bump"; exit 1
fi

S="${1:?usage: release-equivalence.sh <release-commit> <version>}"
VERSION="${2:?usage: release-equivalence.sh <release-commit> <version>}"

command -v jq >/dev/null && command -v gh >/dev/null || full "jq or gh is not available."
[ -n "${GITHUB_REPOSITORY:-}" ] || full "GITHUB_REPOSITORY is not set."
git cat-file -e "${S}^{commit}" 2>/dev/null || full "the release commit ${S} is not in this checkout."

S_TREE=$(git rev-parse "${S}^{tree}")
PARENTS=$(git rev-list --parents -n 1 "$S" | wc -w)

# A. The release PR's head.
if [ "$PARENTS" -eq 3 ]; then
  R=$(git rev-parse "${S}^2")
  if [ "$(git rev-parse "${R}^{tree}")" = "$S_TREE" ] && git merge-base --is-ancestor "${S}^1" "$R"; then
    # The pull request S merged: based on main, head commit R. A run's own
    # pull_requests list is emptied once the PR closes, so it cannot say this.
    HEAD_REF=$(gh api "repos/${GITHUB_REPOSITORY}/commits/${S}/pulls" \
      --jq ".[] | select(.merge_commit_sha == \"${S}\" and .base.ref == \"main\" and .head.sha == \"${R}\") | .head.ref" 2>/dev/null | head -1)
    BASES=""
    if [ -n "$HEAD_REF" ]; then
      BASES=$(gh api --paginate "repos/${GITHUB_REPOSITORY}/pulls?state=all&head=${GITHUB_REPOSITORY%%/*}:${HEAD_REF}&per_page=100" \
        --jq '.[].base.ref' 2>/dev/null | sort -u | tr '\n' ' ')
    fi
    if [ -z "$HEAD_REF" ]; then
      echo "No pull request into main with head ${R:0:12} was merged as ${S:0:12}."
    elif [ "$BASES" != "main " ]; then
      echo "Pull requests from ${HEAD_REF} target more than main (${BASES}), so a run of ${R:0:12} may have tested another merge."
    else
      RUN=$(proving_run "$R" pull_request "$HEAD_REF")
      if [ -n "$RUN" ]; then
        emit true "the release PR's head ${R:0:12} (${HEAD_REF}) has the same tree, and its run ${RUN} passed every suite."
      fi
      echo "No pull_request run on the release PR's head ${R:0:12} proves the suites."
    fi
  else
    echo "The release commit's second parent is not a head with the same tree on top of main."
  fi

  # B. The develop commit the release was cut from.
  if [ "$(git log -1 --format=%s "$R")" = "release: ${VERSION}" ] && [ "$(git rev-list --parents -n 1 "$R" | wc -w)" -eq 2 ]; then
    D=$(git rev-parse "${R}^1")
    git fetch --quiet origin develop 2>/dev/null || true
    if git merge-base --is-ancestor "$D" origin/develop 2>/dev/null; then
      if differs_only_by_the_bump "$D" "$S"; then
        RUN=$(proving_run "$D" push)
        if [ -n "$RUN" ]; then
          emit true "develop commit ${D:0:12} differs only by the version bump, and its run ${RUN} passed every suite."
        fi
        echo "No push run on develop commit ${D:0:12} proves the suites."
      else
        echo "The release differs from develop commit ${D:0:12} by more than the version bump."
      fi
    else
      echo "The commit the release was cut from (${D:0:12}) is not on develop."
    fi
  fi
fi

# C. The release commit itself.
RUN=$(proving_run "$S" push)
if [ -n "$RUN" ]; then
  emit true "the release commit's own push run ${RUN} passed every suite."
fi

full "no green run of the same code was found."
