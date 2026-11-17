#!/usr/bin/env bash
# WHIT-1167: no AI attribution in commits or pull requests.
#
# Project rule: commits, PR titles and PR descriptions never credit an AI
# assistant (no "Co-Authored-By: <assistant>", no "Generated with ..." footer).
# v0.2.29 shipped seven commits carrying such a trailer because nothing
# checked; this does.
#
# What it reads:
#   pull_request  every commit between the base and the head, plus the PR's
#                 title and body (from the event payload)
#   merge_group   every commit the queue is about to merge
#   anything else nothing (exit 0); main and develop are reached through PRs
#
# Usage in CI: bash scripts/ci-no-ai-attribution.sh
# Self-test:   bash scripts/ci-no-ai-attribution.sh --self-test
set -euo pipefail

# Case-insensitive extended regex. Kept to attribution shapes so an ordinary
# mention of a product in code or docs is never caught: only a co-author
# trailer naming an assistant or its vendor's address, or a "generated with"
# footer.
PATTERN='(co-authored-by:.*(claude|anthropic|copilot|chatgpt|openai|gpt-[0-9]|gemini|cursor|codeium|devin))|(noreply@anthropic\.com)|(generated (with|by) \[?(claude|copilot|chatgpt|cursor|codex|gemini))|(🤖 generated with)'

check_text() { # $1 = label, stdin = text; prints offending lines, returns 1 if any
  local label="$1" hits
  hits=$(grep -inE "$PATTERN" || true)
  if [ -n "$hits" ]; then
    while IFS= read -r line; do
      echo "::error::AI attribution in ${label}: ${line}"
    done <<< "$hits"
    return 1
  fi
  return 0
}

if [ "${1:-}" = "--self-test" ]; then
  fail=0
  for bad in \
    "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" \
    "co-authored-by: GitHub Copilot <copilot@github.com>" \
    "🤖 Generated with [Claude Code](https://claude.com/claude-code)" \
    "Generated with ChatGPT"; do
    if printf '%s\n' "$bad" | check_text "self-test" >/dev/null; then
      echo "self-test: missed: $bad"; fail=1
    fi
  done
  for good in \
    "Co-Authored-By: Amro Saleh <amro@example.com>" \
    "WHIT-1060: move the QR encoder into the SDK" \
    "Docs: the Claude API section of the agents guide"; do
    if ! printf '%s\n' "$good" | check_text "self-test" >/dev/null; then
      echo "self-test: false positive: $good"; fail=1
    fi
  done
  [ "$fail" = 0 ] && echo "self-test: ok"
  exit "$fail"
fi

event="${GITHUB_EVENT_NAME:-}"
failed=0

case "$event" in
  pull_request|pull_request_target)
    base=$(jq -r '.pull_request.base.sha' "$GITHUB_EVENT_PATH")
    head=$(jq -r '.pull_request.head.sha' "$GITHUB_EVENT_PATH")
    jq -r '.pull_request.title // ""' "$GITHUB_EVENT_PATH" | check_text "the PR title" || failed=1
    jq -r '.pull_request.body // ""' "$GITHUB_EVENT_PATH" | check_text "the PR description" || failed=1
    ;;
  merge_group)
    base=$(jq -r '.merge_group.base_sha' "$GITHUB_EVENT_PATH")
    head=$(jq -r '.merge_group.head_sha' "$GITHUB_EVENT_PATH")
    ;;
  *)
    echo "Nothing to check on a ${event:-local} run."
    exit 0
    ;;
esac

# Refuse to answer from a shallow clone: a missing base reads as "no commits"
# and would pass everything.
if ! git cat-file -e "${base}^{commit}" 2>/dev/null; then
  echo "::error::Base commit ${base} is not in this checkout (shallow clone?). Check out with fetch-depth: 0."
  exit 1
fi

count=0
while IFS= read -r sha; do
  [ -z "$sha" ] && continue
  count=$((count + 1))
  git log -1 --format='%B' "$sha" | check_text "commit ${sha:0:9}" || failed=1
done < <(git rev-list "${base}..${head}")

echo "Checked ${count} commit(s)."
if [ "$failed" = 1 ]; then
  echo "::error::Remove the AI attribution lines (reword the commits, or squash with a clean message) and push again. Project rule: no AI attribution in commits or PRs."
  exit 1
fi
echo "No AI attribution found."
