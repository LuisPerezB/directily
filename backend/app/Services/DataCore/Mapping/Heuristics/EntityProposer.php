<?php

declare(strict_types=1);

namespace App\Services\DataCore\Mapping\Heuristics;

use App\Services\DataCore\Mapping\Discovery\DiscoveredColumn;
use App\Services\DataCore\Mapping\Discovery\DiscoveredSchema;
use App\Services\DataCore\Mapping\Discovery\DiscoveredTable;
use App\Services\DataCore\Mapping\Discovery\IdentifierQuoter;
use App\Services\DataCore\Mapping\Discovery\TypeClass;
use App\Services\Imports\ImportEntity;

/**
 * Propuesta DETERMINISTA de mapeo de una entidad sobre un esquema descubierto (V2.5 §4). Sin LLM, sin aleatoriedad: el mismo esquema produce la misma
 * propuesta. Pasos:
 *
 *  1. Puntúa cada columna contra cada campo canónico (FieldScorer) con el rol de su tabla (TableRoles); descarta columnas con nombre de dato personal.
 *  2. Elige la tabla principal de la entidad (en ventas, la de líneas) y, solo en ventas, las tablas padre alcanzables por clave foránea declarada o
 *     inferida por nombre (cabecera → cliente; línea → producto), nunca hacia tablas hijas (no multiplica filas).
 *  3. Asigna columnas a campos: con puntaje por debajo del mínimo o con un segundo candidato dentro del margen, el campo queda SIN mapear (no_encontrado,
 *     baja_confianza, ambiguo) y marcado para revisión. Nunca se aprueba nada aquí.
 *  4. Si hay un perfilador, lee muestras estadísticas acotadas (tipo real de columnas de texto, valores distintos de tipo y estado) y reasigna.
 */
final class EntityProposer
{
    /** Campos que identifican la tabla principal de cada entidad. */
    private const PRIMARY = [
        'sales' => ['product_sku', 'quantity', 'unit_price'],
        'products' => ['sku', 'name'],
        'inventory' => ['product_sku', 'on_hand'],
        'suppliers' => ['code', 'name'],
        'lead_times' => ['lead_time_days'],
        'branches' => ['code', 'name'],
    ];

    /** Campos cuyos valores distintos se piden al perfilador (vocabulario del documento). */
    private const DISTINCT_FIELDS = ['sales' => ['document_type', 'status']];

    /** Rol de tabla que corresponde a cada entidad (para elegir la tabla principal). */
    private const HOME_ROLE = ['sales' => 'sales_line', 'products' => 'product', 'inventory' => 'inventory', 'suppliers' => 'supplier', 'lead_times' => 'lead_time', 'branches' => 'branch'];

    private const SALES_HEADER_ROLES = ['sales_header', 'sales_line', 'sales_flat'];

    private const SALES_LINE_ROLES = ['sales_line', 'sales_flat'];

    /** Nombres de clave demasiado genéricos para inferir una unión. */
    private const GENERIC_KEYS = ['id', 'codigo', 'code', 'numero', 'nombre', 'name', 'descripcion', 'tipo', 'estado'];

    /** Distancia: cada salto de unión resta esto al puntaje (desempate hacia la tabla principal). */
    private const HOP_PENALTY = 0.02;

    private readonly FieldScorer $scorer;

    private readonly ValueMapProposer $valueMaps;

    public function __construct(private readonly Lexicon $lexicon = new Lexicon, ?FieldScorer $scorer = null, ?ValueMapProposer $valueMaps = null)
    {
        $this->scorer = $scorer ?? new FieldScorer;
        $this->valueMaps = $valueMaps ?? new ValueMapProposer;
    }

    public function propose(ImportEntity $entity, DiscoveredSchema $schema, ?ColumnProfiler $profiler = null): EntityProposal
    {
        $rules = $this->lexicon->rules($entity);
        $index = $this->tokenIndex($rules);
        $min = (float) config('mapping.min_confidence', 0.60);
        $info = $this->analyze($schema);

        $candidates = [];
        foreach ($info as $key => $tableInfo) {
            $candidates[$key] = $this->scoreTable($entity, $rules, $index, $tableInfo, []);
        }

        $ranked = $this->rankPrimaries($entity, $candidates, $info, $min);
        if ($ranked === []) {
            return $this->empty($entity, $rules, 'No se encontró ninguna tabla que se parezca a «'.$entity->value.'» en el esquema descubierto.');
        }

        $edges = $entity === ImportEntity::Sales ? $this->parentEdges($schema, $info) : [];
        $options = [];
        foreach (array_slice($ranked, 0, 3) as $primaryKey) {
            $options[] = $this->option($entity, $rules, $schema, $info, $candidates, $edges, $primaryKey, $min);
        }
        usort($options, fn (array $a, array $b) => [$b['score'], $a['primary']] <=> [$a['score'], $b['primary']]);
        $best = $options[0];

        // Muestras estadísticas acotadas (opcional): confirman tipos de columnas de texto y traen el vocabulario del documento.
        $profiles = [];
        if ($profiler !== null) {
            $profiles = $this->profile($entity, $rules, $best, $info, $profiler);
            if ($profiles !== []) {
                $rescored = [];
                foreach ($best['cluster'] as $key => $_) {
                    $rescored[$key] = $this->scoreTable($entity, $rules, $index, $info[$key], $profiles);
                }
                $skipped = $best['skipped'];
                $best = $this->option($entity, $rules, $schema, $info, $rescored + $candidates, $edges, $best['primary'], $min, $best['cluster']);
                $best['skipped'] = $skipped;
            }
        }

        return $this->finish($entity, $rules, $schema, $info, $best, array_slice($options, 1), $profiles);
    }

    /** @return array<string, list<string>> token → campos cuyos patrones lo usan */
    private function tokenIndex(array $rules): array
    {
        $index = [];
        foreach ($rules as $field => $rule) {
            foreach ($rule->patterns as $pattern) {
                foreach ($pattern as $token) {
                    $index[$token][$field] = $field;
                }
            }
        }

        return array_map('array_values', $index);
    }

    /**
     * Roles, tokens y tipo de dato personal de cada columna de cada tabla.
     *
     * @return array<string, array{table: DiscoveredTable, roles: array<string, float>, tokens: array<string, list<string>>, pii: array<string, ?string>, ignored: int}>
     */
    private function analyze(DiscoveredSchema $schema): array
    {
        $info = [];
        foreach ($schema->tables() as $key => $table) {
            // Una tabla o columna cuyo nombre no se puede entrecomillar con seguridad (caracteres de control, barra invertida, 128+ caracteres, vacío) no participa:
            // no puede aparecer en una consulta generada.
            if (! IdentifierQuoter::isSafe($table->name) || ($table->schema !== null && ! IdentifierQuoter::isSafe($table->schema))) {
                continue;
            }
            $columns = array_values(array_filter($table->columns, fn (DiscoveredColumn $c) => IdentifierQuoter::isSafe($c->name)));
            if ($columns === []) {
                continue;
            }
            $clean = new DiscoveredTable($table->schema, $table->name, $table->kind, $table->estimatedRows, $columns, $table->primaryKey, $table->foreignKeys);
            $roles = TableRoles::detect($clean);
            $tokens = [];
            $pii = [];
            foreach ($columns as $column) {
                $tokens[$column->name] = NameTokens::of($column->name);
                $pii[$column->name] = PiiColumns::classify($tokens[$column->name], $roles);
            }
            $info[$key] = ['table' => $clean, 'roles' => $roles, 'tokens' => $tokens, 'pii' => $pii, 'ignored' => count($table->columns) - count($columns)];
        }

        return $info;
    }

    /**
     * Candidatos por campo de una tabla.
     *
     * @param  array<string, FieldRules>  $rules
     * @param  array<string, list<string>>  $index
     * @param  array{table: DiscoveredTable, roles: array<string, float>, tokens: array<string, list<string>>, pii: array<string, ?string>}  $tableInfo
     * @param  array<string, array<string, ColumnProfile>>  $profiles  tabla → columna → perfil
     * @return array<string, list<MappingCandidate>>
     */
    private function scoreTable(ImportEntity $entity, array $rules, array $index, array $tableInfo, array $profiles): array
    {
        $table = $tableInfo['table'];
        $out = [];
        foreach ($table->columns as $column) {
            $tokens = $tableInfo['tokens'][$column->name];
            if ($tokens === []) {
                continue;
            }
            $fields = [];
            foreach ($tokens as $token) {
                foreach ($index[$token] ?? [] as $field) {
                    $fields[$field] = true;
                }
            }
            $pii = $tableInfo['pii'][$column->name];
            foreach (array_keys($fields) as $field) {
                if (! PiiColumns::proposable($pii, $entity->value, $field)) {
                    continue;
                }
                $context = $this->context($entity, $rules[$field], $tableInfo['roles']);
                if ($context === null) {
                    continue;
                }
                $result = $this->scorer->score($rules[$field], $column->typeClass, $tokens, $context, $profiles[$table->key()][$column->name] ?? null);
                if ($result !== null) {
                    $out[$field][] = new MappingCandidate($field, $table->key(), $column->name, $result['score'], $result['evidence']);
                }
            }
        }
        foreach ($out as $field => $list) {
            $out[$field] = $this->sorted($list);
        }

        return $out;
    }

    /** @param list<MappingCandidate> $list @return list<MappingCandidate> */
    private function sorted(array $list): array
    {
        usort($list, fn (MappingCandidate $a, MappingCandidate $b) => $b->score <=> $a->score ?: $a->hops <=> $b->hops ?: strcmp($a->tableKey, $b->tableKey) ?: strcmp($a->column, $b->column));

        return $list;
    }

    /** Roles de tabla permitidos para el campo. En ventas, la cabecera y la línea aportan campos distintos; el producto y el cliente solo sus atributos. */
    private function allowedRoles(ImportEntity $entity, FieldRules $rule): array
    {
        if ($entity !== ImportEntity::Sales) {
            return [$rule->home];
        }

        return match (true) {
            in_array($rule->key, ['customer_code', 'customer_segment', 'customer_province'], true) => [...self::SALES_HEADER_ROLES, 'customer'],
            in_array($rule->key, ['product_name', 'category'], true) => [...self::SALES_LINE_ROLES, 'product', 'category'],
            $rule->home === 'sales_header' => self::SALES_HEADER_ROLES,
            default => self::SALES_LINE_ROLES,
        };
    }

    /** 1,0 si la tabla tiene el rol esperado, 0,5 si no tiene ningún rol reconocible, null si tiene otro rol (la columna no puede venir de ahí). */
    private function context(ImportEntity $entity, FieldRules $rule, array $roles): ?float
    {
        if ($roles === []) {
            return 0.5;
        }

        return array_intersect($this->allowedRoles($entity, $rule), array_keys($roles)) !== [] ? 1.0 : null;
    }

    /**
     * Tablas principales candidatas, mejor primero. Una tabla es elegible si tiene candidatos para TODOS los campos que identifican la entidad, o si su
     * nombre indica el rol de la entidad y cubre al menos la mitad; una tabla de ruido (parámetros, bitácora) con una sola coincidencia suelta no lo es.
     *
     * @param  array<string, array<string, list<MappingCandidate>>>  $candidates
     * @return list<string>
     */
    private function rankPrimaries(ImportEntity $entity, array $candidates, array $info, float $min): array
    {
        $fields = self::PRIMARY[$entity->value];
        $homeRoles = $entity === ImportEntity::Sales ? self::SALES_LINE_ROLES : [self::HOME_ROLE[$entity->value]];
        $ranked = [];
        foreach ($candidates as $key => $byField) {
            $hits = 0;
            $sum = 0.0;
            foreach ($fields as $field) {
                $best = isset($byField[$field][0]) ? $byField[$field][0]->score : 0.0;
                if ($best >= $min) {
                    $hits++;
                    $sum += $best;
                }
            }
            $roleMatch = array_intersect($homeRoles, array_keys($info[$key]['roles'])) !== [];
            if ($hits === count($fields) || ($roleMatch && $hits >= (int) ceil(count($fields) / 2))) {
                $ranked[] = ['key' => $key, 'hits' => $hits, 'sum' => $sum, 'roles' => $roleMatch ? 1 : 0, 'rows' => $info[$key]['table']->estimatedRows ?? 0];
            }
        }
        usort($ranked, fn (array $a, array $b) => $b['hits'] <=> $a['hits'] ?: $b['sum'] <=> $a['sum'] ?: $b['roles'] <=> $a['roles'] ?: $b['rows'] <=> $a['rows'] ?: strcmp($a['key'], $b['key']));

        return array_column($ranked, 'key');
    }

    /**
     * Aristas hijo → padre (muchos a uno): claves foráneas declaradas y, si no hay, uniones inferidas por nombre de la clave primaria.
     *
     * @return array<string, array{edges: list<array{parent: string, pairs: list<array{0: string, 1: string}>, kind: string}>, skipped: list<string>}>
     */
    private function parentEdges(DiscoveredSchema $schema, array $info): array
    {
        $found = [];
        foreach ($info as $key => $tableInfo) {
            $table = $tableInfo['table'];
            foreach ($table->foreignKeys as $fk) {
                $refSchema = $fk->refSchema ?? ($schema->dialect === 'mysql' ? null : $table->schema);
                $parent = DiscoveredTable::keyOf($refSchema, $fk->refTable);
                if (! isset($info[$parent]) || $parent === $key) {
                    continue;
                }
                $found[$key][$parent][] = ['pairs' => array_map(null, $fk->columns, $fk->refColumns), 'kind' => 'declared'];
            }
        }
        // Inferidas: columna de una tabla con el mismo nombre que la clave primaria de una sola columna de otra tabla (y tipo compatible).
        $pkIndex = [];
        foreach ($info as $key => $tableInfo) {
            $table = $tableInfo['table'];
            if ($table->kind === 'table' && count($table->primaryKey) === 1 && ! in_array(strtolower($table->primaryKey[0]), self::GENERIC_KEYS, true) && $table->column($table->primaryKey[0]) !== null) {
                $pkIndex[strtolower($table->primaryKey[0])][] = $key;
            }
        }
        foreach ($info as $key => $tableInfo) {
            $table = $tableInfo['table'];
            $declaredColumns = [];
            foreach ($table->foreignKeys as $fk) {
                foreach ($fk->columns as $c) {
                    $declaredColumns[$c] = true;
                }
            }
            foreach ($table->columns as $column) {
                if (isset($declaredColumns[$column->name]) || ! isset($pkIndex[strtolower($column->name)])) {
                    continue;
                }
                $parents = array_values(array_filter($pkIndex[strtolower($column->name)], fn (string $p) => $p !== $key));
                if (count($parents) !== 1) {
                    if ($parents !== []) {
                        $found[$key]['__ambiguous__'][] = ['pairs' => [[$column->name, $column->name]], 'kind' => 'ambiguous'];
                    }

                    continue;
                }
                $parentTable = $info[$parents[0]]['table'];
                $parentColumn = $parentTable->column($parentTable->primaryKey[0]);
                if ($parentColumn === null || ! $this->joinable($column->typeClass, $parentColumn->typeClass)) {
                    continue;
                }
                $found[$key][$parents[0]][] = ['pairs' => [[$column->name, $parentTable->primaryKey[0]]], 'kind' => 'inferred'];
            }
        }

        $edges = [];
        foreach ($found as $child => $parents) {
            ksort($parents);   // el orden de las uniones no depende del orden del catálogo
            foreach ($parents as $parent => $links) {
                if ($parent === '__ambiguous__') {
                    $edges[$child]['skipped'][] = 'una columna coincide con la clave primaria de varias tablas';

                    continue;
                }
                if (count($links) > 1) {
                    // Dos claves hacia la misma tabla (p. ej. bodega de origen y de destino): no se elige una a ciegas.
                    $edges[$child]['skipped'][] = 'hay más de una unión hacia «'.$parent.'»';

                    continue;
                }
                $edges[$child]['edges'][] = ['parent' => $parent, 'pairs' => $links[0]['pairs'], 'kind' => $links[0]['kind']];
            }
        }

        return $edges;
    }

    private function joinable(TypeClass $a, TypeClass $b): bool
    {
        return $a === $b || ($a->isNumeric() && $b->isNumeric());
    }

    /**
     * Arma y puntúa la propuesta con una tabla principal.
     *
     * @param  array<string, FieldRules>  $rules
     * @param  array<string, array<string, list<MappingCandidate>>>  $candidates
     * @param  ?array<string, array{hops: int, from: ?string, pairs: list<array{0: string, 1: string}>, kind: string}>  $cluster  conjunto de tablas ya calculado (segunda pasada)
     * @return array{primary: string, cluster: array<string, array{hops: int, from: ?string, pairs: list<array{0: string, 1: string}>, kind: string}>, byField: array<string, list<MappingCandidate>>, mapped: array<string, MappingCandidate>, unmapped: array<string, array{reason: string, candidates: list<MappingCandidate>}>, score: float, skipped: list<string>}
     */
    private function option(ImportEntity $entity, array $rules, DiscoveredSchema $schema, array $info, array $candidates, array $edges, string $primary, float $min, ?array $cluster = null): array
    {
        $skipped = [];
        if ($cluster === null) {
            $cluster = [$primary => ['hops' => 0, 'from' => null, 'pairs' => [], 'kind' => 'primary']];
            $maxHops = $entity === ImportEntity::Sales ? (int) config('mapping.joins.max_hops', 3) : 0;
            $maxTables = (int) config('mapping.joins.max_tables', 6);
            $queue = [$primary];
            while ($queue !== [] && count($cluster) < $maxTables) {
                $current = array_shift($queue);
                $hops = $cluster[$current]['hops'];
                foreach ($edges[$current]['skipped'] ?? [] as $reason) {
                    $skipped[] = '«'.$current.'»: '.$reason;
                }
                if ($hops >= $maxHops) {
                    continue;
                }
                foreach ($edges[$current]['edges'] ?? [] as $edge) {
                    if (isset($cluster[$edge['parent']]) || ! isset($info[$edge['parent']]) || count($cluster) >= $maxTables) {
                        continue;
                    }
                    $cluster[$edge['parent']] = ['hops' => $hops + 1, 'from' => $current, 'pairs' => $edge['pairs'], 'kind' => $edge['kind']];
                    $queue[] = $edge['parent'];
                }
            }
        }

        $byField = [];
        foreach ($cluster as $key => $meta) {
            foreach ($candidates[$key] ?? [] as $field => $list) {
                foreach ($list as $candidate) {
                    $byField[$field][] = new MappingCandidate($field, $candidate->tableKey, $candidate->column, max(0.0, round($candidate->score - self::HOP_PENALTY * $meta['hops'], 3)), $candidate->evidence, $meta['hops']);
                }
            }
        }
        foreach ($byField as $field => $list) {
            $byField[$field] = $this->sorted($list);
        }
        [$mapped, $unmapped] = $this->assign($rules, $byField, $this->equivalence($cluster), $min);

        $score = 0.0;
        foreach ($rules as $field => $rule) {
            if (isset($mapped[$field])) {
                $score += $rule->required ? $mapped[$field]->score : 0.5 * $mapped[$field]->score;
            } elseif ($rule->required) {
                $score -= 1.0;
            }
        }

        return ['primary' => $primary, 'cluster' => $cluster, 'byField' => $byField, 'mapped' => $mapped, 'unmapped' => $unmapped, 'score' => round($score, 3), 'skipped' => $skipped];
    }

    /**
     * Clases de equivalencia de columnas unidas por la consulta (línea.cod_item ≡ producto.cod_item): el mismo valor no cuenta como dos candidatos.
     *
     * @return array<string, string> «tabla.columna» → representante
     */
    private function equivalence(array $cluster): array
    {
        $parent = [];
        $find = function (string $x) use (&$parent, &$find): string {
            $parent[$x] ??= $x;

            return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
        };
        foreach ($cluster as $key => $meta) {
            foreach ($meta['pairs'] as [$fromColumn, $thisColumn]) {
                $a = $find($meta['from'].'.'.$fromColumn);
                $b = $find($key.'.'.$thisColumn);
                if ($a !== $b) {
                    $parent[$b] = $a;
                }
            }
        }

        return array_combine(array_keys($parent), array_map($find, array_keys($parent))) ?: [];
    }

    /**
     * @param  array<string, FieldRules>  $rules
     * @param  array<string, list<MappingCandidate>>  $byField
     * @param  array<string, string>  $equivalence
     * @return array{0: array<string, MappingCandidate>, 1: array<string, array{reason: string, candidates: list<MappingCandidate>}>}
     */
    private function assign(array $rules, array $byField, array $equivalence, float $min): array
    {
        $margin = (float) config('mapping.ambiguity_margin', 0.10);
        $canon = fn (MappingCandidate $c): string => $equivalence[$c->ref()] ?? $c->ref();
        // Una columna unida (línea.cod_item ≡ producto.cod_item) cuenta una sola vez: queda la más cercana a la tabla principal.
        foreach ($byField as $field => $list) {
            $seen = [];
            $byField[$field] = array_values(array_filter($list, function (MappingCandidate $c) use (&$seen, $canon): bool {
                $key = $canon($c);
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;

                return true;
            }));
        }
        $order = array_keys($rules);
        usort($order, fn (string $a, string $b) => ($byField[$b][0]->score ?? -1.0) <=> ($byField[$a][0]->score ?? -1.0));
        $tops = [];
        foreach ($byField as $field => $list) {
            $tops[$field] = $list[0] ?? null;
        }

        $claimed = [];
        $contested = [];
        $mapped = [];
        $unmapped = [];
        foreach ($order as $field) {
            $all = $byField[$field] ?? [];
            if ($all === []) {
                $unmapped[$field] = ['reason' => 'not_found', 'candidates' => []];

                continue;
            }
            if (isset($contested[$canon($all[0])])) {
                $unmapped[$field] = ['reason' => 'ambiguous', 'candidates' => array_slice($all, 0, 3)];

                continue;
            }
            $list = array_values(array_filter($all, fn (MappingCandidate $c) => ! isset($claimed[$canon($c)])));
            if ($list === []) {
                $unmapped[$field] = ['reason' => 'not_found', 'candidates' => array_slice($all, 0, 3)];

                continue;
            }
            $top = $list[0];
            if ($top->score < $min) {
                $unmapped[$field] = ['reason' => 'low_confidence', 'candidates' => array_slice(array_filter($list, fn (MappingCandidate $c) => $c->score >= 0.30), 0, 3)];

                continue;
            }
            $second = $list[1] ?? null;
            if ($second !== null && $second->score >= $min && $top->score - $second->score < $margin) {
                $unmapped[$field] = ['reason' => 'ambiguous', 'candidates' => array_slice(array_filter($list, fn (MappingCandidate $c) => $top->score - $c->score < $margin), 0, 3)];

                continue;
            }
            // Otro campo con el mismo candidato principal y un puntaje parecido: la columna está disputada y ninguno la reclama.
            $rival = null;
            foreach ($tops as $other => $candidate) {
                if ($other !== $field && ! isset($mapped[$other]) && ! isset($unmapped[$other]) && $candidate !== null && $canon($candidate) === $canon($top)
                    && $candidate->score >= $min && abs($candidate->score - $top->score) < $margin) {
                    $rival = $other;
                    break;
                }
            }
            if ($rival !== null) {
                $contested[$canon($top)] = true;
                $unmapped[$field] = ['reason' => 'ambiguous', 'candidates' => [$top]];

                continue;
            }
            $mapped[$field] = $top;
            $claimed[$canon($top)] = $field;
        }

        return [$mapped, $unmapped];
    }

    /**
     * Pide al perfilador las muestras que hacen falta (columnas de texto candidatas a fecha/número/booleano y valores distintos del vocabulario del documento).
     *
     * @return array<string, array<string, ColumnProfile>> tabla → columna → perfil
     */
    private function profile(ImportEntity $entity, array $rules, array $option, array $info, ColumnProfiler $profiler): array
    {
        $pattern = [];
        $categorical = [];
        foreach ($rules as $field => $rule) {
            $candidates = $option['mapped'][$field] ?? null;
            $list = $candidates !== null ? [$candidates] : array_slice($option['byField'][$field] ?? [], 0, 2);
            if (isset($option['unmapped'][$field]['candidates'])) {
                $list = array_merge($list, $option['unmapped'][$field]['candidates']);
            }
            foreach ($list as $candidate) {
                $tableInfo = $info[$candidate->tableKey];
                $column = $tableInfo['table']->column($candidate->column);
                if ($column === null || PiiColumns::neverSample($tableInfo['pii'][$candidate->column])) {
                    continue;
                }
                if ($column->typeClass === TypeClass::Text && in_array($rule->type, ['date', 'datetime', 'number', 'integer', 'boolean'], true)) {
                    $pattern[$candidate->tableKey][$candidate->column] = true;
                }
                if ($rule->identity && $column->typeClass === TypeClass::Text) {
                    $pattern[$candidate->tableKey][$candidate->column] = true;   // forma de los valores (proporciones): un código no puede parecer un teléfono, un correo o una frase
                }
                if (in_array($field, self::DISTINCT_FIELDS[$entity->value] ?? [], true) && in_array($column->typeClass, [TypeClass::Text, TypeClass::Integer, TypeClass::Boolean], true)) {
                    $categorical[$candidate->tableKey][$candidate->column] = true;
                }
            }
        }
        $profiles = [];
        foreach (array_unique([...array_keys($pattern), ...array_keys($categorical)]) as $key) {
            $found = $profiler->profile($info[$key]['table'], array_keys($pattern[$key] ?? []), array_keys($categorical[$key] ?? []));
            if ($found !== []) {
                $profiles[$key] = $found;
            }
        }

        return $profiles;
    }

    /**
     * @param  array<string, FieldRules>  $rules
     * @param  list<array<string, mixed>>  $others
     * @param  array<string, array<string, ColumnProfile>>  $profiles
     */
    private function finish(ImportEntity $entity, array $rules, DiscoveredSchema $schema, array $info, array $best, array $others, array $profiles): EntityProposal
    {
        $min = (float) config('mapping.min_confidence', 0.60);
        $high = (float) config('mapping.high_band', 0.80);
        $mapped = $best['mapped'];
        $warnings = [];

        // Tablas que aportan algo (y los saltos intermedios necesarios para llegar a ellas).
        $keep = [$best['primary'] => true];
        foreach ($mapped as $candidate) {
            for ($key = $candidate->tableKey; $key !== null && ! isset($keep[$key]); $key = $best['cluster'][$key]['from']) {
                $keep[$key] = true;
            }
        }
        $suppliers = [];
        foreach ($mapped as $field => $candidate) {
            if ($rules[$field]->required) {
                for ($key = $candidate->tableKey; $key !== null; $key = $best['cluster'][$key]['from']) {
                    $suppliers[$key] = true;
                }
            }
        }

        $aliases = [];
        $tables = [];
        foreach (array_keys($best['cluster']) as $key) {
            if (! isset($keep[$key])) {
                continue;
            }
            $aliases[$key] = 't'.count($aliases);
            $meta = $best['cluster'][$key];
            $table = $info[$key]['table'];
            $columns = [];
            foreach ($table->columns as $column) {
                $columns[$column->name] = $column->typeClass->value;
            }
            $entry = ['alias' => $aliases[$key], 'schema' => $table->schema, 'name' => $table->name, 'kind' => $table->kind, 'columns' => array_slice($columns, 0, 300, true)];
            if ($meta['from'] === null) {
                $entry['join'] = null;
            } else {
                $entry['join'] = ['from' => $aliases[$meta['from']], 'type' => isset($suppliers[$key]) ? 'inner' : 'left', 'kind' => $meta['kind'], 'on' => array_map(fn (array $p) => [$p[0], $p[1]], $meta['pairs'])];
                if ($meta['kind'] === 'inferred') {
                    $warnings[] = 'La unión «'.$info[$meta['from']]['table']->name.'» → «'.$table->name.'» se infirió por el nombre de la columna y la clave primaria (no hay clave foránea declarada): verifíquela.';
                }
            }
            $tables[] = $entry;
            if (($info[$key]['ignored'] ?? 0) > 0) {
                $warnings[] = $info[$key]['ignored'].' columna(s) de «'.$table->name.'» se ignoraron por tener un nombre que no se puede usar con seguridad en una consulta (vacío, caracteres de control o demasiado largo).';
            }
        }
        foreach ($best['skipped'] as $skip) {
            $warnings[] = 'Unión omitida por ambigua: '.$skip.'.';
        }

        $fieldMap = [];
        $fields = [];
        foreach ($mapped as $field => $candidate) {
            $ref = $aliases[$candidate->tableKey].'.'.$candidate->column;
            $fieldMap[$field] = $ref;
            $fields[$field] = ['score' => $candidate->score, 'band' => $candidate->score >= $high ? 'high' : ($candidate->score >= $min ? 'medium' : 'low'), 'evidence' => $candidate->evidence, 'source' => $ref];
            if ($field === 'customer_code' && PiiColumns::classify($info[$candidate->tableKey]['tokens'][$candidate->column], $info[$candidate->tableKey]['roles']) === PiiColumns::NATIONAL_ID) {
                $warnings[] = 'El código de cliente viene de una columna de identificación nacional: el importador la guarda solo como huella (HMAC), nunca en claro.';
            }
        }

        $unmapped = [];
        foreach ($best['unmapped'] as $field => $item) {
            $rule = $rules[$field];
            $unmapped[] = ['field' => $field, 'reason' => $item['reason'], 'required' => $rule->required, 'candidates' => array_map(
                fn (MappingCandidate $c) => ['column' => ($aliases[$c->tableKey] ?? $c->tableKey).'.'.$c->column, 'table' => $c->tableKey, 'score' => $c->score],
                $item['candidates'],
            )];
        }
        usort($unmapped, fn (array $a, array $b) => [$b['required'], $a['field']] <=> [$a['required'], $b['field']]);

        $pii = [];
        foreach (array_keys($best['cluster']) as $key) {
            foreach ($info[$key]['pii'] as $column => $kind) {
                if ($kind !== null) {
                    $pii[$key.'.'.$column] = $kind;
                }
            }
        }
        ksort($pii);

        // Mapeos de valores del tipo y estado de documento, a partir de los valores distintos muestreados.
        $valueMaps = [];
        $report = [];
        foreach (self::DISTINCT_FIELDS[$entity->value] ?? [] as $field) {
            if (! isset($mapped[$field])) {
                continue;
            }
            $profile = $profiles[$mapped[$field]->tableKey][$mapped[$field]->column] ?? null;
            if ($profile === null) {
                continue;
            }
            if ($profile->distinct === null) {
                $report[$field] = ['status' => 'sin_vocabulario', 'note' => $profile->note ?? 'La columna tiene demasiados valores distintos para ser un vocabulario o no se pudo leer.'];

                continue;
            }
            $flag = $field === 'status' && in_array('anulad', array_map(fn (string $t) => substr($t, 0, 6), $info[$mapped[$field]->tableKey]['tokens'][$mapped[$field]->column]), true);
            $proposal = $this->valueMaps->propose($field, $profile->distinct, $flag);
            $accepted = [];
            foreach ($proposal['entries'] as $raw => $entry) {
                if ($entry['score'] >= $min) {
                    $accepted[$raw] = $entry['to'];
                }
            }
            if ($accepted !== []) {
                $valueMaps[$field] = $accepted;
            }
            $cap = max(1, (int) config('mapping.sampling.distinct_cap', 25));
            $report[$field] = ['status' => $profile->distinctComplete ? 'completo' : 'parcial', 'entries' => array_slice($proposal['entries'], 0, $cap, true), 'unresolved' => array_slice($proposal['unresolved'], 0, $cap),
                'recognized' => array_slice($proposal['recognized'], 0, $cap), 'suppressed_rare_values' => $profile->suppressedRare]
                + ($profile->distinctComplete ? [] : ['note' => 'Solo se examinó el inicio de la tabla: pueden existir valores que aquí no aparecen.']);
            if ($profile->suppressedRare > 0) {
                $warnings[] = $profile->suppressedRare.' valor(es) de «'.$field.'» aparecen en menos de '.max(1, (int) config('mapping.sampling.min_value_count', 5)).' filas y no se muestran (pueden ser datos sueltos): si existen en el origen, el importador los marcará para revisión y se resuelven con un mapeo de valores.';
            }
            foreach ($proposal['warnings'] as $warning) {
                $warnings[] = $warning;
            }
            if ($proposal['unresolved'] !== []) {
                $warnings[] = 'Hay valores de «'.$field.'» sin mapeo que el importador marcará para revisión: '.implode(', ', array_map(fn (array $u) => $u['value'].' ('.$u['reason'].')', array_slice($proposal['unresolved'], 0, 8))).'.';
            }
        }

        if ($entity === ImportEntity::Sales && ! isset($mapped['customer_code'])) {
            $national = array_keys(array_filter($pii, fn (string $kind) => $kind === PiiColumns::NATIONAL_ID));
            if ($national !== []) {
                $warnings[] = 'No se propone ningún código de cliente: la única columna candidata es una identificación nacional ('.implode(', ', array_slice($national, 0, 5)).'). Si desea usarla, asígnela a mano: el importador la guarda solo como huella (HMAC).';
            }
        }
        if ($entity === ImportEntity::Sales && ! isset($mapped['source_recorded_at'])) {
            $warnings[] = 'No se encontró una columna de fecha de modificación: cada sincronización leerá todas las filas de la consulta (la importación es idempotente, pero no incremental).';
        }
        $alternatives = [];
        foreach ($others as $other) {
            $alternatives[] = ['table' => $other['primary'], 'score' => $other['score']];
        }
        if ($alternatives !== []) {
            $warnings[] = 'Otras tablas candidatas como principal: '.implode(', ', array_map(fn (array $a) => $a['table'], $alternatives)).'.';
        }

        $since = isset($fieldMap['source_recorded_at']) ? $fieldMap['source_recorded_at'] : null;
        if ($since !== null) {
            [$alias, $column] = explode('.', $since, 2);
            foreach ($tables as $table) {
                if ($table['alias'] === $alias && ! in_array($table['columns'][$column] ?? null, ['date', 'datetime'], true)) {
                    $since = null; // solo una columna temporal acota la ventana con :since
                    $warnings[] = 'La columna de modificación no es de tipo fecha: no se usa para la extracción incremental.';
                }
            }
        }

        $spec = ['version' => 1, 'dialect' => $schema->dialect, 'engine' => 'heuristic_v1', 'tables' => $tables, 'since' => $since];
        $complete = $this->missingRequired($rules, $mapped) === [];

        return new EntityProposal($entity->value, $fieldMap, $fields, $spec, $unmapped, $valueMaps, $report, $pii, array_values(array_unique($warnings)), $alternatives, $complete);
    }

    /** @param array<string, MappingCandidate> $mapped @return list<string> */
    private function missingRequired(array $rules, array $mapped): array
    {
        $missing = [];
        foreach ($rules as $field => $rule) {
            if ($rule->required && ! isset($mapped[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function empty(ImportEntity $entity, array $rules, string $warning): EntityProposal
    {
        $unmapped = [];
        foreach ($rules as $field => $rule) {
            $unmapped[] = ['field' => $field, 'reason' => 'not_found', 'required' => $rule->required, 'candidates' => []];
        }
        usort($unmapped, fn (array $a, array $b) => [$b['required'], $a['field']] <=> [$a['required'], $b['field']]);

        return new EntityProposal($entity->value, [], [], ['version' => 1, 'engine' => 'heuristic_v1', 'tables' => [], 'since' => null], $unmapped, [], [], [], [$warning], [], false, 'no_table');
    }
}
