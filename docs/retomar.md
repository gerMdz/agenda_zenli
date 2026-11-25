# Retomar después del reinicio

## Probar claves de acceso de Zenli en Android

Objetivo. Preparar el Docker local para registrar una clave de acceso desde Chrome en el Android de Gerardo, cerrar sesión e ingresar de nuevo con huella o PIN.

### Punto de partida

- El flujo de registro, ingreso y revocación pasó en Chrome con un autenticador virtual. La suite HTTP de seguridad también pasó. Falta la prueba con Android real.
- La migración `Version20261124000400` se aplicó en la base local durante la prueba anterior. Verificar el estado del volumen después del reinicio antes de migrar.
- Antes del reinicio, `git status` devolvió «La estructura necesita una limpieza» en varios archivos. Revisar que el volumen y el repositorio se puedan leer normalmente antes de compilar. Comprobar que `frontend/src/clavesAcceso.js` esté presente.

### Primeros pasos al retomar

1. Revisar el Docker local y su volumen de pruebas. El proyecto usado fue `zenli-vista-local`, con el puerto 18082 publicado solo en loopback.
2. Preparar un origen HTTPS de desarrollo accesible desde el teléfono en la red local, con certificado de confianza para Android. Configurar el backend local para aceptar exactamente ese origen mediante `ZENLI_ORIGEN_CLAVES_ACCESO`. Verificar la conexión desde Chrome del teléfono antes de registrar la clave. Mantener el entorno acotado a la red local.
3. Levantar el Docker local con datos y una cuenta ficticia. Verificar el estado de las migraciones y aplicar las pendientes solo sobre esa base.
4. En el teléfono, ingresar con la contraseña ficticia. En la agenda, abrir el menú de cuenta y elegir **Claves de acceso → Registrar clave de acceso**. Confirmar la contraseña y completar el desbloqueo del dispositivo.
5. Cerrar sesión y elegir **Ingresar con clave de acceso**. Comprobar que Android pida la huella o el PIN y que la agenda abra con el rol correcto. Quitar la clave de prueba, verificar que el ingreso con ella falle y comprobar que la contraseña siga funcionando.

La sesión mantiene el vencimiento de una hora de inactividad. Usar únicamente el Docker local y datos ficticios. Esta preparación quedó pendiente para después del reinicio.
