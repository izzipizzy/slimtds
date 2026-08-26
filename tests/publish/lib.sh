# Fixtures and assertions for the scripts/publish.sh tests.
#
# These tests run on the HOST, not in the app container, and deliberately so:
# publish.sh is a release tool that drives git remotes and `gh`, neither of
# which the container has. Everything here works against throwaway local
# repositories under one temp root, so no test ever touches a real remote.
#
# Written for bash 3.2 (what macOS ships), same as the script under test.
#
# Discipline that matters here: never write `local x=$(cmd)`. `local` returns
# its own exit status, which masks a failure in the substitution and hides it
# from `set -e`. Declare first, assign on the next line.

set -euo pipefail

LIB_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$LIB_DIR/../.." && pwd)
SCRIPT="$REPO_ROOT/scripts/publish.sh"
PTY_RUN="$LIB_DIR/pty-run.py"

[ -f "$SCRIPT" ] || { printf 'no publish.sh at %s\n' "$SCRIPT" >&2; exit 1; }

# One temp root per test process. Every fixture nests inside it, so the trap
# below is the whole cleanup story — no per-fixture bookkeeping to get wrong.
LIBTMP=$(mktemp -d "${TMPDIR:-/tmp}/publish-test.XXXXXX")
trap 'rm -rf "$LIBTMP"' EXIT INT TERM

# --- running commands ---------------------------------------------------

# Run in $dir, forward stdout, die on failure. stderr passes through so a
# failing fixture step is visible in the test output.
run() {
  local dir=$1
  shift
  ( cd "$dir" && "$@" ) || {
    printf 'fixture command failed: %s\n' "$*" >&2
    exit 1
  }
}

# Run in $dir capturing everything, without dying. Sets TRY_STATUS, TRY_OUT
# and TRY_ERR. Called as a plain command (never inside `$( )`) so the
# assignments survive into the caller.
try() {
  local dir=$1
  shift
  local o="$LIBTMP/try.out" e="$LIBTMP/try.err"
  TRY_STATUS=0
  ( cd "$dir" && "$@" ) >"$o" 2>"$e" || TRY_STATUS=$?
  TRY_OUT=$(cat "$o")
  TRY_ERR=$(cat "$e")
}

# Run attached to a real pty, feeding it $input. publish.sh's confirmation
# gate only prompts when `[ -t 0 ]`, which a pipe never satisfies — this is
# the only way to exercise the accept path rather than the auto bypass.
# Sets PTY_STATUS and PTY_OUT (stdout and stderr combined, as the terminal
# sees them).
run_in_pty() {
  local dir=$1 input=$2
  shift 2
  local o="$LIBTMP/pty.out"
  PTY_STATUS=0
  printf '%s' "$input" | python3 "$PTY_RUN" "$dir" "$@" >"$o" 2>&1 || PTY_STATUS=$?
  PTY_OUT=$(cat "$o")
}

# --- assertions ---------------------------------------------------------

fail() {
  printf '    %s\n' "$*" >&2
  exit 1
}

assert_ok() {
  [ "$TRY_STATUS" -eq 0 ] || fail "expected success, got status $TRY_STATUS
    stdout: $TRY_OUT
    stderr: $TRY_ERR"
}

assert_fails() {
  [ "$TRY_STATUS" -ne 0 ] || fail "expected failure, but the command succeeded
    stdout: $TRY_OUT"
}

assert_eq() {
  [ "$1" = "$2" ] || fail "${3:-values differ}
    expected: $1
    actual:   $2"
}

assert_ne() {
  [ "$1" != "$2" ] || fail "${3:-values should differ, both are: $1}"
}

assert_contains() {
  case "$1" in
    *"$2"*) ;;
    *) fail "${3:-expected to contain \"$2\"}
    actual: $1" ;;
  esac
}

assert_not_contains() {
  case "$1" in
    *"$2"*) fail "${3:-expected NOT to contain \"$2\"}
    actual: $1" ;;
  esac
}

assert_matches() {
  printf '%s' "$1" | grep -qE "$2" || fail "${3:-expected to match /$2/}
    actual: $1"
}

# --- repository fixtures ------------------------------------------------

write_file() {
  local root=$1 path=$2 body=$3
  mkdir -p "$(dirname "$root/$path")"
  printf '%s' "$body" > "$root/$path"
}

# A private repo with .publish.conf and no commit yet. $1 is the value for
# VERSION_FILE: unset means package.json (the gsc-hub shape), an explicit
# empty string means the manifest check is opted out of (the slimTDS shape).
new_repo() {
  local version_file=${1-package.json}
  local root
  root=$(mktemp -d "$LIBTMP/repo.XXXXXX")
  run "$root" git init -q -b main .
  run "$root" git config user.email t@t
  run "$root" git config user.name t
  cat > "$root/.publish.conf" <<EOF
PUBLIC_REMOTE=github
PUBLIC_REPO=owner/repo
PUBLIC_HOST=github.com
PRIVATE_REMOTE=origin
PRIVATE_BRANCH=main
CHANGELOG_FILE=CHANGELOG.md
CHANGELOG_ALT=CHANGELOG.ru.md
VERSION_FILE=$version_file
EOF
  printf '%s' "$root"
}

commit_all() {
  local root=$1 message=${2:-commit}
  run "$root" git add -A -f
  run "$root" git commit -qm "$message"
}

head_sha() {
  run "$1" git rev-parse HEAD
}

tree_files() {
  local root=$1 tree=$2
  ( cd "$root" && git ls-tree -r --name-only "$tree" )
}

# A bare repo standing in for GitHub, wired as the `github` remote. Nested
# under owner/repo.git so its path matches PUBLIC_REPO — preflight checks the
# push URL against it.
attach_bare_remote() {
  local repo=$1 base bare
  base=$(mktemp -d "$LIBTMP/remote.XXXXXX")
  bare="$base/owner/repo.git"
  run "$repo" git init -q --bare "$bare"
  run "$repo" git remote add github "$bare"
  printf '%s' "$bare"
}

# A bare repo standing in for Gitea, wired as `origin`, with main pushed.
attach_private_remote() {
  local repo=$1 base bare
  base=$(mktemp -d "$LIBTMP/origin.XXXXXX")
  bare="$base/origin.git"
  run "$repo" git init -q --bare "$bare"
  run "$repo" git remote add origin "$bare"
  run "$repo" git push -q origin main
  printf '%s' "$bare"
}

# Publish $tree as a child of $parent (or a root commit) and tag it.
seed_public() {
  local repo=$1 bare=$2 tree=$3 message=$4 tag=$5 parent=${6:-}
  local sha
  if [ -n "$parent" ]; then
    sha=$(run "$repo" git commit-tree "$tree" -m "$message" -p "$parent")
  else
    sha=$(run "$repo" git commit-tree "$tree" -m "$message")
  fi
  run "$repo" git push --atomic "$bare" "$sha:refs/heads/main" "$sha:refs/tags/$tag"
  printf '%s' "$sha"
}

remote_sha() {
  local repo=$1 bare=$2 ref=$3 out
  out=$(run "$repo" git ls-remote "$bare" "$ref")
  printf '%s' "$out" | awk 'NR==1 {print $1}'
}

# --- command stubs ------------------------------------------------------

# A fake `gh` on PATH that logs its arguments. `release view` must fail until
# `release create` has run, or the script would think every release already
# exists. Real gh says exactly "release not found" for that case and
# publish.sh greps for it, so the stub says it too.
#
# GH_STUB_FAIL makes every call fail with no recognisable message — that is
# how the partial-failure test gets refs pushed without a release.
# GH_STUB_VIEW_FAIL fails only `release view`, with an operational error
# rather than "release not found": a network blip or expired auth on a
# release that does in fact exist.
#
# Sets GH_BIN and GH_LOG in the caller.
stub_gh() {
  GH_BIN=$(mktemp -d "$LIBTMP/ghstub.XXXXXX")
  GH_LOG="$GH_BIN/calls.log"
  local marker="$GH_BIN/released"
  cat > "$GH_BIN/gh" <<EOF
#!/bin/sh
if [ -n "\$GH_STUB_FAIL" ]; then exit 1; fi
printf '%s\n' "\$*" >> '$GH_LOG'
case "\$1 \$2" in
  "release view")
    if [ -n "\$GH_STUB_VIEW_FAIL" ]; then
      echo "HTTP 500: Internal Server Error" >&2
      exit 1
    fi
    if [ -f '$marker' ]; then exit 0; fi
    echo "release not found" >&2
    exit 1
    ;;
  "release create") : > '$marker' ;;
esac
EOF
  chmod +x "$GH_BIN/gh"
}

# A fake `git` on PATH that fails one subcommand (matched on argv[1]) and
# forwards everything else to the real git. Proves that a command whose
# failure would change project_tree's or new_paths' RESULT is guarded with
# `|| die`, rather than silently ignored the way `set -e` is inside a bash
# 3.2 command-substitution subshell.
#
# Sets GIT_STUB_BIN in the caller.
stub_git_failing() {
  local sub=$1 real
  real=$(command -v git)
  GIT_STUB_BIN=$(mktemp -d "$LIBTMP/gitstub.XXXXXX")
  cat > "$GIT_STUB_BIN/git" <<EOF
#!/bin/sh
if [ "\$1" = '$sub' ]; then
  echo "stubbed failure: git \$1" >&2
  exit 1
fi
exec '$real' "\$@"
EOF
  chmod +x "$GIT_STUB_BIN/git"
}

# --- test discovery -----------------------------------------------------

# Run every test_* function defined in the calling file, in source order.
# Each runs in its own subshell so a `fail` (which exits) ends that test
# only, and so fixture state cannot leak between tests.
run_tests() {
  local file=${BASH_SOURCE[1]}
  local passed=0 failed=0 name label out="$LIBTMP/test.out"
  printf '%s\n' "── $(basename "$file")"
  for name in $(grep -o '^test_[A-Za-z0-9_]*' "$file"); do
    label=$(printf '%s' "${name#test_}" | tr '_' ' ')
    # The subshell keeps a `fail` (which exits) from ending the whole run,
    # and keeps fixture state from leaking into the next test. Output is
    # held back and shown only when the test actually fails.
    if ( "$name" ) >"$out" 2>&1; then
      printf '  ok   %s\n' "$label"
      passed=$((passed + 1))
    else
      printf '  FAIL %s\n' "$label"
      sed 's/^/    /' "$out"
      failed=$((failed + 1))
    fi
  done
  printf '   %s passed, %s failed\n\n' "$passed" "$failed"
  [ "$failed" -eq 0 ]
}
