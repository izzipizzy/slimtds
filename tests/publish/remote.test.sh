#!/usr/bin/env bash
# The public history is the only record of what was published: there is no
# journal. remote_state classifies by the TARGET tag first, so a release that
# landed but whose client never saw the answer is recognised as a retry
# rather than refused.
#
# The scenes here use the slimTDS shape — VERSION_FILE empty, no manifest —
# so the whole remote path runs the way this repository will actually run it.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

LOG='# Changelog

## [0.8.0] — 2026-08-25

New.

## [0.7.0] — 2026-08-24

Old.
'

# A private repo plus a bare "GitHub". Sets SCENE_REPO and SCENE_BARE.
scene() {
  SCENE_REPO=$(new_repo '')
  write_file "$SCENE_REPO" .publicignore '.publicignore
.publish.conf
CLAUDE.md
'
  write_file "$SCENE_REPO" CLAUDE.md 'private'
  write_file "$SCENE_REPO" CHANGELOG.md "$LOG"
  write_file "$SCENE_REPO" CHANGELOG.ru.md "$LOG"
  write_file "$SCENE_REPO" src/App.php 'app'
  commit_all "$SCENE_REPO"
  SCENE_BARE=$(attach_bare_remote "$SCENE_REPO")
  SCENE_TREE=$(run "$SCENE_REPO" bash "$SCRIPT" --project-tree "$(head_sha "$SCENE_REPO")")
}

# --- remote-state -------------------------------------------------------

test_is_fresh_when_the_previous_tag_points_at_the_public_tip() {
  local tip out
  scene
  tip=$(seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0)
  out=$(run "$SCENE_REPO" bash "$SCRIPT" --remote-state "$(head_sha "$SCENE_REPO")" 0.8.0)
  assert_contains "$out" 'state=fresh'
  assert_contains "$out" "parent=$tip"
}

# slimTDS's own v0.7.0 is annotated, so refs/tags/v0.7.0 is the tag object,
# not the commit. Without peeling via ^{} the parent contract would see a
# mismatch and refuse a perfectly good release.
test_accepts_an_annotated_previous_tag_by_peeling_it() {
  local sha out
  scene
  sha=$(run "$SCENE_REPO" git commit-tree "$SCENE_TREE" -m 'release: v0.7.0')
  run "$SCENE_REPO" git tag -a v0.7.0 -m v0.7.0 "$sha"
  run "$SCENE_REPO" git push --atomic "$SCENE_BARE" "$sha:refs/heads/main" refs/tags/v0.7.0
  out=$(run "$SCENE_REPO" bash "$SCRIPT" --remote-state "$(head_sha "$SCENE_REPO")" 0.8.0)
  assert_contains "$out" 'state=fresh'
  assert_contains "$out" "parent=$sha"
}

test_is_recovery_when_the_target_tag_and_main_already_point_at_our_commit() {
  local prev out
  scene
  prev=$(seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0)
  seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.8.0' v0.8.0 "$prev" >/dev/null
  out=$(run "$SCENE_REPO" bash "$SCRIPT" --remote-state "$(head_sha "$SCENE_REPO")" 0.8.0)
  assert_contains "$out" 'state=recovery'
}

test_is_a_conflict_when_someone_else_moved_the_public_branch() {
  local tagged stray
  scene
  tagged=$(seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0)
  stray=$(run "$SCENE_REPO" git commit-tree "$SCENE_TREE" -m 'merged on github' -p "$tagged")
  run "$SCENE_REPO" git push "$SCENE_BARE" "$stray:refs/heads/main"
  try "$SCENE_REPO" bash "$SCRIPT" --remote-state "$(head_sha "$SCENE_REPO")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'moved|not the tip'
}

test_is_a_conflict_when_the_previous_tag_is_missing() {
  local sha
  scene
  sha=$(run "$SCENE_REPO" git commit-tree "$SCENE_TREE" -m 'release: v0.7.0')
  run "$SCENE_REPO" git push "$SCENE_BARE" "$sha:refs/heads/main"
  try "$SCENE_REPO" bash "$SCRIPT" --remote-state "$(head_sha "$SCENE_REPO")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'v0\.7\.0'
}

test_is_a_conflict_when_the_target_tag_points_at_a_different_commit() {
  local prev other
  scene
  prev=$(seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0)
  other=$(run "$SCENE_REPO" git commit-tree "$SCENE_TREE" -m 'something else' -p "$prev")
  run "$SCENE_REPO" git push --atomic "$SCENE_BARE" "$other:refs/heads/main" "$other:refs/tags/v0.8.0"
  try "$SCENE_REPO" bash "$SCRIPT" --remote-state "$(head_sha "$SCENE_REPO")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'v0\.8\.0'
}

# Correct tree, correct parent — only the message differs, and only by a
# trailing word. A prefix or glob check on the subject would let this pass as
# our own retry; the comparison has to be on the full message.
test_refuses_a_recovery_shaped_commit_whose_message_is_a_near_miss() {
  local prev forged
  scene
  prev=$(seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0)
  forged=$(run "$SCENE_REPO" git commit-tree "$SCENE_TREE" -m 'release: v0.8.0 forged' -p "$prev")
  run "$SCENE_REPO" git push --atomic "$SCENE_BARE" "$forged:refs/heads/main" "$forged:refs/tags/v0.8.0"
  try "$SCENE_REPO" bash "$SCRIPT" --remote-state "$(head_sha "$SCENE_REPO")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'v0\.8\.0'
}

# Correct tree, correct message, but not a child of v0.7.0. Sitting on the
# public tip with a matching tree is not proof of provenance.
test_refuses_a_recovery_shaped_commit_built_on_the_wrong_parent() {
  local wrong
  scene
  seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0 >/dev/null
  wrong=$(run "$SCENE_REPO" git commit-tree "$SCENE_TREE" -m 'release: v0.8.0')
  # --force only because this fixture stands in for a hand-restored ref; a
  # real one would necessarily bypass the fast-forward push too.
  run "$SCENE_REPO" git push --force --atomic "$SCENE_BARE" \
    "$wrong:refs/heads/main" "$wrong:refs/tags/v0.8.0"
  try "$SCENE_REPO" bash "$SCRIPT" --remote-state "$(head_sha "$SCENE_REPO")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'parent|based on'
}

# Everything `git rev-parse "$sha^"` (first parent only) can see lines up.
# But commit-tree, which is what this script builds releases with, can never
# produce two parents, and the public history must stay linear — so a second
# parent alone is enough to refuse it.
test_refuses_a_recovery_shaped_commit_that_is_a_merge() {
  local prev other merge
  scene
  prev=$(seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0)
  other=$(run "$SCENE_REPO" git commit-tree "$SCENE_TREE" -m 'unrelated')
  merge=$(run "$SCENE_REPO" git commit-tree "$SCENE_TREE" -m 'release: v0.8.0' -p "$prev" -p "$other")
  run "$SCENE_REPO" git push --atomic "$SCENE_BARE" "$merge:refs/heads/main" "$merge:refs/tags/v0.8.0"
  try "$SCENE_REPO" bash "$SCRIPT" --remote-state "$(head_sha "$SCENE_REPO")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'merge|parent'
}

# An unreachable remote must die with something readable, not a bare exit —
# "no such ref" and "could not talk to the server" are different answers.
test_is_a_readable_error_when_the_public_remote_cannot_be_reached() {
  local repo
  repo=$(new_repo '')
  write_file "$repo" .publicignore '.publicignore
.publish.conf
'
  write_file "$repo" CHANGELOG.md "$LOG"
  write_file "$repo" CHANGELOG.ru.md "$LOG"
  write_file "$repo" src/App.php 'app'
  commit_all "$repo"
  run "$repo" git remote add github /nonexistent/path/does-not-exist.git

  try "$repo" bash "$SCRIPT" --remote-state "$(head_sha "$repo")" 0.8.0
  assert_fails
  assert_ne '' "$TRY_ERR" 'died silently instead of explaining'
  assert_matches "$TRY_ERR" 'reach'
  assert_matches "$TRY_ERR" 'github'
}

# --- new-paths ----------------------------------------------------------

test_lists_nothing_when_the_public_tree_gains_no_paths() {
  local out
  scene
  seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0 >/dev/null
  out=$(run "$SCENE_REPO" bash "$SCRIPT" --new-paths "$(head_sha "$SCENE_REPO")" 0.8.0)
  assert_contains "$out" 'new=0'
}

# Fail-closed on ALL new public paths. A top-level check would never see a
# service file landing inside an already-public directory.
test_lists_a_nested_new_path_not_only_top_level_ones() {
  local out
  scene
  seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0 >/dev/null
  write_file "$SCENE_REPO" src/Shared/Ops/Secret.php 'x'
  commit_all "$SCENE_REPO" 'add nested file'
  out=$(run "$SCENE_REPO" bash "$SCRIPT" --new-paths "$(head_sha "$SCENE_REPO")" 0.8.0)
  assert_contains "$out" 'src/Shared/Ops/Secret.php'
  assert_contains "$out" 'new=1'
  assert_matches "$out" 'confirm=[0-9a-f]{12}'
}

# The hash covers the projected tree, not just the names — otherwise a
# confirmation given for one file's contents would still match after the
# contents changed under the same path.
test_changes_the_confirmation_hash_when_the_tree_changes() {
  local first second h1 h2
  scene
  seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0 >/dev/null
  write_file "$SCENE_REPO" extra.txt 'a'
  commit_all "$SCENE_REPO" one
  first=$(run "$SCENE_REPO" bash "$SCRIPT" --new-paths "$(head_sha "$SCENE_REPO")" 0.8.0)
  write_file "$SCENE_REPO" extra.txt 'b'
  commit_all "$SCENE_REPO" two
  second=$(run "$SCENE_REPO" bash "$SCRIPT" --new-paths "$(head_sha "$SCENE_REPO")" 0.8.0)

  h1=$(printf '%s' "$first" | sed -n 's/^confirm=//p')
  h2=$(printf '%s' "$second" | sed -n 's/^confirm=//p')
  assert_ne "$h1" "$h2" 'the confirmation hash ignored a content change'
}

# `set -e` does not apply inside the command-substitution subshell that
# `tree=$(project_tree ...)` runs in under bash 3.2: a failing non-final
# statement neither stops the function nor shows up in its exit status. A
# silently-failed update-index would leave the UNFILTERED index behind for
# write-tree — the whole private tree, reported with a plausible new=N line.
# These two prove the explicit `|| die` guards actually stop that.
test_dies_instead_of_emitting_a_tree_when_git_update_index_fails() {
  scene
  seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0 >/dev/null
  stub_git_failing update-index
  try "$SCENE_REPO" env PATH="$GIT_STUB_BIN:$PATH" \
    bash "$SCRIPT" --new-paths "$(head_sha "$SCENE_REPO")" 0.8.0
  assert_fails
  assert_not_contains "$TRY_OUT" 'new=' 'leaked a result before dying'
  assert_matches "$TRY_ERR" 'update-index|project_tree'
}

test_dies_instead_of_emitting_a_diff_when_git_ls_tree_fails() {
  scene
  seed_public "$SCENE_REPO" "$SCENE_BARE" "$SCENE_TREE" 'release: v0.7.0' v0.7.0 >/dev/null
  stub_git_failing ls-tree
  try "$SCENE_REPO" env PATH="$GIT_STUB_BIN:$PATH" \
    bash "$SCRIPT" --new-paths "$(head_sha "$SCENE_REPO")" 0.8.0
  assert_fails
  assert_not_contains "$TRY_OUT" 'new=' 'leaked a result before dying'
  assert_matches "$TRY_ERR" 'ls-tree'
}

run_tests
