<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation\Gateway;

use App\Services\Conversation\Channels\CacheDraftStore;
use App\Services\Conversation\Channels\DraftMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** V2.5 · W6-D: el almacén de borradores está aislado por empresa en DOS capas independientes (prefijo de la clave y comprobación de la empresa guardada) y su reclamo es de un solo uso. */
class CacheDraftStoreTest extends TestCase
{
    private function draft(int $tenantId, string $id = 'd-1'): DraftMessage
    {
        $now = CarbonImmutable::now();

        return new DraftMessage($id, $tenantId, 'whatsapp', 'opportunity_follow_up', 1, 5, 'Hola Marta', hash('sha256', 'Hola Marta'), 9, $now, $now->addHour());
    }

    public function test_keys_carry_the_tenant_prefix_and_another_tenant_cannot_find_the_draft(): void
    {
        $store = new CacheDraftStore;
        $store->put($this->draft(7));

        $this->assertTrue(Cache::has('tenant:7:gateway:draft:d-1'));
        $this->assertFalse(Cache::has('gateway:draft:d-1'), 'la clave sin prefijo de empresa no existe');
        $this->assertNotNull($store->find(7, 'd-1'));
        $this->assertNull($store->find(8, 'd-1'));
    }

    public function test_an_entry_stored_under_another_tenants_key_is_ignored_even_if_the_key_collides(): void
    {
        $store = new CacheDraftStore;
        // Entrada de la empresa 7 colocada (por error o por un ataque al caché) bajo la clave de la empresa 8.
        Cache::put('tenant:8:gateway:draft:d-2', $this->draft(7, 'd-2')->toStorage(), now()->addHour());

        $this->assertNull($store->find(8, 'd-2'), 'la empresa guardada manda, no solo la clave');
    }

    public function test_a_draft_can_be_claimed_once_per_tenant_and_discarding_removes_the_text(): void
    {
        $store = new CacheDraftStore;
        $store->put($this->draft(7));

        $this->assertFalse($store->wasClaimed(7, 'd-1'));
        $this->assertTrue($store->claim(7, 'd-1'));
        $this->assertFalse($store->claim(7, 'd-1'), 'uso único');
        $this->assertTrue($store->wasClaimed(7, 'd-1'));
        $this->assertTrue($store->claim(8, 'd-1'), 'el reclamo de otra empresa es independiente');

        $store->discard(7, 'd-1');
        $this->assertNull($store->find(7, 'd-1'));
    }
}
