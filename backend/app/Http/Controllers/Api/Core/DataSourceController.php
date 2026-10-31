<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Core;

use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Connectors\Sql\SqlConnectorInterface;
use App\Services\Plans\PlanLimitService;
use App\Support\Results\JsonMap;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Fuentes de datos. Las credenciales se guardan cifradas y NUNCA se devuelven. */
final class DataSourceController extends Controller
{
    public function __construct(
        private readonly ConnectorRegistry $connectors,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $plans,
    ) {}

    public function connectors(): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($c) => $c->toArray(), $this->connectors->all())]);
    }

    public function index(): JsonResponse
    {
        $sources = DataSource::query()->orderBy('name')->get();
        $tokens = PersonalAccessToken::query()->where('tenant_id', $this->context->id())
            ->where('name', 'like', 'datasource:%')->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])
            ->groupBy(fn ($t) => (int) substr($t->name, strlen('datasource:')));

        return response()->json(['data' => $sources->map(fn (DataSource $s) => $this->present($s, $tokens->get($s->id, collect())->all()))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $source = new DataSource;
        $source->fill([
            'type' => $data['type'],
            'connector_key' => $data['connector_key'] ?? $this->defaultConnector($data['type']),
            'name' => $data['name'],
            'config' => $data['config'] ?? [],
            'credentials_encrypted' => $data['credentials'] ?? null,
            'status' => 'active',
        ]);
        $connector = $this->connectors->get((string) $source->connector_key);
        if ($connector instanceof SqlConnectorInterface) {
            // Etapa 2 · P3: host/puerto/base y credenciales se validan; la fuente queda activa solo si la conexión de solo lectura funciona.
            $connector->validateInput((array) ($data['config'] ?? []), $data['credentials'] ?? null);
            $result = $connector->test($source);
            $source->status = $result->ok ? 'active' : 'inactive';
            $source->last_error = $result->ok ? null : $result->message;
        } elseif ($connector !== null && $connector->status() !== 'available') {
            $source->status = 'inactive';
            $source->last_error = $connector->test($source)->message;
        }
        $source->save();
        $source->refresh();
        $this->audit->log('datasource.created', $source, ['type' => $source->type, 'connector_key' => $source->connector_key]);

        return response()->json(['data' => $this->present($source)], 201);
    }

    public function update(Request $request, DataSource $dataSource): JsonResponse
    {
        $data = $this->validated($request, $dataSource);
        $connector = $this->connectors->get((string) $dataSource->connector_key);
        if ($connector instanceof SqlConnectorInterface && (array_key_exists('config', $data) || array_key_exists('credentials', $data))) {
            $connector->validateInput(
                array_key_exists('config', $data) ? (array) $data['config'] : (array) $dataSource->config,
                array_key_exists('credentials', $data) ? $data['credentials'] : (array) $dataSource->credentials_encrypted,
            );
        }
        $attributes = array_intersect_key($data, array_flip(['name', 'config', 'status']));
        if (array_key_exists('credentials', $data)) {
            $attributes['credentials_encrypted'] = $data['credentials'];
        }
        $dataSource->fill($attributes)->save();
        $this->audit->log('datasource.updated', $dataSource, ['fields' => array_keys($attributes)]);

        return response()->json(['data' => $this->present($dataSource)]);
    }

    public function destroy(DataSource $dataSource): Response
    {
        PersonalAccessToken::query()->where('tenant_id', $this->context->id())->where('name', 'datasource:'.$dataSource->id)->delete();
        $dataSource->delete();
        $this->audit->log('datasource.deleted', $dataSource);

        return response()->noContent();
    }

    /** Crea un token de API ligado a la empresa y a esta fuente. Se muestra una sola vez. */
    public function createToken(Request $request, DataSource $dataSource): JsonResponse
    {
        $tenant = $this->context->require();
        $this->plans->assertFeature($tenant, 'api_access');
        $data = $request->validate(['expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:730']]);
        /** @var User $user */
        $user = $request->user();
        $new = $user->createToken('datasource:'.$dataSource->id, ['imports.manage', 'data.view'], isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : now()->addYear());
        $new->accessToken->forceFill(['tenant_id' => $tenant->id])->save();
        $this->audit->log('datasource.token_created', $dataSource, ['token_id' => $new->accessToken->id]);

        return response()->json(['data' => [
            'token' => $new->plainTextToken,
            'token_id' => $new->accessToken->id,
            'expires_at' => $new->accessToken->expires_at?->toIso8601String(),
            'abilities' => ['imports.manage', 'data.view'],
            'usage' => 'Authorization: Bearer <token> — POST /api/v1/sales/batch',
        ]], 201);
    }

    public function revokeToken(DataSource $dataSource, int $tokenId): Response
    {
        PersonalAccessToken::query()->where('tenant_id', $this->context->id())->where('name', 'datasource:'.$dataSource->id)->whereKey($tokenId)->delete();
        $this->audit->log('datasource.token_revoked', $dataSource, ['token_id' => $tokenId]);

        return response()->noContent();
    }

    public function test(DataSource $dataSource): JsonResponse
    {
        $connector = $this->connectors->get((string) $dataSource->connector_key);
        $result = $connector?->test($dataSource)->toArray() ?? ['ok' => false, 'message' => 'Conector no registrado.', 'details' => []];

        return response()->json(['data' => $result]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DataSource $source = null): array
    {
        $creating = $source === null;

        return $request->validate([
            'type' => [$creating ? 'required' : 'prohibited', Rule::in(['file_upload', 'rest_api', 'connector'])],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'connector_key' => [$creating ? 'nullable' : 'prohibited', 'string', Rule::in($this->connectors->keys()), 'required_if:type,connector'],
            'config' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'credentials.*' => ['string', 'max:500'],
            'status' => [$creating ? 'prohibited' : 'sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }

    private function defaultConnector(string $type): string
    {
        return match ($type) {
            'rest_api' => 'generic_rest',
            default => 'generic_csv',
        };
    }

    /** @param list<PersonalAccessToken> $tokens */
    private function present(DataSource $s, array $tokens = []): array
    {
        $connector = $this->connectors->get((string) $s->connector_key);

        return [
            'id' => $s->id,
            'type' => $s->type,
            'connector_key' => $s->connector_key,
            'connector' => $connector ? ['name' => $connector->name(), 'status' => $connector->status()] : null,
            'name' => $s->name,
            'status' => $s->status,
            'config' => JsonMap::orEmpty($s->config),
            'has_credentials' => $s->getRawOriginal('credentials_encrypted') !== null,
            'last_synced_at' => $s->last_synced_at?->toIso8601String(),
            'last_error' => $s->last_error,
            'tokens' => array_map(fn ($t) => ['id' => $t->id, 'last_used_at' => $t->last_used_at?->toIso8601String(), 'expires_at' => $t->expires_at?->toIso8601String(), 'created_at' => $t->created_at?->toIso8601String()], $tokens),
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
