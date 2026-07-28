# Install slimTDS with an AI agent

Give a coding agent shell access to a fresh Linux server, paste the block below, and answer
its questions once. It installs Docker, builds the stack, initializes the database, creates
the admin account, and runs a six-check smoke test before reporting back.

Works with any agent that can run shell commands — Claude Code, Codex/GPT, Qwen, Kimi.
The full runbook it follows is [`AI-INSTALL.md`](AI-INSTALL.md).

---

## English

```
You have SSH/root access to a fresh Linux server. Install slimTDS on it.

  git clone https://github.com/izzipizzy/slimtds.git slimtds && cd slimtds

Read docs/AI-INSTALL.md and follow it exactly, top to bottom.
Ask me every question you need in ONE message up front (§1), then run to
completion without stopping. Never print secrets. Stop and tell me if a §2
preflight check fails — do not work around it. Finish with the §9 report.
```

## Русский

```
У тебя SSH/root-доступ к чистому Linux-серверу. Установи на него slimTDS.

  git clone https://github.com/izzipizzy/slimtds.git slimtds && cd slimtds

Прочитай docs/AI-INSTALL.md и выполни его точно, сверху вниз.
Задай все вопросы ОДНИМ сообщением в начале (§1), дальше работай до конца
без остановок. Секреты не печатай. Если падает проверка из §2 — остановись и
скажи мне, не обходи её. Заверши отчётом из §9.
```

---

## Before you paste

Have ready:

- **A server** — 2 GB RAM and 20 GB disk minimum, ports 80 and 443 free.
- **A domain** with an A record already pointing at that server (needed for TLS; skip only
  if you are installing behind Cloudflare).
- **Optional:** a MaxMind GeoLite2 license key (without it geo targeting does nothing) and a
  Telegram bot token + chat ID (without them digests and alerts are off).

The agent generates the admin password and reports it once, at the end.
