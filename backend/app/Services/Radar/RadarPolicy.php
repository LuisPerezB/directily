<?php

declare(strict_types=1);

namespace App\Services\Radar;

/**
 * Política `radar-v1` (V2.5 · W5-A, ADR-V25-12): qué se propone al Decision Engine y con qué umbrales. Los parámetros viven en config/radar.php; aquí se
 * leen, se validan y se les calcula una huella SHA-256 (`version()`), que se registra en `decision_recommendations.policy` para que cada recomendación diga
 * con qué parámetros nació. Los umbrales son hipótesis de partida «a aprobar en el piloto», nunca universales.
 */
final class RadarPolicy
{
    public const KIND_CONTACT = 'contact';

    public const KIND_RECOVERY = 'recovery';

    public const KIND_CUSTOMER_RISK = 'customer_risk';

    /** Tipos de recomendación que el Radar LISTA (el resto de kinds de decision_recommendations —reposición, inventario, presupuesto, escenarios— no son comerciales). */
    public const KINDS = [self::KIND_CONTACT, self::KIND_RECOVERY, self::KIND_CUSTOMER_RISK];

    /**
     * Tipos que el Radar PROPONE: contacto y recuperación por relación (cliente × producto). La recuperación y el riesgo A NIVEL DE CLIENTE (ausencia respecto al patrón
     * propio) los genera el Decision Engine con `predictily:decisions-generate` (W5-B); el Radar los lista junto con los suyos.
     */
    public const PRODUCED_KINDS = [self::KIND_CONTACT, self::KIND_RECOVERY];

    /** Etiqueta honesta de toda oportunidad sin evidencia de uplift (ADR-V25-13). */
    public const ATTRIBUTION_TEXT = 'Oportunidad potencial, atribución no demostrada';

    /** @var array<string, string> Definiciones que acompañan las respuestas del Radar (para que ninguna cifra se lea sin su significado). */
    public const DEFINITIONS = [
        'kind' => 'contact = contactar por una compra probable; recovery = recuperar una relación (cliente × producto) con la ventana de recompra atrasada, o un cliente ausente respecto a su patrón (no es abandono); customer_risk = riesgo a nivel de cliente.',
        'state' => 'Estado heredado de la agenda V2.2 (pendiente, contactado, interesado, pedido_recibido, pospuesto, descartado); sin gestión es «pendiente». Su correspondencia con el workflow está en la tabla de mapeo.',
        'status' => 'Estado del workflow del Decision Engine (suggested → assigned → reviewed → approved | rejected → in_progress → executed → measured → closed, + expired).',
        'score' => 'Puntaje de prioridad con la versión de su fórmula (score_formula_version), calculado por el Decision Engine; el orden por defecto es score descendente.',
        'expected_gross_value' => 'Supuesto: unidades esperadas × último precio neto de la relación (sin impuestos). No es una venta confirmada ni causada por el contacto.',
        'expected_contribution' => 'Unidades vendibles × (precio supuesto − costo unitario conocido al corte), calculado por el Decision Engine. null si el costo no es confiable (cost_status = unreliable, con sus motivos): no se inventa un margen.',
        'attribution' => 'Oportunidad potencial, atribución no demostrada: sin grupo de control o evidencia de uplift no se afirma que el contacto cause la compra.',
        'inventory' => 'Disponibilidad al corte = existencias − reservado; el tránsito va aparte; unknown = sin fotografía de inventario vigente (no es cero). Para un contacto, el Decision Engine limita el valor a lo disponible.',
        'window' => 'Cuantiles P10/P50/P90 de los días hasta la siguiente compra desde el corte; solo con estado estimated u overdue; nunca un día exacto.',
        'expected_orders' => 'E(nº de pedidos) servido por el modelo de conteo del Customer Core; no se deriva de la probabilidad.',
    ];

    /** @var array<string, mixed> */
    private array $params;

    /** @param array<string, mixed>|null $params null = config/radar.php */
    public function __construct(?array $params = null)
    {
        $params ??= (array) config('radar', []);
        $this->params = [
            'rules_version' => (string) ($params['rules_version'] ?? 'radar-v1'),
            'min_probability' => max(0.0, min(1.0, (float) ($params['min_probability'] ?? 0.20))),
            'max_per_kind_horizon' => max(1, (int) ($params['max_per_kind_horizon'] ?? 100)),
            'recovery_min_purchases' => max(0, (int) ($params['recovery_min_purchases'] ?? 3)),
        ];
    }

    public function minProbability(): float
    {
        return $this->params['min_probability'];
    }

    public function maxPerKindHorizon(): int
    {
        return $this->params['max_per_kind_horizon'];
    }

    public function recoveryMinPurchases(): int
    {
        return $this->params['recovery_min_purchases'];
    }

    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return $this->params;
    }

    /** SHA-256 completo de los parámetros (orden de claves estable). */
    public function fingerprint(): string
    {
        $params = $this->params;
        ksort($params);

        return hash('sha256', (string) json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Valor de `decision_recommendations.policy` (≤ 60 caracteres): `<versión de reglas>:<12 primeros hex de la huella>`. */
    public function version(): string
    {
        return mb_substr($this->params['rules_version'], 0, 40).':'.substr($this->fingerprint(), 0, 12);
    }
}
