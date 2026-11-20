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
11. Política de pagos/señas, si existiera (no definida).
12. Confirmar identidad visual y contenidos públicos vigentes.

No transformar preguntas pendientes en reglas asumidas por defecto sin aprobación del centro.

## Servicios de la portada

- Las imágenes de los 4 servicios iniciales deben subirse desde `/admin` para reemplazar el placeholder. El archivo `masajes.webp` (sprite de imágenes) deja de ser necesario una vez que cada servicio tiene su imagen.

## Integración de agenda

- Resolver el paso entre inicios que usará la consulta pública y cómo se configurará.
- Implementar el flujo de reservas pendientes, incluida la expiración y liberación transaccional de sus ocupaciones. Mientras exista una ocupación, el cálculo la considera ocupada.
- Aplicar el bloqueo del espacio en las futuras escrituras de horarios y bloqueos, y verificar la concurrencia de esas operaciones con las confirmaciones. Las exclusiones GiST protegen las ocupaciones entre sí, pero no cubren las otras tablas.
- Implementar la capa de aplicación que autoriza las confirmaciones y aplica las políticas comerciales antes de exponer una ruta. Validar el esquema completo en la versión de PostgreSQL prevista para el despliegue.
- Incorporar al panel la recuperación de contraseñas y la desactivación de cuentas profesionales.
- Incorporar al panel la edición de franjas profesionales. Mientras una profesional tenga franjas registradas, los turnos internos deben ajustarse a ellas. Se permite la carga manual si todavía no tiene franjas.
- Definir qué servicios requieren un equipo y cómo se organiza cada una de sus sesiones.
