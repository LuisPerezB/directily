<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2.5 · W6-D (ADR-V25-17): la auditoría del gateway (`gateway_tool_calls`) es de SOLO INSERCIÓN también para la base de datos, no solo para el modelo Eloquent.
 *
 * - BEFORE UPDATE: se rechaza, salvo el efecto de la restricción `ON DELETE SET NULL` de `user_id` (borrado de una persona): una actualización ejecutada DENTRO de otro
 *   disparador (pg_trigger_depth() > 1) que solo pone `user_id` en NULL y deja todo lo demás igual.
 * - BEFORE DELETE: se rechaza, salvo (a) la cascada del borrado de la empresa (`ON DELETE CASCADE`, también dentro de otro disparador: pg_trigger_depth() > 1) y (b) la ruta
 *   explícita de retención o borrado por derecho de supresión, que fija la variable de transacción `predictily.audit_purge = 'on'` (AuditRetention::purge()).
 *
 * LÍMITE (honesto): la variable de la ruta (b) la puede fijar cualquier código con acceso a la base de datos, igual que cualquier sentencia SQL: esto protege contra accidentes y
 * contra rutas de código que olvidan que la auditoría no se edita; NO protege contra alguien con SQL arbitrario ni contra el propietario de la tabla (que puede
 * desactivar el disparador). Una defensa contra eso exigiría un rol de base de datos sin permiso UPDATE/DELETE para la aplicación (DISEÑADO, depende del despliegue).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION gateway_tool_calls_guard() RETURNS trigger AS $fn$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        IF pg_trigger_depth() > 1
           AND OLD.user_id IS NOT NULL AND NEW.user_id IS NULL
           AND (NEW.id, NEW.tenant_id, NEW.channel, NEW.tool, NEW.decision, NEW.arguments, NEW.result_summary, NEW.latency_ms, NEW.created_at)
               IS NOT DISTINCT FROM (OLD.id, OLD.tenant_id, OLD.channel, OLD.tool, OLD.decision, OLD.arguments, OLD.result_summary, OLD.latency_ms, OLD.created_at) THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'gateway_tool_calls es de solo inserción: UPDATE rechazado' USING ERRCODE = 'restrict_violation';
    END IF;

    IF TG_OP = 'DELETE' THEN
        IF pg_trigger_depth() > 1 OR COALESCE(current_setting('predictily.audit_purge', true), '') = 'on' THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'gateway_tool_calls es de solo inserción: DELETE rechazado (use AuditRetention::purge)' USING ERRCODE = 'restrict_violation';
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;
SQL);
        DB::unprepared('DROP TRIGGER IF EXISTS gateway_tool_calls_guard_update ON gateway_tool_calls');
        DB::unprepared('CREATE TRIGGER gateway_tool_calls_guard_update BEFORE UPDATE ON gateway_tool_calls FOR EACH ROW EXECUTE FUNCTION gateway_tool_calls_guard()');
        DB::unprepared('DROP TRIGGER IF EXISTS gateway_tool_calls_guard_delete ON gateway_tool_calls');
        DB::unprepared('CREATE TRIGGER gateway_tool_calls_guard_delete BEFORE DELETE ON gateway_tool_calls FOR EACH ROW EXECUTE FUNCTION gateway_tool_calls_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS gateway_tool_calls_guard_update ON gateway_tool_calls');
        DB::unprepared('DROP TRIGGER IF EXISTS gateway_tool_calls_guard_delete ON gateway_tool_calls');
        DB::unprepared('DROP FUNCTION IF EXISTS gateway_tool_calls_guard()');
    }
};
