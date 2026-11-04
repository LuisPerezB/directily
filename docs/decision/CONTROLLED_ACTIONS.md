# Acciones controladas: lo que Predictily hace hoy y lo diseñado para fases futuras

Fecha: 1-nov-2026 · Rama `v25/w1-cierre-y-w2` · V2.5 · W5-B · Fuentes: ADR-V25-13 («Predictily no escribe en el ERP en P0/P1»), §11 ¶4 de la especificación V2.5 y `V25-DEC-029`, `-031`, `-033` y `-034`. El workflow está en [`DECISION_ENGINE.md`](DECISION_ENGINE.md).

**Leyenda de estado:** **IMPLEMENTADO / VERIFICADO** (código + prueba) · **DISEÑADO / NO IMPLEMENTADO** · **BLOQUEADO** · **NO APLICA**.

## 1. Hoy (P0/P1): Predictily recomienda y audita; la persona actúa fuera

**IMPLEMENTADO / VERIFICADO.** Ninguna recomendación ejecuta nada por sí misma:

- **No hay escritura en el ERP, POS ni CRM.** Los conectores del Core de Datos son de solo lectura (`SqlQueryGuard` admite una única sentencia `SELECT`); el Decision Engine no tiene ninguna dependencia de conectores.
- **No se envían mensajes automáticos** (WhatsApp, correo, notificaciones): ni al generar ni al recorrer el workflow. Un contacto es una tarea para una persona, que contacta al cliente por su canal habitual.
- **«Ejecutar» es un registro.** `in_progress` y `executed` significan que una persona declara que está haciendo / hizo la acción **fuera** de Predictily (emitir el pedido en el ERP, llamar al cliente). Cambian el estado y dejan su evento; nada más ocurre.
- **No se compromete dinero.** Una reposición recomienda cantidad, fecha y costo; Predictily no emite pedidos ni reserva fondos (el plan de abastecimiento ya lo declara: «sugerencias»).

Verificado por `DecisionWorkflowApiTest::test_generating_and_walking_the_workflow_never_sends_messages_calls_the_erp_or_queues_work`: con `Http`, `Mail`, `Notification` y `Queue` simulados, generar recomendaciones y recorrer `reviewed → approved →
in_progress → executed` no envía ninguna petición HTTP, correo ni notificación y no encola ningún trabajo.

## 2. Controles que SÍ existen hoy sobre la acción humana

| Control | Estado | Detalle y prueba |
|---|---|---|
| Aprobación explícita de las acciones sensibles | IMPLEMENTADO / VERIFICADO | Reposición, inventario-demanda, presupuesto, escenarios, lo «sobre presupuesto» y lo que la empresa configure (por tipo o por umbral de costo) no pasan de `reviewed` a `in_progress` sin `approved` (409 `decision_approval_required`) |
| Segregación de funciones | IMPLEMENTADO / VERIFICADO | Quien propone o debe ejecutar no aprueba su propia acción; quien aprobó no la ejecuta (403 `decision_segregation_of_duties`) |
| Habilidad por rol | IMPLEMENTADO / VERIFICADO | `decisions.view`, `decisions.manage` (asignar, revisar, descartar, ejecutar, cerrar) y `decisions.approve` (aprobar, rechazar); los roles comerciales gerente/vendedor/servicio (W6-A) se suman al rol de acceso y acotan lo que cada persona ve y toca; aprobar o rechazar exige además segundo factor fresco con MFA activo |
| Idempotencia | IMPLEMENTADO / VERIFICADO | Una transición repetida con la misma `idempotency_key` no repite efectos |
| Bloqueo optimista | IMPLEMENTADO / VERIFICADO | Dos acciones concurrentes sobre la misma recomendación no se pisan: `expected_version` es obligatorio en toda transición humana (`decision_version_conflict`); los comentarios del sistema no cuentan para la versión |
| Auditoría | IMPLEMENTADO / VERIFICADO | Cada transición en `decision_events` y `audit_logs`; el sistema deja eventos propios (vencimiento, reemplazo) |
| Vencimiento | IMPLEMENTADO / VERIFICADO | Una recomendación sin acción en curso caduca; no se ejecuta algo que dejó de describir la realidad |
| Grupo de control sin contacto | IMPLEMENTADO / VERIFICADO | Lo retenido para medir no se asigna ni se ejecuta (409 `control_group_not_actionable`) |
| Reversión | NO IMPLEMENTADO | Un estado ya alcanzado no se edita (el pasado queda); no existe una transición «deshacer». La compensación sería una recomendación nueva (ver § 3.8) |

## 3. Fases futuras (ejecución supervisada): DISEÑADO / NO IMPLEMENTADO

Ninguna de estas capacidades existe ni se activa por configuración. Son el diseño mínimo que debe cumplirse **antes** de que Predictily pueda escribir en un sistema del cliente (§11 ¶4; prioridad P3 «ejecución supervisada avanzada»), y requieren
autorización contractual del cliente y el piloto previo (BLOQUEADO hasta entonces).

1. **Allowlist de herramientas.** Un registro explícito y versionado por empresa de las acciones que Predictily puede ejecutar (p. ej. «crear borrador de orden de compra»), cada una con su esquema de entrada, sistema destino, habilidad exigida e impacto máximo. Lo que
   no está en la lista no se puede invocar, aunque el workflow lo apruebe.
2. **Dry-run.** Toda acción se ejecuta primero en simulación que devuelve exactamente qué se escribiría (sistema, objeto, campos, importes) sin tocar nada; la aprobación humana se da sobre ese resultado, no sobre la recomendación en abstracto.
3. **Confirmación explícita.** Un paso de confirmación separado de la aprobación, por una persona autorizada que ve el dry-run vigente, con segregación respecto de quien propuso, asignó o aprobó.
4. **Deduplicación.** Clave de idempotencia por (recomendación, acción, versión del dry-run) también hacia el sistema destino; reintentos tras un fallo de red nunca generan dos pedidos.
5. **Límites.** Topes por acción, por día y por empresa (importe, número de órdenes, unidades), configurables y registrados; superarlos exige otra aprobación y queda auditado.
6. **Revalidación al ejecutar.** Antes de escribir se vuelve a comprobar que la recomendación sigue vigente (no vencida), que los insumos no cambiaron (huella de fuente), que el presupuesto y la disponibilidad siguen alcanzando y que el destino acepta el cambio; si algo cambió, se cancela y se pide una nueva decisión.
7. **Auditoría reforzada.** Además de `decision_events`: sistema destino, identificador devuelto por el destino, payload enviado (sin secretos), respuesta, quién confirmó y con qué versión del dry-run; inmutable.
8. **Rollback o compensación.** Solo para acciones con inverso definido en la allowlist (cancelar el borrador, revertir el cambio); se registra como una acción nueva con su propio ciclo de aprobación y nunca edita el evento original. Lo irreversible (un pedido ya aceptado por el
   proveedor) se declara como tal en la allowlist y exige una aprobación reforzada.
9. **Interruptor de seguridad.** Desactivar por empresa toda ejecución con un solo cambio, y pruebas negativas entre empresas (una empresa nunca ejecuta sobre el sistema de otra).

## 4. Qué NO es

- No es un asistente que actúa solo: el Intelligence Gateway (P2) solo consulta y explica; nunca aprueba ni ejecuta.
- No es un reemplazo del ERP: la fuente de verdad operativa sigue siendo el ERP, y lo que ocurre después se mide leyendo ventas (outcomes, W5-C).
