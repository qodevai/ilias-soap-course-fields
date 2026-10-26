#!/usr/bin/env bash
set -euo pipefail

CONFIG=/opt/ilias/setup-config.json
MARKER=/var/iliasdata/.installed

wait_for_db() {
  echo "[entrypoint] waiting for the database …"
  for _ in $(seq 1 60); do
    if mariadb -h db -u ilias -pilias -e 'select 1' >/dev/null 2>&1; then
      echo "[entrypoint] database is up"
      return 0
    fi
    sleep 2
  done
  echo "[entrypoint] database never became reachable" >&2
  exit 1
}

# The marker lives on a volume, the generated ilias.ini.php does not. A recreated container
# would skip the setup and then run without a configuration.
if [ -f "$MARKER" ] && [ ! -f /var/www/html/ilias.ini.php ]; then
  echo "[entrypoint] ILIAS was installed by an earlier container, but this container has no configuration." >&2
  echo "[entrypoint] Reset the stack: docker compose -f docker/compose.yml down -v" >&2
  exit 1
fi

if [ ! -f "$MARKER" ]; then
  wait_for_db
  echo "[entrypoint] installing ILIAS — this takes a couple of minutes on first boot"
  # setup writes into both the data dir and the source tree
  chown -R www-data:www-data /var/iliasdata /var/www/html
  su -s /bin/bash www-data -c "php /var/www/html/cli/setup.php install '$CONFIG' --yes"
  touch "$MARKER"
  echo "[entrypoint] ILIAS installed"
else
  wait_for_db
  echo "[entrypoint] already installed, skipping setup"
fi

exec "$@"
