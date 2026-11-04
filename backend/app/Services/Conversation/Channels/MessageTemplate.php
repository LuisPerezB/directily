<?php

declare(strict_types=1);

namespace App\Services\Conversation\Channels;

use InvalidArgumentException;
use Normalizer;

/**
 * Plantilla de mensaje de un canal. En Predictily toda plantilla es un BORRADOR: la aprobación del proveedor (p. ej. de WhatsApp Business) es un trámite del propietario
 * con su cuenta y queda BLOQUEADA aquí (`provider_approval` = not_submitted). Los marcadores se escriben {{nombre}} y se rellenan con valores acotados y saneados.
 */
final class MessageTemplate
{
    public const STATUS_DRAFT = 'draft';

    private const MAX_VALUE_CHARS = 80;

    /** @param list<string> $placeholders */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly string $locale,
        public readonly string $category,
        public readonly string $body,
        public readonly array $placeholders,
        public readonly string $status = self::STATUS_DRAFT,
    ) {}

    /**
     * Texto final. Exige EXACTAMENTE los marcadores declarados; cada valor es texto de UNA línea (se rechaza, no se aplana, un salto de línea) de hasta 80 caracteres, normalizado
     * (NFKC), sin llaves ni marcado, sin enlaces, dominios, correos ni usuarios y sin números largos (teléfonos).
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(array $variables): string
    {
        $missing = array_values(array_diff($this->placeholders, array_keys($variables)));
        $extra = array_values(array_diff(array_map('strval', array_keys($variables)), $this->placeholders));
        if ($missing !== [] || $extra !== []) {
            throw new InvalidArgumentException('Variables de plantilla inválidas'.($missing !== [] ? ': falta '.implode(', ', $missing) : '').($extra !== [] ? ': sobra '.implode(', ', $extra) : '').'.');
        }
        $text = $this->body;
        foreach ($this->placeholders as $name) {
            $text = str_replace('{{'.$name.'}}', $this->clean($name, $variables[$name]), $text);
        }

        return $text;
    }

    /** Dominios y acortadores: un valor que los contenga podría llevar al destinatario a un sitio ajeno aunque no empiece por «http». */
    private const TLDS = 'com|net|org|edu|gov|io|co|ec|me|ly|gl|to|tk|ml|ga|cf|gq|xyz|top|club|site|online|app|dev|shop|store|biz|info|link|click|live|tv|us|es|ru|cn|in|br|ar|pe|cl|mx|uk|de|fr|it|cc|ws|vip|icu|pw|page|cloud|tech|website|zip|mov';

    private function clean(string $name, mixed $value): string
    {
        $reject = fn (string $why) => new InvalidArgumentException("La variable «{$name}» no es válida ({$why}).");
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("La variable «{$name}» debe ser un texto no vacío.");
        }
        // NFKC primero: «ＨＴＴＰ：／／evil.com» (ancho completo) y las formas compatibles se leen como lo que son antes de revisar el contenido.
        $v = class_exists(Normalizer::class) ? (Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value) : $value;
        // Un salto de línea o un carácter de control cambia el sentido del mensaje (agrega otra frase): se RECHAZA, no se aplana.
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $v) === 1) {
            throw $reject('una sola línea, sin caracteres de control');
        }
        $v = trim($v);
        if ($v === '' || mb_strlen($v) > self::MAX_VALUE_CHARS) {
            throw $reject('hasta '.self::MAX_VALUE_CHARS.' caracteres');
        }
        if (str_contains($v, '{{') || str_contains($v, '}}') || preg_match('/[<>\[\]{}|\\\\]/u', $v) === 1) {
            throw $reject('sin llaves, corchetes ni marcado');
        }
        $lower = mb_strtolower($v);
        if (preg_match('~(?:\b(?:https?|ftp|ftps|file|javascript|data|mailto|tel|sms|wa|whatsapp):|www\.|//|@)~i', $lower) === 1
            || preg_match('~\b[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:'.self::TLDS.')\b~i', $lower) === 1
            || preg_match('~\b(?:bit\.ly|t\.co|goo\.gl|tinyurl|wa\.me|cutt\.ly|is\.gd|ow\.ly)\b~i', $lower) === 1) {
            throw $reject('sin enlaces, dominios, correos ni usuarios');
        }
        // Teléfonos y otros números largos: un contacto no es un número al que llamar.
        if (preg_match_all('/\d/', $v) >= 7) {
            throw $reject('sin números de teléfono ni cifras largas');
        }

        return $v;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key, 'version' => $this->version, 'locale' => $this->locale, 'category' => $this->category, 'status' => $this->status,
            'provider_approval' => 'not_submitted', 'body' => $this->body, 'placeholders' => $this->placeholders,
        ];
    }
}
