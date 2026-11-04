<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

/**
 * Plantillas de mensaje como BORRADOR (V2.5 · W6-D). Texto de servicio, sin promesas de precio, plazo ni disponibilidad que Predictily no pueda respaldar; el envío siempre lo
 * decide una persona. Su aprobación por el proveedor del canal es un trámite del propietario (BLOQUEADO).
 */
final class TemplateCatalog
{
    /** @return list<MessageTemplate> */
    public function all(): array
    {
        return [
            new MessageTemplate(
                'opportunity_follow_up', 1, 'es', 'utility',
                'Hola {{contacto}}, le escribimos de {{empresa}}. Notamos que {{producto}} suele formar parte de sus compras y queríamos saber si necesita reponerlo. ¿Le preparamos una cotización?',
                ['contacto', 'empresa', 'producto'],
            ),
            new MessageTemplate(
                'stock_available_notice', 1, 'es', 'utility',
                'Hola {{contacto}}, le informamos que {{producto}} está disponible nuevamente en {{empresa}}. Quedamos atentos a su pedido.',
                ['contacto', 'empresa', 'producto'],
            ),
        ];
    }

    public function find(string $key): ?MessageTemplate
    {
        foreach ($this->all() as $template) {
            if ($template->key === $key) {
                return $template;
            }
        }

        return null;
    }
}
