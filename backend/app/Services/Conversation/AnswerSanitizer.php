<?php

declare(strict_types=1);

namespace App\Services\Conversation;

/**
 * Saneado de la respuesta del modelo en el SERVIDOR (defensa en profundidad: la SPA muestra texto plano, pero la API la consumen otros clientes). El asistente nunca necesita
 * enlaces, imágenes ni marcado: se quitan las etiquetas y los comentarios HTML, las imágenes y las referencias de Markdown, los enlaces de Markdown se reducen a su texto y
 * las direcciones URL desnudas se omiten; también los caracteres de control y de formato. No sustituye la comprobación de cifras ni la autorización: solo evita que un enlace o un
 * marcado inyectado en un dato llegue al usuario por esta vía.
 */
final class AnswerSanitizer
{
    public const LINK_OMITTED = '[enlace omitido]';

    public static function clean(string $answer): string
    {
        $text = preg_replace('~<!--.*?-->~su', ' ', $answer) ?? $answer;
        $text = preg_replace('~</?[a-zA-Z][^>]{0,300}>~u', ' ', $text) ?? $text;
        $text = preg_replace('~!\[[^\]]{0,300}\]\s*(?:\([^)]{0,1000}\)|\[[^\]]{0,100}\])~u', '', $text) ?? $text;
        $text = preg_replace('~(?:^|\n)\s*\[[^\]]+\]:\s*\S+[^\n]*~u', '', $text) ?? $text;
        $text = preg_replace('~\[([^\]]{0,300})\]\s*(?:\([^)]{0,1000}\)|\[[^\]]{0,100}\])~u', '$1', $text) ?? $text;
        $text = preg_replace('~(?:(?:https?|ftp|ftps|file|javascript|data):/?/?[^\s<>"\')]+|www\.[^\s<>"\')]+)~iu', self::LINK_OMITTED, $text) ?? $text;
        $text = preg_replace('/[^\P{Cc}\n\t]+|\p{Cf}+/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);
    }
}
