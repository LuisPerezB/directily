<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation\Gateway;

use App\Services\Conversation\Channels\MessageTemplate;
use App\Services\Conversation\Channels\TemplateCatalog;
use App\Services\Conversation\Channels\WebhookSignature;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** V2.5 · W6-D (V25-GWY-019/-022/-023): piezas puras del contrato del canal de mensajería: firma del webhook y plantillas como borrador. */
class ChannelContractUnitTest extends TestCase
{
    public function test_webhook_signature_accepts_only_the_exact_hmac_of_the_raw_body(): void
    {
        $body = '{"entry":[{"id":"1"}]}';
        $valid = WebhookSignature::sign('secreto-de-prueba', $body);

        $this->assertStringStartsWith('sha256=', $valid);
        $this->assertTrue(WebhookSignature::verify('secreto-de-prueba', $body, $valid));
        $this->assertFalse(WebhookSignature::verify('secreto-de-prueba', $body.' ', $valid), 'cuerpo alterado');
        $this->assertFalse(WebhookSignature::verify('otro-secreto', $body, $valid), 'otro secreto');
        $this->assertFalse(WebhookSignature::verify('secreto-de-prueba', $body, substr($valid, 7)), 'sin el prefijo sha256=');
        $this->assertFalse(WebhookSignature::verify('secreto-de-prueba', $body, 'sha1='.substr($valid, 7)), 'algoritmo distinto');
        $this->assertFalse(WebhookSignature::verify('secreto-de-prueba', $body, null), 'sin cabecera');
        $this->assertFalse(WebhookSignature::verify('', $body, WebhookSignature::sign('', $body)), 'un secreto vacío nunca verifica');
    }

    public function test_every_builtin_template_is_a_draft_not_approved_by_any_provider(): void
    {
        $templates = (new TemplateCatalog)->all();
        $this->assertNotEmpty($templates);
        foreach ($templates as $template) {
            $array = $template->toArray();
            $this->assertSame('draft', $array['status']);
            $this->assertSame('not_submitted', $array['provider_approval']);
            $this->assertSame('es', $array['locale']);
            // Cada marcador declarado aparece en el texto y viceversa.
            preg_match_all('/\{\{([a-z_]+)\}\}/', $template->body, $m);
            $this->assertEqualsCanonicalizing($template->placeholders, array_unique($m[1]));
        }
        $this->assertNotNull((new TemplateCatalog)->find('opportunity_follow_up'));
        $this->assertNull((new TemplateCatalog)->find('inexistente'));
    }

    public function test_rendering_requires_exactly_the_declared_placeholders(): void
    {
        $template = (new TemplateCatalog)->find('stock_available_notice');
        $this->assertSame(
            'Hola Marta, le informamos que Cemento 50kg está disponible nuevamente en Ferretería Andina. Quedamos atentos a su pedido.',
            $template->render(['contacto' => 'Marta', 'empresa' => 'Ferretería Andina', 'producto' => 'Cemento 50kg']),
        );
        $this->expectException(InvalidArgumentException::class);
        $template->render(['contacto' => 'Marta', 'empresa' => 'Ferretería Andina']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badVariables(): array
    {
        return [
            'variable de más' => [['contacto' => 'Ana', 'extra' => 'x']],
            'enlace con esquema' => [['contacto' => 'visite https://evil.example']],
            'www' => [['contacto' => 'www.evil.example']],
            'acortador (bit.ly)' => [['contacto' => 'bit.ly/abc123']],
            'dominio con guion' => [['contacto' => 'evil-site.com']],
            'dominio con subdominio' => [['contacto' => 'pago.seguro.net/x']],
            'dominio .ec' => [['contacto' => 'tienda.com.ec']],
            'wa.me' => [['contacto' => 'wa.me/593999999999']],
            'ancho completo (NFKC)' => [['contacto' => 'ＨＴＴＰ：／／evil.com']],
            'correo' => [['contacto' => 'ana@evil.com']],
            'esquema en ancho completo, sin dominio (solo NFKC lo revela)' => [['contacto' => 'ｈｔｔｐｓ：／／ａ']],
            'arroba en ancho completo (solo NFKC lo revela)' => [['contacto' => 'ａ＠ｂ']],
            'usuario @' => [['contacto' => '@soporte_oficial']],
            'teléfono con signo más' => [['contacto' => '+593 99 999 9999']],
            'teléfono con guiones' => [['contacto' => '099-999-9999']],
            'salto de línea que agrega otra frase' => [['contacto' => "Marta\nSu cuenta fue bloqueada"]],
            'retorno de carro' => [['contacto' => "Marta\rLlame ya"]],
            'tabulación' => [['contacto' => "Marta\tPérez"]],
            'separador de línea Unicode' => [['contacto' => "Marta\u{2028}Pérez"]],
            'llaves dobles' => [['contacto' => '{{producto}}']],
            'marcado' => [['contacto' => 'Ana <b>Pérez</b>']],
            'corchetes' => [['contacto' => 'Ana [Pérez]']],
            'demasiado largo' => [['contacto' => str_repeat('a', 81)]],
            'vacío' => [['contacto' => '   ']],
            'no es texto' => [['contacto' => ['x']]],
        ];
    }

    /** @param array<string, mixed> $variables */
    #[DataProvider('badVariables')]
    public function test_rendering_rejects_variables_that_could_carry_links_numbers_or_extra_sentences(array $variables): void
    {
        $template = new MessageTemplate('t', 1, 'es', 'utility', 'Hola {{contacto}}', ['contacto']);
        $this->expectException(InvalidArgumentException::class);
        $template->render($variables);
    }

    public function test_ordinary_names_with_accents_dots_and_short_numbers_are_accepted(): void
    {
        $template = new MessageTemplate('t', 1, 'es', 'utility', 'Hola {{contacto}}', ['contacto']);
        foreach (['Ana Pérez', 'María José Núñez', 'Ferretería S.A.', 'Cemento 50kg', 'Dr. Ortiz', 'Sucursal 12', "O'Brien"] as $ok) {
            $this->assertSame('Hola '.$ok, $template->render(['contacto' => $ok]));
        }
    }
}
