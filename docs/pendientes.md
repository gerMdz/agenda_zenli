# Preguntas pendientes para Zenli

1. Días y franjas reales de Nataly y Micaela (los horarios publicados son generales).
2. Precio, duración y descripción definitiva de cada servicio; tiempos de preparación/limpieza.
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

## Servicios de la portada

- Las imágenes de los 4 servicios iniciales deben subirse desde `/admin` para reemplazar el placeholder. El archivo `masajes.webp` (sprite de imágenes) deja de ser necesario una vez que cada servicio tiene su imagen.
- Completar duración mínima, cantidad de profesionales y preparación de los servicios que se quieran agendar; revisar los tratamientos importados antes de mostrarlos.
- Confirmar la autorización de cada profesional antes de publicar su nombre o imagen; definir cómo se registra y revoca el consentimiento. Completar todas las imágenes para mostrarlas en conjunto.
- Verificar en navegador la edición y baja de profesionales y la visibilidad individual. Agregar auditoría histórica de cambios de perfil y pruebas de concurrencia de la baja.
- Revisar la reversión de la migración inicial que elimina profesionales por nombre: no es segura con nombres editables.
- Validar la migración de visibilidad de portada y sus controles en PostgreSQL y navegador antes del despliegue.
- Verificar visualmente en navegador el orden de profesionales y las imágenes de promociones.

## Integración de agenda

- La lectura y escritura de las imágenes de servicios y de la portada sigue en SQL nativo (`bytea`). Doctrine no carga columnas en forma diferida: mapearlas en `ServicioPortada` cargaría todas las imágenes en cada listado. Decidir si se usa una entidad separada para la imagen, como en presentación de profesionales.
- Varias entidades parciales comparten tabla (`profesionales`: 4; `usuarios`: 2). Se leen con `HINT_REFRESH`, pero conviene unificarlas antes de que una misma petición modifique la misma fila desde dos entidades.
- Verificar que el proxy HTTPS del VPS envíe `X-Forwarded-For` (en nginx, `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`; Caddy lo hace por defecto). Sin él, todos los intentos de acceso comparten una sola IP y 31 contraseñas erróneas en 10 minutos bloquean el ingreso de todas las cuentas.
- Verificar en el navegador, con sesión iniciada, que la política CSP no bloquee nada del panel y la agenda. Se comprobó sin errores con Chrome sin sesión en `/`, `/admin`, `/admin/agenda` y `/demo`.
- El límite de 8 intentos por cuenta cada 10 minutos permite bloquear de forma sostenida el ingreso de una cuenta conocida. Decidir si se acepta o se cambia por demoras crecientes.
- Auditar ingresos exitosos y fallidos, y la creación, desactivación y cambio de contraseña de cuentas.
- La lista de contactos de mensajes muestra a las profesionales el correo de las administradoras sin perfil profesional. Decidir si se reemplaza por un nombre.
- Definir un tiempo máximo explícito de inactividad de la sesión administrativa; hoy depende del recolector de sesiones de PHP.
- Si se agregan procesos de larga duración (Messenger, workers), reiniciar el EntityManager después de un `flush` fallido.

- Validar en navegador la preparación posterior y probar la concurrencia específica entre su edición y las reservas públicas.
- Resolver el paso entre inicios que usará la consulta pública y cómo se configurará.
- Implementar el flujo de reservas pendientes, incluida la expiración y liberación transaccional de sus ocupaciones. Mientras exista una ocupación, el cálculo la considera ocupada.
- Aplicar el bloqueo del espacio en las futuras escrituras de horarios y bloqueos, y verificar la concurrencia de esas operaciones con las confirmaciones. Las exclusiones GiST protegen las ocupaciones entre sí, pero no cubren las otras tablas.
- Implementar la capa de aplicación que autoriza las confirmaciones y aplica las políticas comerciales antes de exponer una ruta. Validar el esquema completo en la versión de PostgreSQL prevista para el despliegue.
- Incorporar al panel la recuperación de contraseñas y la desactivación de cuentas profesionales.
- Definir qué servicios requieren un equipo y cómo se organiza cada una de sus sesiones.

## Próxima mejora sugerida: mensajes privados

- Abrir «Mensajes privados» en una sección propia del menú lateral, igual que las demás secciones del panel.
- Mostrar junto a «Mensajes privados» un contador de mensajes recibidos sin leer para la cuenta que inició sesión.
- Guardar el estado de lectura por destinatario. Se propone marcar un mensaje como leído cuando la destinataria lo abre y actualizar el contador en ese momento.
- Mantener la privacidad actual: solo la cuenta remitente y la destinataria pueden ver sus mensajes.
