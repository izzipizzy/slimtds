#!/usr/bin/env bash
# End to end, against a bare repo standing in for GitHub and a stub `gh`.
# Nothing here touches a real remote.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

LOG='# Changelog

## [0.8.0] — 2026-08-25

New things.

## [0.7.0] — 2026-08-24

Old.
'

# A repo already at v0.7.0 publicly, whose next release adds no new public
# path — so the confirmation gate has nothing to ask about.
# Sets SCENE_REPO, SCENE_BARE, SCENE_PREV, GH_BIN, GH_LOG.
scene() {
  local tree
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
  # publish() runs preflight, which insists HEAD matches the private remote.
  attach_private_remote "$SCENE_REPO" >/dev/null
  stub_gh
  tree=$(run "$SCENE_REPO" bash "$SCRIPT" --project-tree "$(head_sha "$SCENE_REPO")")
  SCENE_PREV=$(seed_public "$SCENE_REPO" "$SCENE_BARE" "$tree" 'release: v0.7.0' v0.7.0)
}

# Same, but the release genuinely adds a new public path, so the gate fires.
scene_with_new_path() {
  local tree
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
  tree=$(run "$SCENE_REPO" bash "$SCRIPT" --project-tree "$(head_sha "$SCENE_REPO")")
  SCENE_PREV=$(seed_public "$SCENE_REPO" "$SCENE_BARE" "$tree" 'release: v0.7.0' v0.7.0)

  write_file "$SCENE_REPO" src/Shared/Ops/NewThing.php 'x'
  commit_all "$SCENE_REPO" 'add a new public path'
  attach_private_remote "$SCENE_REPO" >/dev/null
  stub_gh
}

test_pushes_a_commit_and_its_tag_then_creates_the_release() {
  local main
  scene
  run "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto bash "$SCRIPT" 0.8.0 >/dev/null

  main=$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/heads/main)
  assert_ne "$SCENE_PREV" "$main" 'public main did not move'
  assert_eq "$SCENE_PREV" "$(run "$SCENE_REPO" git rev-parse "$main^")" 'not a child of the previous tag'
  assert_eq "$main" "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/tags/v0.8.0)"
  assert_contains "$(cat "$GH_LOG")" 'release create v0.8.0'
}

test_publishes_the_projected_tree_without_the_private_files() {
  local main listing
  scene
  run "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto bash "$SCRIPT" 0.8.0 >/dev/null
  main=$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/heads/main)
  listing=$(run "$SCENE_REPO" git ls-tree -r --name-only "$main")
  assert_contains "$listing" 'src/App.php'
  assert_not_contains "$listing" 'CLAUDE.md'
  assert_not_contains "$listing" '.publicignore'
  assert_not_contains "$listing" '.publish.conf'
}

# The target tag is classified first, so a completed release is recognised
# rather than re-cut.
test_is_a_no_op_on_a_second_run() {
  local first
  scene
  run "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto bash "$SCRIPT" 0.8.0 >/dev/null
  first=$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/heads/main)
  run "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto bash "$SCRIPT" 0.8.0 >/dev/null
  assert_eq "$first" "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/heads/main)" 'second run moved main'
}

# The partial failure the recovery path exists for: refs landed, gh did not.
test_finishes_a_release_whose_branch_and_tag_landed_but_whose_gh_call_failed() {
  local landed
  scene
  try "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto GH_STUB_FAIL=1 bash "$SCRIPT" 0.8.0
  assert_fails
  landed=$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/heads/main)
  assert_eq "$landed" "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/tags/v0.8.0)" 'tag and branch disagree'

  try "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto bash "$SCRIPT" 0.8.0
  assert_ok
  assert_eq "$landed" "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/heads/main)" 'retry moved main'
  assert_contains "$(cat "$GH_LOG")" 'release create v0.8.0'
}

# A failing `gh release view` is not by itself "no release exists" — that
# reading also fits a network blip or expired auth. Guessing wrong here means
# either a bogus "already exists" or a duplicate create.
test_refuses_to_guess_when_gh_release_view_fails_operationally() {
  local calls
  scene
  run "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto bash "$SCRIPT" 0.8.0 >/dev/null
  assert_contains "$(cat "$GH_LOG")" 'release create v0.8.0'

  try "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto GH_STUB_VIEW_FAIL=1 bash "$SCRIPT" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'cannot tell|unknown'
  assert_not_contains "$TRY_ERR" 'already exists'

  calls=$(grep -c 'release create v0\.8\.0' "$GH_LOG" || true)
  assert_eq 1 "$calls" 'the release was created more than once'
}

test_dry_run_writes_nothing() {
  local out
  scene
  out=$(run "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto bash "$SCRIPT" --dry-run 0.8.0)
  assert_matches "$out" 'tree=[0-9a-f]{40}'
  assert_contains "$out" 'New things.'
  assert_eq "$SCENE_PREV" "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/heads/main)" 'dry run moved main'
  assert_eq '' "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/tags/v0.8.0)" 'dry run created a tag'
  [ ! -f "$GH_LOG" ] || fail 'dry run called gh'
}

# --- the new-public-path gate ------------------------------------------

test_PUBLISH_CONFIRM_auto_skips_the_gate_non_interactively_but_loudly() {
  local combined
  scene_with_new_path
  combined=$(run "$SCENE_REPO" env PATH="$GH_BIN:$PATH" PUBLISH_CONFIRM=auto \
    sh -c "bash '$SCRIPT' 0.8.0 2>&1")
  assert_contains "$combined" 'WARNING'
  assert_contains "$combined" 'PUBLISH_CONFIRM=auto'
  assert_contains "$combined" '1 new public path'
  assert_ne '' "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/tags/v0.8.0)" 'nothing was published'
}

# Non-interactive without the bypass must die readably, before anything
# lands — not fall through a `read` that can never succeed.
test_dies_readably_when_non_interactive_and_the_bypass_is_unset() {
  local before
  scene_with_new_path
  before=$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/heads/main)
  try "$SCENE_REPO" env PATH="$GH_BIN:$PATH" bash "$SCRIPT" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'stdin is not a terminal'
  assert_matches "$TRY_ERR" 'PUBLISH_CONFIRM'
  assert_eq "$before" "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/heads/main)" 'main moved anyway'
  assert_eq '' "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/tags/v0.8.0)" 'a tag landed anyway'
}

# The gate asks for a hash; it has to SHOW the list and the hash first, or
# the operator is retyping a blind value. Driven over a real pty, since
# `[ -t 0 ]` is never true on a pipe.
test_accepts_the_printed_confirmation_hash_typed_at_the_prompt() {
  local listing hash
  scene_with_new_path
  listing=$(run "$SCENE_REPO" bash "$SCRIPT" --new-paths "$(head_sha "$SCENE_REPO")" 0.8.0)
  hash=$(printf '%s' "$listing" | sed -n 's/^confirm=//p')
  assert_matches "$hash" '^[0-9a-f]{12}$'

  run_in_pty "$SCENE_REPO" "$hash
" env PATH="$GH_BIN:$PATH" bash "$SCRIPT" 0.8.0
  assert_eq 0 "$PTY_STATUS" "publish failed at the prompt:
    $PTY_OUT"
  assert_contains "$PTY_OUT" 'src/Shared/Ops/NewThing.php'
  assert_contains "$PTY_OUT" "confirm=$hash"
  assert_contains "$PTY_OUT" 'Type the confirmation hash'
  assert_ne '' "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/tags/v0.8.0)" 'nothing was published'
  assert_contains "$(cat "$GH_LOG")" 'release create v0.8.0'
}

test_refuses_a_wrong_confirmation_hash_and_publishes_nothing() {
  scene_with_new_path
  run_in_pty "$SCENE_REPO" "deadbeef1234
" env PATH="$GH_BIN:$PATH" bash "$SCRIPT" 0.8.0
  assert_ne 0 "$PTY_STATUS" 'a wrong hash was accepted'
  assert_contains "$PTY_OUT" 'did not match'
  assert_eq '' "$(remote_sha "$SCENE_REPO" "$SCENE_BARE" refs/tags/v0.8.0)" 'a tag landed anyway'
}

# --- argument validation ------------------------------------------------

# A shell case glob (`[0-9]*.[0-9]*.[0-9]*`) would accept this: `.` there
# matches any character, not a literal dot.
test_rejects_a_version_that_only_looks_like_xyz_as_a_glob_in_both_modes() {
  scene
  try "$SCENE_REPO" env PATH="$GH_BIN:$PATH" bash "$SCRIPT" 1a2b3
  assert_fails
  assert_matches "$TRY_ERR" 'x\.y\.z'

  try "$SCENE_REPO" env PATH="$GH_BIN:$PATH" bash "$SCRIPT" --dry-run 1a2b3
  assert_fails
  assert_matches "$TRY_ERR" 'x\.y\.z'
}

run_tests
