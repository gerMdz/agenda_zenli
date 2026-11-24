# Preguntas pendientes para Zenli

1. Días y franjas reales de Nataly y Micaela (los horarios publicados son generales).
2. Precio, duración y descripción definitiva de cada servicio; tiempos de preparación/limpieza. El centro ya ofrece servicios exprés de 20 minutos, que pueden cargarse con esa duración en la agenda interna.
3. En masaje en pareja, ¿una o dos profesionales atienden las sesiones consecutivas? ¿Cómo se asignan? ¿Son 30+30 minutos sin pausa?
4. Equipamiento físico real y capacidad efectiva del único espacio.
5. Política concreta para confirmación automática vs manual y reglas por tratamiento.
6. Vencimiento inicial de pendientes: se sugirieron 2 h, pero la migración v2 tiene 24 h; definir valor antes de usarlo.
7. Plazos reales para cancelación, reprogramación, anticipación y horizonte de reservas.
8. Vigencia, compatibilidad y condiciones de promociones.
9. Quién puede crear, aprobar y levantar bloqueos de clientes; categorías y política de conservación de incidencias.
10. Método de verificación del teléfono y comunicaciones por correo/WhatsApp.
11. Política de pagos/señas: importes exigidos, medios, devoluciones y demás condiciones no están definidos; la agenda solo registra estado e importe.
12. Confirmar identidad visual y contenidos públicos vigentes.

No transformar preguntas pendientes en reglas asumidas por defecto sin aprobación del centro.

## Retomar la agenda privada

### Cierre operativo de la agenda interna

1. Verificar en PostgreSQL el alta, la edición, la cancelación, la preparación, el alcance por profesional y la concurrencia. La suite de agenda pasó en PostgreSQL 17 temporal, incluido el caso de dos altas internas simultáneas sobre el mismo espacio.
2. Repetir el ciclo desde `/admin/agenda` con datos de prueba y cuentas de administración y profesional. Confirmar que al mover un turno se libera su horario anterior, que un destino ocupado conserva el turno original y que al cancelar se libera también su preparación. Revisar el flujo en pantalla chica.
3. Corregir los problemas observados y repetir los casos afectados. Para el uso cotidiano, cargar las franjas confirmadas de cada profesional y sus excepciones, y verificar la disponibilidad con ambas cuentas.

### Punto de partida desde otra PC

1. Actualizá `main` desde `origin/main` con `git pull --ff-only`. Si todavía no tenés el repositorio en esa PC, cloná `gerMdz/agenda_zenli`. El commit `ac3e69e` incluye los feriados, el cálculo diario de disponibilidad y los cambios visuales anteriores.
2. Levantá el entorno de desarrollo y verificá que responda. Revisá y aplicá las migraciones a esa base local, incluida `Version20261122000100` de feriados.

   ```sh
   docker compose up --build -d
   curl --fail http://127.0.0.1:8080/health
   docker compose exec --user www-data app php bin/console doctrine:migrations:migrate
   ```

3. Si la base local es nueva, creá una cuenta con `docker compose exec --user www-data app php bin/console zenli:admin:crear admin@ejemplo.com`. Entrá a `http://127.0.0.1:8080/admin`. Cada PC tiene su propio volumen de PostgreSQL. Git sincroniza el código, y los datos locales se recuperan por separado si se necesitan.
4. En la agenda, marcá un feriado de prueba y comprobá que aparezca el loto en la vista general y en la de una profesional. Cargá un turno válido ese día, consultá la disponibilidad de esa fecha y quitá la marca. Probá también una fecha sin horario especial para una profesional en modo manual.

La compilación del frontend y las pruebas de agenda y permisos pasaron en PostgreSQL 17 temporal. Queda pendiente la comprobación anterior en el navegador del nuevo entorno. Para el uso interno con datos reales, el próximo paso es cargar los horarios confirmados de cada profesional.

- La agenda abre después de iniciar sesión. «Mis horarios» y «Horarios no disponibles» son vistas de consulta del perfil propio. Las franjas semanales, las fechas puntuales y las indisponibilidades se editan desde **Administración → Horarios**.
- Verificamos la compilación del frontend y las dos secciones en Chrome contra el Docker local. Con el perfil de prueba de Micaela vimos el 25/11/2026 como «Todo el día» y el 27/11/2026 de 13:00 a 15:00. Son datos ficticios exclusivos del entorno local.
- En Chrome a 390 × 844 px verificamos el fondo a pantalla completa y el menú plegado al entrar. Las acciones de agenda y la grilla quedan antes del resumen y el horario habitual.
- Para habilitar el uso interno con datos reales, confirmar y cargar las franjas de cada profesional, revisar sus excepciones y comprobar la agenda con una cuenta profesional y otra administradora. Mantener `/demo` y las reservas públicas desactivadas. «Solicitudes pendientes» sigue como panel informativo.
- Verificar «Descargar app» en celulares reales con HTTPS. En Android, comprobar el diálogo de Chrome. En iPhone, seguir la guía desde Safari. En ambos, abrir el icono del loto y comprobar que lleve a la agenda después de iniciar sesión.
- Probar en Android y iPhone la consulta en modo avión tras la primera descarga, la caducidad de la copia y la sincronización al volver a abrir la app con conexión. Las ediciones, cancelaciones y la consulta de disponibilidad en tiempo real requieren conexión. Para habilitarlo en un despliegue, aplicar la migración de identificadores de altas sincronizadas.
- Para desplegar los estados de asistencia, aplicar la migración `Version20261124000200`. La búsqueda por nombre y el registro de asistencia se usan con conexión.
- Los commits, el push y el despliegue los realiza Gerardo.

## Servicios de la portada

- Las imágenes de los 4 servicios iniciales deben subirse desde Sitio público → Servicios en portada (`/admin#servicios-portada`) para reemplazar el placeholder. El archivo `masajes.webp` (sprite de imágenes) deja de ser necesario una vez que cada servicio tiene su imagen.
- Completar la duración informativa y la cantidad de profesionales de cada servicio cuando el centro las confirme; revisar los tratamientos importados antes de mostrarlos.
- Revisar la configuración de preparación interna con el centro. Sin configuración se usan cero minutos y la agenda informa que falta definirla.
- Para cargar datos ficticios en desarrollo, ejecutar `docker compose exec --user www-data app php bin/console zenli:agenda:datos-prueba`. El turno de prueba se crea para el 06/07/2099.
- Confirmar la autorización de cada profesional antes de publicar su nombre o imagen; definir cómo se registra y revoca el consentimiento. Completar todas las imágenes para mostrarlas en conjunto.
- Verificar en navegador la edición y baja de profesionales y la visibilidad individual. Agregar auditoría histórica de cambios de perfil y pruebas de concurrencia de la baja.
- Revisar la reversión de la migración inicial que elimina profesionales por nombre: no es segura con nombres editables.
- Validar la migración de visibilidad de portada y sus controles en PostgreSQL y navegador antes del despliegue.
- Verificar visualmente en navegador el orden de profesionales y las imágenes de promociones.

## Integración de agenda

- La lectura y escritura de las imágenes de servicios y de la portada sigue en SQL nativo (`bytea`). Doctrine no carga columnas en forma diferida: mapearlas en `ServicioPortada` cargaría todas las imágenes en cada listado. Decidir si se usa una entidad separada para la imagen, como en presentación de profesionales.
- Varias entidades parciales comparten tabla (`profesionales`: 4; `usuarios`: 2). Se leen con `HINT_REFRESH`, pero conviene unificarlas antes de que una misma petición modifique la misma fila desde dos entidades.
- Tras desplegar, confirmar que `eventos_acceso` registre la IP pública real y no una dirección 172.x de Docker (`SELECT tipo, host(ip) FROM eventos_acceso ORDER BY id DESC LIMIT 5`).
- Definir cuánto tiempo se conservan `eventos_acceso` (contiene direcciones IP, que son datos personales) y decidir si conviene mostrar los accesos en el panel.
- La demora por cuenta todavía permite que alguien que conozca un correo mantenga demorado su ingreso con un intento por período. El desbloqueo desde el panel lo alivia, pero no lo impide.
- Las sesiones se guardan en archivos dentro del contenedor: cada despliegue cierra todas las sesiones abiertas. Evaluar guardarlas en PostgreSQL si molesta.
- Validar en navegador las vistas nuevas de «Auditoría» (`/admin#auditoria`), el historial de turnos en `/admin/agenda` y las etiquetas de autoría en cada sección del panel antes del despliegue.
- La lista de contactos de mensajes muestra a las profesionales el correo de las administradoras sin perfil profesional. Decidir si se reemplaza por un nombre.
- Si se agregan procesos de larga duración (Messenger, workers), reiniciar el EntityManager después de un `flush` fallido.

- Validar en navegador la preparación posterior y probar la concurrencia específica entre su edición y las reservas públicas.
- Resolver el paso entre inicios que usará la consulta pública y cómo se configurará.
- Implementar el flujo de reservas pendientes, incluida la expiración y liberación transaccional de sus ocupaciones. Mientras exista una ocupación, el cálculo la considera ocupada.
- Aplicar el bloqueo del espacio en las futuras escrituras de horarios y bloqueos, y verificar la concurrencia de esas operaciones con las confirmaciones. Las exclusiones GiST protegen las ocupaciones entre sí, pero no cubren las otras tablas.
- Implementar la capa de aplicación que autoriza las confirmaciones y aplica las políticas comerciales antes de exponer una ruta. Validar el esquema completo en la versión de PostgreSQL prevista para el despliegue.
- Incorporar al panel la recuperación de contraseñas y la desactivación de cuentas profesionales.
- Definir qué servicios requieren un equipo y cómo se organiza cada una de sus sesiones.

## Próxima mejora sugerida: mensajes privados

- La bandeja ya tiene su propia sección en el menú lateral. La próxima mejora propuesta es el estado de lectura.
- Mostrar junto a «Mensajes privados» un contador de mensajes recibidos sin leer para la cuenta que inició sesión.
- Guardar el estado de lectura por destinatario. Se propone marcar un mensaje como leído cuando la destinataria lo abre y actualizar el contador en ese momento.
- Mantener la privacidad actual: solo la cuenta remitente y la destinataria pueden ver sus mensajes.
