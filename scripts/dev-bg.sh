#!/usr/bin/env bash
# Start the Next.js dev server detached from the current terminal.
# Survives terminal job control (Ctrl+Z / suspend) so the forwarded
# public URL does not start returning HTTP 504.
set -euo pipefail

cd "$(dirname "$0")/.."
LOG="${DEV_LOG:-/tmp/next-dev.log}"

if ss -ltnp 2>/dev/null | grep -q ':3000'; then
  echo "Port 3000 is already in use. Existing server:"
  ss -ltnp 2>/dev/null | grep ':3000' || true
else
  setsid nohup npm run dev > "$LOG" 2>&1 < /dev/null &
  disown || true

  for _ in $(seq 1 60); do
    if curl -s -o /dev/null --max-time 2 http://localhost:3000/login; then
      echo "Dev server ready on http://localhost:3000 (log: $LOG)"
      break
    fi
    sleep 1
  done

  if ! curl -s -o /dev/null --max-time 2 http://localhost:3000/login; then
    echo "Dev server did not become ready in time. See $LOG" >&2
    tail -30 "$LOG" >&2 || true
    exit 1
  fi
fi

# Re-register the GitHub Codespaces tunnel for this port.
# After a dev-server restart the tunnel edge keeps dialing the dead old
# process and answers HTTP 502 instead of reaching the new one.
# Cycling visibility forces GitHub to re-resolve the backing process.
# This MUST run on every invocation (including when the server was already
# running), otherwise a restart leaves the public URL returning 502.
CS="${CODESPACE_NAME:-laughing-halibut-pjwwwq49jgg7f6rjx}"
PORT="${PORT:-3000}"
if command -v gh >/dev/null 2>&1 && gh codespace ports visibility "$PORT:private" -c "$CS" >/dev/null 2>&1; then
    gh codespace ports visibility "$PORT:public" -c "$CS" >/dev/null 2>&1 || true
    echo "Tunnel re-registered for port $PORT ($CS)."
else
    echo "Could not re-register tunnel (gh codespace unavailable?); skipping." >&2
fi
