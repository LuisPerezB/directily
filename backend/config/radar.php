<?php

/**
 * Revenue Radar y Agenda Comercial (V2.5 · W5-A; ADR-V25-12). Parámetros de la política `radar-v1` que decide QUÉ predicciones del Customer Core propone el Radar al
 * Decision Engine. Son hipótesis de partida: ninguno es universal y todos se aprueban (o se cambian) en el piloto con datos de la empresa. Su huella SHA-256 viaja en
 * `decision_recommendations.policy` (RadarPolicy::version()), de modo que un cambio de parámetros es visible en cada recomendación.
 *
 * El Radar NO decide economía ni disponibilidad: el costo unitario y su confiabilidad, el inventario al corte, el score, la prioridad, el vencimiento y el experimento
 * los resuelve el Decision Engine (App\Services\Decision, parámetros por empresa en tenants.settings.decision).
 *
 * - `min_probability`: P(≥ 1 compra en el horizonte) mínima para proponer un contacto. 0,20 deja fuera lo que el modelo considera improbable sin exigir certeza;
 *   a aprobar en el piloto con la precisión@K observada.
 * - `max_per_kind_horizon`: tope de oportunidades por tipo y horizonte (las mejor valoradas). Acota el trabajo del vendedor y el costo de la materialización; el
 *   resto sigue disponible en /customer/predictions.
 * - `recovery_min_purchases`: compras previas mínimas de la relación para hablar de «recuperación» cuando su ventana de recompra está atrasada (con menos
 *   historia un atraso no es información).
 * - `seller_scope` (V2.5 · W6-A; ADR-V25-15): alcance comercial del VENDEDOR sobre las oportunidades y decisiones (App\Services\Auth\Commercial\CommercialScope). Un vendedor
 *   ve siempre lo asignado a él; `unassigned_in_portfolio` decide si además ve lo SIN asignar de los clientes de su cartera (clientes, zonas o segmentos de
 *   `tenant_users.commercial_scope`). Verdadero por defecto: si fuera falso, nada llegaría a un vendedor hasta que alguien se lo asigne. Lo asignado a OTRA persona nunca se
 *   muestra, aunque el cliente esté en su cartera. Es una decisión de visibilidad, no estadística: a aprobar con el propietario en el piloto.
 */
return [
    'rules_version' => 'radar-v1',
    'min_probability' => 0.20,
    'max_per_kind_horizon' => 100,
    'recovery_min_purchases' => 3,
    'seller_scope' => [
        'unassigned_in_portfolio' => true,
    ],
];
