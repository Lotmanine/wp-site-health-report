#!/bin/bash
set -uo pipefail

WEBROOT=/var/www/html
wpc() {
	setpriv --reuid=www-data --regid=www-data --init-groups \
		env HOME=/tmp php -d opcache.enable_cli=0 /usr/local/bin/wp --path="$WEBROOT" "$@"
}

until [ -f "$WEBROOT/wp-config.php" ]; do sleep 1; done
until wpc db check --quiet >/dev/null 2>&1 || php -r '
	$c = @mysqli_connect(getenv("WORDPRESS_DB_HOST"), getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME"));
	exit($c ? 0 : 1);'; do sleep 2; done

rm -rf "$WEBROOT/wp-content/plugins/oldev-health-report" "$WEBROOT/wp-content/themes/oldev-health"
cp -r /opt/shr/plugin "$WEBROOT/wp-content/plugins/oldev-health-report"
cp -r /opt/shr/theme "$WEBROOT/wp-content/themes/oldev-health"
chown -R www-data:www-data "$WEBROOT/wp-content/plugins/oldev-health-report" "$WEBROOT/wp-content/themes/oldev-health"

if ! wpc core is-installed; then
	wpc core install \
		--url="$WP_HOME" \
		--title="Website Health Report" \
		--admin_user="$WP_ADMIN_USER" \
		--admin_password="$WP_ADMIN_PASSWORD" \
		--admin_email="$WP_ADMIN_EMAIL" \
		--skip-email
	wpc plugin delete akismet hello
	wpc post delete 1 2 --force
	wpc option update blogdescription "Passive website health checks"
	wpc option update timezone_string UTC
	wpc rewrite structure '/%postname%/' --hard
fi

wpc plugin is-installed fluent-crm || wpc plugin install fluent-crm --version="${FLUENTCRM_VERSION:-3.3.1}"
wpc plugin activate fluent-crm
wpc theme activate oldev-health
wpc plugin activate oldev-health-report
wpc rewrite flush --hard
touch /tmp/shr-ready

while true; do
	wpc action-scheduler run --batch-size=10 --batches=1 --force >/dev/null 2>&1 || true
	sleep "${SHR_WORKER_INTERVAL:-5}"
done
