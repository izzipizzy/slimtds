#!/usr/bin/env bash
# Project the private tree onto the public GitHub repository.
#
# This file is byte-identical between gsc-hub and slimTDS: everything
# repo-specific lives in .publish.conf. Keep it that way — a repo-specific
# constant here silently breaks the other copy.
#
# Written for bash 3.2 (what macOS ships): no `${#arr[@]}` over an empty array
# under `set -u`, so path lists travel through files and xargs -0, not arrays.
set -euo pipefail

die() { printf '%s\n' "$*" >&2; exit 1; }

ROOT=$(git rev-parse --show-toplevel) || die 'not a git repository'
cd "$ROOT"
CONF="$ROOT/.publish.conf"
[ -f "$CONF" ] || die "missing $CONF"
# shellcheck source=/dev/null
. "$CONF"

: "${PUBLIC_REMOTE:?}" "${PUBLIC_REPO:?}" "${PUBLIC_HOST:?}" "${PRIVATE_REMOTE:?}" "${PRIVATE_BRANCH:?}"
: "${CHANGELOG_FILE:?}" "${CHANGELOG_ALT:?}"
# VERSION_FILE may be empty, and that is a supported configuration rather
# than a missing one: slimTDS keeps no version in a manifest at all — the
# image is stamped from the release tag at build time, and a hand-kept second
# copy would be exactly the invented version its BuildInfo refuses to report.
# `?` rather than `:?` so the key must still be PRESENT: a line deleted by
# accident fails loudly, only a deliberate empty value opts out. The version
# argument stays cross-checked against both CHANGELOG files either way.
: "${VERSION_FILE?}"

WORK=$(mktemp -d "${TMPDIR:-/tmp}/publish.XXXXXX")
trap 'rm -rf "$WORK"' EXIT INT TERM

# Rebuild the public tree from scratch out of <commit>. Never incremental from
# the public tip: a file added to .publicignore after it was already published
# would stay public forever.
project_tree() {
  local commit=$1
  local idx="$WORK/index" ign="$WORK/ignore" list="$WORK/excluded"

  git rev-parse --verify --quiet "${commit}^{commit}" >/dev/null \
    || die "no such commit: $commit"
  git show "$commit:.publicignore" > "$ign" 2>/dev/null \
    || die "commit $commit has no .publicignore"

  # `set -e` does not apply inside the command-substitution subshell this
  # function runs in (bash <4.4, including 3.2): a failing non-final
  # statement here does NOT stop the function and does NOT make the
  # function's own exit status reflect it. Every command whose failure could
  # change the resulting tree is therefore guarded explicitly with `|| die`
  # instead of relying on errexit. A silent failure of the update-index step
  # in particular would leave the untouched, unfiltered index behind for
  # write-tree to commit as-is — the private tree, whole.
  GIT_INDEX_FILE="$idx" git read-tree "$commit" \
    || die "git read-tree failed while projecting $commit"
  GIT_INDEX_FILE="$idx" git ls-files -c -i --exclude-from="$ign" -z > "$list" \
    || die "git ls-files failed while projecting $commit"
  if [ -s "$list" ]; then
    GIT_INDEX_FILE="$idx" xargs -0 git update-index --force-remove -- < "$list" \
      || die "git update-index failed while removing ignored paths from $commit"
  fi
  GIT_INDEX_FILE="$idx" git write-tree \
    || die "git write-tree failed while projecting $commit"
}

# Read a file out of the published commit, never out of the worktree.
show() { git show "$1:$2"; }

# The canonical machine-readable source is CHANGELOG_FILE. The translation is
# only checked for presence of the target version — two parsers over two files
# would eventually disagree about which version is the predecessor.
changelog_info() {
  local commit=$1 version=$2
  local log="$WORK/changelog" alt="$WORK/changelog-alt"

  show "$commit" "$CHANGELOG_FILE" > "$log" || die "commit has no $CHANGELOG_FILE"
  show "$commit" "$CHANGELOG_ALT" > "$alt" || die "commit has no $CHANGELOG_ALT"

  # Skipped entirely when VERSION_FILE is empty — see the note by the
  # requirement check at the top. Nothing downstream reads the manifest, so
  # there is no partial state to guard against here.
  if [ -n "$VERSION_FILE" ]; then
    local ver="$WORK/version"
    show "$commit" "$VERSION_FILE" > "$ver" || die "commit has no $VERSION_FILE"

    local manifest
    manifest=$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$ver" | head -1)
    [ -n "$manifest" ] || die "no version field in $VERSION_FILE"
    [ "$manifest" = "$version" ] \
      || die "manifest says $manifest, you asked for $version — run the version bump first"
  fi

  # Dots interpolated into a BRE match any character; escape them so
  # 0.8.0 doesn't also match 0X8X0.
  local version_re=${version//./\\.}

  local hits
  hits=$(grep -c "^## \[$version_re\]" "$log" || true)
  [ "$hits" -ge 1 ] || die "no section for $version in $CHANGELOG_FILE"
  [ "$hits" -eq 1 ] || die "section for $version appears twice in $CHANGELOG_FILE"

  grep -q "^## \[$version_re\]" "$alt" \
    || die "$CHANGELOG_ALT has no section for $version"

  local start end previous
  start=$(grep -n "^## \[$version_re\]" "$log" | head -1 | cut -d: -f1)
  # Skip past any `## [` heading that doesn't carry an x.y.z version (e.g.
  # `## [Unreleased]`) — the predecessor is the next *release* section.
  previous=$(awk -v s="$start" 'NR > s && /^## \[/ {
      if (match($0, /\[[0-9]+\.[0-9]+\.[0-9]+\]/)) {
        print substr($0, RSTART + 1, RLENGTH - 2);
        exit
      }
    }' "$log")
  [ -n "$previous" ] || die "no predecessor section after $version in $CHANGELOG_FILE"

  end=$(awk -v s="$start" 'NR > s && /^## \[/ { print NR - 1; exit }' "$log")
  [ -n "$end" ] || end=$(wc -l < "$log")

  printf 'version=%s\n' "$version"
  printf 'previous=%s\n' "$previous"
  printf -- '---body---\n'
  sed -n "$((start + 1)),${end}p" "$log"
}

# ls-remote gives ref -> SHA and nothing else: no parent, no tree, no message.
# In a fresh clone the object may not even be local. So fetch what we need by
# SHA into an isolated namespace before inspecting anything.
remote_sha() {
  local ref=$1 out
  # A non-zero exit here means the remote itself couldn't be contacted
  # (network blip, auth failure, bad PUBLIC_REMOTE) — that must die with a
  # readable message, not a bare exit. An empty result on a zero exit means
  # the remote was reached fine and the ref just doesn't exist yet, which is
  # a normal answer (e.g. a fresh release has no target tag) and must keep
  # returning empty.
  out=$(git ls-remote "$PUBLIC_REMOTE" "$ref" 2>/dev/null) \
    || die "cannot reach $PUBLIC_REMOTE — check network access and .publish.conf"
  printf '%s\n' "$out" | awk 'NR==1 {print $1}'
}

# Peeled value of a tag: annotated tags expose refs/tags/X^{}.
remote_tag_commit() {
  local tag=$1 peeled plain
  peeled=$(remote_sha "refs/tags/$tag^{}")
  if [ -n "$peeled" ]; then printf '%s\n' "$peeled"; return; fi
  plain=$(remote_sha "refs/tags/$tag")
  printf '%s\n' "$plain"
}

fetch_object() {
  local sha=$1
  git cat-file -e "$sha^{commit}" 2>/dev/null && return 0
  git fetch -q --no-tags "$PUBLIC_REMOTE" "$sha" 2>/dev/null || return 1
  git cat-file -e "$sha^{commit}" 2>/dev/null
}

# The target tag is classified FIRST. The other order breaks recovery: once
# v0.8.0 and main are pushed, "the previous tag points at the tip" is false by
# construction, and we would refuse a release we had already published.
remote_state() {
  local commit=$1 version=$2
  local tree info previous main_sha target_sha prev_sha subject

  # Same subshell-errexit gap as project_tree itself: this function also
  # runs inside a command-substitution subshell (called as
  # `state=$(remote_state ...)`), so a silently-failed project_tree call
  # would otherwise fall through with an empty/partial $tree instead of
  # stopping here.
  tree=$(project_tree "$commit") || die "project_tree failed for $commit"
  info=$(changelog_info "$commit" "$version")
  previous=$(printf '%s\n' "$info" | sed -n 's/^previous=//p')

  main_sha=$(remote_sha 'refs/heads/main')
  [ -n "$main_sha" ] || die "$PUBLIC_REPO has no main branch — nothing to build on"
  target_sha=$(remote_tag_commit "v$version")
  prev_sha=$(remote_tag_commit "v$previous")

  if [ -n "$target_sha" ]; then
    [ "$target_sha" = "$main_sha" ] \
      || die "tag v$version exists but is not the public tip — restore the ref by hand"
    fetch_object "$target_sha" || die "cannot fetch $target_sha from $PUBLIC_REMOTE"
    [ "$(git rev-parse "$target_sha^{tree}")" = "$tree" ] \
      || die "tag v$version points at a different tree — restore the ref by hand"
    # Tree equality alone isn't proof this is OUR retried release: an
    # unrelated commit can carry an identical tree, and even the right
    # parent by coincidence. The full commit message — compared exactly
    # against what this run would pass to commit-tree, not a prefix of the
    # subject — and the parent actually being the peeled predecessor tag are
    # the two remaining signals that tie the tag back to our own publish
    # step. Check both; either one alone lets a forged commit pass.
    subject=$(git log -1 --format=%B "$target_sha" 2>/dev/null) || subject=''
    [ "$subject" = "release: v$version" ] \
      || die "tag v$version points at a different commit — restore the ref by hand"
    [ -n "$prev_sha" ] \
      || die "no tag v$previous on $PUBLIC_REPO — cannot verify v$version's parent; restore the ref by hand"
    local target_parent parent_count
    target_parent=$(git rev-parse "$target_sha^") \
      || die "tag v$version has no parent commit — restore the ref by hand"
    [ "$target_parent" = "$prev_sha" ] \
      || die "tag v$version is not based on v$previous — restore the ref by hand"
    # "$target_sha^" is only the FIRST parent — a two-parent merge commit
    # whose first parent happens to be v$previous, and whose tree and
    # message happen to match, would otherwise sail through as a legitimate
    # retry. commit-tree (what this script itself uses to build a release)
    # always produces exactly one parent, and the public history is
    # required to stay linear, so a second parent is proof this was never
    # our own commit.
    parent_count=$(git rev-list --parents -n 1 "$target_sha" 2>/dev/null | wc -w | tr -d ' ') \
      || die "cannot inspect the parents of $target_sha"
    [ "$parent_count" -eq 2 ] \
      || die "tag v$version's commit is a merge (or has no parent) — restore the ref by hand"
    printf 'state=recovery\nparent=%s\ncommit=%s\n' "$target_parent" "$target_sha"
    return
  fi

  [ -n "$prev_sha" ] \
    || die "no tag v$previous on $PUBLIC_REPO — the predecessor from $CHANGELOG_FILE must exist"
  [ "$prev_sha" = "$main_sha" ] \
    || die "v$previous is not the tip of $PUBLIC_REPO main — it moved; restore the ref by hand"

  printf 'state=fresh\nparent=%s\n' "$main_sha"
}

# Every new public path is confirmed, not only new top-level ones: a service
# file lands as src/lib/server/ops-secret.ts inside an already-public
# directory, and a top-level check would never see it.
new_paths() {
  local commit=$1 version=$2
  local tree state parent old="$WORK/old" new="$WORK/new" added="$WORK/added"

  # `set -e` does not apply inside this function's own command-substitution
  # subshell (called as `paths=$(new_paths ...)`), same as project_tree — a
  # failure of ls-tree, sort or comm here would otherwise silently produce
  # an empty/partial $added and report new=0, turning the anti-leak
  # confirmation gate off. Every command that feeds $added or $tree is
  # therefore explicitly guarded rather than left to errexit.
  tree=$(project_tree "$commit") || die "project_tree failed for $commit"
  state=$(remote_state "$commit" "$version") || die "remote_state failed for $commit"
  parent=$(printf '%s\n' "$state" | sed -n 's/^parent=//p')

  fetch_object "$parent" || die "cannot fetch $parent from $PUBLIC_REMOTE"
  git ls-tree -r --name-only "$parent^{tree}" | sort > "$old" \
    || die "git ls-tree/sort failed listing $parent"
  git ls-tree -r --name-only "$tree" | sort > "$new" \
    || die "git ls-tree/sort failed listing the projected tree"
  comm -13 "$old" "$new" > "$added" \
    || die "comm failed diffing old and new paths"

  # The hash covers the projected tree, not just the names: a file can change
  # content while keeping its path, and a name-only hash would still match a
  # confirmation the operator gave for the previous content.
  local confirm
  confirm=$(printf '%s\n' "$tree" | cat - "$added" | git hash-object --stdin | cut -c1-12)

  cat "$added"
  printf 'new=%s\n' "$(wc -l < "$added" | tr -d ' ')"
  printf 'confirm=%s\n' "$confirm"
}

have() { command -v "$1" >/dev/null 2>&1; }

preflight() {
  local version=$1 branch head_sha origin_sha

  have git || die 'git not found'
  have gh || die 'gh not found — the GitHub release step needs it'

  # git push goes wherever $PUBLIC_REMOTE's push URL points; `gh release
  # create` goes to $PUBLIC_REPO regardless. If those two disagree — a
  # mistyped restore, a remote repointed by hand — the tree lands on one repo
  # and the release gets created on another, silently. Accept https and ssh
  # forms, with or without a trailing .git. Checking only the path suffix is
  # not enough: https://example.invalid/$PUBLIC_REPO would pass that check
  # too, even though it is nowhere near $PUBLIC_HOST — the host itself has
  # to be verified against PUBLIC_HOST (from .publish.conf, so a GitHub
  # Enterprise host stays possible without a repo-specific edit to this
  # shared script). A bare filesystem path carries no host at all — that is
  # the shape a local mirror (and this test suite) uses to stand in for the
  # real remote — so only the path is checked in that case.
  local remote_url normalized host repo_path
  remote_url=$(git remote get-url --push "$PUBLIC_REMOTE" 2>/dev/null) \
    || die "no push URL for remote $PUBLIC_REMOTE — check .publish.conf"
  normalized=${remote_url%.git}
  case "$normalized" in
    http://*|https://*)
      host=${normalized#*://}
      host=${host%%/*}
      repo_path=${normalized#*://*/}
      ;;
    ssh://*)
      host=${normalized#ssh://}
      host=${host#*@}
      host=${host%%/*}
      host=${host%%:*}
      repo_path=${normalized#ssh://*/}
      ;;
    *@*:*)
      host=${normalized#*@}
      host=${host%%:*}
      repo_path=${normalized#*:}
      ;;
    *)
      host=
      repo_path=$normalized
      ;;
  esac
  if [ -n "$host" ] && [ "$host" != "$PUBLIC_HOST" ]; then
    die "$PUBLIC_REMOTE push URL ($remote_url) is on host $host, not $PUBLIC_HOST — check .publish.conf or the remote"
  fi
  case "$repo_path" in
    */"$PUBLIC_REPO" | "$PUBLIC_REPO") ;;
    *) die "$PUBLIC_REMOTE push URL ($remote_url) does not match PUBLIC_REPO ($PUBLIC_REPO) — check .publish.conf or the remote" ;;
  esac

  branch=$(git rev-parse --abbrev-ref HEAD)
  [ "$branch" = "$PRIVATE_BRANCH" ] \
    || die "on branch $branch — publish from $PRIVATE_BRANCH only"

  [ -z "$(git status --porcelain --untracked-files=no)" ] \
    || die 'uncommitted tracked changes — commit them first'

  # Written as `if`, not `test && die`: under `set -e` a false test at the end
  # of an `&&` chain is itself a failing command and would kill the script.
  local gitdir
  gitdir=$(git rev-parse --git-dir)
  if [ -f "$gitdir/MERGE_HEAD" ]; then die 'a merge is in progress'; fi
  if [ -d "$gitdir/rebase-merge" ] || [ -d "$gitdir/rebase-apply" ]; then
    die 'a rebase is in progress'
  fi

  # Order is fixed on purpose: Gitea first, then GitHub. Publishing a commit
  # the private remote has never seen makes the private repo stop being the
  # source of truth the moment anything goes wrong.
  git fetch -q "$PRIVATE_REMOTE" "$PRIVATE_BRANCH" \
    || die "cannot fetch $PRIVATE_REMOTE — check network access and .publish.conf"
  head_sha=$(git rev-parse HEAD)
  origin_sha=$(git rev-parse "$PRIVATE_REMOTE/$PRIVATE_BRANCH")
  [ "$head_sha" = "$origin_sha" ] \
    || die "HEAD differs from $PRIVATE_REMOTE/$PRIVATE_BRANCH — push there first"

  # Not `git ls-tree -r HEAD | grep -q ...`: grep -q exits on its first match,
  # and on a tree large enough that the producer is still writing, the write
  # gets SIGPIPE. Under `pipefail` that makes the pipeline's exit status the
  # producer's death (141), not grep's — the `if` sees a false pipeline and
  # this check silently passes. Land the listing in a file first so grep's
  # (and awk's) exit status alone decides the outcome. Both checks below
  # reuse this one listing instead of invoking git a second time.
  # Without -z, `git ls-tree` C-quotes any path containing a character that
  # would otherwise be ambiguous (tab, newline, ...): the whole path gets
  # wrapped in double quotes with backslash escapes. A directory named e.g.
  # "weird\tdir" then prints as "weird\\tdir/.gitattributes" — a field that
  # ends in a literal `"`, not in ".gitattributes", so the regex below would
  # silently miss it and let it publish. -z disables that quoting outright,
  # so the path field always ends in its real bytes; NUL-delimited records
  # are turned into newline-delimited lines here (no path in a normal repo
  # legitimately contains a raw newline byte) so the rest of this function's
  # line-oriented tools (awk, grep) keep working unchanged.
  local lstree="$WORK/ls-tree"
  git ls-tree -rz HEAD | tr '\0' '\n' > "$lstree" \
    || die 'git ls-tree failed while scanning HEAD for .gitattributes/gitlinks'

  # git applies .gitattributes at ANY level, not only the root — checking
  # only "HEAD:.gitattributes" would miss e.g. assets/.gitattributes
  # carrying an LFS filter, which would publish right alongside the LFS
  # pointer it was meant to rewrite. Split on the FIRST tab only (via
  # index()/substr(), not `-F'\t'` field splitting) so a path that itself
  # contains a literal tab byte still yields the whole real path, not just
  # the piece before that byte.
  if awk '{
      t = index($0, "\t");
      if (t == 0) next;
      path = substr($0, t + 1);
      if (path ~ /(^|\/)\.gitattributes$/) { found=1 }
    } END { exit !found }' "$lstree"; then
    die '.gitattributes in the tree — the projection does not apply its filters'
  fi

  if grep -q '^160000 ' "$lstree"; then
    die 'submodule gitlink in the tree — a public clone could not resolve it'
  fi

  [ -n "$version" ] || die 'no version given'
}

# "x.y.z": digits, dot, digits, dot, digits, anchored both ends. A shell case
# glob (`[0-9]*.[0-9]*.[0-9]*`) would accept "1a2b3" too, since `.` there
# matches any single character, not a literal dot.
looks_like_semver() {
  printf '%s' "$1" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'
}

# Ask the operator to retype the confirmation hash. A terminal on stdin
# always prompts — PUBLISH_CONFIRM is ignored outright, so an exported
# PUBLISH_CONFIRM=auto left over from a test session cannot silently skip the
# gate in a real, interactive release. Only when stdin is NOT a terminal does
# PUBLISH_CONFIRM=auto skip the prompt (for tests only; never set it for a
# real release), and it does so loudly — the warning names how many public
# paths were skipped. Non-interactive without the bypass set dies with a
# readable message instead of a bare exit from a `read` that can never
# succeed.
confirm_new_paths() {
  local expected=$1 count=$2 answer
  if [ "$count" -eq 0 ]; then return 0; fi

  if [ -t 0 ]; then
    printf 'Type the confirmation hash to publish these %s new path(s): ' "$count" >&2
    read -r answer || die 'no confirmation given — nothing was published'
    [ "$answer" = "$expected" ] || die 'confirmation did not match — nothing was published'
    return 0
  fi

  if [ "${PUBLISH_CONFIRM:-}" = 'auto' ]; then
    printf '*** WARNING: PUBLISH_CONFIRM=auto skipped confirmation for %s new public path(s) ***\n' "$count" >&2
    return 0
  fi

  die "stdin is not a terminal and PUBLISH_CONFIRM=auto is not set — cannot confirm $count new public path(s)"
}

publish() {
  local version=$1 dry=${2:-}
  local commit tree info previous body state parent existing paths count confirm message

  preflight "$version"
  commit=$(git rev-parse HEAD)
  # project_tree/new_paths run in their own command-substitution subshells;
  # `|| die` here is belt-and-suspenders (publish() itself is not nested in
  # a subshell, so plain errexit already applies to these two statements),
  # kept explicit anyway for the same reason the leaf functions are.
  tree=$(project_tree "$commit") || die "project_tree failed for $commit"
  info=$(changelog_info "$commit" "$version")
  previous=$(printf '%s\n' "$info" | sed -n 's/^previous=//p')
  body=$(printf '%s\n' "$info" | sed -n '/^---body---$/,$p' | sed '1d')
  message="release: v$version"

  state=$(remote_state "$commit" "$version")
  parent=$(printf '%s\n' "$state" | sed -n 's/^parent=//p')
  existing=$(printf '%s\n' "$state" | sed -n 's/^commit=//p')

  paths=$(new_paths "$commit" "$version") || die "new_paths failed for $commit"
  count=$(printf '%s\n' "$paths" | sed -n 's/^new=//p')
  confirm=$(printf '%s\n' "$paths" | sed -n 's/^confirm=//p')

  if [ -n "$dry" ]; then
    printf 'tree=%s\nparent=%s\nprevious=%s\nmessage=%s\nnew=%s\n\n' \
      "$tree" "$parent" "$previous" "$message" "$count"
    printf '%s\n' "$paths" | sed '/^new=/d'
    printf -- '--- release body ---\n%s\n' "$body"
    return 0
  fi

  local public_sha
  if [ -n "$existing" ]; then
    public_sha=$existing
  else
    # The gate in confirm_new_paths only ever asks for the hash — it never
    # shows what it covers. Print the list and the hash it was computed from
    # here, before asking, so the operator has something to actually check
    # against rather than a blind value to retype.
    if [ "$count" -gt 0 ]; then
      printf 'New public path(s) not published before (%s):\n' "$count" >&2
      printf '%s\n' "$paths" | sed '/^new=/d;/^confirm=/d' >&2
      printf 'confirm=%s\n' "$confirm" >&2
    fi
    confirm_new_paths "$confirm" "$count"
    public_sha=$(git commit-tree "$tree" -p "$parent" -m "$message")
    # No force, ever: the commit is a child of the tip we just read, so this
    # must be a fast-forward, and a plain push rejects the race by itself.
    git push --atomic "$PUBLIC_REMOTE" \
      "$public_sha:refs/heads/main" "$public_sha:refs/tags/v$version"
  fi

  # A non-zero `gh release view` is not, on its own, "no release exists" —
  # that reading also fits a network blip, expired auth, or a missing scope,
  # and blindly running `release create` on any of those either fails loudly
  # with "already exists" (if the release is in fact there) or reports the
  # wrong cause. gh's own wording distinguishes the two: a real 404 says
  # exactly "release not found" on stderr; anything else is "we don't know".
  # Land stderr in a file rather than a `var=$(cmd)` capture — that would
  # swallow the exit status under `set -e` before it could be tested.
  local view_err="$WORK/gh-view-err"
  if gh release view "v$version" --repo "$PUBLIC_REPO" >/dev/null 2>"$view_err"; then
    printf 'release v%s already exists\n' "$version"
  elif grep -qi 'release not found' "$view_err"; then
    printf '%s\n' "$body" > "$WORK/body"
    gh release create "v$version" --repo "$PUBLIC_REPO" \
      --title "v$version" --notes-file "$WORK/body"
  else
    die "cannot tell whether release v$version exists on $PUBLIC_REPO — gh release view failed: $(cat "$view_err") — check by hand before retrying"
  fi

  printf 'published v%s as %s\n' "$version" "$public_sha"
}

case "${1:-}" in
  --project-tree)   [ $# -eq 2 ] || die 'usage: publish.sh --project-tree <commit>';   project_tree "$2" ;;
  --changelog-info) [ $# -eq 3 ] || die 'usage: publish.sh --changelog-info <commit> <x.y.z>'; changelog_info "$2" "$3" ;;
  --remote-state)   [ $# -eq 3 ] || die 'usage: publish.sh --remote-state <commit> <x.y.z>';   remote_state "$2" "$3" ;;
  --new-paths)      [ $# -eq 3 ] || die 'usage: publish.sh --new-paths <commit> <x.y.z>';      new_paths "$2" "$3" ;;
  --preflight)      [ $# -eq 2 ] || die 'usage: publish.sh --preflight <x.y.z>';               preflight "$2" ;;
  --dry-run)        [ $# -eq 2 ] || die 'usage: publish.sh --dry-run <x.y.z>'
                    looks_like_semver "$2" || die 'version must look like x.y.z'
                    publish "$2" dry ;;
  '' | -h | --help) die 'usage: publish.sh <x.y.z> | --dry-run <x.y.z>' ;;
  -*)               die "unknown option $1" ;;
  *)                [ $# -eq 1 ] || die 'usage: publish.sh <x.y.z>'
                    looks_like_semver "$1" || die 'version must look like x.y.z'
                    publish "$1" ;;
esac
