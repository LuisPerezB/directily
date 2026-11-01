<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ChangeCodeRequest;
use App\Http\Requests\Catalog\StoreAliasRequest;
use App\Services\Catalog\IdentityAliasService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Alias de identidad y cambio de código (ADR-V25-24). GET: `data.view`; POST/DELETE: `catalog.manage`. Aislado por empresa:
 * la entidad se resuelve con el global scope de BelongsToTenant, así que un id de otra empresa responde 404.
 */
abstract class AbstractAliasController extends Controller
{
    public function __construct(protected readonly IdentityAliasService $aliases) {}

    abstract protected function type(): string;

    /** @return class-string<Model> */
    abstract protected function aliasClass(): string;

    protected function aliasIndex(Model $subject): JsonResponse
    {
        $rows = $this->aliasClass()::query()->where($this->type().'_id', $subject->getKey())->orderBy('id')->get();

        return response()->json(['data' => $rows->map(fn (Model $a) => $a->toApi())->values()]);
    }

    protected function aliasStore(StoreAliasRequest $request, Model $subject): JsonResponse
    {
        $alias = $this->aliases->add($this->type(), $subject, $request->validated(), $request->user()?->id);

        return response()->json(['data' => $alias->toApi()], 201);
    }

    protected function aliasDestroy(Model $subject, int $aliasId): Response
    {
        $alias = $this->aliasClass()::query()->where($this->type().'_id', $subject->getKey())->findOrFail($aliasId);
        $this->aliases->remove($this->type(), $subject, $alias);

        return response()->noContent();
    }

    /** @return array{subject: Model, alias: Model, previous_code: string} */
    protected function applyCodeChange(ChangeCodeRequest $request, Model $subject): array
    {
        $data = $request->validated();

        return $this->aliases->changeCode($this->type(), $subject, $data['old_code'], $data['new_code'], $data['effective_from'], $request->user()?->id);
    }
}
