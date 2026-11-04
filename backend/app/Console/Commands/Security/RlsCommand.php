<?php

declare(strict_types=1);

namespace App\Console\Commands\Security;

use App\Services\Tenancy\TenantRls;
use Illuminate\Console\Command;

/**
 * `php artisan predictily:rls {status|enable|disable}` — RLS de PostgreSQL en las tablas de ventas (V2.5 · W6-A; ADR-V25-15). EXPERIMENTAL y DESACTIVADA por defecto: NO activar en producción
 * hasta resolver los bloqueos de docs/architecture/TENANT_ISOLATION.md §4 (el motor no fija la empresa por transacción, la cuenta de la aplicación debe ser un rol sin privilegios especiales, pooler).
 * `enable` exige `security.rls.enabled` = true (PREDICTILY_RLS_ENABLED): con las políticas activas pero la aplicación sin fijar la empresa, toda consulta devolvería cero filas.
 * Orden de activación y de reversión: docs/architecture/TENANT_ISOLATION.md §RLS.
 */
final class RlsCommand extends Command
{
    protected $signature = 'predictily:rls {action=status : status | enable | disable}';

    protected $description = 'RLS de PostgreSQL (defensa adicional de aislamiento por empresa) en las tablas de ventas';

    public function handle(TenantRls $rls): int
    {
        $action = (string) $this->argument('action');
        if (! in_array($action, ['status', 'enable', 'disable'], true)) {
            $this->error('Acción desconocida: use status, enable o disable.');

            return self::INVALID;
        }
        if ($action === 'enable' && ! TenantRls::enabled()) {
            $this->error('La bandera security.rls.enabled (PREDICTILY_RLS_ENABLED) está apagada: con las políticas activas y la aplicación sin fijar la empresa, toda consulta devolvería cero filas. Actívela y reinicie la aplicación antes de ejecutar enable.');

            return self::FAILURE;
        }
        $result = match ($action) {
            'enable' => $rls->enable(),
            'disable' => $rls->disable(),
            default => null,
        };
        if ($result !== null) {
            $this->info(($action === 'enable' ? 'RLS activado en: ' : 'RLS desactivado en: ').implode(', ', $result));
        }
        $this->line('Bandera de aplicación: '.(TenantRls::enabled() ? 'activa' : 'apagada'));
        $this->table(['tabla', 'RLS', 'FORCE', 'política'], collect($rls->status())->map(fn (array $s, string $t): array => [$t, $s['rls'] ? 'sí' : 'no', $s['forced'] ? 'sí' : 'no', $s['policy'] ? 'sí' : 'no'])->values()->all());

        return self::SUCCESS;
    }
}
