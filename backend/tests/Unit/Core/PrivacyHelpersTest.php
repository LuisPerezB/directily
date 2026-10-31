<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Support\Privacy\CustomerIdentity;
use App\Support\Privacy\PiiMasker;
use Tests\TestCase;

final class PrivacyHelpersTest extends TestCase
{
    public function test_masker_hides_emails_ids_and_tokens(): void
    {
        $masked = PiiMasker::mask('Contacto: juan@empresa.ec, CI 0912345678, RUC 0992345678001, token 12|AbCdEfGhIjKlMnOpQrStUvWx');
        $this->assertSame('Contacto: j***@e***.ec, CI [ID], RUC [ID], token [TOKEN]', $masked);
        $this->assertSame(['password' => '[REDACTED]', 'nested' => ['email' => 'a***@b***.com']], PiiMasker::sanitize(['password' => 'x', 'nested' => ['email' => 'ana@b.com']]));
    }

    public function test_sanitize_cleans_json_maps_and_keeps_them_as_objects(): void
    {
        $clean = PiiMasker::sanitize(['model_version' => (object) ['contact' => 'ana@b.com', 'token' => 'x'], 'empty' => (object) []]);

        $this->assertInstanceOf(\stdClass::class, $clean['model_version']);
        $this->assertSame('a***@b***.com', $clean['model_version']->contact);
        $this->assertSame('[REDACTED]', $clean['model_version']->token);
        $this->assertSame('{}', json_encode($clean['empty']), 'un mapa vacío no se vuelve lista');
    }

    public function test_customer_identity_never_keeps_tax_ids_in_clear(): void
    {
        $person = CustomerIdentity::fromCode('1710034065');
        $this->assertStringStartsWith('id:', $person['external_code']);
        $this->assertStringNotContainsString('1710034065', $person['external_code']);
        $this->assertSame('person', $person['customer_type']);
        $this->assertSame(64, strlen((string) $person['tax_id_hash']));
        $this->assertSame($person, CustomerIdentity::fromCode('171003406-5'), 'Mismo documento con formato distinto → misma huella');

        $this->assertSame('company', CustomerIdentity::fromCode('1790012345001')['customer_type']);
        $this->assertSame('CONSUMIDOR_FINAL', CustomerIdentity::fromCode('9999999999999')['external_code']);
        $this->assertSame(['external_code' => 'CLI-001', 'customer_type' => 'unknown', 'tax_id_hash' => null], CustomerIdentity::fromCode('CLI-001'));
    }
}
