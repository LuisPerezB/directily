<?php

declare(strict_types=1);

namespace Tests\Unit\Bridge;

use App\Services\Bridge\BridgeEnrolmentService;
use App\Services\Bridge\BridgeSignature;
use PHPUnit\Framework\TestCase;

/**
 * W6-B · La firma de peticiones del Bridge es la MISMA en el agente Python y aquí: vectores compartidos (bridge/tests/fixtures/signature_vectors.json).
 * Ed25519 es determinista: firmar con la misma semilla produce los mismos bytes, así que se comprueba el mensaje, la firma y la huella.
 */
final class BridgeSignatureVectorsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../../../bridge/tests/fixtures/signature_vectors.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    public function test_message_signature_and_fingerprint_match_the_python_agent(): void
    {
        $v = self::vectors();
        $pair = sodium_crypto_sign_seed_keypair(BridgeSignature::decode($v['seed_b64']));
        $public = sodium_crypto_sign_publickey($pair);
        $secret = sodium_crypto_sign_secretkey($pair);
        $this->assertSame($v['public_key_b64'], BridgeSignature::encode($public));
        $this->assertSame($v['fingerprint'], BridgeSignature::fingerprint($public));

        foreach ($v['requests'] as $r) {
            $message = BridgeSignature::message($r['method'], $r['path'], $r['query'], $r['timestamp'], $r['nonce'], $r['body']);
            $this->assertSame($r['message'], $message, 'mensaje canónico idéntico al del agente');
            $this->assertSame($r['signature'], BridgeSignature::encode(sodium_crypto_sign_detached($message, $secret)), 'misma firma (Ed25519 determinista)');
            $this->assertTrue(BridgeSignature::verify($public, BridgeSignature::decode($r['signature']), $message));
            // Cualquier alteración invalida la firma.
            $this->assertFalse(BridgeSignature::verify($public, BridgeSignature::decode($r['signature']), $message.'x'));
            $this->assertFalse(BridgeSignature::verify($public, BridgeSignature::decode($r['signature']), BridgeSignature::message($r['method'], $r['path'].'/x', $r['query'], $r['timestamp'], $r['nonce'], $r['body'])));
        }
    }

    public function test_enrolment_proof_matches_the_python_agent(): void
    {
        $v = self::vectors();
        $e = $v['enrol'];
        $this->assertSame($e['normalized'], BridgeEnrolmentService::normalize($e['code']));
        $this->assertSame($e['code_hash'], BridgeEnrolmentService::hashCode(BridgeEnrolmentService::normalize($e['code'])));
        $this->assertSame($e['message'], BridgeSignature::enrolMessage($e['code_hash'], $v['public_key_b64'], $e['timestamp']));
        $this->assertTrue(BridgeSignature::verify(BridgeSignature::decode($v['public_key_b64']), BridgeSignature::decode($e['proof']), $e['message']));
    }

    public function test_code_normalisation_is_forgiving_about_separators_and_confusable_letters(): void
    {
        $this->assertSame('0123456789ABCDEFGHJK', BridgeEnrolmentService::normalize('brg-0123-4567-89ab-cdef-ghjk'));
        $this->assertSame('0123456789ABCDEFGHJK', BridgeEnrolmentService::normalize(" BRG 0123 4567 89AB CDEF GHJK \n"));
        $this->assertSame('00110123456789ABCDEF', BridgeEnrolmentService::normalize('BRG-OOIL-0123-4567-89AB-CDEF'));
        $this->expectException(\InvalidArgumentException::class);
        BridgeEnrolmentService::normalize('BRG-1234');
    }

    public function test_base64_url_decoding_rejects_garbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BridgeSignature::decode('***no es base64***');
    }
}
