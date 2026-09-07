#!/usr/bin/env sh
# Supervised runner for the standalone LLM autoplay loop.
# Restarts autoplay.php if it ever exits (crash, network drop, kill); Ctrl+C stops it.
#
#   ./run-autoplay.sh            # default agent from var/state.json
#   ./run-autoplay.sh 142285     # a specific agent id (passed straight through)

cd "$(dirname "$0")" || exit 1

trap 'echo "[run-autoplay] stopping"; exit 0' INT TERM

while true; do
    echo "[run-autoplay] starting $(date)"
    php autoplay.php "$@"
    echo "[run-autoplay] autoplay.php exited ($?) - restarting in 3s, Ctrl+C to stop"
    sleep 3
done
