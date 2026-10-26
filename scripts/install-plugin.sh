#!/usr/bin/env bash
# Copy the plugin into the scratch ILIAS, rebuild the component artifacts, activate it.
set -euo pipefail
cd "$(dirname "$0")/.."
C=ilias-soap-spike-ilias-1
DEST=/var/www/html/public/Customizing/global/plugins/Services/WebServices/SoapHook/CourseUserFields

docker exec "$C" rm -rf "$DEST"
docker exec "$C" mkdir -p "$(dirname "$DEST")"
docker cp plugin "$C:$DEST"
docker exec "$C" chown -R www-data:www-data "$DEST"
# build first: the plugin list is a build artifact
docker exec -u www-data "$C" php /var/www/html/cli/setup.php build --yes
docker exec -u www-data "$C" php /var/www/html/cli/setup.php update /opt/ilias/setup-config.json --yes --no-interaction
# the plugin class is found via the composer classmap, so regenerate it (the image has no composer)
if ! docker exec "$C" test -x /usr/local/bin/composer; then
  docker run --rm composer:2 cat /usr/bin/composer | docker exec -i "$C" sh -c 'cat > /usr/local/bin/composer && chmod +x /usr/local/bin/composer'
fi
docker exec -u www-data "$C" sh -c 'cd /var/www/html && composer du --no-dev'
# ILIAS 10 has no plugin CLI: the Administration UI writes this row, so do the same.
docker exec ilias-soap-spike-db-1 mariadb -uilias -pilias ilias -e \
  "REPLACE INTO il_plugin (plugin_id, last_update_version, active, db_version) VALUES ('cufsoap', '0.1.0', 1, 0)"
