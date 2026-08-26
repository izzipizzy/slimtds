#!/usr/bin/env bash
# The public tree is the private tree minus .publicignore, and nothing else.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

IGNORE='.publicignore
.publish.conf
CLAUDE.md
docs/superpowers/
scripts/*.py
'

test_drops_the_excluded_paths_and_keeps_everything_else() {
  local repo tree
  repo=$(new_repo)
  write_file "$repo" .publicignore "$IGNORE"
  write_file "$repo" CLAUDE.md 'private notes'
  write_file "$repo" docs/superpowers/plans/x.md 'plan'
  write_file "$repo" scripts/sync.py 'py'
  write_file "$repo" scripts/build.sh 'sh'
  write_file "$repo" src/App.php 'app'
  write_file "$repo" README.md 'readme'
  commit_all "$repo"

  tree=$(run "$repo" bash "$SCRIPT" --project-tree "$(head_sha "$repo")")
  assert_eq 'README.md
scripts/build.sh
src/App.php' "$(tree_files "$repo" "$tree" | sort)"
}

# The regression that motivated using --exclude-from over `git check-ignore`:
# check-ignore reads .gitignore, so a tracked build artefact that is supposed
# to publish would have been cut out of the public tree.
test_does_not_consult_gitignore_so_a_tracked_ignored_file_still_publishes() {
  local repo tree
  repo=$(new_repo)
  write_file "$repo" .publicignore "$IGNORE"
  write_file "$repo" .gitignore 'public/assets/
'
  write_file "$repo" public/assets/app.css 'built'
  write_file "$repo" README.md 'readme'
  commit_all "$repo"

  tree=$(run "$repo" bash "$SCRIPT" --project-tree "$(head_sha "$repo")")
  assert_contains "$(tree_files "$repo" "$tree")" 'public/assets/app.css'
}

test_is_deterministic_two_runs_produce_the_same_tree() {
  local repo first second
  repo=$(new_repo)
  write_file "$repo" .publicignore "$IGNORE"
  write_file "$repo" CLAUDE.md 'x'
  write_file "$repo" README.md 'y'
  commit_all "$repo"

  first=$(run "$repo" bash "$SCRIPT" --project-tree "$(head_sha "$repo")")
  second=$(run "$repo" bash "$SCRIPT" --project-tree "$(head_sha "$repo")")
  assert_eq "$first" "$second" 'projection is not deterministic'
}

test_handles_paths_with_spaces_and_special_characters() {
  local repo tree
  repo=$(new_repo)
  write_file "$repo" .publicignore '.publicignore
.publish.conf
notes dir/
'
  write_file "$repo" 'notes dir/a b.md' 'private'
  write_file "$repo" "src/it's fine.php" 'public'
  commit_all "$repo"

  tree=$(run "$repo" bash "$SCRIPT" --project-tree "$(head_sha "$repo")")
  assert_eq "src/it's fine.php" "$(tree_files "$repo" "$tree")"
}

# Always a rebuild from scratch, never incremental from the public tip: a
# file added to .publicignore after it was published must actually leave the
# public tree on the next release.
test_removes_a_file_that_was_public_before_it_was_added_to_publicignore() {
  local repo before after
  repo=$(new_repo)
  write_file "$repo" .publicignore '.publicignore
.publish.conf
'
  write_file "$repo" docs/internal.md 'was public'
  write_file "$repo" README.md 'readme'
  commit_all "$repo"

  before=$(run "$repo" bash "$SCRIPT" --project-tree "$(head_sha "$repo")")
  assert_contains "$(tree_files "$repo" "$before")" 'docs/internal.md'

  write_file "$repo" .publicignore '.publicignore
.publish.conf
docs/internal.md
'
  commit_all "$repo" 'hide the internal doc'

  after=$(run "$repo" bash "$SCRIPT" --project-tree "$(head_sha "$repo")")
  assert_not_contains "$(tree_files "$repo" "$after")" 'docs/internal.md'
}

test_preserves_the_executable_bit_and_symlinks() {
  local repo tree listing
  repo=$(new_repo)
  write_file "$repo" .publicignore '.publicignore
.publish.conf
'
  write_file "$repo" bin/console '#!/usr/bin/env php
'
  chmod +x "$repo/bin/console"
  run "$repo" ln -s bin/console link
  commit_all "$repo"

  tree=$(run "$repo" bash "$SCRIPT" --project-tree "$(head_sha "$repo")")
  listing=$(run "$repo" git ls-tree -r "$tree")
  assert_matches "$listing" '100755 blob [0-9a-f]+	bin/console'
  assert_matches "$listing" '120000 blob [0-9a-f]+	link'
}

# The script cds to the repository root before doing anything; a root-level
# rule must not become relative to wherever it was invoked from.
test_excludes_a_root_level_file_even_when_invoked_from_a_subdirectory() {
  local repo tree
  repo=$(new_repo)
  write_file "$repo" .publicignore "$IGNORE"
  write_file "$repo" CLAUDE.md 'private notes'
  write_file "$repo" src/App.php 'app'
  write_file "$repo" README.md 'readme'
  commit_all "$repo"

  tree=$(run "$repo/src" bash "$SCRIPT" --project-tree "$(head_sha "$repo")")
  assert_eq 'README.md
src/App.php' "$(tree_files "$repo" "$tree" | sort)"
}

test_refuses_a_commit_that_has_no_publicignore() {
  local repo
  repo=$(new_repo)
  write_file "$repo" README.md 'readme'
  commit_all "$repo"

  try "$repo" bash "$SCRIPT" --project-tree "$(head_sha "$repo")"
  assert_fails
  assert_matches "$TRY_ERR" 'publicignore'
}

# The script's temp index and ignore file live in a mktemp -d dir removed by
# `trap ... EXIT INT TERM`. If that trap ever regressed, an interrupted
# release would leave a private-tree index behind in TMPDIR. Driven by
# stubbing `git ls-remote` to block, then killing the whole process group so
# bash stops waiting on it and actually reaches the trap.
test_removes_its_temp_working_directory_when_interrupted_by_a_signal() {
  local repo tmp stub pid leftovers created=''
  repo=$(new_repo '')
  write_file "$repo" .publicignore '.publicignore
.publish.conf
'
  write_file "$repo" CHANGELOG.md '# Changelog

## [0.8.0] — 2026-08-25

New.

## [0.7.0] — 2026-08-24

Old.
'
  write_file "$repo" CHANGELOG.ru.md "$(cat "$repo/CHANGELOG.md")"
  write_file "$repo" src/App.php 'app'
  commit_all "$repo"
  attach_bare_remote "$repo" >/dev/null
  attach_private_remote "$repo" >/dev/null

  tmp=$(mktemp -d "$LIBTMP/signal-tmpdir.XXXXXX")
  stub=$(mktemp -d "$LIBTMP/hangstub.XXXXXX")
  printf '#!/bin/sh\nif [ "$1" = ls-remote ]; then sleep 30; exit 0; fi\nexec %s "$@"\n' \
    "$(command -v git)" > "$stub/git"
  chmod +x "$stub/git"

  # The signal has to reach the blocking stub as well as publish.sh, or bash
  # defers the trap until the foreground command it is waiting on returns.
  # That means killing a process GROUP, and the child must be its own group
  # leader. `set -m` would do it only when bash has job control, which it
  # does not when this file is sourced by run.sh — hence perl's setpgrp,
  # which makes the backgrounded pid the group id unconditionally.
  ( cd "$repo" && PATH="$stub:$PATH" TMPDIR="$tmp" \
      exec perl -e 'setpgrp(0,0); exec @ARGV or die' -- \
        bash "$SCRIPT" --dry-run 0.8.0 ) >/dev/null 2>&1 &
  pid=$!

  # Poll for the temp directory rather than sleeping a fixed interval. A
  # fixed wait can, on a loaded machine, signal the process BEFORE mktemp -d
  # has run — and then "nothing left behind" is a vacuous pass rather than
  # proof the trap fired. Seeing the directory first makes the later
  # assertion mean something.
  local waited=0
  while [ "$waited" -lt 200 ]; do
    created=$(ls -d "$tmp"/publish.* 2>/dev/null | head -1 || true)
    if [ -n "$created" ]; then break; fi
    kill -0 "$pid" 2>/dev/null || fail 'publish.sh exited before creating its temp directory'
    perl -e 'select undef, undef, undef, 0.05'
    waited=$((waited + 1))
  done
  [ -n "$created" ] || fail 'publish.sh never created a temp working directory'

  # Still parked in the stubbed ls-remote, so what follows exercises the
  # signal path and not the ordinary EXIT trap.
  kill -0 "$pid" 2>/dev/null || fail 'publish.sh exited on its own before the signal'
  kill -TERM -"$pid" 2>/dev/null || fail 'could not signal the publish process group'
  wait "$pid" 2>/dev/null || true

  leftovers=$(ls -d "$tmp"/publish.* 2>/dev/null || true)
  assert_eq '' "$leftovers" "the temp working directory survived the signal: $created"
}

run_tests
