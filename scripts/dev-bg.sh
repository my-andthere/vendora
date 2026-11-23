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
  exit 0
fi

setsid nohup npm run dev > "$LOG" 2>&1 < /dev/null &
disown || true

for _ in $(seq 1 60); do
  if curl -s -o /dev/null --max-time 2 http://localhost:3000/login; then
    echo "Dev server ready on http://localhost:3000 (log: $LOG)"
    exit 0
  fi
  sleep 1
done

echo "Dev server did not become ready in time. See $LOG" >&2
tail -30 "$LOG" >&2 || true
exit 1
