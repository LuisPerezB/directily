<?php

declare(strict_types=1);

namespace App\Services\Decision;

use App\Enums\TenantRole;
use App\Exceptions\DomainStateException;
use App\Models\DecisionEvent;
use App\Models\DecisionRecommendation;
use App\Models\User;
use App\Services\Decision\Exceptions\DecisionForbiddenException;
use App\Services\Forecasting\Support\CoreServices;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Workflow de decisiones (V2.5 · W5-B; ADR-V25-13; §11): suggested → assigned → reviewed → approved | rejected → in_progress → executed →
 * measured → closed, + expired. La tabla de transiciones es `TransitionRules`; este servicio la aplica con estas garantías, todas probadas:
 *
 *  - Auditoría: TODA transición deja una fila en `decision_events` (de, a, quién, por qué, evidencia) en la MISMA transacción que el cambio.
 *  - Idempotencia: el cliente manda `idempotency_key`; repetir la misma transición (reintento de red) devuelve el mismo resultado sin otro
 *    evento ni otro efecto. La clave de evento = SHA-256(recomendación, clave del cliente), única por empresa. La misma clave con otro destino → 409.
 *  - Concurrencia: la fila se bloquea (SELECT … FOR UPDATE) y TODA transición humana exige `expected_version` (bloqueo optimista): `version` = id del
 *    último evento de la recomendación que cambia su estado o su asignación (los comentarios del sistema, como el refresco de insumos, no cuentan); si cambió
 *    desde que el cliente la leyó → 409 `decision_version_conflict`. Así una segunda asignación o un cierre no pisan en silencio lo que otra persona hizo.
 *  - Segregación de funciones: quien propone o debe ejecutar (asignado) no aprueba ni rechaza formalmente su propia acción; quien aprobó no la
 *    ejecuta. Es independiente de la habilidad: ni un propietario puede aprobar lo suyo.
 *  - Aprobación de acciones sensibles: reposición, inventario/demanda, presupuesto, escenarios (y lo que la empresa configure o exceda su umbral
 *    de costo, o esté sobre presupuesto) NO pasan de `reviewed` a `in_progress` sin `approved`.
 *  - Grupo de control: las recomendaciones de control no se asignan ni se ejecutan (quedan para medición).
 *  - Predictily NO escribe en el ERP ni envía mensajes: «ejecutar» es que una persona registra que hizo la acción fuera del sistema.
 */
final class Workflow
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Subconsulta que precarga en cada fila el usuario que la aprobó (`approver_user_id`): evita una consulta por recomendación al calcular las transiciones
     * disponibles en una lista. Misma definición que `approverOf()`.
     */
    public static function withApprover(Builder $query): Builder
    {
        return $query->addSelect(['approver_user_id' => DecisionEvent::query()->select('actor_user_id')
            ->whereColumn('decision_events.recommendation_id', 'decision_recommendations.id')->where('event', 'transition')->where('from_status', 'reviewed')
            ->where('to_status', 'approved')->orderByDesc('id')->limit(1)]);
    }

    /**
     * Versión para el bloqueo optimista: id del último evento de la recomendación que cambia su estado, su asignación o su seguimiento (transition,
     * assignment, feedback). Los eventos `comment` (refresco de insumos, resultado de la sincronización de la agenda) no cambian la recomendación y no cuentan.
     */
    public function versionOf(int $recommendationId): int
    {
        return (int) DecisionEvent::query()->where('recommendation_id', $recommendationId)->where('event', '<>', 'comment')->max('id');
    }

    /** Subconsulta de `version` para listas: mismo criterio que {@see self::versionOf()}. */
    public static function withVersion(Builder $query): Builder
    {
        return $query->withMax(['events as version' => fn ($q) => $q->where('event', '<>', 'comment')], 'id');
    }

    /** ¿Exige aprobación explícita antes de ejecutarse? Tipo sensible, restricción de presupuesto o costo sobre el umbral de la empresa. */
    public function requiresApproval(DecisionRecommendation $rec, DecisionSettings $settings): bool
    {
        if ($settings->kindRequiresApproval((string) $rec->kind)) {
            return true;
        }
        foreach ((array) $rec->constraints as $constraint) {
            if (is_array($constraint) && ($constraint['code'] ?? null) === 'over_budget') {
                return true;
            }
        }
        $threshold = $settings->approvalCostThreshold();

        return $threshold !== null && $rec->action_cost !== null && (float) $rec->action_cost >= $threshold;
    }

    /**
     * Transiciones que las reglas ofrecen desde el estado actual, con si el usuario puede hacerlas ahora y, si no, por qué.
     *
     * @return list<array{action: string, to_status: string, ability: string|null, requires: array<string, bool>, allowed: bool, blocked_by: string|null}>
     */
    public function availableFor(DecisionRecommendation $rec, User $actor, TenantRole $role, DecisionSettings $settings): array
    {
        $out = [];
        foreach (TransitionRules::from((string) $rec->status) as $rule) {
            if ($rule['system']) {
                continue;
            }
            $violation = $this->violation($rule, $rec, $actor, $role, [], $settings, false);
            $out[] = [
                'action' => $rule['action'], 'to_status' => $rule['to'], 'ability' => $rule['ability'],
                'requires' => ['assignee' => in_array($rule['action'], ['assign', 'reassign'], true), 'reason' => $rule['reason'], 'expected_version' => $rule['version']],
                'allowed' => $violation === null,
                'blocked_by' => $violation === null ? null : $this->codeOf($violation),
            ];
        }

        return $out;
    }

    /**
     * Transición pedida por una persona.
     *
     * @param  array{expected_version?: int|null, reason?: string|null, assignee_user_id?: int|null}  $params
     *
     * @throws DomainStateException 409 transición no permitida, versión desfasada, clave reutilizada, aprobación pendiente o grupo de control
     * @throws DecisionForbiddenException 403 sin habilidad o por segregación de funciones
     * @throws ValidationException 422 faltan expected_version, motivo o asignado
     */
    public function transition(int $recommendationId, string $to, User $actor, TenantRole $role, string $clientKey, array $params = []): TransitionResult
    {
        // Una recomendación del grupo de control no existe para quien no puede aprobar: 404 antes de cualquier otra comprobación (no se revela el grupo).
        $known = DecisionRecommendation::query()->find($recommendationId, ['id', 'experiment_group']);
        if ($known === null || ($known->experiment_group === 'control' && ! $role->can('decisions.approve'))) {
            throw new NotFoundHttpException('Recomendación no encontrada.');
        }
        $eventKey = hash('sha256', 'decision.transition:'.$recommendationId.':'.$clientKey);
        $fingerprint = $this->fingerprint($to, $actor, $params);
        if (($replay = $this->replayFor($eventKey, $to, $fingerprint, $role)) !== null) {
            return $replay;
        }
        $tenant = $this->context->require();
        $settings = DecisionSettings::forTenant($tenant);

        $result = DB::transaction(function () use ($recommendationId, $to, $actor, $role, $clientKey, $params, $eventKey, $settings, $fingerprint): TransitionResult {
            $rec = DecisionRecommendation::query()->lockForUpdate()->find($recommendationId) ?? throw new NotFoundHttpException('Recomendación no encontrada.');
            // Quien llegó segundo a la misma clave espera el bloqueo y aquí ve el resultado del primero.
            if (($replay = $this->replayFor($eventKey, $to, $fingerprint, $role)) !== null) {
                return $replay;
            }
            // Quien sí ve el grupo de control (puede aprobar) recibe el motivo; las de control no se asignan, aprueban ni ejecutan, ni con versión ni sin ella.
            if ($rec->experiment_group === 'control') {
                throw new DomainStateException(
                    'Esta recomendación pertenece al grupo de control del experimento: queda para medición y no se asigna, aprueba ni ejecuta.',
                    'control_group_not_actionable', ['recommendation_id' => $rec->id],
                );
            }
            $current = $this->versionOf($rec->id);
            $expected = $params['expected_version'] ?? null;
            if ($expected !== null && (int) $expected !== $current) {
                throw new DomainStateException(
                    'La recomendación cambió desde que la leyó (versión '.$expected.' → '.$current.'). Vuelva a cargarla y repita la acción.',
                    'decision_version_conflict',
                    ['recommendation_id' => $rec->id, 'expected_version' => (int) $expected, 'current_version' => $current, 'status' => $rec->status],
                );
            }
            $rule = TransitionRules::find((string) $rec->status, $to);
            if ($rule === null || $rule['system']) {
                throw new DomainStateException(
                    'La transición «'.$rec->status.'» → «'.$to.'» no está permitida.',
                    'decision_transition_not_allowed',
                    ['recommendation_id' => $rec->id, 'from' => $rec->status, 'to' => $to, 'allowed' => array_values(array_unique(array_map(fn ($r) => $r['to'], array_filter(TransitionRules::from((string) $rec->status), fn ($r) => ! $r['system']))))],
                );
            }
            if ($rule['version'] && $expected === null) {
                throw ValidationException::withMessages(['expected_version' => 'Indique expected_version (la versión que leyó): toda transición usa bloqueo optimista.']);
            }
            if ($violation = $this->violation($rule, $rec, $actor, $role, $params, $settings, true)) {
                throw $violation;
            }

            $from = (string) $rec->status;
            $attributes = ['status' => $to];
            if (in_array($rule['action'], ['assign', 'reassign'], true)) {
                $attributes['assigned_user_id'] = (int) $params['assignee_user_id'];
                $attributes['owner_user_id'] = $rec->owner_user_id ?? $actor->getKey();
            }
            $rec->forceFill($attributes)->save();
            $event = DecisionEvent::query()->create([
                'recommendation_id' => $rec->id, 'event' => $rule['event'], 'from_status' => $from, 'to_status' => $to,
                'actor_user_id' => $actor->getKey(), 'actor_kind' => 'user', 'reason' => $this->reason($params),
                'payload' => array_filter([
                    'action' => $rule['action'], 'expected_version' => $expected === null ? null : (int) $expected, 'version_before' => $current,
                    'assignee_user_id' => $attributes['assigned_user_id'] ?? null, 'client_idempotency_key' => $clientKey,
                    'ability' => $rule['ability'], 'role' => $role->value, 'request' => $fingerprint,
                ], fn ($v) => $v !== null),
                'idempotency_key' => $eventKey,
            ]);

            return new TransitionResult($rec->refresh(), $event, false);
        });

        if (! $result->replayed) {
            CoreServices::audit('decision.'.$result->event->payload['action'], $result->recommendation, [
                'recommendation_id' => $result->recommendation->id, 'kind' => $result->recommendation->kind, 'from' => $result->event->from_status,
                'to' => $result->event->to_status, 'user_id' => $actor->getKey(),
            ]);
        }

        return $result;
    }

    /**
     * Aplica la transición del workflow que IMPLICA un estado de la agenda comercial (tabla `radar-agenda-map-v1` de W5-A: contactado e interesado →
     * `in_progress`, pedido_recibido → `executed`, descartado → `rejected`, pospuesto y pendiente → sin cambio). Es una transición como cualquier otra: pasa
     * por `transition()`, con las MISMAS habilidades del rol, segregación de funciones, aprobación de acciones sensibles, grupo de control, idempotencia
     * (la clave es del evento de seguimiento) y bloqueo optimista (`expected_version` = el evento de seguimiento recién registrado).
     *
     * El seguimiento de la agenda NUNCA se pierde ni se rechaza por esto: si el workflow no admite la transición desde el estado actual, el workflow NO se mueve y se
     * devuelve el motivo para que el evento de seguimiento lo registre. Resultados (`sync`):
     *  - `applied`: se hizo la transición (`event_id` = su evento).
     *  - `unchanged`: el workflow ya estaba en el estado implicado (p. ej. interesado cuando ya está `in_progress`).
     *  - `not_applicable`: el estado heredado no mueve el workflow (pospuesto; pendiente nunca lo hace retroceder).
     *  - `skipped`: no se pudo, con `reason`: `transition_not_defined` (p. ej. suggested/assigned/reviewed → in_progress sin pasar por la revisión; approved →
     *    executed sin iniciar), `decision_approval_required` (acción sensible aún sin aprobar), `forbidden` (el rol no tiene la habilidad), `decision_segregation_of_duties`
     *    (quien aprobó no ejecuta; quien propuso o debe ejecutar no rechaza formalmente), `control_group_not_actionable`, `decision_version_conflict`, `validation_failed`.
     *
     * @param  array{expected_version?: int|null, reason?: string|null, assignee_user_id?: int|null}  $params
     * @return array{sync: string, reason: string|null, from: string, to: string|null, event_id: int|null, required_ability: string|null}
     */
    public function applyImplied(int $recommendationId, ?string $implied, User $actor, ?TenantRole $role, string $clientKey, array $params = []): array
    {
        $rec = DecisionRecommendation::query()->lockForUpdate()->find($recommendationId) ?? throw new NotFoundHttpException('Recomendación no encontrada.');
        $from = (string) $rec->status;
        $outcome = fn (string $sync, ?string $reason = null, ?int $eventId = null, ?string $ability = null): array => [
            'sync' => $sync, 'reason' => $reason, 'from' => $from, 'to' => $implied, 'event_id' => $eventId, 'required_ability' => $ability,
        ];

        if ($implied === null) {
            return $outcome('not_applicable', 'agenda_state_does_not_change_workflow');
        }
        if (in_array($implied, ['suggested', 'assigned'], true)) {
            return $outcome('not_applicable', 'agenda_pending_never_moves_workflow_back');
        }
        if ($implied === $from) {
            return $outcome('unchanged', 'already_in_state');
        }
        $rule = TransitionRules::find($from, $implied);
        if ($rule === null || $rule['system']) {
            return $outcome('skipped', 'transition_not_defined');
        }
        if ($role === null) {
            return $outcome('skipped', 'forbidden');
        }
        // Descartar exige un motivo: el del seguimiento si lo trae, o uno que dice de dónde viene.
        if ($rule['reason'] && mb_strlen(trim((string) ($params['reason'] ?? ''))) < TransitionRules::MIN_REASON_LENGTH) {
            $params['reason'] = 'Descartada desde la agenda comercial.';
        }
        try {
            $result = $this->transition($recommendationId, $implied, $actor, $role, $clientKey, $params);

            return $outcome('applied', null, (int) $result->event->id);
        } catch (DecisionForbiddenException $e) {
            return $outcome('skipped', $e->errorCode, null, $e->ability);
        } catch (DomainStateException $e) {
            return $outcome('skipped', $e->errorCode);
        } catch (ValidationException) {
            return $outcome('skipped', 'validation_failed');
        }
    }

    /**
     * ¿La habilidad del rol o la segregación de funciones impedirían la transición que implica un estado de la agenda? Sirve para RECHAZAR de antemano un seguimiento que
     * cerraría la oportunidad en la agenda (descartado, pedido recibido) sin que el workflow pueda reflejarlo por falta de autoridad: sin esto la oportunidad desaparecería
     * de las listas mientras el workflow sigue donde estaba. No escribe nada. Devuelve null si no hay bloqueo de autorización.
     *
     * @param  array{expected_version?: int|null, reason?: string|null, assignee_user_id?: int|null}  $params
     * @return array{reason: string, ability: string|null}|null
     */
    public function impliedBlockedByAuthorization(int $recommendationId, ?string $implied, User $actor, ?TenantRole $role, array $params = []): ?array
    {
        if ($implied === null || in_array($implied, ['suggested', 'assigned'], true)) {
            return null;
        }
        $rec = DecisionRecommendation::query()->find($recommendationId) ?? throw new NotFoundHttpException('Recomendación no encontrada.');
        $rule = $implied === $rec->status ? null : TransitionRules::find((string) $rec->status, $implied);
        if ($rule === null || $rule['system']) {
            return null;
        }
        if ($role === null) {
            return ['reason' => 'forbidden', 'ability' => null];
        }
        $violation = $this->violation($rule, $rec, $actor, $role, $params, DecisionSettings::forTenant($this->context->require()), true);

        return $violation instanceof DecisionForbiddenException ? ['reason' => $violation->errorCode, 'ability' => $violation->ability] : null;
    }

    /**
     * Transición del SISTEMA (vencimiento, reemplazo por una recomendación más nueva, medición de outcomes de W5-C). Solo las reglas `system`.
     * Devuelve null si la recomendación ya no está en un estado desde el que aplique (otro proceso la movió: no es un error). Idempotente por
     * (recomendación, destino, discriminador).
     *
     * @param  array<string, mixed>  $payload
     */
    public function systemTransition(int $recommendationId, string $to, string $discriminator, string $reason, array $payload = []): ?TransitionResult
    {
        $eventKey = hash('sha256', 'decision.system:'.$recommendationId.':'.$to.':'.$discriminator);

        return DB::transaction(function () use ($recommendationId, $to, $reason, $payload, $eventKey): ?TransitionResult {
            $rec = DecisionRecommendation::query()->lockForUpdate()->find($recommendationId);
            if ($rec === null) {
                return null;
            }
            if (($replay = $this->replaySystem($eventKey, $to)) !== null) {
                return $replay;
            }
            $rule = TransitionRules::find((string) $rec->status, $to);
            if ($rule === null || ! $rule['system']) {
                return null;
            }
            $from = (string) $rec->status;
            $rec->forceFill(['status' => $to])->save();
            $event = DecisionEvent::query()->create([
                'recommendation_id' => $rec->id, 'event' => 'transition', 'from_status' => $from, 'to_status' => $to, 'actor_user_id' => null,
                'actor_kind' => 'system', 'reason' => $reason, 'payload' => ['action' => $rule['action']] + $payload, 'idempotency_key' => $eventKey,
            ]);

            return new TransitionResult($rec->refresh(), $event, false);
        });
    }

    /** Evento inicial de una recomendación recién creada (lo llama el DecisionEngine dentro de su transacción). */
    public function recordProposal(DecisionRecommendation $rec, ?int $userId, array $payload): DecisionEvent
    {
        return DecisionEvent::query()->create([
            'recommendation_id' => $rec->id, 'event' => 'transition', 'from_status' => null, 'to_status' => 'suggested', 'actor_user_id' => $userId,
            'actor_kind' => $userId === null ? 'system' : 'user', 'reason' => 'Recomendación propuesta.', 'payload' => ['action' => 'propose'] + $payload,
            'idempotency_key' => hash('sha256', 'decision.proposed:'.$rec->idempotency_key),
        ]);
    }

    /**
     * Repetición de una transición de una persona. Se AUTORIZA antes de devolver nada (el rol debe tener la habilidad con la que se hizo) y la petición debe ser
     * la MISMA: mismo destino, mismo usuario y mismo cuerpo (huella `payload.request`); otra cosa con la misma clave → 409 `idempotency_key_reused`.
     */
    private function replayFor(string $eventKey, string $to, string $fingerprint, TenantRole $role): ?TransitionResult
    {
        $event = DecisionEvent::query()->where('idempotency_key', $eventKey)->first();
        if ($event === null) {
            return null;
        }
        $ability = $event->payload['ability'] ?? null;
        if (is_string($ability) && ! $role->can($ability)) {
            throw DecisionForbiddenException::missingAbility($ability, 'No tiene permisos para esta acción: exige la habilidad '.$ability.'.');
        }
        if ($event->to_status !== $to || ($event->payload['request'] ?? null) !== $fingerprint) {
            throw new DomainStateException(
                'Esa idempotency_key ya se usó con otro contenido (destino «'.$event->to_status.'»); use otra clave para una petición distinta.',
                'idempotency_key_reused',
                ['recommendation_id' => $event->recommendation_id, 'original_to_status' => $event->to_status, 'requested_to_status' => $to],
            );
        }

        return new TransitionResult(DecisionRecommendation::query()->findOrFail($event->recommendation_id), $event, true);
    }

    /** Huella de la petición: destino, usuario y cuerpo (motivo, asignado, versión esperada). Dos peticiones iguales tienen la misma huella. */
    private function fingerprint(string $to, User $actor, array $params): string
    {
        return hash('sha256', ScoreFormula::canonicalJson([
            'to' => $to, 'actor' => (int) $actor->getKey(), 'reason' => $this->reason($params), 'assignee' => isset($params['assignee_user_id']) ? (int) $params['assignee_user_id'] : null,
            'expected_version' => isset($params['expected_version']) ? (int) $params['expected_version'] : null,
        ]));
    }

    private function replaySystem(string $eventKey, string $to): ?TransitionResult
    {
        $event = DecisionEvent::query()->where('idempotency_key', $eventKey)->first();
        if ($event === null) {
            return null;
        }
        if ($event->to_status !== $to) {
            throw new DomainStateException(
                'Esa idempotency_key ya se usó para pasar a «'.$event->to_status.'»; use otra clave para pasar a «'.$to.'».',
                'idempotency_key_reused',
                ['recommendation_id' => $event->recommendation_id, 'original_to_status' => $event->to_status, 'requested_to_status' => $to],
            );
        }

        return new TransitionResult(DecisionRecommendation::query()->findOrFail($event->recommendation_id), $event, true);
    }

    /**
     * Primera regla que impide la transición al usuario, como excepción lista para lanzar (null = puede). Con `$strict = false` (vista de
     * transiciones disponibles) se omiten las comprobaciones que dependen de los parámetros de la petición (asignado, motivo).
     *
     * @param  array{action: string, from: list<string>, to: string, ability: string|null, system: bool, version: bool, reason: bool, event: string}  $rule
     * @param  array<string, mixed>  $params
     */
    private function violation(array $rule, DecisionRecommendation $rec, User $actor, TenantRole $role, array $params, DecisionSettings $settings, bool $strict): ?Throwable
    {
        if ($rec->experiment_group === 'control') {
            return new DomainStateException(
                'Esta recomendación pertenece al grupo de control del experimento: queda para medición y no se asigna, aprueba ni ejecuta.',
                'control_group_not_actionable', ['recommendation_id' => $rec->id],
            );
        }
        if ($rule['ability'] !== null && ! $role->can($rule['ability'])) {
            return DecisionForbiddenException::missingAbility($rule['ability'], 'No tiene permisos para esta acción ('.$rule['action'].'): exige la habilidad '.$rule['ability'].'.');
        }
        $userId = (int) $actor->getKey();

        // Segregación de funciones: decidir (aprobar/rechazar formalmente) no puede hacerlo quien propuso o debe ejecutar la acción.
        if (in_array($rule['action'], ['approve', 'reject'], true)) {
            $roles = [];
            if ($rec->proposed_by_kind === 'user' && (int) $rec->proposed_by_user_id === $userId) {
                $roles[] = 'proposer';
            }
            if ($rec->assigned_user_id !== null && (int) $rec->assigned_user_id === $userId) {
                $roles[] = 'executor';
            }
            if ($roles !== []) {
                return DecisionForbiddenException::segregation(
                    'No puede '.($rule['action'] === 'approve' ? 'aprobar' : 'rechazar').' esta recomendación: usted la propuso o es quien debe ejecutarla. Debe decidirla otra persona con la habilidad decisions.approve.',
                    $roles,
                );
            }
        }
        // Quien ejecuta no es quien aprobó, y solo ejecuta la persona asignada (o un supervisor con decisions.approve que no aprobó).
        if (in_array($rule['action'], TransitionRules::EXECUTION_ACTIONS, true)) {
            if ($rule['action'] === 'start_without_approval' && $this->requiresApproval($rec, $settings)) {
                return new DomainStateException(
                    'Esta acción es sensible y exige aprobación antes de ejecutarse (reviewed → approved).',
                    'decision_approval_required', ['recommendation_id' => $rec->id, 'kind' => $rec->kind],
                );
            }
            if ($this->approverOf($rec) === $userId) {
                return DecisionForbiddenException::segregation('No puede ejecutar una acción que usted mismo aprobó: la debe ejecutar otra persona.', ['approver']);
            }
            if ($rec->assigned_user_id !== null && (int) $rec->assigned_user_id !== $userId && ! $role->can('decisions.approve')) {
                return DecisionForbiddenException::missingAbility('decisions.approve', 'Solo la persona asignada (o un supervisor con decisions.approve) puede registrar la ejecución.');
            }
        }
        if (! $strict) {
            return null;
        }
        if (in_array($rule['action'], ['assign', 'reassign'], true)) {
            $assignee = $params['assignee_user_id'] ?? null;
            if ($assignee === null) {
                return ValidationException::withMessages(['assignee_user_id' => 'Indique la persona a la que se asigna.']);
            }
            $assigneeRole = TenantRole::tryFrom((string) DB::table('tenant_users')->where('tenant_id', $this->context->id())->where('user_id', (int) $assignee)->value('role'));
            if ($assigneeRole === null || ! $assigneeRole->can('decisions.manage')) {
                return ValidationException::withMessages(['assignee_user_id' => 'La persona asignada debe ser miembro de la empresa con la habilidad decisions.manage.']);
            }
        }
        if ($rule['reason'] && mb_strlen(trim((string) ($params['reason'] ?? ''))) < TransitionRules::MIN_REASON_LENGTH) {
            return ValidationException::withMessages(['reason' => 'El motivo es obligatorio (al menos '.TransitionRules::MIN_REASON_LENGTH.' caracteres).']);
        }

        return null;
    }

    /**
     * Usuario que aprobó la recomendación (la transición `reviewed → approved`), o null. Solo cuenta la transición real: un evento de seguimiento de la
     * agenda (`feedback`, W5-A) registrado mientras la recomendación está aprobada también lleva `to_status = approved` pero no es una aprobación.
     */
    private function approverOf(DecisionRecommendation $rec): ?int
    {
        // La lista precarga el aprobador con una subconsulta (`approver_user_id`) para no consultar una vez por fila.
        if (array_key_exists('approver_user_id', $rec->getAttributes())) {
            return $rec->getAttributes()['approver_user_id'] === null ? null : (int) $rec->getAttributes()['approver_user_id'];
        }
        $id = DecisionEvent::query()->where('recommendation_id', $rec->id)->where('event', 'transition')->where('from_status', 'reviewed')->where('to_status', 'approved')
            ->orderByDesc('id')->value('actor_user_id');

        return $id === null ? null : (int) $id;
    }

    /** @param array<string, mixed> $params */
    private function reason(array $params): ?string
    {
        $reason = trim((string) ($params['reason'] ?? ''));

        return $reason === '' ? null : mb_substr($reason, 0, 1000);
    }

    private function codeOf(Throwable $e): string
    {
        return match (true) {
            $e instanceof DecisionForbiddenException => $e->errorCode,
            $e instanceof DomainStateException => $e->errorCode,
            default => 'invalid',
        };
    }
}
