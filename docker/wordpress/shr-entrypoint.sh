#!/bin/bash
set -euo pipefail

# Trust X-Forwarded-For only from the networks this container is attached to, where the proxy connects from.
php /opt/shr/shr-remoteip.php /etc/apache2/conf-available/remoteip.conf

# Sets up the site and runs the queue beside Apache, so no extra container or cron is needed.
/usr/local/bin/shr-bootstrap.sh &

exec docker-entrypoint.sh "$@"
