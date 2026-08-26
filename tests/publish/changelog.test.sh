#!/usr/bin/env bash
# CHANGELOG.md is the single machine-readable source: it decides the release
# body and, through the section below the target, the predecessor tag the new
# commit must be built on.
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

pkg() { printf '{\n  "name": "x",\n  "version": "%s"\n}\n' "$1"; }

LOG='# Changelog

## [0.8.0] — 2026-08-25

Headline for the new one.

### How to update

    make deploy

## [0.7.0] — 2026-08-24

The previous one.
'

RU='# Changelog

## [0.8.0] — 2026-08-25

Заголовок.

## [0.7.0] — 2026-08-24

Предыдущий.
'

# Literal variants rather than ${LOG/a/b}: that expansion matches its pattern
# as a GLOB, so `[0.8.0]` is a character class and the substitution silently
# does nothing — the test would then assert against an unmodified fixture.
LOG_WITHOUT_TARGET='# Changelog

## [0.9.0] — 2026-08-25

Headline for the new one.

## [0.7.0] — 2026-08-24

The previous one.
'

RU_WITHOUT_TARGET='# Changelog

## [0.7.1] — 2026-08-25

Заголовок.

## [0.7.0] — 2026-08-24

Предыдущий.
'

# A repo shaped like gsc-hub: a manifest carrying the version.
repo_with_manifest() {
  local log=$1 ru=$2 version=$3 repo
  repo=$(new_repo)
  write_file "$repo" .publicignore '.publicignore
.publish.conf
'
  write_file "$repo" CHANGELOG.md "$log"
  write_file "$repo" CHANGELOG.ru.md "$ru"
  write_file "$repo" package.json "$(pkg "$version")"
  commit_all "$repo"
  printf '%s' "$repo"
}

# A repo shaped like slimTDS: VERSION_FILE empty, no manifest at all.
repo_without_manifest() {
  local log=$1 ru=$2 repo
  repo=$(new_repo '')
  write_file "$repo" .publicignore '.publicignore
.publish.conf
'
  write_file "$repo" CHANGELOG.md "$log"
  write_file "$repo" CHANGELOG.ru.md "$ru"
  write_file "$repo" composer.json '{"name":"claude/slim-tds"}'
  commit_all "$repo"
  printf '%s' "$repo"
}

test_reports_the_target_version_its_predecessor_and_the_section_body() {
  local repo out body
  repo=$(repo_with_manifest "$LOG" "$RU" 0.8.0)
  out=$(run "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0)
  assert_contains "$out" 'version=0.8.0'
  assert_contains "$out" 'previous=0.7.0'

  body=${out#*---body---$'\n'}
  assert_contains "$body" 'Headline for the new one.'
  assert_not_contains "$body" 'The previous one.'
  assert_not_contains "$body" '## [0.8.0]'
}

test_refuses_when_the_manifest_version_differs_from_the_argument() {
  local repo
  repo=$(repo_with_manifest "$LOG" "$RU" 0.7.0)
  try "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'manifest'
}

test_refuses_when_the_target_section_is_missing() {
  local repo
  repo=$(repo_with_manifest "$LOG_WITHOUT_TARGET" "$RU" 0.8.0)
  try "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'no section'
}

test_refuses_when_the_target_section_appears_twice() {
  local repo
  repo=$(repo_with_manifest "$LOG
## [0.8.0] — 2026-08-26

Oops.
" "$RU" 0.8.0)
  try "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'twice|more than one'
}

test_refuses_when_there_is_no_predecessor_section() {
  local repo
  repo=$(repo_with_manifest '# Changelog

## [0.8.0] — 2026-08-25

First ever.
' '# Changelog

## [0.8.0] — 2026-08-25

Первый.
' 0.8.0)
  try "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'predecessor'
}

test_refuses_when_the_translated_changelog_lacks_the_target_version() {
  local repo
  repo=$(repo_with_manifest "$LOG" "$RU_WITHOUT_TARGET" 0.8.0)
  try "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'CHANGELOG\.ru\.md'
}

# The predecessor is the next *release* section, so a heading that carries no
# x.y.z is stepped over rather than proposed as a tag that cannot exist.
test_skips_a_non_version_heading_to_find_the_real_predecessor() {
  local repo out
  repo=$(repo_with_manifest '# Changelog

## [0.8.0] — 2026-08-25

Headline.

## [Unreleased]

Nothing here yet.

## [0.7.0] — 2026-08-24

The previous one.
' "$RU" 0.8.0)
  out=$(run "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0)
  assert_contains "$out" 'previous=0.7.0'
}

# The same property, in the shape this repository actually uses: everything
# older than the public repo lives under a plain "## Before ..." heading, so
# it can never be proposed as a parent tag that was never pushed.
test_skips_a_plain_heading_that_is_not_a_bracketed_version() {
  local repo out
  repo=$(repo_without_manifest '# Changelog

## [0.8.0] — 2026-08-25

Headline.

## [0.7.0] — 2026-08-24

The previous one.

## Before the public repository

- **v0.4.1** — 2026-05-30 — never published.
' '# Changelog

## [0.8.0] — 2026-08-25

Заголовок.

## [0.7.0] — 2026-08-24

Предыдущий.
')
  out=$(run "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0)
  assert_contains "$out" 'previous=0.7.0'
}

test_refuses_with_a_readable_message_when_the_commit_has_no_manifest() {
  local repo
  repo=$(new_repo)
  write_file "$repo" .publicignore '.publicignore
.publish.conf
'
  write_file "$repo" CHANGELOG.md "$LOG"
  write_file "$repo" CHANGELOG.ru.md "$RU"
  commit_all "$repo"

  try "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'commit has no package\.json'
}

# --- VERSION_FILE is optional ------------------------------------------
#
# slimTDS keeps no version in a manifest: the image is stamped from the
# release tag at build time. An empty VERSION_FILE opts the manifest check
# out; the version argument stays cross-checked against both CHANGELOG files.

test_works_with_an_empty_VERSION_FILE_and_no_manifest_present() {
  local repo out
  repo=$(repo_without_manifest "$LOG" "$RU")
  out=$(run "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0)
  assert_contains "$out" 'version=0.8.0'
  assert_contains "$out" 'previous=0.7.0'
  assert_contains "$out" 'Headline for the new one.'
}

# Opting out of the manifest check must not opt out of the CHANGELOG checks —
# those are the only thing left tying the argument to a real release.
test_an_empty_VERSION_FILE_still_refuses_a_version_with_no_changelog_section() {
  local repo
  repo=$(repo_without_manifest "$LOG" "$RU")
  try "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.9.0
  assert_fails
  assert_matches "$TRY_ERR" 'no section'
}

test_an_empty_VERSION_FILE_still_refuses_a_version_missing_from_the_translation() {
  local repo
  repo=$(repo_without_manifest "$LOG" "$RU_WITHOUT_TARGET")
  try "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'CHANGELOG\.ru\.md'
}

# `?` rather than `:?` on the requirement check: an empty value is a
# deliberate opt-out, but a DELETED line is a broken config and must still
# fail loudly. Without this distinction a fat-fingered .publish.conf would
# silently drop the manifest check in gsc-hub too.
test_refuses_when_the_VERSION_FILE_key_is_absent_rather_than_empty() {
  local repo
  repo=$(repo_without_manifest "$LOG" "$RU")
  run "$repo" sh -c "grep -v '^VERSION_FILE=' .publish.conf > .publish.conf.new && mv .publish.conf.new .publish.conf"
  try "$repo" bash "$SCRIPT" --changelog-info "$(head_sha "$repo")" 0.8.0
  assert_fails
  assert_matches "$TRY_ERR" 'VERSION_FILE'
}

run_tests
