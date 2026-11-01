<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * V2.5 · Factor Intelligence y taxonomía dentro de Data Core: CONTRATOS P0 (arquitecto; ADR-V25-16 y ADR-V25-25).
 *
 * P0 = contratos, disponibilidad temporal y aislamiento; el pipeline de factores es desactivable y ninguna familia
 * se activa por defecto. Las integraciones externas y la ablation sectorial son P2.
 *
 * - No se crea un segundo almacén de features: `factor_definitions` es el CATÁLOGO (metadatos V2.1 §24.4) y apunta
 *   al almacenamiento nativo de cada familia (ventas, precios, promociones, calendario, compromisos, inventario) o
 *   a `external_indicator_observations` (series externas), que se amplía con las fechas de V2.1 §24.5.
 * - `external_events`: eventos/alertas extraídos de fuentes (manual, conector o LLM), con procedencia y estado
 *   PROPOSED/VERIFIED/REJECTED/EXPIRED; las alertas oficiales verificadas se muestran aunque no mejoren el error.
 * - `source_versions`: versión/revisión de una fuente con licencia y huella.
 * - `factor_applicability_rules` y `factor_evaluations`: qué factor aplica a qué perfil/categoría/región y qué
 *   demostró la ablation (baseline vs +factor con holdout intacto).
 * - `shared_forecast_grants` (+ bitácora de accesos): contrato V2.1 §10 DISEÑADO y DESACTIVADO; existe para fijar
 *   el contrato y sus pruebas negativas de aislamiento. Un pronóstico compartido NO es un pedido confirmado.
 * - Taxonomía versionada (V2.1 §24.2): `taxonomy_nodes` (Sector → Industria → Categoría → Subcategoría),
 *   `product_taxonomy_assignments` (producto/SKU → nodo con procedencia manual | llm_proposed | verified) y
 *   `tenant_sector_profiles` (perfiles de relevancia por empresa). El SKU canónico nunca se sustituye.
 * tenant_id NULL = catálogo público compartido (solo definiciones y datos públicos; nunca datos privados).
 * Todo aditivo y reversible.
 */
return new class extends Migration
{
    private const FAMILIES = "sales_history, prices_discounts, competition, search_trends, consumer_preferences, weather, epidemiology, animal_plant_health, food_safety_alerts, trade_regulatory, export_statistics, orders_contracts, shared_projections, production_estimates, reviews_satisfaction, capacity_inventory, calendar_events";

    public function up(): void
    {
        Schema::create('source_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = fuente pública');
            $table->string('source_key', 80)->comment('p. ej. inec_ipc, inamhi_pronostico, manual');
            $table->string('version_label', 80);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('retrieved_at')->nullable();
            $table->string('license', 120)->nullable();
            $table->string('terms_url', 500)->nullable();
            $table->char('checksum', 64)->nullable();
            $table->jsonb('coverage')->default('{}')->comment('Cobertura geográfica y temporal declarada');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['source_key', 'published_at']);
        });
        DB::statement('CREATE UNIQUE INDEX source_versions_natural_key ON source_versions (COALESCE(tenant_id, 0), source_key, version_label)');

        Schema::create('factor_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = definición del catálogo público');
            $table->string('family', 40)->comment(self::FAMILIES);
            $table->string('key', 100);
            $table->string('name', 200);
            $table->string('availability_class', 30)->comment('OBSERVED_HISTORICAL, STATIC, KNOWN_IN_ADVANCE, THIRD_PARTY_FORECAST, USER_SCENARIO, UNAVAILABLE_FOR_HORIZON');
            $table->string('storage', 40)->comment('Almacenamiento nativo: sales, product_price_history, promotions, calendar_events, commercial_commitments, inventory_snapshots, external_indicator_observations, external_events');
            $table->unsignedBigInteger('external_indicator_id')->nullable()->comment('Si storage = external_indicator_observations');
            $table->string('unit', 50)->nullable();
            $table->string('frequency', 15)->nullable();
            $table->string('geo_scope', 30)->nullable()->comment('country, region, province, branch, product');
            $table->string('source_key', 80)->nullable();
            $table->string('license', 120)->nullable();
            $table->string('missing_strategy', 30)->default('disable_feature')->comment('disable_feature, last_valid_with_age, unavailable');
            $table->unsignedInteger('max_staleness_days')->nullable()->comment('Antigüedad máxima para STALE');
            $table->unsignedInteger('retention_days')->nullable();
            $table->text('sector_justification')->nullable();
            $table->jsonb('schema')->default('{}');
            $table->boolean('is_active')->default(false)->comment('Ninguna familia se activa por defecto (V2.1 §24.4)');
            $table->boolean('is_informative_alert')->default(false)->comment('Alerta regulatoria/sanitaria: se muestra aunque no mejore el error (V2.1 §24.7)');
            $table->timestampsTz();
            $table->foreign('external_indicator_id')->references('id')->on('external_indicators')->nullOnDelete();
            $table->index(['family', 'is_active']);
        });
        DB::statement('CREATE UNIQUE INDEX factor_definitions_natural_key ON factor_definitions (COALESCE(tenant_id, 0), key)');

        Schema::table('external_indicator_observations', function (Blueprint $table) {
            $table->timestampTz('observed_at')->nullable()->comment('Cuándo se midió/observó el valor');
            $table->timestampTz('available_at')->nullable()->comment('Cuándo Predictily pudo usarlo; NULL = GREATEST(published_at, ingested_at)');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampTz('issued_at')->nullable()->comment('Emisión del pronóstico externo (THIRD_PARTY_FORECAST)');
            $table->unsignedInteger('forecast_horizon_days')->nullable();
            $table->unsignedBigInteger('source_version_id')->nullable();
            $table->string('scope_ref', 80)->nullable()->comment('Región/provincia/sucursal a la que aplica; NULL = alcance del indicador');
            $table->jsonb('quality_flags')->default('[]');
            $table->foreign('source_version_id')->references('id')->on('source_versions')->nullOnDelete();
        });

        Schema::create('external_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = evento público');
            $table->string('family', 40);
            $table->string('event_type', 60);
            $table->string('title', 300);
            $table->text('summary')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('source_ref', 200)->nullable()->comment('ID del documento en la fuente');
            $table->string('authority', 200)->nullable()->comment('Autor u organismo');
            $table->timestampTz('published_at')->nullable()->comment('NULL = sin fecha: no usable como feature (INVALID)');
            $table->timestampTz('available_at')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('jurisdiction', 80)->nullable();
            $table->string('corroboration', 20)->default('single_source')->comment('single_source, corroborated, official');
            $table->string('status', 20)->default('PROPOSED')->comment('PROPOSED, VERIFIED, REJECTED, EXPIRED');
            $table->string('extracted_by', 20)->default('manual')->comment('manual, connector, llm');
            $table->jsonb('extraction')->default('{}')->comment('Salida estructurada validada por JSON Schema (sin PII)');
            $table->jsonb('affects')->default('{}')->comment('{products, categories, regions, taxonomy_nodes}');
            $table->boolean('is_informative_alert')->default(false);
            $table->unsignedBigInteger('source_version_id')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->foreign('source_version_id')->references('id')->on('source_versions')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'published_at']);
        });
        DB::statement("ALTER TABLE external_events ADD CONSTRAINT external_events_status CHECK (status IN ('PROPOSED','VERIFIED','REJECTED','EXPIRED'))");

        Schema::create('taxonomy_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = taxonomía pública compartida');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('level', 20)->comment('sector, industry, category, subcategory');
            $table->string('code', 80);
            $table->string('name', 200);
            $table->unsignedInteger('version')->default(1);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedBigInteger('erp_category_id')->nullable()->comment('Relación con product_categories del tenant');
            $table->jsonb('attributes')->default('{}');
            $table->timestampsTz();
            $table->foreign('parent_id')->references('id')->on('taxonomy_nodes')->restrictOnDelete();
            $table->foreign('erp_category_id')->references('id')->on('product_categories')->nullOnDelete();
            $table->index(['tenant_id', 'level']);
        });
        DB::statement('CREATE UNIQUE INDEX taxonomy_nodes_natural_key ON taxonomy_nodes (COALESCE(tenant_id, 0), code, version)');

        Schema::create('product_taxonomy_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('taxonomy_node_id');
            $table->string('provenance', 20)->comment('manual, llm_proposed, verified');
            $table->decimal('confidence', 5, 4)->nullable()->comment('Heurística o declarada; no es probabilidad calibrada');
            $table->boolean('is_primary')->default(true);
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'product_id']);
            $table->foreign(['tenant_id', 'product_id'])->references(['tenant_id', 'id'])->on('products')->cascadeOnDelete();
            $table->foreign('taxonomy_node_id')->references('id')->on('taxonomy_nodes')->restrictOnDelete();
        });

        Schema::create('tenant_sector_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('profile_key', 80)->comment('Perfil registrado en el motor (platform/extensions.py)');
            $table->unsignedSmallInteger('priority')->default(100)->comment('Menor = mayor prioridad');
            $table->string('scope', 20)->default('tenant')->comment('tenant, branch, category, product');
            $table->string('scope_ref', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'profile_key', 'scope', 'scope_ref']);
        });

        Schema::create('factor_applicability_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete()->comment('NULL = regla del catálogo público');
            $table->unsignedBigInteger('factor_definition_id');
            $table->string('profile_key', 80)->nullable();
            $table->unsignedBigInteger('taxonomy_node_id')->nullable();
            $table->string('region', 80)->nullable();
            $table->string('effect', 20)->default('applicable')->comment('applicable, not_applicable');
            $table->jsonb('rule')->default('{}');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->text('justification')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('factor_definition_id')->references('id')->on('factor_definitions')->cascadeOnDelete();
            $table->foreign('taxonomy_node_id')->references('id')->on('taxonomy_nodes')->cascadeOnDelete();
        });

        Schema::create('factor_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('factor_definition_id')->nullable()->comment('NULL = familia completa o combinación');
            $table->string('family', 40)->nullable();
            $table->jsonb('factor_set')->default('[]')->comment('Claves evaluadas juntas (ablation de combinaciones)');
            $table->string('segment', 80)->nullable();
            $table->unsignedInteger('horizon_days');
            $table->date('cutoff');
            $table->jsonb('baseline_metrics')->default('{}');
            $table->jsonb('candidate_metrics')->default('{}');
            $table->boolean('holdout_untouched')->comment('El holdout final no se usó para elegir variables ni hiperparámetros');
            $table->string('decision', 20)->comment('promote, reject, inconclusive');
            $table->jsonb('cost')->default('{}')->comment('Costo de API/cómputo');
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'family', 'cutoff']);
            $table->foreign('factor_definition_id')->references('id')->on('factor_definitions')->nullOnDelete();
        });

        Schema::create('shared_forecast_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('issuer_tenant_id');
            $table->unsignedBigInteger('recipient_tenant_id');
            $table->string('scope', 20)->comment('product, category');
            $table->jsonb('subjects')->default('[]')->comment('IDs del emisor (productos/categorías) incluidos');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('granularity', 20)->comment('day, week, month');
            $table->jsonb('allowed_content')->default('[]')->comment('p. ej. ["point_forecast","interval_80"]; nunca ventas ni clientes');
            $table->string('forecast_version', 80)->nullable();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->string('purpose', 300);
            $table->string('status', 20)->default('draft')->comment('draft, active, revoked, expired');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->foreign('issuer_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('recipient_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['recipient_tenant_id', 'status']);
            $table->index(['issuer_tenant_id', 'status']);
        });
        DB::statement('ALTER TABLE shared_forecast_grants ADD CONSTRAINT shared_forecast_grants_distinct CHECK (issuer_tenant_id <> recipient_tenant_id)');
        DB::statement('ALTER TABLE shared_forecast_grants ADD CONSTRAINT shared_forecast_grants_period CHECK (period_to >= period_from AND expires_at > issued_at)');

        Schema::create('shared_forecast_grant_accesses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('grant_id');
            $table->unsignedBigInteger('accessed_by_tenant_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30)->comment('read, denied_expired, denied_revoked, denied_scope');
            $table->timestampTz('accessed_at')->useCurrent();
            $table->foreign('grant_id')->references('id')->on('shared_forecast_grants')->cascadeOnDelete();
            $table->foreign('accessed_by_tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['grant_id', 'accessed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_forecast_grant_accesses');
        Schema::dropIfExists('shared_forecast_grants');
        Schema::dropIfExists('factor_evaluations');
        Schema::dropIfExists('factor_applicability_rules');
        Schema::dropIfExists('tenant_sector_profiles');
        Schema::dropIfExists('product_taxonomy_assignments');
        Schema::dropIfExists('taxonomy_nodes');
        Schema::dropIfExists('external_events');
        Schema::table('external_indicator_observations', function (Blueprint $table) {
            $table->dropForeign(['source_version_id']);
            $table->dropColumn(['observed_at', 'available_at', 'valid_from', 'valid_to', 'issued_at', 'forecast_horizon_days', 'source_version_id', 'scope_ref', 'quality_flags']);
        });
        Schema::dropIfExists('factor_definitions');
        Schema::dropIfExists('source_versions');
    }
};
