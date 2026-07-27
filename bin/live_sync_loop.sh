#!/usr/bin/env bash
# Keep 1m bars fresh on weekdays (London + US). Run in tmux for Monday live.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
while true; do
  /usr/bin/php bin/sync_intraday.php --force >> storage/sync.log 2>&1 || true
  sleep 120
done
