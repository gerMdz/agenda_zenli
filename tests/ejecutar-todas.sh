#!/usr/bin/env bash
set -euo pipefail

raiz="$(cd "$(dirname "$0")/.." && pwd)"
cd "$raiz"

echo "=========================================="
echo " Ejecución de Pruebas Locales Automatizadas"
echo "=========================================="

# 1. Asegurar vendor de Composer si no está en el host
if [[ ! -f "vendor/autoload.php" ]]; then
    echo "--> vendor/ no encontrado en el host. Intentando sincronizar..."
    if docker compose ps --services --filter "status=running" 2>/dev/null | grep -q "app"; then
        echo "--> Copiando vendor desde el contenedor docker 'app'..."
        docker compose cp app:/var/www/html/vendor ./vendor
    elif command -v composer >/dev/null 2>&1; then
        echo "--> Ejecutando composer install..."
        composer install --prefer-dist --no-interaction --quiet
    else
        echo "Error: no se encontró vendor/autoload.php ni composer local ni contenedor app corriendo." >&2
        exit 1
    fi
fi

# 2. Asegurar node_modules para el frontend si el host tiene Node >= 20
node_version=$(node -v 2>/dev/null || echo "v0")
node_major=$(echo "$node_version" | sed -E 's/^v([0-9]+).*/\1/')
if (( node_major >= 20 )) && [[ ! -d "frontend/node_modules" ]]; then
    echo "--> Instalando dependencias de frontend en el host..."
    (cd frontend && npm ci --no-audit --no-fund)
fi

echo ""
echo "=== 1/9: Disponibilidad y reglas horarias (en memoria) ==="
php tests/agenda-availability.php
echo "✓ Disponibilidad OK"

echo ""
echo "=== 2/9: Concurrencia e integridad transaccional (PostgreSQL) ==="
bash tests/agenda-postgresql.sh
echo "✓ Concurrencia y PostgreSQL OK"

echo ""
echo "=== 3/9: Seguridad HTTP, Argon2id, CSRF y Sesiones ==="
bash tests/seguridad-http.sh
echo "✓ Seguridad HTTP OK"

echo ""
echo "=== 4/9: Auditoría e historial de turnos ==="
bash tests/auditoria-postgresql.sh
echo "✓ Auditoría OK"

echo ""
echo "=== 5/9: Perfiles profesionales ==="
bash tests/perfiles-profesionales-postgresql.sh
echo "✓ Perfiles profesionales OK"

echo ""
echo "=== 6/9: Presentación de profesionales ==="
bash tests/presentacion-profesionales-postgresql.sh
echo "✓ Presentación de profesionales OK"

echo ""
echo "=== 7/9: Servicios en portada ==="
bash tests/servicios-portada-postgresql.sh
echo "✓ Servicios en portada OK"

echo ""
echo "=== 8/9: Promociones y visibilidad de portada ==="
bash tests/promociones-portada-postgresql.sh
bash tests/visibilidad-portada-postgresql.sh
echo "✓ Portada y promociones OK"

echo ""
echo "=== 9/9: Compilación del frontend (Vite / Vue 3) ==="
node_version=$(node -v 2>/dev/null || echo "v0")
node_major=$(echo "$node_version" | sed -E 's/^v([0-9]+).*/\1/')

if (( node_major >= 20 )); then
    (cd frontend && npm run build)
else
    echo "--> Node local es $node_version (< v20). Compilando con contenedor Node 22 (igual que en producción)..."
    docker build --target frontend -t zenli-frontend-test -f Dockerfile .
fi
echo "✓ Build de frontend OK"

echo ""
echo "========================================================"
echo " ¡TODAS LAS PRUEBAS LOCALES PASARON SATISFACTORIAMENTE! "
echo "========================================================"
