<?php

declare(strict_types=1);

namespace App\Services\Intelligence\SharedGrants;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Exportación LOPDP de las concesiones de pronóstico compartido (V2.1 §10; ADR-V25-25).
 *
 * `shared_forecast_grants` y su bitácora NO tienen `tenant_id`: pertenecen a DOS empresas (emisora y receptora), por lo que no caben en la
 * clasificación TABLES/CHILD_TABLES de TenantDataExporter (que exige un único propietario). Decisión, aplicada con la misma regla a ambas partes:
 * cada empresa exporta lo que le corresponde como parte y NADA que pertenezca a la otra:
 *
 * - EMISOR  → `shared_forecast_grants_issued.csv`: el contrato completo de lo que concedió (incluye sus propios `subjects`, `created_by`, `revoked_by`),
 *   con el receptor identificado por su UUID (no por su identificador interno). `shared_forecast_grant_accesses_on_issued.csv`: la bitácora sobre
 *   sus concesiones (acción, momento y quién: `recipient` u `other_company`); NUNCA el usuario ni el identificador de la empresa que accedió.
 * - RECEPTOR → `shared_forecast_grants_received.csv`: las concesiones recibidas (sin borradores) con el emisor identificado por su UUID y el
 *   NÚMERO de elementos concedidos, no los identificadores internos del emisor ni sus usuarios. `shared_forecast_grant_accesses_made.csv`: los accesos
 *   que hizo su propia empresa, con sus propios usuarios; el `grant_id` solo se informa de las concesiones de las que la empresa es parte (el intento de un
 *   tercero sobre una concesión ajena conserva acción y momento, no el identificador de ese contrato).
 *
 * Se exportan aunque la bandera de concesiones esté apagada: la portabilidad de los datos que existan no depende de la función. Nunca contienen
 * ventas, clientes ni pronósticos del emisor (la concesión no los guarda; solo el contrato).
 */
final class SharedForecastGrantExport
{
    /** archivo → columnas (en el orden de la consulta). */
    public const FILES = [
        'shared_forecast_grants_issued' => ['id', 'recipient_uuid', 'scope', 'subjects', 'period_from', 'period_to', 'granularity', 'allowed_content', 'forecast_version', 'issued_at', 'expires_at', 'purpose', 'status', 'revoked_at', 'revoked_by', 'created_by', 'created_at', 'updated_at'],
        'shared_forecast_grant_accesses_on_issued' => ['id', 'grant_id', 'actor', 'action', 'accessed_at'],
        'shared_forecast_grants_received' => ['id', 'issuer_uuid', 'scope', 'subjects_count', 'period_from', 'period_to', 'granularity', 'allowed_content', 'forecast_version', 'issued_at', 'expires_at', 'purpose', 'status', 'revoked_at', 'created_at', 'updated_at'],
        'shared_forecast_grant_accesses_made' => ['id', 'grant_id', 'user_id', 'action', 'accessed_at'],
    ];

    /**
     * Agrega los CSV de concesiones de la empresa al ZIP. Los temporales se registran en `$tmpFiles` para que quien llama los borre.
     *
     * @param  list<string>  $tmpFiles
     * @return array<string, int> filas por archivo (para el resumen de la exportación)
     */
    public function addTo(ZipArchive $zip, int $tenantId, array &$tmpFiles): array
    {
        $summary = [];
        foreach (self::FILES as $name => $columns) {
            $tmp = tempnam(sys_get_temp_dir(), 'pexp');
            $tmpFiles[] = $tmp;
            $out = fopen($tmp, 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ',', '"', '');
            $count = 0;
            foreach ($this->query($name, $tenantId)->cursor() as $row) {
                fputcsv($out, array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, (array) $row), ',', '"', '');
                $count++;
            }
            fclose($out);
            $zip->addFile($tmp, $name.'.csv');
            $summary[$name] = $count;
        }

        return $summary;
    }

    private function query(string $name, int $tenantId): Builder
    {
        return match ($name) {
            'shared_forecast_grants_issued' => DB::table('shared_forecast_grants as g')
                ->join('tenants as r', 'r.id', '=', 'g.recipient_tenant_id')->where('g.issuer_tenant_id', $tenantId)->orderBy('g.id')
                ->selectRaw('g.id, r.uuid as recipient_uuid, g.scope, g.subjects, g.period_from, g.period_to, g.granularity, g.allowed_content, g.forecast_version, g.issued_at, g.expires_at, g.purpose, g.status, g.revoked_at, g.revoked_by, g.created_by, g.created_at, g.updated_at'),
            'shared_forecast_grant_accesses_on_issued' => DB::table('shared_forecast_grant_accesses as a')
                ->join('shared_forecast_grants as g', 'g.id', '=', 'a.grant_id')->where('g.issuer_tenant_id', $tenantId)->orderBy('a.id')
                ->selectRaw("a.id, a.grant_id, CASE WHEN a.accessed_by_tenant_id = g.recipient_tenant_id THEN 'recipient' WHEN a.accessed_by_tenant_id = g.issuer_tenant_id THEN 'issuer' ELSE 'other_company' END as actor, a.action, a.accessed_at"),
            'shared_forecast_grants_received' => DB::table('shared_forecast_grants as g')
                ->join('tenants as i', 'i.id', '=', 'g.issuer_tenant_id')->where('g.recipient_tenant_id', $tenantId)->where('g.status', '<>', 'draft')->orderBy('g.id')
                ->selectRaw('g.id, i.uuid as issuer_uuid, g.scope, jsonb_array_length(g.subjects) as subjects_count, g.period_from, g.period_to, g.granularity, g.allowed_content, g.forecast_version, g.issued_at, g.expires_at, g.purpose, g.status, g.revoked_at, g.created_at, g.updated_at'),
            // Un intento sobre una concesión de la que la empresa NO es parte (emisora ni receptora) conserva la acción y el momento, pero NO el identificador de esa
            // concesión: sería conocer el id de un contrato ajeno.
            'shared_forecast_grant_accesses_made' => DB::table('shared_forecast_grant_accesses as a')
                ->leftJoin('shared_forecast_grants as g', 'g.id', '=', 'a.grant_id')
                ->where('a.accessed_by_tenant_id', $tenantId)->orderBy('a.id')
                ->selectRaw('a.id, CASE WHEN g.issuer_tenant_id = ? OR g.recipient_tenant_id = ? THEN a.grant_id END as grant_id, a.user_id, a.action, a.accessed_at', [$tenantId, $tenantId]),
        };
    }
}
