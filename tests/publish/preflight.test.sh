#!/usr/bin/env bash
# Preflight is the fail-safe around a fixed rule of order: push to Gitea
# first, publish to GitHub second. Everything it checks is something that
# would otherwise be discovered only after a ref had already landed.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

LOG='# Changelog

## [0.8.0] — 2026-08-25

New.

## [0.7.0] — 2026-08-24

Old.
'

# A private repo with both remotes attached and main pushed to origin.
scene() {
  local repo
  repo=$(new_repo '')
  write_file "$repo" .publicignore '.publicignore
.publish.conf
'
  write_file "$repo" CHANGELOG.md "$LOG"
  write_file "$repo" CHANGELOG.ru.md "$LOG"
  write_file "$repo" src/App.php 'app'
  commit_all "$repo"
  attach_bare_remote "$repo" >/dev/null
  attach_private_remote "$repo" >/dev/null
  printf '%s' "$repo"
}

test_passes_on_a_clean_private_main_that_matches_origin() {
  local repo
  repo=$(scene)
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_ok
}

test_refuses_when_the_working_tree_has_uncommitted_tracked_changes() {
  local repo
  repo=$(scene)
  write_file "$repo" src/App.php 'changed'
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'uncommitted'
}

test_refuses_on_a_branch_other_than_the_private_main() {
  local repo
  repo=$(scene)
  run "$repo" git checkout -qb feature
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'branch'
}

# The order rule itself: publishing a commit the private remote has never
# seen makes the private repo stop being the source of truth.
test_refuses_when_HEAD_is_ahead_of_the_private_remote() {
  local repo
  repo=$(scene)
  write_file "$repo" src/App.php 'changed'
  commit_all "$repo" 'local only'
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'push .* first|origin'
}

test_refuses_when_the_tree_carries_gitattributes() {
  local repo
  repo=$(scene)
  write_file "$repo" .gitattributes '* text=auto
'
  commit_all "$repo" attrs
  run "$repo" git push -q origin main
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'gitattributes'
}

# git applies .gitattributes at any level. One at assets/.gitattributes
# carrying an LFS filter would publish right next to the pointer file it was
# meant to rewrite, so checking only HEAD:.gitattributes is not enough.
test_refuses_a_nested_gitattributes_not_only_a_root_one() {
  local repo
  repo=$(scene)
  write_file "$repo" public/assets/.gitattributes '*.bin filter=lfs
'
  commit_all "$repo" 'nested attrs'
  run "$repo" git push -q origin main
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'gitattributes'
}

# Without -z, git ls-tree C-quotes a path containing a tab: the field then
# ends in a literal `"`, not in ".gitattributes", and a check anchored on the
# field's end silently misses it.
test_refuses_a_nested_gitattributes_under_a_directory_name_ls_tree_would_quote() {
  local repo
  repo=$(scene)
  write_file "$repo" "$(printf 'weird\tdir')/.gitattributes" '*.bin filter=lfs
'
  commit_all "$repo" 'quoted nested attrs'
  run "$repo" git push -q origin main
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'gitattributes'
}

test_does_not_false_positive_on_a_filename_merely_ending_in_gitattributes() {
  local repo
  repo=$(scene)
  write_file "$repo" weird.gitattributes.txt 'not actually a gitattributes file
'
  commit_all "$repo" 'lookalike name'
  run "$repo" git push -q origin main
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_ok
}

test_refuses_when_the_tree_carries_a_submodule_gitlink() {
  local repo sub
  repo=$(scene)
  sub=$(new_repo)
  write_file "$sub" a.txt a
  commit_all "$sub"
  run "$repo" git -c protocol.file.allow=always submodule add -q "$sub" vendor/sub
  run "$repo" git commit -qm submodule
  run "$repo" git push -q origin main
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'submodule|gitlink'
}

# `git ls-tree -r HEAD | grep -q '^160000 '` fails OPEN under `set -e -o
# pipefail` once the listing outgrows the pipe buffer: grep exits on its
# first match, the producer takes SIGPIPE, and the pipeline's status becomes
# 141 rather than grep's 0 — so the `if` sees false and the check passes. A
# handful of files never reproduces it; the filler is what makes the producer
# still be writing when grep is done.
test_refuses_a_gitlink_even_in_a_tree_too_large_to_fit_a_pipe_buffer() {
  local repo sub listing
  repo=$(scene)
  sub=$(new_repo)
  write_file "$sub" a.txt a
  commit_all "$sub"
  run "$repo" git -c protocol.file.allow=always submodule add -q "$sub" vendor/sub

  mkdir -p "$repo/zzz-filler"
  run "$repo" sh -c 'i=0; while [ $i -lt 4000 ]; do printf x > "zzz-filler/f$i.txt"; i=$((i+1)); done'
  commit_all "$repo" 'submodule + filler'
  run "$repo" git push -q origin main

  listing=$(run "$repo" git ls-tree -r HEAD)
  [ "${#listing}" -gt 200000 ] || fail "filler too small to exercise the pipe: ${#listing} bytes"

  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'submodule|gitlink'
}

# `git push` goes wherever the remote's push URL points; `gh release create`
# goes to PUBLIC_REPO regardless. If they disagree the tree lands on one repo
# and the release is cut on another, silently.
test_refuses_when_the_public_remote_push_URL_does_not_match_PUBLIC_REPO() {
  local repo
  repo=$(scene)
  run "$repo" git remote set-url --push github git@github.com:someone-else/unrelated.git
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'PUBLIC_REPO|does not match'
}

test_accepts_an_https_push_URL_for_PUBLIC_REPO_git_suffix_included() {
  local repo
  repo=$(scene)
  run "$repo" git remote set-url --push github https://github.com/owner/repo.git
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_ok
}

test_accepts_an_ssh_push_URL_for_PUBLIC_REPO_git_suffix_included() {
  local repo
  repo=$(scene)
  run "$repo" git remote set-url --push github git@github.com:owner/repo.git
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_ok
}

# The path suffix alone proves nothing: https://example.invalid/owner/repo
# ends in exactly PUBLIC_REPO. The host has to be checked against PUBLIC_HOST.
test_refuses_an_https_push_URL_whose_host_is_not_PUBLIC_HOST() {
  local repo
  repo=$(scene)
  run "$repo" git remote set-url --push github https://example.invalid/owner/repo.git
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'host|PUBLIC_HOST'
}

test_refuses_an_ssh_push_URL_whose_host_is_not_PUBLIC_HOST() {
  local repo
  repo=$(scene)
  run "$repo" git remote set-url --push github git@example.invalid:owner/repo.git
  try "$repo" bash "$SCRIPT" --preflight 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'host|PUBLIC_HOST'
}

run_tests
