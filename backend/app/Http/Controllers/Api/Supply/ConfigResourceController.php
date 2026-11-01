<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Supply;

use App\Services\Forecasting\Support\CoreServices;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CRUD de los datos de configuración que consume el planificador (ofertas, tasas, capacidades, presupuestos, recursos y
 * recetas). Lectura: forecast.view. Escritura: catalog.manage. Toda referencia se valida contra la empresa activa y
 * las claves foráneas compuestas de la base impiden referencias entre empresas.
 */
abstract class ConfigResourceController extends SupplyController
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Etiqueta de auditoría (supply.<entity>.<action>). */
    abstract protected function entity(): string;

    /** @return array<string, mixed> reglas de validación; $id != null al actualizar */
    abstract protected function rules(?int $id): array;

    /** @return array<string, mixed> */
    abstract protected function present(Model $model): array;

    /** @return list<string> */
    protected function with(): array
    {
        return [];
    }

    /** @return list<string> filtros de igualdad permitidos en el listado */
    protected function filters(): array
    {
        return [];
    }

    protected function orderBy(): string
    {
        return 'id';
    }

    /** Hook previo a guardar: normaliza o rechaza combinaciones inválidas. @param array<string,mixed> $data @return array<string,mixed> */
    protected function prepare(array $data, ?Model $existing): array
    {
        return $data;
    }

    /** @param array<string,mixed> $data */
    protected function afterSave(Model $model, array $data): void {}

    public function index(Request $request): JsonResponse
    {
        /** @var Builder $query */
        $query = ($this->model())::query()->with($this->with())->orderByDesc($this->orderBy());
        foreach ($this->filters() as $f) {
            if ($request->filled($f)) {
                $query->where($f, $request->input($f));
            }
        }
        $page = $query->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Model $m) => $this->present($m))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $m = ($this->model())::query()->with($this->with())->findOrFail($id);

        return response()->json(['data' => $this->present($m)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->prepare($request->validate($this->rules(null)), null);
        try {
            $m = DB::transaction(fn () => ($this->model())::query()->create($this->fill($data)));   // savepoint: el error de unicidad no aborta la transacción externa
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['_' => 'Ya existe un registro con la misma clave natural (revise proveedor, producto, sucursal, vigencia o período).']);
        }
        $this->afterSave($m, $data);
        CoreServices::audit('supply.'.$this->entity().'.created', $m, ['id' => $m->getKey()]);

        return response()->json(['data' => $this->present($m->fresh($this->with()) ?? $m)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $m = ($this->model())::query()->findOrFail($id);
        $data = $this->prepare($request->validate($this->rules($id)), $m);
        try {
            DB::transaction(fn () => $m->fill($this->fill($data))->save());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['_' => 'Ya existe un registro con la misma clave natural.']);
        }
        $this->afterSave($m, $data);
        CoreServices::audit('supply.'.$this->entity().'.updated', $m, ['id' => $m->getKey()]);

        return response()->json(['data' => $this->present($m->fresh($this->with()) ?? $m)]);
    }

    public function destroy(int $id): Response
    {
        $m = ($this->model())::query()->findOrFail($id);
        $m->delete();
        CoreServices::audit('supply.'.$this->entity().'.deleted', null, ['id' => $id]);

        return response()->noContent();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    protected function fill(array $data): array
    {
        return $data;
    }
}
