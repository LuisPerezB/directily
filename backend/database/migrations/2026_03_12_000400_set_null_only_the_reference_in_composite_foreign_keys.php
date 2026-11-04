<?php

use App\Support\Database\CompositeForeignKeys;
use Illuminate\Database\Migrations\Migration;

/**
 * V2.5 · arquitecto: las FKs compuestas (tenant_id, x_id) con ON DELETE SET NULL anulaban también tenant_id (NOT NULL) y el
 * borrado en firme de la fila referenciada fallaba (defecto previo desde la etapa 2, visto al revisar W4-A). Se reescriben como
 * ON DELETE SET NULL (x_id) (PostgreSQL 15+). Las migraciones posteriores que creen FKs así llaman al mismo ayudante.
 */
return new class extends Migration
{
    public function up(): void
    {
        CompositeForeignKeys::setNullOnlyReferences();
    }

    public function down(): void
    {
        CompositeForeignKeys::restorePlainSetNull();
    }
};
