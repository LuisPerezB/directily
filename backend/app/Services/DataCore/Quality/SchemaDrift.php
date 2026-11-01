<?php

declare(strict_types=1);

namespace App\Services\DataCore\Quality;

use App\Models\BronzeObject;
use App\Services\Imports\Parsing\ValueParser;
use Illuminate\Support\Facades\DB;

/**
 * Deriva de esquema por fuente y entidad (ADR-V25-24; W2-A).
 *
 * - Huella = SHA-256 de los encabezados NORMALIZADOS y ORDENADOS (clave sin tildes, en minúsculas); en SQL, además el tipo observado de
 *   cada columna.
 * - Se compara con la huella de la carga anterior de la misma fuente Y entidad (la fuente puede entregar varias entidades con columnas
 *   distintas: comparar entre entidades daría deriva falsa). La carga anterior es el último objeto Bronze de la fuente y entidad; sin
 *   `data_source_id`, la última carga SIN fuente de la misma entidad de la empresa. Ahí también queda la lista de columnas (metadatos).
 * - Si cambia: sube la versión de esquema, el objeto Bronze guarda huella y versión y se registra un WARNING `schema_drift` con las
 *   columnas agregadas, quitadas y con tipo cambiado (SIN valores). `data_sources.schema_fingerprint` guarda la huella combinada de las
 *   entidades de la fuente y `data_sources.schema_version` sube con cada deriva.
 * - Limitación: en SQL los tipos son los del valor que entrega el controlador (entero, decimal, texto, fecha, booleano); un numeric de
 *   PostgreSQL llega como texto, así que un cambio numeric → varchar no se detecta. Un tipo aún desconocido (columna toda NULL en la
 *   extracción) no cuenta como cambio.
 */
final class SchemaDrift
{
    /**
     * @param  array<string, ?string>  $columns  encabezado original => tipo observado (null = no se observa: archivos)
     * @return array{fingerprint: string, version: int, columns: array<string, string>, drift: ?array{previous_fingerprint: string, previous_version: int, added: list<string>, removed: list<string>, retyped: list<string>}}
     */
    public function observe(?int $dataSourceId, string $entity, array $columns): array
    {
        $normalized = $this->normalize($columns);
        $fingerprint = $this->fingerprint($normalized);

        $previous = BronzeObject::query()
            ->where('entity', $entity)
            ->when($dataSourceId !== null, fn ($q) => $q->where('data_source_id', $dataSourceId), fn ($q) => $q->whereNull('data_source_id'))
            ->whereNotNull('schema_fingerprint')
            ->orderByDesc('id')
            ->first(['id', 'schema_fingerprint', 'schema_version', 'metadata']);

        if ($previous === null) {
            return ['fingerprint' => $fingerprint, 'version' => 1, 'columns' => $normalized, 'drift' => null];
        }
        $previousVersion = max(1, (int) $previous->schema_version);
        if (hash_equals((string) $previous->schema_fingerprint, $fingerprint)) {
            return ['fingerprint' => $fingerprint, 'version' => $previousVersion, 'columns' => $normalized, 'drift' => null];
        }
        $before = (array) (($previous->metadata ?? [])['columns'] ?? []);
        if ($before === []) {
            // Carga anterior sin lista de columnas (anterior a esta versión): no hay con qué comparar, se toma como línea base.
            return ['fingerprint' => $fingerprint, 'version' => $previousVersion, 'columns' => $normalized, 'drift' => null];
        }
        $added = array_values(array_diff(array_keys($normalized), array_keys($before)));
        $removed = array_values(array_diff(array_keys($before), array_keys($normalized)));
        $retyped = [];
        foreach (array_intersect_key($normalized, $before) as $name => $type) {
            $old = (string) $before[$name];
            if ($type !== '' && $old !== '' && $type !== $old) {
                $retyped[] = $name;
            }
        }
        if ($added === [] && $removed === [] && $retyped === []) {
            return ['fingerprint' => $fingerprint, 'version' => $previousVersion, 'columns' => $normalized, 'drift' => null]; // solo un tipo antes desconocido
        }
        sort($added);
        sort($removed);
        sort($retyped);

        return [
            'fingerprint' => $fingerprint,
            'version' => $previousVersion + 1,
            'columns' => $normalized,
            'drift' => ['previous_fingerprint' => (string) $previous->schema_fingerprint, 'previous_version' => $previousVersion, 'added' => $added, 'removed' => $removed, 'retyped' => $retyped],
        ];
    }

    /**
     * Refleja la observación en la fuente: huella combinada de las entidades y versión (sube con la deriva). Idempotente por objeto: se
     * llama una sola vez, al guardar el objeto Bronze NUEVO.
     */
    public function recordOnSource(int $tenantId, int $dataSourceId, bool $drifted): void
    {
        $latest = DB::select(
            'SELECT DISTINCT ON (entity) entity, schema_fingerprint FROM bronze_objects WHERE tenant_id = ? AND data_source_id = ? AND schema_fingerprint IS NOT NULL ORDER BY entity, id DESC',
            [$tenantId, $dataSourceId],
        );
        $parts = array_map(fn ($r) => $r->entity.':'.$r->schema_fingerprint, $latest);
        sort($parts);
        DB::table('data_sources')->where('tenant_id', $tenantId)->where('id', $dataSourceId)->update([
            'schema_fingerprint' => hash('sha256', implode("\n", $parts)),
            'schema_version' => DB::raw('schema_version + '.($drifted ? 1 : 0)),
        ]);
    }

    /**
     * @param  array<string, ?string>  $columns
     * @return array<string, string> clave normalizada => tipo ('' si no se observa)
     */
    private function normalize(array $columns): array
    {
        $out = [];
        $position = 0;
        foreach ($columns as $name => $type) {
            $position++;
            $key = ValueParser::key((string) $name);
            $key = $key !== '' ? $key : 'columna_'.$position;
            $suffix = 2;
            $unique = $key;
            while (isset($out[$unique])) {
                $unique = $key.'#'.$suffix++;
            }
            $out[$unique] = $type === null ? '' : (string) $type;
        }
        ksort($out);

        return $out;
    }

    /** @param array<string, string> $normalized */
    private function fingerprint(array $normalized): string
    {
        $lines = [];
        foreach ($normalized as $name => $type) {
            $lines[] = $type === '' ? $name : $name.':'.$type;
        }

        return hash('sha256', implode("\n", $lines));
    }
}
