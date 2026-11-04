<?php

declare(strict_types=1);

namespace App\Services\Bridge;

use Illuminate\Support\Facades\DB;

/**
 * Memoria anti-repetición de las peticiones firmadas del dispositivo (revisión adversarial B-5). Un nonce solo puede usarse UNA vez por dispositivo
 * mientras la petición es aceptable (±`signature_skew_seconds`). Se guarda en la base de datos —no en la caché— con clave primaria (dispositivo, nonce):
 * `INSERT … ON CONFLICT DO NOTHING` es atómico entre instancias y la protección NO depende de la durabilidad ni del tipo del almacén de caché (un vaciado,
 * o una caché por proceso, no reabre la repetición). Las filas vencidas se podan de forma oportunista (1 de cada 50 peticiones).
 */
final class BridgeNonceStore
{
    /** true si el nonce es NUEVO (se registra); false si ya se usó. */
    public function remember(int $deviceId, string $nonce): bool
    {
        $ttl = (int) config('bridge.nonce_ttl_seconds', 900);
        $inserted = DB::table('bridge_request_nonces')->insertOrIgnore([
            'bridge_device_id' => $deviceId, 'nonce' => $nonce, 'expires_at' => now()->addSeconds($ttl),
        ]);
        if (random_int(1, 50) === 1) {
            $this->prune();
        }

        return $inserted === 1;
    }

    /** Borra los nonces vencidos (ya no podrían aceptarse por la ventana de reloj). Devuelve cuántos. */
    public function prune(): int
    {
        return DB::table('bridge_request_nonces')->where('expires_at', '<', now())->delete();
    }
}
