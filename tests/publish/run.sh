#!/usr/bin/env bash
# Run every scripts/publish.sh test. Usage: tests/publish/run.sh [name ...]
#
# These run on the HOST, not in the app container, and are not part of `make
# test`: publish.sh is a release tool that drives git remotes and `gh`, so
# testing it inside a container that has neither would prove nothing. Every
# fixture is a throwaway local repository — no real remote is ever contacted.
#
# Requires bash, git, python3 (one test drives the confirmation prompt over a
# real pty) and perl. It does NOT require gh: a stub stands in for it.
set -uo pipefail

DIR=$(cd "$(dirname "$0")" && pwd)

if [ $# -gt 0 ]; then
  files=()
  for name in "$@"; do
    file="$DIR/${name%.test.sh}.test.sh"
    [ -f "$file" ] || { printf 'no such test file: %s\n' "$file" >&2; exit 1; }
    files+=("$file")
  done
else
  files=("$DIR"/*.test.sh)
fi

for tool in git python3 perl; do
  command -v "$tool" >/dev/null || { printf '%s not found\n' "$tool" >&2; exit 1; }
done

failed=0
for file in "${files[@]}"; do
  bash "$file" || failed=$((failed + 1))
done

if [ "$failed" -eq 0 ]; then
  printf 'all publish tests passed\n'
else
  printf '%s test file(s) had failures\n' "$failed" >&2
fi
exit "$( [ "$failed" -eq 0 ] && echo 0 || echo 1 )"
