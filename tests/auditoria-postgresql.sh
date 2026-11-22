#!/usr/bin/env bash
# Prueba de auditoría de datos e historial de turnos a través del kernel real de Symfony con PostgreSQL.
set -euo pipefail

raiz="$(cd "$(dirname "$0")/.." && pwd)"
directorio="$(mktemp -d /tmp/zenli-agenda-pg.XXXXXXXX)"
postgres_bin="${ZENLI_POSTGRES_BIN:-$(pg_config --bindir)}"
limpiar() {
    if [[ -f "$directorio/datos/postmaster.pid" ]]; then
        "$postgres_bin/pg_ctl" -D "$directorio/datos" -m immediate -w stop >/dev/null
    fi
    rm -rf -- "$directorio"
}
trap limpiar EXIT

"$postgres_bin/initdb" -D "$directorio/datos" -U zenli_pruebas -A trust --no-locale --encoding=UTF8 > "$directorio/initdb.log"
"$postgres_bin/pg_ctl" -D "$directorio/datos" -l "$directorio/postgresql.log" -o "-c listen_addresses='' -c unix_socket_directories=$directorio -p 5432" -w start >/dev/null

mkdir -p "$directorio/cache" "$directorio/log" "$directorio/sesiones"
export APP_ENV=prod APP_DEBUG=0 APP_SECRET=secreto-de-prueba-local
export APP_CACHE_DIR="$directorio/cache" APP_LOG_DIR="$directorio/log"
export DATABASE_URL="postgresql://zenli_pruebas@localhost/postgres?host=$directorio&serverVersion=17&charset=utf8"
export ZENLI_SESIONES="$directorio/sesiones"

php "$raiz/bin/console" doctrine:migrations:migrate --no-interaction --quiet
php -d session.save_path="$ZENLI_SESIONES" -d session.gc_probability=0 "$raiz/tests/auditoria-postgresql.php"
