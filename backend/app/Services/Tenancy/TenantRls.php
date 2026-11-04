<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * RLS de PostgreSQL como DEFENSA ADICIONAL de aislamiento por empresa (V2.5 · W6-A; ADR-V25-15). No sustituye la validación de la aplicación (BelongsToTenant, FKs compuestas,
 * middleware): es una segunda barrera por si una consulta se escribe sin el filtro de empresa. EXPERIMENTAL y DESACTIVADA por defecto (`security.rls.enabled`); se evalúa en las
 * tablas de ventas. NO está lista para producción: ver los bloqueos en docs/architecture/TENANT_ISOLATION.md §4.
 *
 * Funcionamiento:
 * - La aplicación fija `app.tenant_id` en la CONEXIÓN cada vez que cambia la empresa del contexto (`TenantContext::set/forget/runAs`) mientras la bandera esté activa, y lo
 *   VUELVE a fijar tras cualquier rollback (un `SET` dentro de una transacción revertida se deshace: sin esto la conexión y el contexto de la aplicación divergirían).
 * - `enable()` crea en cada tabla piloto la política `predictily_tenant_isolation` (USING y WITH CHECK: `tenant_id = app.tenant_id`) y la activa con FORCE ROW LEVEL SECURITY (sin
 *   FORCE, el dueño de la tabla —el usuario de la aplicación— esquivaría las políticas). Sin contexto de empresa NO se devuelve ninguna fila y no se puede insertar.
 * - NO hay puerta de plataforma dentro de la sesión: un parámetro de sesión que el propio rol de la aplicación pudiera fijar (`app.rls_bypass = on`) anularía la defensa justo
 *   contra el error que debe atrapar (código de la aplicación que no debería hacerlo). Las tareas de plataforma sin empresa (mantenimiento, `withoutTenantScope()`) deben conectarse
 *   con OTRO rol de base de datos con `BYPASSRLS` o ser dueño de la tabla sin FORCE: DISEÑADO / NO IMPLEMENTADO (ver el documento).
 *
 * Límites (documentados en docs/architecture/TENANT_ISOLATION.md §4): no protege contra un superusuario ni un rol con BYPASSRLS (docker-compose usa un superusuario: allí RLS no hace
 * nada); el motor Python no fija `app.tenant_id` por transacción y quedaría sin filas; con un pooler externo en modo transacción el valor de sesión puede filtrarse entre clientes;
 * solo cubre las tablas piloto.
 */
final class TenantRls
{
    public const POLICY = 'predictily_tenant_isolation';

    public static function enabled(): bool
    {
        return (bool) config('security.rls.enabled', false);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_values(array_map('strval', (array) config('security.rls.tables', [])));
    }

    /**
     * Fija (o limpia con null) la empresa en la conexión. No hace nada con la bandera apagada. NUNCA lanza: si la conexión está en una transacción abortada (un error SQL previo), el
     * `finally` de `TenantContext::runAs` no debe ocultar el error original con «current transaction is aborted»; la empresa se vuelve a fijar en el rollback (`reassert`). Hasta
     * entonces la conexión no puede ejecutar nada, de modo que no hay ventana en la que otra empresa lea con un valor viejo.
     */
    public static function sync(?int $tenantId): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            DB::select("select set_config('app.tenant_id', ?, false)", [$tenantId === null ? '' : (string) $tenantId]);
        } catch (Throwable $e) {
            Log::warning('RLS: no se pudo fijar la empresa en la conexión; se reintenta al terminar la transacción.', ['tenant_id' => $tenantId, 'error' => mb_substr($e->getMessage(), 0, 120)]);
        }
    }

    /** Vuelve a fijar la empresa VIGENTE del contexto (tras un rollback, que deshace los `SET` hechos dentro de la transacción). */
    public static function reassert(): void
    {
        if (self::enabled()) {
            self::sync(app(TenantContext::class)->id());
        }
    }

    /** Crea las políticas y activa RLS (FORCE) en las tablas piloto. Idempotente. @return list<string> las tablas activadas */
    public function enable(): array
    {
        $done = [];
        foreach ($this->tables() as $table) {
            $this->assertIdentifier($table);
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$table}");
            $check = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";
            DB::statement('CREATE POLICY '.self::POLICY." ON {$table} FOR ALL USING ({$check}) WITH CHECK ({$check})");
            $done[] = $table;
        }

        return $done;
    }

    /** Quita las políticas y desactiva RLS en las tablas piloto. Idempotente. @return list<string> */
    public function disable(): array
    {
        $done = [];
        foreach ($this->tables() as $table) {
            $this->assertIdentifier($table);
            DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            $done[] = $table;
        }

        return $done;
    }

    /** @return array<string, array{rls: bool, forced: bool, policy: bool}> por tabla */
    public function status(): array
    {
        $out = [];
        foreach ($this->tables() as $table) {
            $this->assertIdentifier($table);
            $row = DB::selectOne('SELECT c.relrowsecurity AS rls, c.relforcerowsecurity AS forced, EXISTS (SELECT 1 FROM pg_policy p WHERE p.polrelid = c.oid AND p.polname = ?) AS policy FROM pg_class c WHERE c.oid = to_regclass(?)', [self::POLICY, $table]);
            $out[$table] = ['rls' => (bool) ($row->rls ?? false), 'forced' => (bool) ($row->forced ?? false), 'policy' => (bool) ($row->policy ?? false)];
        }

        return $out;
    }

    private function assertIdentifier(string $table): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException('Nombre de tabla no válido para RLS.');
        }
    }
}
