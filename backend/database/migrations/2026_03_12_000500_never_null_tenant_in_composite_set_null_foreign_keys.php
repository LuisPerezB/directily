<?php

use App\Support\Database\CompositeForeignKeys;
use Illuminate\Database\Migrations\Migration;

/**
 * V2.5 · arquitecto: 2026_03_12_000400 solo reescribía las FKs compuestas con una columna NOT NULL. En `model_registry`,
 * `tenant_id` es NULLABLE (NULL = modelo de plataforma) y sus FKs (tenant_id, previous_model_registry_id) y
 * (tenant_id, promotion_decision_id) seguían anulando también `tenant_id`: borrar el modelo anterior o la decisión de
 * promoción convertía el modelo de la empresa en un modelo de plataforma. Se reescriben para anular solo la referencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        CompositeForeignKeys::setNullOnlyReferences();
    }

    public function down(): void
    {
        CompositeForeignKeys::restorePlainSetNull(onlyNullableTenant: true);
    }
};
