#!/usr/bin/env bash
# Stop any running Next.js dev server for this project.
set -euo pipefail

cd "$(dirname "$0")/.."

pids="$(pgrep -f 'next dev' || true) $(pgrep -f 'next-server' || true)"
if [[ -z "${pids// /}" ]]; then
  echo "No dev server running."
  exit 0
fi

echo "Stopping dev server(s): $(echo "$pids" | tr '\n' ' ')"
# shellcheck disable=SC2086
kill $pids 2>/dev/null || true
sleep 2
# shellcheck disable=SC2086
kill -9 $pids 2>/dev/null || true
echo "Stopped."
