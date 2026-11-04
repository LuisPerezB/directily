<?php

declare(strict_types=1);

namespace App\Services\Outcomes;

/**
 * Vocabulario cerrado de la medición de outcomes (V2.5 · W5-C; ADR-V25-14): clasificaciones, acciones y eventos observables, con la explicación en español de
 * lo que cada clasificación SÍ y NO afirma. Fuente única para el servicio, la API y la documentación.
 */
final class OutcomeVocabulary
{
    public const FORECAST = 'forecast';

    public const OBSERVED = 'observed';

    public const TEMPORALLY_ASSOCIATED = 'temporally_associated';

    public const INCREMENTAL_ESTIMATED = 'incremental_estimated';

    public const CAUSALLY_ATTRIBUTABLE = 'causally_attributable';

    /** Mismas cinco del CHECK de `decision_outcomes.classification`. */
    public const CLASSIFICATIONS = [self::FORECAST, self::OBSERVED, self::TEMPORALLY_ASSOCIATED, self::INCREMENTAL_ESTIMATED, self::CAUSALLY_ATTRIBUTABLE];

    /**
     * Clasificaciones que ESTE paquete escribe en las FILAS de `decision_outcomes`. `forecast` no se persiste (nada observado aún); `causally_attributable` no se produce
     * (diseño DOCUMENTADO, no implementado); `incremental_estimated` es una propiedad del AGREGADO tratamiento-control (`/decisions/roi`: `experiments`, `roi.status`), no de una
     * compra ni de un cliente: no se estampa en las filas.
     */
    public const WRITTEN = [self::OBSERVED, self::TEMPORALLY_ASSOCIATED];

    /** Clasificaciones que solo existen como resultado agregado del informe. */
    public const AGGREGATE_ONLY = [self::INCREMENTAL_ESTIMATED];

    public const LABELS = [
        self::FORECAST => 'Previsto',
        self::OBSERVED => 'Observado',
        self::TEMPORALLY_ASSOCIATED => 'Asociado temporalmente',
        self::INCREMENTAL_ESTIMATED => 'Incremental estimado',
        self::CAUSALLY_ATTRIBUTABLE => 'Atribuible causalmente',
    ];

    public const NOTES = [
        self::FORECAST => 'Previsto, aún sin observar: la ventana no cerró o está en espera de documentos tardíos. Es una oportunidad potencial, atribución no demostrada.',
        self::OBSERVED => 'Se observó lo ocurrido en la ventana. No se atribuye a ninguna acción: la compra (o su ausencia) pudo ocurrir igual sin intervención.',
        self::TEMPORALLY_ASSOCIATED => 'La compra ocurrió después de la acción registrada. Sin grupo de control no hay contrafactual: NO se puede decir que la acción la causó.',
        self::INCREMENTAL_ESTIMATED => 'Resultado AGREGADO de un experimento con grupo de control analizado por intención de tratamiento, con tamaños de muestra e intervalo: no es una propiedad de una compra ni de un cliente (no se estampa en las filas). Es una estimación, no causalidad demostrada.',
        self::CAUSALLY_ATTRIBUTABLE => 'No lo produce esta versión: exige un diseño de experimento pre-registrado (DISEÑADO, ver OUTCOME_MEASUREMENT.md).',
    ];

    public const METHODS = ['itt', 'quasi_experiment', 'none'];

    // ── Acciones (action_kind): qué pasó con la recomendación ──
    public const CONTACTED = 'contacted';

    public const EXECUTED = 'executed';

    public const NOT_CONTACTED = 'not_contacted';

    public const NOT_EXECUTED = 'not_executed';

    public const REJECTED = 'rejected';

    public const EXPIRED = 'expired';

    public const CONTROL = 'control';

    public const ACTION_KINDS = [self::CONTACTED, self::EXECUTED, self::NOT_CONTACTED, self::NOT_EXECUTED, self::REJECTED, self::EXPIRED, self::CONTROL];

    /** Acciones que cuentan como «atendida». */
    public const ACTED = [self::CONTACTED, self::EXECUTED];

    public const ACTION_LABELS = [
        self::CONTACTED => 'Contactada',
        self::EXECUTED => 'Ejecutada',
        self::NOT_CONTACTED => 'No contactada',
        self::NOT_EXECUTED => 'No ejecutada',
        self::REJECTED => 'Rechazada',
        self::EXPIRED => 'Caducada',
        self::CONTROL => 'Grupo de control (retenida a propósito)',
    ];

    // ── Eventos observables (observed_event) ──
    public const PURCHASE = 'purchase';

    public const NO_PURCHASE = 'no_purchase';

    public const PRODUCT_SALES = 'product_sales';

    public const STOCKOUT = 'stockout';

    /** «La disponibilidad no bajó»: al cierre de la ventana hay al menos lo que había al generar la recomendación (inventario-demanda). Observación de existencias; no diagnostica exceso. */
    public const NO_SELL_THROUGH = 'no_sell_through';

    public const EVENTS = [self::PURCHASE, self::NO_PURCHASE, self::PRODUCT_SALES, self::STOCKOUT, self::NO_SELL_THROUGH];

    /** Tipos con cliente: se miden por compras del cliente. El resto (reposición, inventario-demanda, presupuesto, escenarios) se mide por producto. */
    public const CUSTOMER_KINDS = ['contact', 'recovery', 'customer_risk'];

    public const AGENDA_CONTACT_STATES = ['contactado', 'interesado', 'pedido_recibido'];

    public static function isCustomerKind(string $kind): bool
    {
        return in_array($kind, self::CUSTOMER_KINDS, true);
    }
}
