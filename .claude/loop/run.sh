#!/usr/bin/env bash
#
# Works through TASKS.md with fresh Claude Code sessions:
#   1. One headless session per task: it does the first unchecked task, checks it off and commits.
#   2. After every 5 checked-off tasks, a fresh session runs the laravel-best-practices
#      and simplify skills over the code those tasks wrote, and commits the fixes.
#   3. Then the next batch. It stops after 3 batches (3 reviews, about 15 tasks), when every task
#      is checked off, or when a session needs you. Run it again to continue.
#
# Run it in a terminal (not inside Claude Code):  .claude/loop/run.sh [options]
#
#   --batch N             tasks between reviews (default 5)
#   --cycles N            stop after N reviews (default 3; 0 runs until TASKS.md is done)
#   --review-now          first review the tasks finished since the last review, however many
#   --permission-mode M   permission mode for the sessions (default auto; bypassPermissions skips all checks)
#   --model M             model for the sessions (default: your Claude Code default)
#   --effort E            effort for the sessions (default: your Claude Code default)
#   --output-lines N      lines shown per tool result or diff (default 20; 0 shows everything)
#   --dry-run             show the next step and its prompt, then exit
#   -h, --help            show this help
#
# Each session is shown as Claude Code shows it: streamed text, tool calls with their results,
# diffs of edits and a status line (render.py).
# A session that needs you writes .claude/loop/BLOCKED.md and the loop stops, printing the
# command that reopens that session so you can answer it there.
# Logs: .claude/loop/logs/*.jsonl (one per session). To replay one:
#   python3 .claude/loop/render.py < .claude/loop/logs/<file>.jsonl | less -R
# Exit codes: 0 finished, 1 a session failed, 2 waiting for you.

set -euo pipefail

LOOP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$LOOP_DIR/../.." && pwd)"
TASKS="$ROOT/TASKS.md"
SANDBOX="$(dirname "$ROOT")/tolk-sandbox"
BLOCKED="$LOOP_DIR/BLOCKED.md"
STATE_DIR="$LOOP_DIR/state"
REVIEWED="$STATE_DIR/reviewed-tasks"
REVIEW_BASE="$STATE_DIR/review-base"
LAST_SESSION="$STATE_DIR/last-session"
LOG_DIR="$LOOP_DIR/logs"
LOCK="$LOOP_DIR/.lock"
EMPTY_TREE=4b825dc642cb6eb9a060e54bf8d69288fbee4904

batch=5 cycles=3 review_now=0 dry_run=0 permission_mode=auto model="" effort="" output_lines=20

usage() { sed -n '3,/^$/s/^# \{0,1\}//p' "$0"; }

while [ $# -gt 0 ]; do
  case "$1" in
    --batch) batch="${2:?--batch needs a number}"; shift 2 ;;
    --cycles) cycles="${2:?--cycles needs a number}"; shift 2 ;;
    --review-now) review_now=1; shift ;;
    --permission-mode) permission_mode="${2:?--permission-mode needs a mode}"; shift 2 ;;
    --model) model="${2:?--model needs a model}"; shift 2 ;;
    --effort) effort="${2:?--effort needs a level}"; shift 2 ;;
    --output-lines) output_lines="${2:?--output-lines needs a number}"; shift 2 ;;
    --dry-run) dry_run=1; shift ;;
    -h | --help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 1 ;;
  esac
done

case "$batch$cycles$output_lines" in *[!0-9]*) echo "--batch, --cycles and --output-lines take whole numbers." >&2; exit 1 ;; esac
[ "$batch" -ge 1 ] || { echo "--batch must be at least 1." >&2; exit 1; }

if [ -t 1 ]; then
  BOLD=$'\033[1m' DIM=$'\033[2m' RED=$'\033[31m' GREEN=$'\033[32m' YELLOW=$'\033[33m' OFF=$'\033[0m'
else
  BOLD="" DIM="" RED="" GREEN="" YELLOW="" OFF=""
fi

say() { printf '%s\n' "$*"; }
warn() { printf '%s%s%s\n' "$YELLOW" "$*" "$OFF"; }
fail() { printf '%s%s%s\n' "$RED" "$*" "$OFF" >&2; }

banner() {
  say ""
  say "${BOLD}━━ $1${OFF}"
  say "${DIM}   $2 · $(date '+%H:%M')${OFF}"
}

elapsed() {
  local s=$(($(date +%s) - $1))
  printf '%dm%02ds' $((s / 60)) $((s % 60))
}

count() { sed '/^$/d' | wc -l | tr -d ' '; }

open_ids() { grep -E '^- \[ \] \*\*T[0-9]' "$TASKS" | sed -E 's/^- \[ \] \*\*(T[0-9.]+).*/\1/' || true; }
done_ids() { grep -E '^- \[[xX]\] \*\*T[0-9]' "$TASKS" | sed -E 's/^- \[[xX]\] \*\*(T[0-9.]+).*/\1/' || true; }
pending_ids() { done_ids | grep -vxF -f "$REVIEWED" || true; }

task_title() {
  grep -m1 -E "^- \[[ xX]\] \*\*${1//./\\.} " "$TASKS" | sed -E 's/^- \[[ xX]\] \*\*[^ ]+ (.*)\*\*.*$/\1/'
}

git_head() { git -C "$ROOT" rev-parse --verify -q HEAD 2>/dev/null || true; }

# Runs one headless session with a fresh context and shows it as it runs. Sets SESSION, LOG and RESULT.
run_claude() {
  local label="$1" prompt="$2" rc
  SESSION="$(uuidgen | tr '[:upper:]' '[:lower:]')"
  LOG="$LOG_DIR/$(date +%Y%m%d-%H%M%S)-$label.jsonl"
  printf '%s\n' "$SESSION" >"$LAST_SESSION"

  local args=(-p --session-id "$SESSION" --name "loop $label"
    --output-format stream-json --verbose --include-partial-messages
    --permission-mode "$permission_mode" --disallowedTools=AskUserQuestion)
  [ -n "$model" ] && args+=(--model "$model")
  [ -n "$effort" ] && args+=(--effort "$effort")
  [ -d "$SANDBOX" ] && args+=(--add-dir="$SANDBOX")

  set +e
  (cd "$ROOT" && printf '%s' "$prompt" | claude "${args[@]}") |
    python3 "$LOOP_DIR/render.py" --log "$LOG" --cwd "$ROOT" --lines "$output_lines" --mode "$permission_mode"
  rc=${PIPESTATUS[0]}
  set -e

  RESULT="$(jq -Rr 'fromjson? | select(.type == "result") | .result // empty' "$LOG")"
  return "$rc"
}

session_stats() {
  jq -Rr 'fromjson? | select(.type == "result") | "\(.num_turns) turns"' "$LOG" | tail -1
}

show_blocked() {
  say ""
  say "${YELLOW}${BOLD}A session is waiting for you:${OFF}"
  say ""
  cat "$BLOCKED"
  say ""
  if [ -s "$LAST_SESSION" ]; then
    say "Answer it in that session:  ${BOLD}cd $ROOT && claude --resume $(cat "$LAST_SESSION")${OFF}"
  fi
  say "When it is resolved, the session deletes BLOCKED.md (or delete it yourself) and you run the loop again."
}

do_task() {
  local id title before after start prompt
  id="$(open_ids | head -1)"
  title="$(task_title "$id")"
  prompt="$(printf 'Your task: **%s %s**\n\n' "$id" "$title"; cat "$LOOP_DIR/task-prompt.md")"

  banner "$id $title" "task $(($(pending_ids | count) + 1)) of $batch before the next review · $(open_ids | count) open"
  if [ "$dry_run" -eq 1 ]; then say ""; say "$prompt"; exit 0; fi

  before="$(done_ids | count)"
  start="$(date +%s)"
  if ! run_claude "$id" "$prompt"; then
    fail "The session for $id ended with an error. Log: $LOG"
    say "Reopen it with:  claude --resume $SESSION"
    return 1
  fi
  if [ -f "$BLOCKED" ]; then show_blocked; return 2; fi
  if ! done_ids | grep -qxF "$id"; then
    fail "$id is still unchecked in TASKS.md, and the session wrote no BLOCKED.md. Log: $LOG"
    say "Reopen it with:  claude --resume $SESSION"
    return 1
  fi

  after="$(done_ids | count)"
  [ $((after - before)) -gt 1 ] && warn "$((after - before)) tasks were checked off in one session."
  if [ -n "$(git_head)" ] && [ -n "$(git -C "$ROOT" status --porcelain)" ]; then
    warn "$id left uncommitted changes; the next session will see them."
  fi
  say "${GREEN}✓ $id done${OFF} ${DIM}($(elapsed "$start"), $(session_stats))${OFF}"
}

do_review() {
  local ids list first last span head base range target prompt start
  ids="$(pending_ids)"
  first="$(say "$ids" | head -1)"
  last="$(say "$ids" | tail -1)"
  list="$(say "$ids" | paste -sd, - | sed 's/,/, /g')"
  span="$first"
  [ "$last" != "$first" ] && span="${first}–${last}"
  head="$(git_head)"
  base="$(cat "$REVIEW_BASE" 2>/dev/null || true)"

  if [ -z "$head" ]; then
    range="there is no git repository or commit yet; review the files those tasks created."
    target="the files created by $list"
  elif [ -n "$base" ] && git -C "$ROOT" merge-base --is-ancestor "$base" HEAD 2>/dev/null; then
    range="\`git diff $base HEAD\`, plus anything uncommitted."
    target="$base..HEAD"
  else
    range="everything committed so far, as there was no review since the repository was created: \`git diff $EMPTY_TREE HEAD\`, plus anything uncommitted."
    target="$EMPTY_TREE..HEAD"
  fi

  prompt="$(
    printf 'Tasks completed since the last review: %s.\n' "$list"
    printf 'Changes to review: %s\n' "$range"
    printf 'Target for the simplify skill: `%s`\n\n' "$target"
    cat "$LOOP_DIR/review-prompt.md"
  )"

  banner "Review of $list" "laravel-best-practices, then simplify"
  if [ "$dry_run" -eq 1 ]; then say ""; say "$prompt"; exit 0; fi

  start="$(date +%s)"
  if ! run_claude "review-${span//–/-}" "$prompt"; then
    fail "The review session ended with an error. Log: $LOG"
    say "Reopen it with:  claude --resume $SESSION"
    return 1
  fi
  if [ -f "$BLOCKED" ]; then show_blocked; return 2; fi
  case "$RESULT" in
    *"RESULT: REVIEWED"* | *"RESULT: REVIEW SKIPPED"*) ;;
    *)
      fail "The review session did not report a result. Log: $LOG"
      say "Reopen it with:  claude --resume $SESSION"
      return 1
      ;;
  esac

  done_ids >"$REVIEWED"
  head="$(git_head)"
  [ -n "$head" ] && printf '%s\n' "$head" >"$REVIEW_BASE"
  say "${GREEN}✓ Review of $span done${OFF} ${DIM}($(elapsed "$start"), $(session_stats))${OFF}"
}

for cmd in claude jq python3 uuidgen git; do
  command -v "$cmd" >/dev/null 2>&1 || { fail "This loop needs '$cmd' on the PATH."; exit 1; }
done
[ -f "$TASKS" ] || { fail "No TASKS.md in $ROOT."; exit 1; }
mkdir -p "$STATE_DIR" "$LOG_DIR"
touch "$REVIEWED"

if [ -f "$BLOCKED" ]; then show_blocked; exit 2; fi

if [ "$dry_run" -eq 0 ]; then
  mkdir "$LOCK" 2>/dev/null || { fail "Another loop is already running (if it isn't, remove $LOCK)."; exit 1; }
  trap 'rc=$?; rmdir "$LOCK" 2>/dev/null; exit $rc' EXIT
  trap 'say ""; warn "Interrupted. Run the loop again to continue; an unfinished task is picked up where it stopped."; exit 130' INT TERM

  # Keep the Mac awake while the loop runs.
  command -v caffeinate >/dev/null 2>&1 && caffeinate -i -w $$ &
fi

reviews=0
while :; do
  open="$(open_ids | count)"
  pending="$(pending_ids | count)"

  if [ "$pending" -gt 0 ] && { [ "$pending" -ge "$batch" ] || [ "$open" -eq 0 ] || [ "$review_now" -eq 1 ]; }; then
    review_now=0
    do_review || exit $?
    reviews=$((reviews + 1))
    if [ "$cycles" -gt 0 ] && [ "$reviews" -ge "$cycles" ]; then
      say ""
      say "Stopped after $reviews review(s) (--cycles $cycles). Run the loop again to continue."
      exit 0
    fi
    continue
  fi

  if [ "$open" -eq 0 ]; then
    say ""
    say "${GREEN}${BOLD}Every task in TASKS.md is checked off.${OFF}"
    exit 0
  fi

  do_task || exit $?
done
