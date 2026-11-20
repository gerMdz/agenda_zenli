# Zenli

Proyecto Symfony para la carga de agenda inspirada en el centro Zenli. El entorno de desarrollo usa Symfony 7.4, Nginx, PHP 8.4 con PHP-FPM, Doctrine y PostgreSQL 17.

## Arranque con Docker Compose

```sh
docker compose up --build -d
curl --fail http://127.0.0.1:8080/health
docker compose exec --user www-data app php bin/console dbal:run-sql 'SELECT 1'
```

Nginx sirve el directorio `public/` y envía las rutas de Symfony a PHP-FPM por la red interna de Compose. El endpoint `/health` responde con `{"status":"ok"}`. La consulta SQL comprueba por separado la conexión de Doctrine con PostgreSQL. Si el puerto 8080 está ocupado, ejecutá el arranque con `ZENLI_HTTP_PORT=8081 docker compose up --build -d` y usá ese puerto en la URL.

El primer build requiere acceso a los registros de imágenes y a Packagist. Mientras se incorpora `composer.lock`, Composer resuelve las dependencias durante el build. Los cambios en el código PHP y la configuración se montan desde el repositorio. Para cambios de dependencias o de la imagen, repetí el arranque con `--build`.

Para detener el entorno, usá `docker compose down`. PostgreSQL conserva los datos en el volumen `postgres_data`.

## Configuración de los contenedores

- `docker/php/php.ini` define la zona horaria de PHP. El Dockerfile lo incorpora en la imagen de PHP-FPM.
- `docker/nginx/default.conf` define el servidor de Nginx. Compose lo monta en modo de solo lectura.
- `docker/database/postgres.env` define la base y las credenciales locales de PostgreSQL. Si las cambiás, actualizá también `DATABASE_URL` en `.env`. PostgreSQL usa esas variables para inicializar un volumen nuevo.

## Contexto y migraciones

Leé `AGENTS.md` y los documentos de `docs/` antes de implementar reglas de negocio. `docs/pendientes.md` contiene decisiones que Zenli debe confirmar.

Las migraciones Doctrine v2 siguen siendo un borrador. El arranque deja la base lista para conexiones, sin aplicar las migraciones. Revisalas antes de aplicarlas en una base de desarrollo y antes de cualquier uso con datos reales.

## Frontend con Vue y PrimeVue

Compose construye el frontend con Vite y copia el resultado a la imagen de Nginx. Abrí `http://127.0.0.1:8080/` para ver la portada. Usá `/health` para verificar la respuesta de Symfony.

Para editar el frontend con recarga automática, levantá Compose y ejecutá estos comandos con Node 22.12 o superior dentro de la rama 22, o con Node 24:

```sh
cd frontend
npm install
npm run dev
```

Vite atiende en `http://localhost:5173/` y reenvía `/health` y `/api` al backend de Compose. Si cambiaste el puerto del backend, pasá `ZENLI_BACKEND_URL` al iniciar Vite. Para actualizar el frontend servido por Compose, reconstruí Nginx con `docker compose up --build -d nginx`.

## Administración de la portada

En un entorno de desarrollo, revisá y aplicá las migraciones, y creá la primera cuenta administradora desde la terminal. El comando pide la contraseña de forma interactiva.

```sh
docker compose exec --user www-data app php bin/console doctrine:migrations:migrate
docker compose exec --user www-data app php bin/console zenli:admin:crear admin@ejemplo.com
```

Entrá a `/admin`. Podés actualizar el teléfono de WhatsApp en formato internacional, subir una imagen JPG, PNG o WebP de hasta 4 MB o volver a la imagen original. También podés cargar los enlaces HTTPS de Instagram y Facebook. Cada campo vacío oculta ese enlace en la portada. En el mismo panel podés crear cuentas administradoras, cambiar sus contraseñas y activar o desactivar su acceso. Los cambios de portada se publican al guardar.

En producción el panel utiliza cookies seguras y se debe abrir por HTTPS. Si el proxy HTTPS aplica un límite al cuerpo de la solicitud, configurá al menos 5 MB para `/api/admin/portada`.

## Despliegue en el VPS

Usá `compose.prod.yaml` como archivo independiente. La configuración de producción usa imágenes con el código incluido y un volumen PostgreSQL propio. Requiere Docker Compose y acceso a los registros de imágenes, Packagist y npm durante la compilación.

1. Copiá `docker/database/production.env.example` a `.env.production` con permisos de lectura y escritura solo para su dueño. Completá `APP_SECRET` y `POSTGRES_PASSWORD` con valores distintos generados con `openssl rand -hex 32`.
2. Configurá `DATABASE_URL` como `postgresql://zenli:CONTRASEÑA@database:5432/zenli?charset=utf8`, usando el valor hexadecimal de `POSTGRES_PASSWORD` en lugar de `CONTRASEÑA`.
3. Configurá el proxy HTTPS del VPS para enviar el tráfico del dominio a `http://127.0.0.1:8080`. Si ese puerto está ocupado, cambiá `ZENLI_HTTP_PORT` en `.env.production` y ajustá el destino del proxy.

El proxy HTTPS debe reenviar el encabezado `Host` original. Nginx usa ese dominio para armar la URL pública de la miniatura de la portada al compartirla por WhatsApp y otras redes.

En el VPS, ejecutá `./bin/desplegar` para publicar la rama actual. El script actualiza el código con avance rápido de Git, comprueba que PostgreSQL esté listo, respalda la base, construye las imágenes, aplica las migraciones pendientes y verifica las rutas de salud, portada y administración. Si falla una migración, se detiene antes de reemplazar los contenedores web. Requiere Bash, `flock`, Git, curl y Docker Compose.

El despliegue guarda los respaldos en `~/zenli-backups`, o en el directorio indicado por `ZENLI_BACKUP_DIR`. Después de verificar el respaldo nuevo con `pg_restore --list`, conserva ese archivo y los dos más recientes generados por el script. Podés probar su restauración en una base de prueba vacía.

Los archivos `composer.lock` y `frontend/package-lock.json` deben incorporarse al repositorio para fijar versiones. Hasta entonces, el build resuelve las dependencias con `composer install` y `npm install`. Con sus lockfiles presentes, Composer usa las versiones registradas y el frontend usa `npm ci`.
