<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Requests\Catalog\ChangeCodeRequest;
use App\Http\Requests\Catalog\StoreAliasRequest;
use App\Models\Customer;
use App\Models\CustomerAlias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Alias y cambio de código de cliente. Los códigos se minimizan (cédula/RUC nunca en claro) y ninguna respuesta los devuelve
 * en claro. Fusión y división de clientes: DISEÑADAS, no implementadas.
 */
final class CustomerAliasController extends AbstractAliasController
{
    protected function type(): string
    {
        return 'customer';
    }

    protected function aliasClass(): string
    {
        return CustomerAlias::class;
    }

    public function index(Customer $customer): JsonResponse
    {
        return $this->aliasIndex($customer);
    }

    public function store(StoreAliasRequest $request, Customer $customer): JsonResponse
    {
        return $this->aliasStore($request, $customer);
    }

    public function destroy(Customer $customer, int $alias): Response
    {
        return $this->aliasDestroy($customer, $alias);
    }

    public function codeChange(ChangeCodeRequest $request, Customer $customer): JsonResponse
    {
        $result = $this->applyCodeChange($request, $customer);

        return response()->json(['data' => [
            'customer_id' => $customer->id,
            'external_code' => $result['subject']->external_code,
            'effective_from' => $request->validated('effective_from'),
            'alias' => $result['alias']->toApi(),
        ]]);
    }
}
