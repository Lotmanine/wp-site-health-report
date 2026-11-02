#!/bin/bash
set -euo pipefail

if [ "${1:-}" = "apache2-foreground" ]; then
	if [ ! -f /data/scanner.sqlite ]; then
		touch /data/scanner.sqlite
		chown www-data:www-data /data/scanner.sqlite
	fi
	setpriv --reuid=www-data --regid=www-data --init-groups php artisan migrate --force
fi

exec "$@"
