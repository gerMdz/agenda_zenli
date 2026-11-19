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
12. Confirmar dirección, identidad visual y contenidos públicos vigentes.

No transformar preguntas pendientes en reglas asumidas por defecto sin aprobación del centro.

## Integración de agenda

- Resolver el paso entre inicios que usará la consulta pública y cómo se configurará.
- Integrar el cálculo con la lectura de horarios, bloqueos y ocupaciones desde PostgreSQL. Resolver la vigencia de reservas pendientes al construir esa consulta.
- Validar el esquema y definir una transacción que vuelva a comprobar disponibilidad al confirmar. Las exclusiones GiST del borrador protegen ocupaciones entre sí. Como los bloqueos y horarios viven en otras tablas, sus escrituras deben coordinarse con las reservas mediante el mismo protocolo de bloqueo y verificarse con pruebas de concurrencia.
