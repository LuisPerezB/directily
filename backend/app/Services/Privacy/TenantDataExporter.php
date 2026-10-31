<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Tenant;
use App\Models\TenantExport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Genera un ZIP con CSVs de todos los datos de la empresa (portabilidad, LOPDP): toda tabla con `tenant_id` se exporta salvo las credenciales
 * (NOT_EXPORTED), y de las exportadas se omiten las columnas secretas o internas (credenciales, errores técnicos, rutas del servidor).
 * Los nombres de clientes se exportan descifrados porque pertenecen a la empresa titular;
 * las cédulas/RUC nunca se almacenaron en claro y solo existe su huella.
 */
final class TenantDataExporter
{
    /** tabla => columnas exportadas (sin secretos ni credenciales). */
    public const TABLES = [
        'branches' => ['id', 'code', 'name', 'province', 'canton', 'commercial_zone', 'address', 'is_active', 'created_at'],
        'product_categories' => ['id', 'parent_id', 'code', 'name'],
        'products' => ['id', 'sku', 'name', 'category_id', 'unit', 'brand', 'current_price', 'current_cost', 'is_active', 'created_at'],
        'customer_segments' => ['id', 'code', 'name'],
        'customers' => ['id', 'external_code', 'segment_id', 'customer_type', 'province', 'canton', 'commercial_zone', 'created_at'],
        'suppliers' => ['id', 'code', 'name', 'tax_id', 'country', 'default_lead_time_days'],
        'sales' => ['id', 'branch_id', 'document_type', 'document_number', 'status', 'issued_at', 'sale_date', 'customer_id', 'channel', 'currency', 'subtotal', 'discount_total', 'tax_total', 'total'],
        'sale_items' => ['id', 'sale_id', 'line_number', 'product_id', 'branch_id', 'sale_date', 'quantity', 'unit_price', 'discount_amount', 'net_amount', 'tax_amount', 'unit_cost', 'is_return'],
        'inventory_snapshots' => ['id', 'branch_id', 'product_id', 'snapshot_date', 'on_hand', 'reserved', 'in_transit', 'unit_cost'],
        'supplier_lead_times' => ['id', 'supplier_id', 'product_id', 'branch_id', 'lead_time_days', 'lead_time_std_days', 'source'],
        'promotions' => ['id', 'name', 'promo_type', 'discount_pct', 'starts_on', 'ends_on', 'branch_id', 'product_id', 'category_id', 'known_at', 'deleted_at'],
        'promotion_revisions' => ['id', 'promotion_id', 'revision', 'name', 'promo_type', 'discount_pct', 'starts_on', 'ends_on', 'branch_id', 'product_id', 'category_id', 'known_at', 'deleted', 'source', 'recorded_at'],
        'product_price_history' => ['id', 'product_id', 'branch_id', 'price', 'currency', 'valid_from', 'valid_to', 'source'],
        'purchase_cost_history' => ['id', 'product_id', 'supplier_id', 'unit_cost', 'currency', 'valid_from', 'source'],
        'import_jobs' => ['id', 'entity_type', 'original_filename', 'file_hash', 'status', 'created_at', 'finished_at'],
        'forecast_runs' => ['id', 'version', 'run_type', 'status', 'horizon_days', 'cutoff_date', 'feature_set_version', 'is_current', 'created_at', 'completed_at'],
        'recommendations' => ['id', 'forecast_run_id', 'branch_id', 'product_id', 'type', 'suggested_qty', 'risk_level', 'status', 'created_at'],
        'audit_logs' => ['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'created_at'],

        // ── Etapa 2 · Plataforma y Core de Datos ──
        'core_runs' => ['id', 'core', 'operation', 'version', 'run_type', 'status', 'result_type', 'quality_status', 'uncertainty_source', 'as_of', 'horizons', 'horizon_start', 'horizon_end', 'target_definition', 'options', 'config_version', 'effective_config', 'data_snapshot_id', 'model_versions', 'evaluation_manifest', 'assumptions', 'limitations', 'summary', 'is_current', 'triggered_by', 'user_id', 'started_at', 'completed_at', 'created_at'],
        'data_snapshots' => ['id', 'core', 'as_of', 'hash', 'components', 'row_counts', 'coverage', 'created_at'],
        'data_coverage' => ['id', 'entity', 'data_source_id', 'branch_id', 'complete_from', 'complete_through', 'basis', 'last_ingested_at', 'notes', 'created_at', 'updated_at'],
        'warehouses' => ['id', 'branch_id', 'code', 'name', 'storage_capacity', 'capacity_unit', 'is_active', 'created_at'],
        'unit_conversions' => ['id', 'product_id', 'from_unit', 'to_unit', 'factor', 'valid_from', 'source'],
        'commercial_commitments' => ['id', 'customer_id', 'branch_id', 'data_source_id', 'commitment_type', 'external_id', 'status', 'issued_at', 'known_at', 'valid_from', 'valid_to', 'expected_delivery_date', 'cancelled_at', 'currency', 'created_at'],
        'commercial_commitment_items' => ['id', 'commitment_id', 'line_number', 'product_id', 'quantity', 'quantity_fulfilled', 'unit', 'unit_price', 'delivery_date', 'source_quantity', 'source_unit', 'conversion_factor'],
        'commercial_commitment_revisions' => ['id', 'commitment_id', 'import_job_id', 'revision', 'status', 'known_at', 'cancelled_at', 'snapshot', 'recorded_at'],
        // Solo los eventos DE LA EMPRESA (tenant_id = empresa): el calendario público (tenant_id NULL) no es de la empresa.
        'calendar_events' => ['id', 'scope', 'scope_ref', 'code', 'name', 'category', 'starts_on', 'ends_on', 'status', 'known_at', 'source', 'revision', 'created_at'],
        // Fuentes SIN `config` (host, usuario, base) ni `credentials_encrypted` ni `last_error`: nunca se exportan credenciales.
        'data_sources' => ['id', 'type', 'connector_key', 'name', 'status', 'last_synced_at', 'created_at'],
        'source_mappings' => ['id', 'data_source_id', 'entity', 'query', 'field_map', 'options', 'version', 'approved_by', 'approved_at', 'is_active', 'created_at'],
        'source_syncs' => ['id', 'data_source_id', 'source_mapping_id', 'import_job_id', 'entity', 'mapping_version', 'mode', 'status', 'triggered_by', 'since', 'watermark', 'rows_extracted', 'pages', 'stats', 'started_at', 'finished_at', 'created_at'],

        // ── Etapa 2 · Comportamiento de compra (resultados por comprador; el nombre/identificación del comprador no se incluye aquí) ──
        'customer_predictions' => ['id', 'core_run_id', 'customer_id', 'product_id', 'horizon_days', 'as_of', 'purchase_probability', 'probability_raw', 'probability_coherence_adjusted', 'calibration_status', 'quantity_if_purchase', 'quantity_lo', 'quantity_hi', 'expected_quantity', 'committed_quantity', 'incremental_quantity', 'total_view_quantity', 'assumed_unit_price', 'expected_amount', 'currency', 'median_interval_days', 'days_since_last_purchase', 'n_purchases', 'model_family', 'model_role', 'quality_flags'],
        'customer_product_rankings' => ['id', 'core_run_id', 'customer_id', 'product_id', 'horizon_days', 'rank', 'purchase_probability', 'expected_quantity', 'expected_amount', 'currency', 'ranking_source', 'ranking_score'],
        'customer_activity' => ['id', 'core_run_id', 'customer_id', 'as_of', 'last_purchase_date', 'days_since_last_purchase', 'n_purchase_days', 'purchase_frequency_per_30d', 'median_interval_days', 'mean_interval_days', 'interval_p75_days', 'interval_p90_days', 'n_intervals', 'inactivity_risk', 'inactivity_status', 'behavior_change', 'frequency_ratio', 'behavior_p_value', 'spend_recent', 'spend_prior', 'spend_ratio', 'currency'],
        'customer_reconciliations' => ['id', 'core_run_id', 'forecast_run_id', 'product_id', 'horizon_days', 'aggregate_quantity', 'aggregate_days', 'buyers_total_quantity', 'buyers_committed_quantity', 'buyers_incremental_quantity', 'buyers_expected_quantity', 'n_buyers', 'committed_outside_model', 'residual_quantity', 'excess_quantity', 'excess_ratio', 'residual_share', 'status', 'severity'],

        // ── Etapa 2 · Abastecimiento, costos y escenarios (migración 2026_02_04) ──
        'exchange_rates' => ['id', 'from_currency', 'to_currency', 'rate', 'valid_on', 'source', 'known_at'],
        'supplier_offers' => ['id', 'supplier_id', 'product_id', 'branch_id', 'unit_cost', 'currency', 'unit', 'min_order_qty', 'order_multiple', 'lead_time_days', 'lead_time_std_days', 'capacity_per_period', 'capacity_period_days', 'fixed_order_cost', 'acquisition_cost_pct', 'acquisition_cost_per_unit', 'valid_from', 'valid_to', 'known_at', 'source', 'is_active', 'notes'],
        'location_capacities' => ['id', 'branch_id', 'warehouse_id', 'capacity', 'capacity_unit', 'valid_from', 'valid_to', 'notes'],
        'purchase_budgets' => ['id', 'branch_id', 'period_start', 'period_end', 'amount', 'currency', 'notes'],
        'production_resources' => ['id', 'code', 'name', 'branch_id', 'capacity_per_period', 'capacity_unit', 'period_days', 'is_active'],
        'production_recipes' => ['id', 'code', 'name', 'output_product_id', 'branch_id', 'output_quantity', 'yield_rate', 'production_days', 'resource_id', 'resource_usage_per_batch', 'cost_per_batch', 'max_batches_per_period', 'is_active'],
        'production_recipe_lines' => ['id', 'recipe_id', 'input_product_id', 'quantity_per_batch'],
        'supply_plans' => ['id', 'core_run_id', 'forecast_run_id', 'objective', 'coverage_mode', 'solver_status', 'mip_gap', 'objective_value', 'solve_seconds', 'currency', 'total_purchase_cost', 'total_holding_cost', 'total_shortage_penalty', 'unmet_units_total', 'demand_units_total', 'details', 'created_at'],
        'supply_plan_lines' => ['id', 'supply_plan_id', 'line_type', 'branch_id', 'product_id', 'supplier_id', 'offer_id', 'recipe_id', 'quantity', 'unit', 'offer_quantity', 'offer_unit', 'order_date', 'expected_receipt_date', 'unit_cost', 'line_cost', 'currency', 'actionable_now', 'binding_constraints', 'justification', 'assumptions'],
        'supply_plan_shortfalls' => ['id', 'supply_plan_id', 'branch_id', 'product_id', 'period_start', 'period_end', 'demand', 'unmet', 'reason'],
        'cost_forecasts' => ['id', 'core_run_id', 'product_id', 'supplier_id', 'cost_source', 'currency', 'unit', 'method', 'reference_method', 'quality_status', 'n_obs', 'last_observed_on', 'last_observed_cost', 'points', 'metrics', 'availability', 'limitations', 'created_at'],
        'scenario_evaluations' => ['id', 'core_run_id', 'user_id', 'name', 'horizon_days', 'request', 'results', 'created_at'],

        // ── Etapa 1 · datos de la empresa que antes faltaban (P6) ──
        'alerts' => ['id', 'type', 'severity', 'title', 'message', 'payload', 'related_type', 'related_id', 'dedup_key', 'status', 'acknowledged_by', 'acknowledged_at', 'created_at', 'updated_at'],
        'scenarios' => ['id', 'user_id', 'forecast_run_id', 'name', 'horizon_days', 'filters', 'assumptions', 'results', 'created_at', 'updated_at'],
        'purchase_orders' => ['id', 'supplier_id', 'branch_id', 'external_id', 'status', 'ordered_at', 'expected_at', 'received_at', 'total', 'created_at', 'updated_at'],
        'purchase_order_items' => ['id', 'purchase_order_id', 'product_id', 'quantity_ordered', 'quantity_received', 'unit_cost', 'created_at', 'updated_at'],
        'stock_movements' => ['id', 'branch_id', 'product_id', 'movement_type', 'quantity', 'occurred_at', 'reference', 'created_at'],
        // Solo los indicadores DE LA EMPRESA (tenant_id = empresa); el catálogo público (tenant_id NULL) no es de la empresa.
        'external_indicators' => ['id', 'code', 'name', 'source', 'frequency', 'unit', 'country', 'description', 'affected_sectors', 'connector_key', 'is_active', 'created_at', 'updated_at'],
        'external_indicator_observations' => ['id', 'indicator_id', 'period_date', 'value', 'published_at', 'ingested_at', 'revision', 'source_url', 'created_at'],
        // Membresías (quién tiene acceso y con qué rol); nombres y correos viven en la cuenta de la plataforma, no se exportan aquí.
        'tenant_users' => ['id', 'user_id', 'role', 'invited_by', 'created_at', 'updated_at'],
        // Sin `path` (ruta interna del archivo en el servidor) ni `error_message`.
        'tenant_exports' => ['id', 'user_id', 'status', 'file_size', 'file_hash', 'summary', 'expires_at', 'created_at', 'updated_at'],
        'tenant_usage' => ['id', 'period', 'metric', 'value', 'created_at', 'updated_at'],
        'import_errors' => ['id', 'import_job_id', 'row_number', 'column', 'error_code', 'severity', 'message', 'raw_value', 'created_at'],

        // ── Etapa 1 · resultados de demanda y registro técnico (derivados, pero producidos para la empresa con sus datos) ──
        'forecast_values' => ['id', 'forecast_run_id', 'node_key', 'level', 'branch_id', 'product_id', 'category_id', 'granularity', 'target_date', 'period_days', 'horizon_step', 'yhat', 'lo_80', 'hi_80', 'lo_95', 'hi_95', 'model_registry_id', 'model_family', 'unit_price_assumed', 'revenue_yhat', 'p50', 'quality_status', 'case_label'],
        'forecast_actuals' => ['id', 'forecast_run_id', 'node_key', 'level', 'branch_id', 'product_id', 'category_id', 'target_date', 'horizon_step', 'yhat', 'lo_80', 'hi_80', 'actual', 'abs_error', 'is_censored', 'evaluated_at'],
        'forecast_features' => ['id', 'feature_set_version', 'name', 'kind', 'description', 'config', 'is_active', 'created_at'],
        'forecasting_series' => ['id', 'branch_id', 'product_id', 'unique_id', 'first_sale_date', 'last_sale_date', 'history_days', 'nonzero_days', 'total_units', 'adi', 'cv2', 'demand_class', 'stockout_days', 'censored_days', 'is_active', 'last_profiled_at', 'lifecycle', 'created_at', 'updated_at'],
        // Sin `artifact_path` (ruta interna del artefacto en el servidor); sí su huella `artifact_sha256`.
        'model_registry' => ['id', 'version', 'model_family', 'strategy_level', 'scope', 'sector', 'demand_class', 'status', 'core', 'role', 'hyperparameters', 'feature_set_version', 'training_data_from', 'training_data_to', 'training_data_hash', 'series_count', 'artifact_sha256', 'artifact_compat', 'library_versions', 'random_seed', 'training_job_id', 'forecast_run_id', 'core_run_id', 'trained_at', 'validated_at', 'promoted_at', 'retired_at', 'notes', 'created_at', 'updated_at'],
        'model_evaluations' => ['id', 'model_registry_id', 'forecast_run_id', 'core_run_id', 'core', 'model_family', 'evaluation_type', 'level', 'level_key', 'horizon_days', 'is_baseline', 'is_selected', 'wape', 'mae', 'rmse', 'bias', 'coverage_80', 'coverage_95', 'n_obs', 'n_windows', 'cutoffs', 'extra', 'metrics', 'created_at'],
        // Sin `error_message` (texto técnico interno); el estado y el resultado sí.
        'training_jobs' => ['id', 'forecast_run_id', 'core_run_id', 'job_type', 'status', 'stage', 'progress', 'attempts', 'triggered_by', 'user_id', 'params', 'result', 'model_registry_id', 'queued_at', 'started_at', 'finished_at', 'duration_ms', 'created_at', 'updated_at'],
        'data_quality_reports' => ['id', 'import_job_id', 'forecast_run_id', 'scope', 'score', 'summary', 'issues', 'created_at'],
    ];

    /**
     * Tablas de la empresa que NO tienen `tenant_id` propio y pertenecen a ella por su tabla padre: tabla => [columna de enlace, tabla padre].
     * Se exportan solo las filas cuyo padre tiene `tenant_id` = empresa (las observaciones de los indicadores públicos, con tenant_id NULL, no).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CHILD_TABLES = [
        'external_indicator_observations' => ['indicator_id', 'external_indicators'],
    ];

    /**
     * Tablas con `tenant_id` que NO se exportan, con el motivo. La prueba de cobertura exige que toda tabla con `tenant_id` esté en
     * TABLES o aquí: una tabla nueva sin clasificar rompe la prueba y obliga a decidir qué se exporta y qué nunca (credenciales).
     * Solo quedan fuera las credenciales: todo dato de la empresa se exporta (con columnas internas o secretas omitidas arriba).
     *
     * @var array<string, string>
     */
    public const NOT_EXPORTED = [
        'personal_access_tokens' => 'credenciales de acceso (solo se guarda su hash): nunca se exportan',
    ];

    /** Fragmentos de nombre de columna que jamás deben exportarse (la prueba lo verifica sobre TABLES). */
    public const FORBIDDEN_COLUMN_PARTS = ['credential', 'password', 'secret', 'token', 'api_key', 'name_encrypted', 'remember', 'error_message', 'last_error'];

    /** Filas de `$table` que son de la empresa: por su `tenant_id` o, en las tablas hijas, por el `tenant_id` de su padre. */
    private function tenantRows(string $table, int $tenantId): Builder
    {
        if (isset(self::CHILD_TABLES[$table])) {
            [$link, $parent] = self::CHILD_TABLES[$table];

            return DB::table($table)->whereIn($link, DB::table($parent)->where('tenant_id', $tenantId)->select('id'));
        }

        return DB::table($table)->where('tenant_id', $tenantId);
    }

    public function export(TenantExport $export): void
    {
        $tenant = Tenant::query()->findOrFail($export->tenant_id);
        $disk = Storage::disk((string) config('predictily.imports.disk', 'local'));
        $relative = sprintf('tenants/%d/exports/export-%d-%s.zip', $tenant->id, $export->id, now()->format('YmdHis'));
        $disk->makeDirectory(dirname($relative));
        $zipPath = $disk->path($relative);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de exportación.');
        }
        $tmpFiles = [];
        $summary = [];
        try {
            foreach (self::TABLES as $table => $columns) {
                $tmp = tempnam(sys_get_temp_dir(), 'pexp');
                $tmpFiles[] = $tmp;
                $out = fopen($tmp, 'wb');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $columns, ',', '"', '');
                $count = 0;
                $this->tenantRows($table, $tenant->id)->select($columns)->orderBy('id')
                    ->chunkById(5000, function ($rows) use ($out, &$count): void {
                        foreach ($rows as $row) {
                            fputcsv($out, array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, (array) $row), ',', '"', '');
                            $count++;
                        }
                    });
                fclose($out);
                $zip->addFile($tmp, $table.'.csv');
                $summary[$table] = $count;
            }
            // Nombres de clientes (cifrados en reposo) en archivo separado.
            $tmp = tempnam(sys_get_temp_dir(), 'pexp');
            $tmpFiles[] = $tmp;
            $out = fopen($tmp, 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['customer_id', 'name'], ',', '"', '');
            DB::table('customers')->where('tenant_id', $tenant->id)->whereNotNull('name_encrypted')->orderBy('id')
                ->chunkById(2000, function ($rows) use ($out): void {
                    foreach ($rows as $row) {
                        try {
                            $name = decrypt($row->name_encrypted, false);
                        } catch (\Throwable) {
                            $name = null;
                        }
                        fputcsv($out, [$row->id, $name], ',', '"', '');
                    }
                });
            fclose($out);
            $zip->addFile($tmp, 'customer_names.csv');
            $zip->addFromString('LEEME.txt', implode("\n", [
                'Exportación de datos de la empresa '.$tenant->name,
                'Generada: '.now($tenant->timezone)->toIso8601String(),
                'Contenido: un CSV por tabla (UTF-8) con todos los datos de la empresa: catálogos, ventas, inventario, compras y movimientos, alertas, escenarios, indicadores externos propios, resultados de demanda y de los cores de la etapa 2 (ejecuciones, huellas de datos, cobertura, compromisos comerciales, predicciones de compra, planes de abastecimiento, costos), registro técnico de modelos, membresías y consumo.',
                'Quedan fuera los indicadores y el calendario públicos compartidos (no son de la empresa) y las rutas internas de archivos y artefactos del servidor.',
                'Las cédulas/RUC de clientes no se almacenan en claro en Predictily; solo existe una huella irreversible.',
                'No se incluyen credenciales de conectores, tokens de acceso ni la configuración de conexión de las fuentes de datos.',
            ]));
            $zip->addFromString('tenant.json', json_encode([
                'uuid' => $tenant->uuid, 'name' => $tenant->name, 'legal_name' => $tenant->legal_name, 'tax_id' => $tenant->tax_id,
                'sector' => $tenant->sector, 'settings' => $tenant->effectiveSettings(), 'allow_shared_training' => $tenant->allow_shared_training,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $zip->close();
        } finally {
            foreach ($tmpFiles as $file) {
                @unlink($file);
            }
        }

        $export->forceFill([
            'status' => 'completed',
            'path' => $relative,
            'file_size' => filesize($zipPath) ?: null,
            'file_hash' => hash_file('sha256', $zipPath) ?: null,
            'summary' => $summary,
            'expires_at' => now()->addDays((int) config('predictily.privacy.export_retention_days', 7)),
        ])->save();
    }
}
