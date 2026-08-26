#!/usr/bin/env python3
"""Run a command attached to a real pseudo-terminal, feeding it this
process's own stdin once the child is up.

scripts/publish.sh's confirmation gate (confirm_new_paths) only prompts when
`[ -t 0 ]` is true — a plain pipe, which is what a test runner's
execFileSync/spawnSync gives a child by default, never satisfies that. This
is the only way to actually drive the "type the hash" accept path from a
test rather than only ever exercising the PUBLISH_CONFIRM=auto bypass.

Usage: pty-run.py <cwd> <cmd> [args...]   (input is read from this
process's stdin and written to the child's pty right after it starts)
Prints the child's combined stdout+stderr (as seen through the pty) and
exits with the child's exit status.
"""
import os
import sys


def main() -> int:
    cwd = sys.argv[1]
    cmd = sys.argv[2:]
    input_data = sys.stdin.buffer.read()

    import pty

    pid, fd = pty.fork()
    if pid == 0:
        os.chdir(cwd)
        os.execvp(cmd[0], cmd)
        os._exit(127)  # pragma: no cover - only on exec failure

    os.write(fd, input_data)
    output = b''
    while True:
        try:
            chunk = os.read(fd, 4096)
        except OSError:
            break
        if not chunk:
            break
        output += chunk
    _, status = os.waitpid(pid, 0)
    sys.stdout.buffer.write(output)
    sys.stdout.flush()
    if os.WIFEXITED(status):
        return os.WEXITSTATUS(status)
    return 1


if __name__ == '__main__':
    sys.exit(main())
