#!/usr/bin/env bash
set -euo pipefail

directorio="$(mktemp -d /tmp/zenli-agenda-pg.XXXXXXXX)"
postgres_bin="${ZENLI_POSTGRES_BIN:-$(pg_config --bindir)}"
limpiar() {
    if [[ -f "$directorio/datos/postmaster.pid" ]]; then
        "$postgres_bin/pg_ctl" -D "$directorio/datos" -m immediate -w stop >/dev/null
    fi
    rm -rf -- "$directorio"
}
trap limpiar EXIT

"$postgres_bin/initdb" -D "$directorio/datos" -U zenli_pruebas -A trust --no-locale > "$directorio/initdb.log"
if ! "$postgres_bin/pg_ctl" -D "$directorio/datos" -l "$directorio/postgresql.log" \
    -o "-c listen_addresses='' -c unix_socket_directories=$directorio -p 5432" -w start >/dev/null; then
    cat "$directorio/postgresql.log" >&2
    exit 1
fi

php "$(dirname "$0")/agenda-postgresql.php" "$directorio"
