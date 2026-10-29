<?php

declare(strict_types=1);

namespace App\Services\Imports\Processors;

use App\Services\Catalog\CatalogResolver;
use App\Services\Imports\Parsing\ValueParser;
use App\Services\Imports\Support\Issue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Eventos de calendario de la empresa con alcance (país, región, empresa, sucursal, categoría, producto, sector).
 * Identidad: (alcance, referencia, código, inicio). Un cambio de nombre/categoría/fin/estado agrega una REVISIÓN
 * (conserva lo que se sabía antes). `known_at` NULL = desconocido: nunca se rellena con la fecha de la carga, y un
 * evento sin `known_at` no debe usarse como conocido a futuro en un backtest.
 */
final class CalendarEventImportProcessor implements ImportProcessor
{
    private const SCOPES = [
        'country' => ['country', 'pais', 'nacional'],
        'region' => ['region', 'zona', 'regional'],
        'tenant' => ['tenant', 'empresa', 'compania', 'company'],
        'branch' => ['branch', 'sucursal', 'local', 'establecimiento'],
        'category' => ['category', 'categoria', 'linea', 'familia'],
        'product' => ['product', 'producto', 'sku', 'articulo'],
        'sector' => ['sector', 'rubro', 'industria'],
    ];

    private const CATEGORIES = [
        'holiday' => ['holiday', 'feriado', 'feriado_nacional', 'festivo'],
        'local_holiday' => ['local_holiday', 'feriado_local', 'feriado_regional', 'festivo_local'],
        'commercial_season' => ['commercial_season', 'temporada', 'temporada_comercial', 'temporada_alta'],
        'campaign' => ['campaign', 'campana', 'campana_comercial'],
        'promotion' => ['promotion', 'promocion'],
        'sector_event' => ['sector_event', 'evento_sectorial', 'evento_del_sector', 'evento'],
        'agricultural_season' => ['agricultural_season', 'temporada_agricola', 'ciclo_agricola', 'siembra', 'cosecha'],
        'international_peak' => ['international_peak', 'pico_internacional', 'evento_internacional'],
        'exceptional' => ['exceptional', 'extraordinario', 'excepcional', 'evento_extraordinario'],
    ];

    private const STATUSES = [
        'planned' => ['planned', 'planeado', 'planificado', 'previsto', 'tentativo'],
        'confirmed' => ['confirmed', 'confirmado', 'oficial', 'definitivo'],
        'cancelled' => ['cancelled', 'canceled', 'cancelado', 'suspendido', 'anulado'],
    ];

    public function run(ImportRunContext $ctx): array
    {
        $tenant = $ctx->tenant;
        $p = $ctx->parser;
        $tz = $tenant->timezone ?: 'America/Guayaquil';
        $catalog = new CatalogResolver($tenant, array_merge($ctx->options, ['create_missing_products' => false, 'create_missing_branches' => false]));
        $maxKnown = CarbonImmutable::now($tz)->endOfDay();
        $total = $ctx->countRows();
        $seen = [];
        $valid = [];

        foreach ($ctx->canonicalRows() as $n => $raw) {
            $issues = [];
            $natural = null;
            $scope = $this->enumValue(self::SCOPES, $p->string($raw['scope'] ?? null, 30));
            if ($scope === null) {
                $issues[] = ValueParser::isBlank($raw['scope'] ?? null)
                    ? Issue::error('required', 'Falta el alcance del evento.', 'scope')
                    : Issue::error('invalid_value', 'Alcance no reconocido. Use country, region, tenant, branch, category, product o sector.', 'scope', $raw['scope']);
            }
            $ref = $this->scopeRef($scope, $p->string($raw['scope_ref'] ?? null, 80), $catalog, $issues);
            $code = $p->string($raw['code'] ?? null, 80);
            if ($code === null) {
                $issues[] = Issue::error('required', 'Falta el código del evento.', 'code');
            }
            $name = $p->string($raw['name'] ?? null, 200);
            if ($name === null) {
                $issues[] = Issue::error('required', 'Falta el nombre del evento.', 'name');
            }
            $category = $this->enumValue(self::CATEGORIES, $p->string($raw['category'] ?? null, 40));
            if ($category === null) {
                $issues[] = ValueParser::isBlank($raw['category'] ?? null)
                    ? Issue::error('required', 'Falta la categoría del evento.', 'category')
                    : Issue::error('invalid_value', 'Categoría no reconocida.', 'category', $raw['category']);
            }
            $starts = ($parsed = $p->dateTime($raw['starts_on'] ?? null)) !== null ? $parsed[0]->toDateString() : null;
            if ($starts === null) {
                $issues[] = ValueParser::isBlank($raw['starts_on'] ?? null)
                    ? Issue::error('required', 'Falta la fecha de inicio.', 'starts_on')
                    : Issue::error('invalid_date', 'Fecha de inicio no válida.', 'starts_on', $raw['starts_on']);
            }
            $ends = $starts;
            if (! ValueParser::isBlank($raw['ends_on'] ?? null)) {
                $ends = ($parsed = $p->dateTime($raw['ends_on'])) !== null ? $parsed[0]->toDateString() : null;
                if ($ends === null) {
                    $issues[] = Issue::error('invalid_date', 'Fecha de fin no válida.', 'ends_on', $raw['ends_on']);
                } elseif ($starts !== null && $ends < $starts) {
                    $issues[] = Issue::error('invalid_date', 'El evento termina antes de empezar.', 'ends_on', $raw['ends_on']);
                }
            }
            $rawStatus = $p->string($raw['status'] ?? null, 30);
            $status = $rawStatus === null ? 'confirmed' : $this->enumValue(self::STATUSES, $rawStatus);
            if ($status === null) {
                $issues[] = Issue::error('invalid_value', 'Estado no reconocido. Use planned, confirmed o cancelled.', 'status', $rawStatus);
            }
            $knownAt = null;
            if (! ValueParser::isBlank($raw['known_at'] ?? null)) {
                $parsed = $p->dateTime($raw['known_at']);
                if ($parsed === null) {
                    $issues[] = Issue::error('invalid_date', 'Fecha desde la que se conocía el evento no válida.', 'known_at', $raw['known_at']);
                } elseif ($parsed[0]->greaterThan($maxKnown)) {
                    $issues[] = Issue::error('future_date', 'La fecha desde la que se conocía el evento no puede ser futura.', 'known_at', $raw['known_at']);
                } else {
                    $knownAt = $parsed[0]->utc()->format('Y-m-d H:i:sP');
                    if ($starts !== null && $parsed[0]->toDateString() > $starts) {
                        $issues[] = Issue::warning('known_after_start', 'El evento se conoció después de su inicio: no sirve como conocimiento previo.', 'known_at', $raw['known_at']);
                    }
                }
            } else {
                $issues[] = Issue::warning('known_at_unknown', 'Sin fecha de conocimiento: el evento no se usará como conocido a futuro en un corte histórico.', 'known_at');
            }
            if ($scope !== null && $code !== null && $starts !== null) {
                $natural = implode('|', [$scope, $ref ?? '', mb_strtoupper($code), $starts]);
                if (isset($seen[$natural])) {
                    $issues[] = Issue::error('duplicate_in_file', 'Evento repetido (mismo alcance, referencia, código e inicio).', 'code', $code);
                }
            }
            $ctx->issues->addMany($n, $issues);
            if (! $ctx->issues->rowHasError($n)) {
                if ($natural !== null) {
                    $seen[$natural] = true;
                }
                $valid[] = ['scope' => $scope, 'scope_ref' => $ref, 'code' => $code, 'name' => $name, 'category' => $category, 'starts_on' => $starts,
                    'ends_on' => $ends, 'status' => $status, 'known_at' => $knownAt, 'source' => $p->string($raw['source'] ?? null, 50) ?? 'import'];
            }
            $ctx->progress($n, $total, 5, 60);
        }
        $ctx->issues->flush();

        $existing = [];
        foreach (array_chunk(array_values(array_unique(array_column($valid, 'code'))), 500) as $chunk) {
            foreach (DB::table('calendar_events')->where('tenant_id', $tenant->id)->whereIn('code', $chunk)->orderBy('revision')
                ->get(['id', 'scope', 'scope_ref', 'code', 'starts_on', 'revision', 'name', 'category', 'ends_on', 'status', 'known_at']) as $row) {
                $existing[implode('|', [$row->scope, $row->scope_ref ?? '', mb_strtoupper($row->code), substr((string) $row->starts_on, 0, 10)])] = $row; // la última revisión gana
            }
        }
        $stats = ['rows_total' => $total, 'rows_error' => $ctx->issues->rowsWithErrors(), 'rows_warning' => $ctx->issues->rowsWithWarnings(), 'rows_valid' => count($valid),
            'events_new' => 0, 'events_revised' => 0, 'events_known_at_updated' => 0, 'events_unchanged' => 0, 'products_new' => 0, 'branches_new' => 0,
            'normalized_preview' => array_slice($valid, 0, 20)];
        $plan = [];
        foreach ($valid as $event) {
            $key = implode('|', [$event['scope'], $event['scope_ref'] ?? '', mb_strtoupper($event['code']), $event['starts_on']]);
            $current = $existing[$key] ?? null;
            if ($current === null) {
                $stats['events_new']++;
                $plan[] = ['new', $event, null];

                continue;
            }
            $contentChanged = $current->name !== $event['name'] || $current->category !== $event['category']
                || substr((string) $current->ends_on, 0, 10) !== $event['ends_on'] || $current->status !== $event['status'];
            $currentKnown = $current->known_at !== null ? CarbonImmutable::parse((string) $current->known_at)->getTimestamp() : null;
            $newKnown = $event['known_at'] !== null ? CarbonImmutable::parse($event['known_at'])->getTimestamp() : null;
            if ($contentChanged) {
                $stats['events_revised']++;
                $plan[] = ['revise', $event, $current];
            } elseif ($newKnown !== null && ($currentKnown === null || $newKnown < $currentKnown)) {
                $stats['events_known_at_updated']++;
                $plan[] = ['known', $event, $current];
            } else {
                $stats['events_unchanged']++;
            }
        }

        if ($ctx->commit && $plan !== []) {
            DB::transaction(function () use ($plan, $tenant): void {
                $now = now()->format('Y-m-d H:i:sP');
                foreach ($plan as [$action, $event, $current]) {
                    $row = ['name' => $event['name'], 'category' => $event['category'], 'ends_on' => $event['ends_on'], 'status' => $event['status'], 'known_at' => $event['known_at'], 'source' => $event['source']];
                    if ($action === 'known') {
                        DB::table('calendar_events')->where('tenant_id', $tenant->id)->where('id', $current->id)->update(['known_at' => $event['known_at'], 'updated_at' => $now]);

                        continue;
                    }
                    DB::table('calendar_events')->insert($row + [
                        'tenant_id' => $tenant->id, 'scope' => $event['scope'], 'scope_ref' => $event['scope_ref'], 'code' => $event['code'], 'starts_on' => $event['starts_on'],
                        'revision' => $action === 'new' ? 0 : ((int) $current->revision + 1), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            });
        }

        return ['stats' => $stats, 'issues' => $ctx->issues->summary()];
    }

    /** @param list<Issue> $issues */
    private function scopeRef(?string $scope, ?string $raw, CatalogResolver $catalog, array &$issues): ?string
    {
        if ($scope === null) {
            return null;
        }
        if ($scope === 'tenant') {
            if ($raw !== null) {
                $issues[] = Issue::warning('scope_ref_ignored', 'El alcance «tenant» no usa referencia; se ignora.', 'scope_ref', $raw);
            }

            return null;
        }
        if ($raw === null) {
            $issues[] = Issue::error('required', 'Falta la referencia del alcance.', 'scope_ref');

            return null;
        }
        switch ($scope) {
            case 'country':
                if (preg_match('/^[A-Za-z]{2}$/', $raw) !== 1) {
                    $issues[] = Issue::error('invalid_value', 'El país debe ser un código ISO de 2 letras (p. ej. EC).', 'scope_ref', $raw);

                    return null;
                }

                return strtoupper($raw);
            case 'branch':
                $id = $catalog->branchId($raw);
                if ($id === null) {
                    $issues[] = Issue::error('unknown_branch', "La sucursal '{$raw}' no existe en la empresa.", 'scope_ref', $raw);

                    return null;
                }

                return 'branch:'.$id;
            case 'product':
                $id = $catalog->productId($raw);
                if ($id === null) {
                    $issues[] = Issue::error('unknown_product', "El producto '{$raw}' no existe en la empresa.", 'scope_ref', $raw);

                    return null;
                }

                return 'product:'.$id;
            case 'category':
                $id = $catalog->categoryId($raw);
                if ($id === null) {
                    $issues[] = Issue::error('unknown_category', "La categoría '{$raw}' no existe en la empresa.", 'scope_ref', $raw);

                    return null;
                }

                return 'category:'.$id;
            default: // region, sector
                $key = ValueParser::key($raw);
                if ($key === '') {
                    $issues[] = Issue::error('invalid_value', 'Referencia no válida.', 'scope_ref', $raw);

                    return null;
                }

                return $scope === 'sector' ? 'sector:'.$key : $key;
        }
    }

    /** @param array<string, list<string>> $map */
    private function enumValue(array $map, ?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $key = ValueParser::key($raw);
        foreach ($map as $value => $synonyms) {
            if (in_array($key, $synonyms, true)) {
                return $value;
            }
        }

        return null;
    }
}
