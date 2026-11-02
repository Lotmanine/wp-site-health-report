#!/bin/bash
set -euo pipefail

# Sets up the site and runs the queue beside Apache, so no extra container or cron is needed.
/usr/local/bin/shr-bootstrap.sh &

exec docker-entrypoint.sh "$@"
