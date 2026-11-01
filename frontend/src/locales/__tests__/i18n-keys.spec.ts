import { describe, expect, it } from 'vitest'
import es from '../es.json'

/**
 * Verifica que cada clave i18n estática usada en el código exista en es.json y que
 * las familias dinámicas (estados, modelos, etc.) tengan todas sus variantes.
 */
const sources = import.meta.glob('../../**/*.{vue,ts}', { query: '?raw', import: 'default', eager: true }) as Record<string, string>

function has(path: string): boolean {
  let node: unknown = es
  for (const part of path.split('.')) {
    if (node && typeof node === 'object' && part in (node as Record<string, unknown>)) node = (node as Record<string, unknown>)[part]
    else return false
  }
  return node !== undefined
}

describe('claves de traducción', () => {
  it('todas las claves estáticas existen', () => {
    const missing = new Set<string>()
    for (const [file, code] of Object.entries(sources)) {
      if (file.includes('__tests__')) continue
      for (const m of code.matchAll(/\bt[em]?\(\s*'([a-zA-Z0-9_.]+)'/g)) {
        if (!has(m[1]!)) missing.add(`${m[1]} (${file})`)
      }
    }
    expect([...missing]).toEqual([])
  })

  it('toda familia dinámica con plantilla (`prefijo.${valor}`) tiene su grupo en es.json', () => {
    const missing = new Set<string>()
    for (const [file, code] of Object.entries(sources)) {
      if (file.includes('__tests__')) continue
      for (const m of code.matchAll(/\bt[em]?\(\s*`([a-zA-Z0-9_.]+)\.\$\{/g)) {
        if (!has(m[1]!)) missing.add(`${m[1]} (${file})`)
      }
    }
    expect([...missing]).toEqual([])
  })

  it('las familias dinámicas están completas', () => {
    const families: Record<string, string[]> = {
      'status.import': ['uploaded', 'awaiting_mapping', 'validating', 'validated', 'importing', 'completed', 'failed', 'rejected'],
      'status.job': ['queued', 'running', 'succeeded', 'failed', 'cancelled'],
      'status.run': ['queued', 'running', 'completed', 'failed'],
      'status.risk': ['low', 'medium', 'high', 'critical'],
      'status.recommendation': ['open', 'acknowledged', 'dismissed', 'ordered'],
      'status.severity': ['info', 'warning', 'critical'],
      roles: ['owner', 'admin', 'analyst', 'viewer'],
      sectors: ['ferreteria', 'distribuidora', 'repuestos_automotrices', 'repuestos_agricolas', 'retail_multisucursal', 'importadora', 'consumo_recurrente', 'otro'],
      models: ['naive', 'seasonal_naive', 'window_average', 'auto_ets', 'croston_sba', 'adida', 'tsb', 'lightgbm_global', 'timesfm'],
      levels: ['total', 'branch', 'category', 'branch_category', 'product', 'series'],
      'imports.entities': ['sales', 'products', 'inventory', 'lead_times', 'branches', 'suppliers', 'promotions', 'commitments', 'calendar_events', 'unit_conversions', 'warehouses'],
      'imports.entityHints': ['sales', 'products', 'inventory', 'lead_times', 'branches', 'suppliers', 'promotions', 'commitments', 'calendar_events', 'unit_conversions', 'warehouses'],
      'inventory.types': ['reorder', 'stockout_risk', 'overstock', 'no_action'],
      'settings.tabs': ['empresa', 'parametros', 'sucursales', 'usuarios', 'privacidad', 'plan', 'auditoria'],
      granularity: ['day', 'week', 'month'],
      'scenarios.rows': ['units', 'revenue', 'cost', 'gross_margin', 'gross_margin_pct', 'purchase_need_units', 'purchase_need_cost'],
      // ── Etapa 2 ──
      quality: ['ok', 'degraded', 'insufficient_data', 'not_configured', 'insufficient_evidence', 'unknown'],
      caseLabels: ['new_product_category_median', 'new_product_own_average', 'new_product_own_average_low_evidence', 'sparse_history', 'discontinued_zero', 'branch_closed_declared', 'branch_opening', 'exceptional_event'],
      caseLabelHints: ['new_product_category_median', 'new_product_own_average', 'new_product_own_average_low_evidence', 'sparse_history', 'discontinued_zero', 'branch_closed_declared', 'branch_opening', 'exceptional_event'],
      horizonSupport: ['degraded', 'insufficient_data', 'not_computed', 'unknown'],
      'horizonSupport.status': ['ok', 'degraded', 'insufficient_data', 'not_computed', 'unknown'],
      'envelope.uncertainty': ['empirical_holdout', 'conformal', 'model', 'unknown', 'none'],
      'envelope.abstention': ['insufficient_data', 'not_configured', 'insufficient_evidence'],
      'envelope.resultTypes': ['forecast', 'conditional_simulation', 'causal_estimate', 'optimized_plan', 'evaluation'],
      'rollback.errors': ['no_current_run', 'rollback_already_current', 'rollback_target_not_production', 'rollback_target_not_previous', 'rollback_target_without_models', 'rollback_target_without_forecasts', 'rollback_artifact_mismatch', 'rollback_target_not_restorable', 'rollback_target_without_slot', 'rollback_target_without_artifact', 'rollback_target_incompatible'],
      'status.model': ['candidate', 'validated', 'production', 'retired', 'failed'],
      'status.connector': ['available', 'requires_credentials', 'implemented_untested', 'planned'],
      'status.verification': ['tested', 'tested_with_test_database', 'implemented_untested', 'not_verified', 'planned'],
      'status.sync': ['queued', 'running', 'completed', 'needs_review', 'failed'],
      'status.commitment': ['open', 'confirmed', 'partially_fulfilled', 'fulfilled', 'cancelled', 'expired'],
      'status.calendar': ['planned', 'confirmed', 'cancelled'],
      'modelRegistry.roles': ['primary', 'fallback'],
      rankingSources: ['model', 'popularity', 'recency'],
      customerFlags: ['thin_history', 'stock_unavailable_now', 'low_availability_90d', 'price_unknown', 'coherence_adjusted', 'calibration_degraded', 'calibration_uncalibrated'],
      'customers.tabs': ['predicciones', 'evaluacion', 'conciliacion', 'modelos', 'ejecuciones'],
      'customers.calibration': ['degraded', 'uncalibrated'],
      'customers.profile.status': ['predicted', 'no_individual_prediction', 'no_candidate_relations', 'no_prediction_run'],
      'customers.profile.inactivity': ['active', 'at_risk', 'inactive_by_pattern', 'insufficient_history'],
      'customers.profile.behavior': ['increase', 'decrease', 'stable', 'insufficient_history'],
      'customers.eval.tier': ['full', 'lean', 'reference_only', 'abstained'],
      'customers.eval.tierText': ['full', 'lean', 'reference_only', 'abstained'],
      'customers.eval.blocks': ['train', 'calibration', 'selection', 'holdout'],
      'customers.eval.quantity': ['quantity_if_purchase', 'expected_quantity', 'expected_amount'],
      'customers.reconciliation.status': ['ok', 'inconsistent', 'aggregate_unavailable', 'aggregate_incomplete'],
      'customers.reconciliation.hint': ['ok', 'inconsistent', 'aggregate_unavailable', 'aggregate_incomplete'],
      'customers.reconciliation.severity': ['minor', 'major'],
      'customers.runs.operations': ['train', 'predict'],
      'manifest.steps': ['history', 'selection', 'calibration', 'holdout', 'production'],
      'manifest.holdoutRules': ['used_for_model_selection', 'used_for_hyperparameters', 'used_for_thresholds', 'used_for_interval_calibration'],
      'manifest.warnings': ['noBacktest', 'noHoldout', 'overlap', 'checksFailed', 'residualsNotObservable'],
      'extended.exclusions': ['scale_undefined', 'price_missing', 'stockout_days'],
      'sources.tabs': ['fuentes', 'cobertura', 'calidad', 'calendario', 'compromisos', 'almacenes'],
      'sql.entities': ['sales', 'products', 'inventory', 'commitments', 'branches', 'suppliers', 'lead_times', 'promotions', 'calendar_events', 'unit_conversions', 'warehouses'],
      'sql.query.state': ['active', 'pending', 'inactive'],
      'sql.sync.modes': ['incremental', 'full'],
      'sql.readOnlyModes': ['transaction_read_only', 'validator_and_reader_account'],
      'coverage.entities': ['sales', 'inventory', 'purchases', 'costs', 'promotions', 'customers', 'commitments', 'calendar', 'externals'],
      'coverage.rules': ['declared_by_user', 'min_over_sources', 'none'],
      'coverage.bases': ['declared', 'inferred'],
      'quality.temporalStatus': ['undeclared', 'declared', 'inferred', 'stale', 'data_beyond_coverage', 'no_data'],
      'quality.overall': ['ok', 'warning'],
      'quality.status': ['ok', 'warning', 'no_data'],
      'calendar.scopes': ['country', 'region', 'tenant', 'branch', 'category', 'product', 'sector'],
      'calendar.categories': ['holiday', 'local_holiday', 'commercial_season', 'campaign', 'promotion', 'sector_event', 'agricultural_season', 'international_peak', 'exceptional'],
      'commitments.types': ['quote', 'order', 'contract'],
      'effectiveConfig.cores': ['demand', 'customer', 'supply', 'cost', 'scenario'],
      'effectiveConfig.sources': ['core', 'profile', 'tenant'],
      'effectiveConfig.invalid': ['booleano', 'vacío', 'número', 'lista', 'objeto', 'json'],
      'supply.tabs': ['plan', 'configuracion', 'costos', 'escenarios', 'causalidad', 'simulacion'],
      'supply.solver': ['optimal', 'feasible_with_gap', 'time_limit', 'infeasible', 'heuristic_fallback', 'not_solved', 'unknown'],
      'supply.shortfall': ['lead_time', 'budget', 'supplier_capacity', 'storage_capacity', 'resource_capacity', 'economic', 'no_offer', 'mixed', 'unknown'],
      'supply.families': ['budget', 'supplier_capacity', 'storage_capacity', 'storage', 'resource_capacity', 'resource', 'minimum_order', 'structural'],
      'supply.plan.objectives': ['min_cost', 'max_margin'],
      'supply.plan.coverageModes': ['soft', 'hard'],
      'supply.plan.errors': ['range14_365', 'gt1', 'lt1', 'range0_5', 'range1_300'],
      'supply.config.tabs': ['offers', 'rates', 'capacities', 'budgets', 'resources', 'recipes'],
      'supply.config.offers.issues': ['no_unit_conversion', 'no_fx_rate'],
      'supply.costs.methods': ['last_value', 'mean_3', 'drift', 'holt_damped'],
      'supply.scenarios.classes': ['observed', 'forecasted', 'assumed'],
      'supply.causal.status': ['met', 'not_met', 'declared_not_verifiable'],
      'supply.causal.requirements': ['effect_definition', 'price_variation', 'support', 'confounders', 'availability', 'identification', 'estimator'],
      'supply.causal.confounders': ['promotions', 'calendar_seasonality', 'stock_availability', 'own_cost_changes', 'competitor_prices', 'macro_conditions', 'marketing_other_than_promotions'],
      'supply.causal.assumptionNames': ['conditional_ignorability', 'positivity', 'no_interference', 'price_exogeneity'],
      'supply.simulation.policies': ['current', 'reference', 'planner'],
      'assistant.tools': ['dashboard_summary', 'product_forecast', 'forecast_accuracy', 'inventory_recommendations', 'customer_predictions', 'supply_plan'],
      'assistant.figureStatus': ['ok', 'no_data', 'forbidden', 'plan_limit', 'invalid_arguments', 'not_found', 'tool_not_allowed', 'error'],
      'assistant.status': ['no_applicable_tool', 'tool_denied', 'no_answer', 'ungrounded_rejected', 'answered'],
      'assistant.reasons': ['missing_api_key', 'disabled_by_config', 'provider_disabled', 'unknown_provider', 'disabled_for_plan', 'invalid_base_url', 'missing_model'],
      'assistant.errors': ['disabled', 'plan', 'rate_limited', 'quota', 'provider', 'validation'],
    }
    const missing = Object.entries(families).flatMap(([prefix, keys]) => keys.map((k) => `${prefix}.${k}`).filter((k) => !has(k)))
    expect(missing).toEqual([])
    // Los avisos de horizonte y los estados del solver llevan título/etiqueta y texto.
    for (const k of ['degraded', 'insufficient_data', 'not_computed', 'unknown']) for (const f of ['title', 'text']) expect(has(`horizonSupport.${k}.${f}`), `horizonSupport.${k}.${f}`).toBe(true)
    for (const k of ['optimal', 'feasible_with_gap', 'time_limit', 'infeasible', 'heuristic_fallback', 'not_solved', 'unknown']) for (const f of ['label', 'text']) expect(has(`supply.solver.${k}.${f}`), `supply.solver.${k}.${f}`).toBe(true)
  })

  it('solo el óptimo probado se llama «óptimo» (E2: nunca presentar como óptimo lo que no lo es)', () => {
    const solver = (es as unknown as { supply: { solver: Record<string, { label: string } | string> } }).supply.solver
    for (const [status, entry] of Object.entries(solver)) {
      if (typeof entry === 'string') continue
      if (status === 'optimal') expect(entry.label).toMatch(/ptimo/)
      else expect(entry.label, status).not.toMatch(/ptimo/i)
    }
  })

  it('no usa caracteres reservados de vue-i18n sin escapar', () => {
    const bad: string[] = []
    const walk = (node: unknown, path: string) => {
      if (typeof node === 'string') {
        if (/[@|$]/.test(node)) bad.push(path)
      } else if (node && typeof node === 'object') {
        for (const [k, v] of Object.entries(node)) walk(v, path ? `${path}.${k}` : k)
      }
    }
    walk(es, '')
    expect(bad).toEqual([])
  })
})
