# Retomar después del reinicio

## Probar claves de acceso de Zenli en Android

Objetivo. Preparar el Docker local para registrar una clave de acceso desde Chrome en el Android de Gerardo, cerrar sesión e ingresar de nuevo con huella o PIN.

### Punto de partida

- El flujo de registro, ingreso y revocación pasó en Chrome con un autenticador virtual. La suite HTTP de seguridad también pasó. Falta la prueba con Android real.
- La migración `Version20261124000400` se aplicó en la base local durante la prueba anterior. Verificar el estado del volumen después del reinicio antes de migrar.
- Antes del reinicio, `git status` devolvió «La estructura necesita una limpieza» en varios archivos. Revisar que el volumen y el repositorio se puedan leer normalmente antes de compilar. Comprobar que `frontend/src/clavesAcceso.js` esté presente.

### Estado de verificación local
- **Verificación automatizada E2E completada**: Se ejecutó la prueba de extremo a extremo contra el Docker local (`zenli-vista-local`, puerto 18082) mediante Chrome en modo headless con un Autenticador Virtual WebAuthn (`tests/webauthn-e2e/test-webauthn.js`).
- Se verificó exitosamente:
  1. Inicio de sesión con contraseña (Argon2id).
  2. Apertura del diálogo de **Claves de acceso**, confirmación de contraseña y ceremonia de registro de credencial WebAuthn en PostgreSQL (`claves_acceso`).
  3. Cierre de sesión y navegación a la pantalla de ingreso.
  4. Ingreso mediante **Passkey** («Ingresar con clave de acceso») resolviendo el desafío WebAuthn con el autenticador virtual y abriendo la agenda `/admin/agenda`.
  5. Revocación de la clave de acceso con auditoría en `eventos_acceso` (`PASSKEY_CREADA` y `PASSKEY_REVOCADA`).

