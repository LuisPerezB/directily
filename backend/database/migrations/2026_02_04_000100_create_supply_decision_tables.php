<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 · P4 Decisiones comunes (docs/architecture/v2/P4-decisiones.md; instrucctions.md §6.F y §6.G).
 *
 * Datos de configuración que el planificador consume (ofertas de proveedor, tasas de cambio, capacidades,
 * presupuestos, recursos y recetas de producción) y resultados (planes, líneas y faltantes, pronósticos de
 * costos, evaluaciones de escenarios). Las ejecuciones usan core_runs (core = supply | cost | scenario).
 *
 * Reglas del diseño:
 * - Lo desconocido queda NULL (plazo, mínimo, múltiplo, capacidad, vigencia, known_at).
 * - Nada se convierte en silencio: unidad y moneda de cada oferta son obligatorias; sin unit_conversions o sin
 *   tasa de cambio explícita la oferta es «no comparable».
 * - Todas las FK son compuestas (tenant_id, x_id) para impedir referencias entre empresas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tasas de cambio explícitas (1 from_currency = rate to_currency), con fecha y fuente.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->decimal('rate', 20, 10)->comment('1 unidad de from_currency = rate unidades de to_currency');
            $table->date('valid_on')->comment('Fecha a la que corresponde la tasa');
            $table->string('source', 50)->comment('Fuente declarada (manual, banco central, contrato...)');
            $table->timestampTz('known_at')->nullable()->comment('Cuándo se conocía la tasa; NULL = desconocido');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'from_currency', 'to_currency', 'valid_on'], 'exchange_rates_natural_key');
        });
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_positive CHECK (rate > 0)');
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_distinct_currencies CHECK (from_currency <> to_currency)');

        // Ofertas por proveedor y producto. Cantidades de la oferta en `unit` (no en la unidad del producto).
        Schema::create('supplier_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = entrega a cualquier sucursal');
            $table->decimal('unit_cost', 16, 6)->comment('Precio por unidad de la oferta, sin gastos de adquisición');
            $table->char('currency', 3);
            $table->string('unit', 20)->comment('Unidad de la oferta; debe convertirse a la del producto con unit_conversions');
            $table->decimal('min_order_qty', 16, 4)->nullable()->comment('Mínimo de compra por orden, en unidades de la oferta; NULL = sin mínimo conocido');
            $table->decimal('order_multiple', 16, 4)->nullable()->comment('Lote/múltiplo en unidades de la oferta; NULL = 1');
            $table->unsignedSmallInteger('lead_time_days')->nullable()->comment('NULL = se resuelve con supplier_lead_times');
            $table->decimal('lead_time_std_days', 6, 2)->nullable();
            $table->decimal('capacity_per_period', 16, 4)->nullable()->comment('Capacidad del proveedor por período, en unidades de la oferta; NULL = desconocida/sin límite declarado');
            $table->unsignedSmallInteger('capacity_period_days')->default(7);
            $table->decimal('fixed_order_cost', 14, 4)->default(0)->comment('Costo fijo por línea de orden (flete mínimo, trámite), en la moneda de la oferta');
            $table->decimal('acquisition_cost_pct', 8, 5)->default(0)->comment('Gastos de adquisición no recuperables como fracción del precio (aranceles, flete, impuestos no acreditables). El IVA recuperable NO es costo');
            $table->decimal('acquisition_cost_per_unit', 14, 6)->default(0)->comment('Gasto por unidad de la oferta, en la moneda de la oferta');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampTz('known_at')->nullable()->comment('Cuándo se conocía la oferta; NULL = desconocido');
            $table->string('source', 30)->default('manual');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'product_id', 'is_active']);
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX supplier_offers_natural_key ON supplier_offers (tenant_id, supplier_id, product_id, COALESCE(branch_id, 0), COALESCE(valid_from, DATE '1900-01-01'))");
        DB::statement('ALTER TABLE supplier_offers ADD CONSTRAINT supplier_offers_nonnegative CHECK (unit_cost >= 0 AND fixed_order_cost >= 0 AND acquisition_cost_pct >= 0 AND acquisition_cost_per_unit >= 0)');
        DB::statement('ALTER TABLE supplier_offers ADD CONSTRAINT supplier_offers_positive_lots CHECK ((min_order_qty IS NULL OR min_order_qty >= 0) AND (order_multiple IS NULL OR order_multiple > 0) AND (capacity_per_period IS NULL OR capacity_per_period >= 0) AND capacity_period_days > 0)');

        // Capacidad de almacenamiento por sucursal (ubicación del planificador).
        Schema::create('location_capacities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->decimal('capacity', 16, 4);
            $table->string('capacity_unit', 20)->comment('Unidad física de la capacidad (m3, pallets, kg, unidad...)');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'warehouse_id'])->references(['tenant_id', 'id'])->on('warehouses')->cascadeOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX location_capacities_natural_key ON location_capacities (tenant_id, branch_id, COALESCE(warehouse_id, 0), COALESCE(valid_from, DATE '1900-01-01'))");
        DB::statement('ALTER TABLE location_capacities ADD CONSTRAINT location_capacities_nonnegative CHECK (capacity >= 0)');

        // Presupuesto de compra por período (aplica a órdenes EMITIDAS dentro del período).
        Schema::create('purchase_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = toda la empresa');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 16, 4);
            $table->char('currency', 3);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'period_start', 'period_end']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE purchase_budgets ADD CONSTRAINT purchase_budgets_valid CHECK (amount >= 0 AND period_end >= period_start)');

        // Producción genérica: recursos con capacidad y recetas insumo -> producto. Sin reglas sectoriales.
        Schema::create('production_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->unsignedBigInteger('branch_id')->nullable()->comment('NULL = recurso compartido por todas las sucursales');
            $table->decimal('capacity_per_period', 16, 4);
            $table->string('capacity_unit', 20)->comment('horas, kg, lotes... (unidad del recurso)');
            $table->unsignedSmallInteger('period_days')->default(7);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE production_resources ADD CONSTRAINT production_resources_valid CHECK (capacity_per_period >= 0 AND period_days > 0)');

        Schema::create('production_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->unsignedBigInteger('output_product_id');
            $table->unsignedBigInteger('branch_id')->nullable()->comment('Sucursal donde se produce; NULL = cualquiera');
            $table->decimal('output_quantity', 16, 6)->comment('Producción nominal por lote, en la unidad del producto');
            $table->decimal('yield_rate', 7, 6)->default(1)->comment('Rendimiento (0,1]: fracción útil de la producción nominal');
            $table->unsignedSmallInteger('production_days')->default(1)->comment('Tiempo desde el inicio del lote hasta que el producto está disponible');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->decimal('resource_usage_per_batch', 16, 6)->default(0)->comment('Consumo del recurso por lote, en capacity_unit del recurso');
            $table->decimal('cost_per_batch', 14, 4)->nullable()->comment('Costo de transformación por lote (sin insumos); NULL = desconocido, se trata como 0 con aviso');
            $table->decimal('max_batches_per_period', 14, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'output_product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'resource_id'])->references(['tenant_id', 'id'])->on('production_resources')->nullOnDelete();
        });
        DB::statement('ALTER TABLE production_recipes ADD CONSTRAINT production_recipes_valid CHECK (output_quantity > 0 AND yield_rate > 0 AND yield_rate <= 1 AND production_days >= 1 AND resource_usage_per_batch >= 0)');

        Schema::create('production_recipe_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('recipe_id');
            $table->unsignedBigInteger('input_product_id');
            $table->decimal('quantity_per_batch', 16, 6)->comment('Insumo consumido por lote, en la unidad del producto');
            $table->timestampsTz();
            $table->unique(['recipe_id', 'input_product_id']);
            $table->foreign(['tenant_id', 'recipe_id'])->references(['tenant_id', 'id'])->on('production_recipes')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'input_product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE production_recipe_lines ADD CONSTRAINT production_recipe_lines_positive CHECK (quantity_per_batch > 0)');

        // Plan de abastecimiento (una ejecución del core supply con operation = plan).
        Schema::create('supply_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_run_id');
            $table->unsignedBigInteger('forecast_run_id')->nullable()->comment('Pronóstico de demanda usado');
            $table->string('objective', 20)->comment('min_cost, max_margin');
            $table->string('coverage_mode', 10)->default('soft')->comment('soft: demanda no atendida permitida con penalización; hard: debe cubrirse');
            $table->string('solver_status', 30)->comment('optimal, feasible_with_gap, time_limit, infeasible, heuristic_fallback, not_solved');
            $table->decimal('mip_gap', 12, 6)->nullable();
            $table->decimal('objective_value', 20, 4)->nullable();
            $table->decimal('solve_seconds', 10, 3)->nullable();
            $table->char('currency', 3);
            $table->decimal('total_purchase_cost', 18, 4)->nullable();
            $table->decimal('total_holding_cost', 18, 4)->nullable();
            $table->decimal('total_shortage_penalty', 18, 4)->nullable();
            $table->decimal('unmet_units_total', 18, 4)->nullable();
            $table->decimal('demand_units_total', 18, 4)->nullable();
            $table->jsonb('details')->default('{}')->comment('Períodos, actividad de restricciones, producción, comparación de ofertas, alternativas, infactibilidad');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'core_run_id']);
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'forecast_run_id'])->references(['tenant_id', 'id'])->on('forecast_runs')->nullOnDelete();
        });

        Schema::create('supply_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supply_plan_id');
            $table->string('line_type', 15)->comment('purchase, production');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('offer_id')->nullable();
            $table->unsignedBigInteger('recipe_id')->nullable();
            $table->decimal('quantity', 18, 4)->comment('En la unidad del producto');
            $table->string('unit', 20)->nullable();
            $table->decimal('offer_quantity', 18, 4)->nullable()->comment('En la unidad de la oferta');
            $table->string('offer_unit', 20)->nullable();
            $table->date('order_date')->comment('Fecha sugerida de emisión (o de inicio del lote)');
            $table->date('expected_receipt_date')->comment('Recepción prevista');
            $table->decimal('unit_cost', 16, 6)->nullable()->comment('Costo puesto en bodega por unidad del producto, en la moneda del plan');
            $table->decimal('line_cost', 18, 4)->nullable();
            $table->char('currency', 3);
            $table->boolean('actionable_now')->default(false)->comment('Fecha de emisión = fecha de corte; las demás se replanifican en cada revisión');
            $table->jsonb('binding_constraints')->default('[]')->comment('Restricciones activas que limitan esta línea');
            $table->text('justification')->nullable();
            $table->jsonb('assumptions')->default('{}');
            $table->timestampsTz();
            $table->index(['tenant_id', 'supply_plan_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'supply_plan_id'])->references(['tenant_id', 'id'])->on('supply_plans')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->nullOnDelete();
            $table->foreign(['tenant_id', 'offer_id'])->references(['tenant_id', 'id'])->on('supplier_offers')->nullOnDelete();
            $table->foreign(['tenant_id', 'recipe_id'])->references(['tenant_id', 'id'])->on('production_recipes')->nullOnDelete();
        });

        // Demanda que el plan NO atiende (por período), con la causa determinista.
        Schema::create('supply_plan_shortfalls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('supply_plan_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('demand', 18, 4);
            $table->decimal('unmet', 18, 4);
            $table->string('reason', 40)->comment('lead_time, budget, supplier_capacity, storage_capacity, resource_capacity, no_offer, mixed');
            $table->index(['tenant_id', 'supply_plan_id']);
            $table->foreign(['tenant_id', 'supply_plan_id'])->references(['tenant_id', 'id'])->on('supply_plans')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
        });

        // Pronóstico de costos: una fila por serie (producto × proveedor) y ejecución.
        Schema::create('cost_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_run_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('cost_source', 20)->comment('purchase_history, sales_cost');
            $table->char('currency', 3);
            $table->string('unit', 20)->nullable();
            $table->string('method', 30)->comment('Método usado para el pronóstico servido');
            $table->string('reference_method', 30)->default('last_value');
            $table->string('quality_status', 30);
            $table->unsignedInteger('n_obs');
            $table->date('last_observed_on')->nullable();
            $table->decimal('last_observed_cost', 16, 6)->nullable();
            $table->jsonb('points')->default('[]')->comment('[{target_date, yhat, reference, lo_80, hi_80}]');
            $table->jsonb('metrics')->nullable()->comment('Holdout temporal por método: mae, wape, bias, n');
            $table->jsonb('availability')->nullable()->comment('Observaciones usadas/excluidas por known_at y regla aplicada');
            $table->jsonb('limitations')->default('[]');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'supplier_id'])->references(['tenant_id', 'id'])->on('suppliers')->nullOnDelete();
        });
        // Un proveedor NULL y otro explícito son series distintas: unicidad por proveedor con COALESCE.
        DB::statement('CREATE UNIQUE INDEX cost_forecasts_series_key ON cost_forecasts (tenant_id, core_run_id, product_id, COALESCE(supplier_id, 0), cost_source, currency)');

        Schema::create('scenario_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('core_run_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150)->nullable();
            $table->unsignedSmallInteger('horizon_days');
            $table->jsonb('request')->comment('Estrategias y trayectorias recibidas');
            $table->jsonb('results')->comment('Comparación de estrategias, clasificación de entradas y coherencia');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'core_run_id']);
            $table->foreign(['tenant_id', 'core_run_id'])->references(['tenant_id', 'id'])->on('core_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_evaluations');
        DB::statement('DROP INDEX IF EXISTS cost_forecasts_series_key');
        Schema::dropIfExists('cost_forecasts');
        Schema::dropIfExists('supply_plan_shortfalls');
        Schema::dropIfExists('supply_plan_lines');
        Schema::dropIfExists('supply_plans');
        Schema::dropIfExists('production_recipe_lines');
        Schema::dropIfExists('production_recipes');
        Schema::dropIfExists('production_resources');
        Schema::dropIfExists('purchase_budgets');
        Schema::dropIfExists('location_capacities');
        Schema::dropIfExists('supplier_offers');
        Schema::dropIfExists('exchange_rates');
    }
};
