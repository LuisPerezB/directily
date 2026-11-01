<?php

declare(strict_types=1);

namespace Tests\Unit\DataCore;

use App\Models\DataSource;
use App\Services\Connectors\Connectors\MySqlSqlConnector;
use App\Services\Connectors\Connectors\PostgresSqlConnector;
use App\Services\Connectors\Connectors\SqlServerSqlConnector;
use App\Services\Connectors\Sql\IpAddress;
use App\Services\Connectors\Sql\MySqlDialect;
use App\Services\Connectors\Sql\PostgresDialect;
use App\Services\Connectors\Sql\SqlConnectionSettings;
use App\Services\Connectors\Sql\SqlHostPolicy;
use App\Services\Connectors\Sql\SqlHostRejected;
use App\Services\Connectors\Sql\SqlHostResolver;
use App\Services\Connectors\Sql\SqlServerDialect;
use App\Services\Connectors\Sql\SqlSourceConnection;
use App\Services\Connectors\Sql\SqlSourceException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Resolver sin red: devuelve respuestas fijas y registra las consultas (una por llamada a resolve()). */
final class FakeHostResolver implements SqlHostResolver
{
    /** @var list<string> */
    public array $queries = [];

    /** @param array<string, list<string>> $answers */
    public function __construct(private readonly array $answers = []) {}

    public function resolve(string $hostname): array
    {
        $this->queries[] = $hostname;

        return $this->answers[$hostname] ?? [];
    }
}

/** DNS rebinding: la primera respuesta es pública y las siguientes apuntan al servicio de metadatos. */
final class RebindingHostResolver implements SqlHostResolver
{
    public int $calls = 0;

    public function resolve(string $hostname): array
    {
        return ++$this->calls === 1 ? ['8.8.8.8'] : ['169.254.169.254'];
    }
}

/**
 * Política anti-SSRF de los conectores SQL (V2.5 §15, ADR-V25-15; defectos HIGH de conectores y seguridad_ui): un caso por cada
 * vector de evasión del sondeo de la auditoría del 31-oct-2026 (hexadecimal, decimal, octal, IPv4 mapeada/compatible en IPv6,
 * AAAA, metadato IPv6 de AWS, DNS rebinding) más la lista blanca y los valores por defecto seguros.
 */
final class SqlHostPolicyTest extends TestCase
{
    /** @param array<string, list<string>> $answers */
    private function dns(array $answers = []): FakeHostResolver
    {
        $resolver = new FakeHostResolver($answers);
        $this->app->instance(SqlHostResolver::class, $resolver);

        return $resolver;
    }

    private function reasonOf(string $host): string
    {
        try {
            SqlHostPolicy::resolve($host);
        } catch (SqlHostRejected $e) {
            return $e->reason;
        }

        return 'permitido';
    }

    // ───────────────────────── sintaxis: solo RFC 1123 o IP canónica ─────────────────────────

    /** @return array<string, array{string}> */
    public static function malformedHosts(): array
    {
        return [
            'decimal (2130706433 = 127.0.0.1)' => ['2130706433'],
            'hexadecimal con puntos (0x7f.0.0.1)' => ['0x7f.0.0.1'],
            'octal con puntos (0177.0.0.1)' => ['0177.0.0.1'],
            'octal completo' => ['017700000001'],
            'abreviada 127.1' => ['127.1'],
            'hexadecimal completo (metadatos)' => ['0xA9FEA9FE'],
            'hexadecimal por octetos (metadatos)' => ['0xa9.0xfe.0xa9.0xfe'],
            'octal por octetos (metadatos)' => ['0251.0376.0251.0376'],
            'cinco partes' => ['1.2.3.4.5'],
            'octeto fuera de rango' => ['256.1.1.1'],
            'IPv4 con punto final' => ['127.0.0.1.'],
            'última etiqueta numérica' => ['erp.123'],
            'vacío' => [''],
            'espacio' => ['erp cliente'],
            'salto de línea' => ["erp.example\n"],
            'inyección en el DSN' => ['db;host=evil'],
            'guion bajo' => ['erp_cliente.example'],
            'guion inicial' => ['-erp.example'],
            'guion final en etiqueta' => ['erp-.example'],
            'etiqueta de 64 caracteres' => [str_repeat('a', 64).'.example'],
            'demasiado largo' => [str_repeat('a.', 130).'com'],
            'unicode' => ['ünicode.example'],
            'IPv4 entre corchetes' => ['[10.0.0.1]'],
            'corchetes sin IPv6' => ['[nope]'],
            'corchete sin cerrar' => ['[::1'],
            'zona de IPv6' => ['fe80::1%eth0'],
            'con usuario' => ['user@erp.example'],
            'URL' => ['http://erp.example'],
            'con ruta' => ['erp.example/path'],
            'con puerto' => ['erp.example:5432'],
        ];
    }

    #[DataProvider('malformedHosts')]
    public function test_numeric_forms_and_malformed_hosts_are_rejected_before_any_resolution(string $host): void
    {
        $dns = $this->dns(['2130706433' => ['127.0.0.1']]);
        $this->assertSame(SqlHostRejected::INVALID_SYNTAX, $this->reasonOf($host), $host);
        $this->assertNotNull(SqlHostPolicy::syntaxError($host));
        $this->assertSame([], $dns->queries, 'un host con sintaxis inválida nunca llega al resolvedor');
    }

    /** @return array<string, array{string, string}> */
    public static function canonicalHosts(): array
    {
        return [
            'nombre' => ['erp.cliente.example', 'erp.cliente.example'],
            'mayúsculas' => ['ERP.Cliente.EXAMPLE', 'erp.cliente.example'],
            'punto final' => ['erp.cliente.example.', 'erp.cliente.example'],
            'una etiqueta' => ['erp', 'erp'],
            'punycode' => ['xn--bcher-kva.example', 'xn--bcher-kva.example'],
            'IPv4' => ['10.0.0.5', '10.0.0.5'],
            'IPv6 con corchetes' => ['[::1]', '::1'],
            'IPv6' => ['::1', '::1'],
            'IPv6 expandida' => ['2001:0DB8:0000:0000:0000:0000:0000:0001', '2001:db8::1'],
            'IPv6 pública con corchetes' => ['[2606:4700:4700::1111]', '2606:4700:4700::1111'],
            'IPv4 mapeada' => ['::ffff:10.0.0.5', '::ffff:10.0.0.5'],
        ];
    }

    #[DataProvider('canonicalHosts')]
    public function test_canonical_names_and_ip_literals_are_accepted_and_normalized(string $host, string $expected): void
    {
        $this->assertSame($expected, SqlHostPolicy::normalize($host));
        $this->assertNull(SqlHostPolicy::syntaxError($host));
    }

    // ───────────────────────── siempre bloqueadas (ninguna lista las autoriza) ─────────────────────────

    /** @return array<string, array{string}> */
    public static function alwaysBlocked(): array
    {
        return [
            'metadatos de nube' => ['169.254.169.254'],
            'link-local IPv4' => ['169.254.0.1'],
            'metadatos mapeados en IPv6' => ['::ffff:169.254.169.254'],
            'metadatos mapeados en hexadecimal IPv6' => ['::ffff:a9fe:a9fe'],
            'metadatos mapeados con corchetes' => ['[::ffff:169.254.169.254]'],
            'metadato IPv6 de AWS' => ['fd00:ec2::254'],
            'DNS IPv6 de AWS' => ['fd00:ec2::253'],
            'no especificada IPv4' => ['0.0.0.0'],
            'red 0.0.0.0/8' => ['0.1.2.3'],
            'multicast' => ['224.0.0.1'],
            'multicast administrativo' => ['239.255.255.250'],
            'broadcast' => ['255.255.255.255'],
            'reservada clase E' => ['240.0.0.1'],
            'multicast IPv6' => ['ff02::1'],
            'link-local IPv6' => ['fe80::1'],
            'no especificada IPv6' => ['::'],
            'IPv4 compatible con IPv6 (metadatos)' => ['::a9fe:a9fe'],
            'IPv4 traducida SIIT (metadatos)' => ['::ffff:0:a9fe:a9fe'],
            'NAT64 (metadatos)' => ['64:ff9b::a9fe:a9fe'],
            'NAT64 de uso local' => ['64:ff9b:1::1'],
            '6to4 hacia metadatos' => ['2002:a9fe:a9fe::1'],
            'Teredo' => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
            'metadatos de Alibaba' => ['100.100.100.200'],
            'WireServer de Azure' => ['168.63.129.16'],
            'metadatos de Oracle' => ['192.0.0.192'],
            'prefijo de descarte' => ['100::1'],
        ];
    }

    #[DataProvider('alwaysBlocked')]
    public function test_cloud_metadata_link_local_multicast_and_transition_forms_are_always_denied(string $host): void
    {
        $this->dns();
        $this->assertSame(SqlHostRejected::BLOCKED, $this->reasonOf($host), 'por defecto: '.$host);
        // Ni la lista blanca de hosts ni un CIDR que lo cubra todo las autorizan.
        config(['connectors.sql.allowed_hosts' => [$host], 'connectors.sql.allowed_cidrs' => ['0.0.0.0/0', '::/0']]);
        $this->assertSame(SqlHostRejected::BLOCKED, $this->reasonOf($host), 'con lista blanca: '.$host);
    }

    // ───────────────────────── loopback ─────────────────────────

    /** @return array<string, array{string}> */
    public static function loopbackAddresses(): array
    {
        return [
            'IPv4' => ['127.0.0.1'],
            'IPv4 del bloque /8' => ['127.255.255.254'],
            'IPv6' => ['::1'],
            'IPv6 con corchetes' => ['[::1]'],
            'IPv6 expandida' => ['0:0:0:0:0:0:0:1'],
            'mapeada' => ['::ffff:127.0.0.1'],
            'mapeada en hexadecimal' => ['::ffff:7f00:1'],
            'compatible' => ['::7f00:1'],
            'NAT64' => ['64:ff9b::7f00:1'],
        ];
    }

    #[DataProvider('loopbackAddresses')]
    public function test_loopback_is_denied_by_default_even_with_a_private_network_allow_list(string $host): void
    {
        $this->dns();
        $this->assertSame(SqlHostRejected::LOOPBACK, $this->reasonOf($host));
        // Autorizar redes privadas no autoriza el loopback.
        config(['connectors.sql.allowed_cidrs' => ['10.0.0.0/8', 'fc00::/7']]);
        $this->assertSame(SqlHostRejected::LOOPBACK, $this->reasonOf($host));
    }

    public function test_loopback_is_authorized_only_by_an_explicit_ip_literal_or_cidr_and_never_by_a_name(): void
    {
        $this->dns(['localhost' => ['127.0.0.1'], 'erp.local.example' => ['127.0.0.1']]);

        config(['connectors.sql.allowed_hosts' => ['127.0.0.1']]);
        $this->assertSame('127.0.0.1', SqlHostPolicy::resolve('127.0.0.1')->address);
        $this->assertSame(SqlHostRejected::NOT_LISTED, $this->reasonOf('127.0.0.2'), 'otra IP del bloque 127/8 no está autorizada');

        config(['connectors.sql.allowed_hosts' => ['localhost']]);
        $this->assertSame(SqlHostRejected::LOOPBACK, $this->reasonOf('localhost'), 'un nombre en la lista no autoriza el loopback');

        config(['connectors.sql.allowed_hosts' => ['localhost', '127.0.0.1']]);
        $this->assertSame('127.0.0.1', SqlHostPolicy::resolve('localhost')->address, 'nombre en la lista + su IP de loopback declarada explícitamente');

        config(['connectors.sql.allowed_hosts' => [], 'connectors.sql.allowed_cidrs' => ['127.0.0.0/8']]);
        $this->assertSame('127.0.0.1', SqlHostPolicy::resolve('erp.local.example')->address, 'un CIDR explícito que cubre la IP la autoriza');
    }

    public function test_loopback_cannot_be_authorized_in_production(): void
    {
        $this->dns();
        config(['connectors.sql.allowed_hosts' => [], 'connectors.sql.allowed_cidrs' => ['127.0.0.0/8', '10.0.0.0/8']]);
        $this->assertSame('127.0.0.1', SqlHostPolicy::resolve('127.0.0.1')->address, 'en pruebas/desarrollo la autorización explícita funciona');

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame(SqlHostRejected::LOOPBACK, $this->reasonOf('127.0.0.1'), 'en producción el loopback nunca se autoriza (CIDR)');
        config(['connectors.sql.allowed_hosts' => ['127.0.0.1', '10.1.2.3'], 'connectors.sql.allowed_cidrs' => []]);
        $this->assertSame(SqlHostRejected::LOOPBACK, $this->reasonOf('127.0.0.1'), 'en producción el loopback nunca se autoriza (IP en la lista)');
        $this->assertSame('10.1.2.3', SqlHostPolicy::resolve('10.1.2.3')->address, 'una red privada autorizada sí funciona en producción');
    }

    // ───────────────────────── redes privadas ─────────────────────────

    /** @return array<string, array{string}> */
    public static function privateAddresses(): array
    {
        return [
            '10/8' => ['10.0.0.5'],
            '10/8 (último)' => ['10.255.255.255'],
            '172.16/12' => ['172.16.0.1'],
            '172.16/12 (último)' => ['172.31.255.254'],
            '192.168/16' => ['192.168.0.10'],
            'CGNAT 100.64/10' => ['100.64.0.1'],
            'CGNAT (último)' => ['100.127.255.254'],
            'benchmarking 198.18/15' => ['198.18.0.1'],
            'TEST-NET-1' => ['192.0.2.10'],
            'TEST-NET-3' => ['203.0.113.7'],
            'ULA fc00::/7' => ['fc00::1'],
            'ULA fd' => ['fd12:3456:789a::1'],
            'ULA con corchetes' => ['[fd00::1]'],
            'mapeada 10.0.0.5' => ['::ffff:10.0.0.5'],
            'mapeada en hexadecimal' => ['::ffff:a00:5'],
            'mapeada 192.168.1.1' => ['::ffff:192.168.1.1'],
            'NAT64 hacia 10.0.0.5' => ['64:ff9b::a00:5'],
            'compatible hacia 10.0.0.5' => ['::a00:5'],
            'documentación IPv6' => ['2001:db8::1'],
        ];
    }

    #[DataProvider('privateAddresses')]
    public function test_private_networks_are_denied_by_default(string $host): void
    {
        $this->dns();
        $this->assertSame(SqlHostRejected::PRIVATE, $this->reasonOf($host));
    }

    #[DataProvider('privateAddresses')]
    public function test_a_private_address_is_authorized_by_the_explicit_ip_or_by_a_covering_cidr(string $host): void
    {
        $this->dns();
        $normalized = SqlHostPolicy::normalize($host);

        config(['connectors.sql.allowed_hosts' => [$normalized], 'connectors.sql.allowed_cidrs' => []]);
        $this->assertNotSame('', SqlHostPolicy::resolve($host)->address, 'IP literal en la lista blanca de hosts');

        config(['connectors.sql.allowed_hosts' => [], 'connectors.sql.allowed_cidrs' => ['192.0.0.0/32', '2001:ffff::/32']]);
        $this->assertSame(SqlHostRejected::PRIVATE, $this->reasonOf($host), 'un CIDR que no la cubre no la autoriza');

        // CIDR de la familia y el bloque adecuados.
        $packed = IpAddress::embeddedIPv4((string) IpAddress::pack($normalized)) ?? IpAddress::pack($normalized);
        $cidr = strlen((string) $packed) === 4 ? IpAddress::text((string) $packed).'/32' : IpAddress::text((string) $packed).'/128';
        config(['connectors.sql.allowed_cidrs' => [$cidr]]);
        $this->assertNotSame('', SqlHostPolicy::resolve($host)->address, 'CIDR que la cubre: '.$cidr);
    }

    public function test_a_private_range_cidr_authorizes_hostnames_that_resolve_into_it(): void
    {
        $this->dns(['erp.vpn.example' => ['10.20.0.15'], 'erp.otra.example' => ['10.99.0.1']]);
        $this->assertSame(SqlHostRejected::PRIVATE, $this->reasonOf('erp.vpn.example'), 'por defecto, denegado');

        config(['connectors.sql.allowed_cidrs' => ['10.20.0.0/16']]);
        $target = SqlHostPolicy::resolve('erp.vpn.example');
        $this->assertSame(['erp.vpn.example', '10.20.0.15', false], [$target->host, $target->address, $target->literal]);
        $this->assertSame(SqlHostRejected::PRIVATE, $this->reasonOf('erp.otra.example'), 'fuera del CIDR autorizado');
    }

    public function test_a_listed_hostname_may_resolve_to_a_private_network_but_never_to_loopback_or_metadata(): void
    {
        $this->dns([
            'erp.cliente.example' => ['10.8.0.5'],
            'erp.local.example' => ['127.0.0.1'],
            'erp.metadatos.example' => ['169.254.169.254'],
        ]);
        config(['connectors.sql.allowed_hosts' => ['erp.cliente.example', 'erp.local.example', 'erp.metadatos.example']]);

        $this->assertSame('10.8.0.5', SqlHostPolicy::resolve('erp.cliente.example')->address);
        $this->assertSame(SqlHostRejected::LOOPBACK, $this->reasonOf('erp.local.example'));
        $this->assertSame(SqlHostRejected::BLOCKED, $this->reasonOf('erp.metadatos.example'));
    }

    // ───────────────────────── públicas, A + AAAA y fijado de la IP ─────────────────────────

    /** @return array<string, array{string, string}> */
    public static function publicAddresses(): array
    {
        return [
            'DNS público' => ['8.8.8.8', '8.8.8.8'],
            'Cloudflare' => ['1.1.1.1', '1.1.1.1'],
            'documentación de ejemplo.com' => ['93.184.216.34', '93.184.216.34'],
            'IPv6 pública' => ['2606:4700:4700::1111', '2606:4700:4700::1111'],
            'IPv6 pública con corchetes' => ['[2606:4700:4700::1111]', '2606:4700:4700::1111'],
            'mapeada pública (se fija la IPv4)' => ['::ffff:8.8.8.8', '8.8.8.8'],
            'justo antes de 172.16/12' => ['172.15.255.255', '172.15.255.255'],
            'justo después de 172.16/12' => ['172.32.0.1', '172.32.0.1'],
            'justo antes del CGNAT' => ['100.63.255.255', '100.63.255.255'],
            'justo después del CGNAT' => ['100.128.0.1', '100.128.0.1'],
            'justo antes de link-local' => ['169.253.255.255', '169.253.255.255'],
        ];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_allowed_without_a_list_and_the_ip_is_pinned(string $host, string $expected): void
    {
        $this->dns();
        $target = SqlHostPolicy::resolve($host);
        $this->assertSame($expected, $target->address);
        $this->assertTrue($target->literal);
    }

    public function test_a_and_aaaa_are_resolved_and_every_answer_must_be_allowed(): void
    {
        $dns = $this->dns([
            'erp.publico.example' => ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34'],
            'erp.solo-aaaa.example' => ['2606:4700:4700::1111'],
            'erp.mixto.example' => ['93.184.216.34', 'fc00::5'],
            'erp.mixto-metadatos.example' => ['8.8.8.8', '::ffff:169.254.169.254'],
            'erp.interno.example' => ['10.0.0.5'],
            'erp.metadatos.example' => ['169.254.169.254'],
            'erp.mapeado.example' => ['::ffff:a9fe:a9fe'],
            'erp.aws-ipv6.example' => ['fd00:ec2::254'],
            'erp.rebind.example' => ['8.8.8.8', '127.0.0.1'],
        ]);

        $this->assertSame('93.184.216.34', SqlHostPolicy::resolve('erp.publico.example')->address, 'con A y AAAA se fija la IPv4');
        $this->assertSame('2606:4700:4700::1111', SqlHostPolicy::resolve('erp.solo-aaaa.example')->address, 'solo AAAA: se fija la IPv6 validada');
        $this->assertSame(SqlHostRejected::PRIVATE, $this->reasonOf('erp.mixto.example'), 'una AAAA ULA entre respuestas públicas lo rechaza');
        $this->assertSame(SqlHostRejected::BLOCKED, $this->reasonOf('erp.mixto-metadatos.example'));
        $this->assertSame(SqlHostRejected::PRIVATE, $this->reasonOf('erp.interno.example'));
        $this->assertSame(SqlHostRejected::BLOCKED, $this->reasonOf('erp.metadatos.example'));
        $this->assertSame(SqlHostRejected::BLOCKED, $this->reasonOf('erp.mapeado.example'), 'AAAA que des-mapea a metadatos');
        $this->assertSame(SqlHostRejected::BLOCKED, $this->reasonOf('erp.aws-ipv6.example'));
        $this->assertSame(SqlHostRejected::LOOPBACK, $this->reasonOf('erp.rebind.example'), 'basta una respuesta de loopback');
        $this->assertContains('erp.publico.example', $dns->queries);
    }

    public function test_an_unresolvable_host_is_denied_instead_of_passing_the_policy(): void
    {
        $this->dns();
        $this->assertSame(SqlHostRejected::UNRESOLVABLE, $this->reasonOf('erp.no-existe.example'));
    }

    public function test_denial_messages_are_generic_and_do_not_reveal_the_network(): void
    {
        $this->dns(['erp.interno.example' => ['10.0.0.5'], 'erp.metadatos.example' => ['169.254.169.254'], 'erp.no-existe.example' => []]);
        $messages = [];
        foreach (['10.0.0.5', '127.0.0.1', '169.254.169.254', 'erp.interno.example', 'erp.metadatos.example', 'erp.no-existe.example'] as $host) {
            try {
                SqlHostPolicy::resolve($host);
                $this->fail("Debió rechazarse: {$host}");
            } catch (SqlHostRejected $e) {
                $messages[$host] = $e->getMessage();
            }
        }
        $this->assertSame([SqlHostPolicy::DENIED_MESSAGE], array_values(array_unique($messages)), 'el mismo mensaje para toda denegación: no hay oráculo de la red interna');
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d+\.\d+\.\d+/', SqlHostPolicy::DENIED_MESSAGE);
    }

    // ───────────────────────── lista blanca ─────────────────────────

    public function test_a_configured_allow_list_is_closed_world_and_rejects_others_without_resolving(): void
    {
        $dns = $this->dns(['otro.example' => ['8.8.8.8'], 'erp.cliente.example' => ['8.8.4.4']]);
        config(['connectors.sql.allowed_hosts' => ['ERP.Cliente.Example']]);

        $this->assertSame(SqlHostRejected::NOT_LISTED, $this->reasonOf('otro.example'));
        $this->assertSame(SqlHostRejected::NOT_LISTED, $this->reasonOf('1.1.1.1'));
        $this->assertSame([], $dns->queries, 'un destino fuera de la lista no se resuelve');
        $this->assertSame('8.8.4.4', SqlHostPolicy::resolve('erp.cliente.example')->address);
    }

    public function test_a_malformed_allow_list_fails_closed(): void
    {
        $dns = $this->dns(['erp.cliente.example' => ['8.8.8.8']]);
        // Un comodín o un error de escritura no vacían la lista ni abren el sistema: nada pasa.
        config(['connectors.sql.allowed_hosts' => ['*.cliente.example', 'erp cliente']]);
        $this->assertSame(SqlHostRejected::NOT_LISTED, $this->reasonOf('erp.cliente.example'));
        $this->assertSame([], $dns->queries);
    }

    public function test_invalid_cidr_entries_never_authorize_anything(): void
    {
        $this->dns();
        config(['connectors.sql.allowed_cidrs' => ['10.0.0.0/99', 'no-es-un-cidr', '10.0.0.0/', '']]);
        $this->assertSame(SqlHostRejected::PRIVATE, $this->reasonOf('10.0.0.5'));
    }

    public function test_the_old_assert_allowed_contract_still_throws_a_source_exception(): void
    {
        $this->expectException(SqlSourceException::class);
        SqlHostPolicy::assertAllowed('::ffff:169.254.169.254');
    }

    // ───────────────────────── DNS rebinding: la IP validada es la IP conectada ─────────────────────────

    public function test_the_validated_ip_is_pinned_so_a_second_resolution_cannot_change_the_destination(): void
    {
        $resolver = new RebindingHostResolver;
        $this->app->instance(SqlHostResolver::class, $resolver);

        $target = SqlHostPolicy::resolve('erp.rebind.example');
        $this->assertSame('8.8.8.8', $target->address);
        $this->assertSame(1, $resolver->calls, 'una sola resolución por validación');

        $settings = (new SqlConnectionSettings('erp.rebind.example', 5432, 'erp', sslMode: 'verify-full'))->pinnedTo($target);

        $pg = (new PostgresDialect)->dsn($settings);
        $this->assertStringContainsString('host=erp.rebind.example;hostaddr=8.8.8.8;', $pg, 'PostgreSQL: hostaddr fija la IP y host se conserva para TLS');
        $this->assertStringContainsString('sslmode=verify-full', $pg);

        $mysql = (new MySqlDialect)->dsn((new SqlConnectionSettings('erp.rebind.example', 3306, 'erp'))->pinnedTo($target));
        $this->assertStringContainsString('mysql:host=8.8.8.8;', $mysql);
        $this->assertStringNotContainsString('rebind', $mysql, 'MySQL: se conecta al literal IP');

        $sqlsrv = (new SqlServerDialect)->dsn((new SqlConnectionSettings('erp.rebind.example', 1433, 'erp'))->pinnedTo($target));
        $this->assertStringContainsString('Server=8.8.8.8,1433', $sqlsrv);
        $this->assertStringNotContainsString('rebind', $sqlsrv, 'SQL Server: se conecta al literal IP');

        $this->assertSame(SqlHostRejected::BLOCKED, $this->reasonOf('erp.rebind.example'), 'si la respuesta cambia, la siguiente validación la bloquea');
    }

    public function test_ipv6_targets_are_pinned_in_every_dialect(): void
    {
        $this->dns(['erp.v6.example' => ['2606:4700:4700::1111']]);
        $target = SqlHostPolicy::resolve('erp.v6.example');
        $settings = (new SqlConnectionSettings('erp.v6.example', 5432, 'erp'))->pinnedTo($target);

        $this->assertStringContainsString('hostaddr=2606:4700:4700::1111;', (new PostgresDialect)->dsn($settings));
        $this->assertStringContainsString('mysql:host=2606:4700:4700::1111;', (new MySqlDialect)->dsn($settings));
        $this->assertStringContainsString('Server=[2606:4700:4700::1111],5432', (new SqlServerDialect)->dsn($settings));
    }

    public function test_without_a_pinned_address_the_dialects_keep_using_the_host_and_still_reject_unsafe_parts(): void
    {
        $plain = new SqlConnectionSettings('erp.cliente.example', 5432, 'erp');
        $this->assertStringNotContainsString('hostaddr', (new PostgresDialect)->dsn($plain));
        $this->assertStringContainsString('mysql:host=erp.cliente.example;', (new MySqlDialect)->dsn($plain));
        $this->expectException(\InvalidArgumentException::class);
        (new MySqlDialect)->dsn(new SqlConnectionSettings('host;dbname=evil', 3306, 'erp'));
    }

    public function test_postgres_connects_to_the_pinned_ip_without_ever_resolving_the_name(): void
    {
        $db = (array) config('database.connections.pgsql');
        $ip = (string) ($db['host'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->markTestSkipped('La base de pruebas no se configura por IP literal.');
        }
        // El nombre NO existe en ningún DNS: solo la IP fijada por la política puede explicar que la conexión funcione.
        $dns = $this->dns(['erp.sin-dns.invalid' => [$ip]]);
        config(['connectors.sql.allowed_hosts' => ['erp.sin-dns.invalid', $ip], 'connectors.sql.allowed_cidrs' => []]);

        $connection = SqlSourceConnection::open(new PostgresDialect, new SqlConnectionSettings('erp.sin-dns.invalid', (int) $db['port'], (string) $db['database']), (string) $db['username'], (string) $db['password']);
        try {
            $this->assertSame(1, (int) $connection->pdo()->query('SELECT 1')->fetchColumn());
            $this->assertSame('on', $connection->pdo()->query('SHOW transaction_read_only')->fetchColumn(), 'sigue siendo una transacción de solo lectura');
        } finally {
            $connection->close();
        }
        $this->assertSame(['erp.sin-dns.invalid'], $dns->queries);
    }

    public function test_opening_a_connection_applies_the_policy_before_any_connection_attempt(): void
    {
        $this->dns(['erp.metadatos.example' => ['169.254.169.254'], 'erp.interno.example' => ['10.0.0.5']]);
        foreach (['erp.metadatos.example', 'erp.interno.example', '::ffff:169.254.169.254', '127.0.0.1'] as $host) {
            try {
                SqlSourceConnection::open(new PostgresDialect, new SqlConnectionSettings($host, 5432, 'erp'), 'usuario', 'clave');
                $this->fail("Debió rechazarse antes de conectar: {$host}");
            } catch (SqlHostRejected $e) {
                $this->assertSame(SqlHostPolicy::DENIED_MESSAGE, $e->getMessage());
            }
        }
    }

    public function test_the_connector_test_reports_the_generic_denial_not_the_network(): void
    {
        $this->dns();
        $source = new DataSource;
        $source->forceFill(['config' => ['host' => '::ffff:169.254.169.254', 'database' => 'erp'], 'credentials_encrypted' => ['username' => 'u', 'password' => 'clave-secreta-1']]);
        $result = (new PostgresSqlConnector)->test($source);
        $this->assertFalse($result->ok);
        $this->assertSame(SqlHostPolicy::DENIED_MESSAGE, $result->message);
        $this->assertStringNotContainsString('clave-secreta-1', json_encode($result->details));
    }

    // ───────────────────────── validación de la configuración de la fuente ─────────────────────────

    public function test_every_sql_connector_rejects_numeric_host_forms_in_the_source_configuration(): void
    {
        foreach ([new PostgresSqlConnector, new MySqlSqlConnector, new SqlServerSqlConnector] as $connector) {
            foreach (['2130706433', '0x7f.0.0.1', '0177.0.0.1', '0xA9FEA9FE', '127.1', 'db;host=evil'] as $host) {
                try {
                    $connector->validateInput(['host' => $host, 'database' => 'erp'], ['username' => 'u', 'password' => 'p']);
                    $this->fail($connector->key().' aceptó el host '.$host);
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('config.host', $e->errors(), $connector->key().' '.$host);
                }
            }
            $connector->validateInput(['host' => 'erp.cliente.example', 'database' => 'erp'], ['username' => 'u', 'password' => 'p']);
            $connector->validateInput(['host' => '10.0.0.5', 'database' => 'erp'], ['username' => 'u', 'password' => 'p']);
            $connector->validateInput(['host' => '[2606:4700:4700::1111]', 'database' => 'erp'], ['username' => 'u', 'password' => 'p']);
        }
        $this->addToAssertionCount(1);
    }

    // ───────────────────────── utilidades de direcciones ─────────────────────────

    public function test_cidr_parsing_and_membership(): void
    {
        $this->assertNull(IpAddress::parseCidr('10.0.0.0/33'));
        $this->assertNull(IpAddress::parseCidr('10.0.0.0/'));
        $this->assertNull(IpAddress::parseCidr('10.0.0.0/-1'));
        $this->assertNull(IpAddress::parseCidr('no-es-un-cidr'));
        $this->assertNull(IpAddress::parseCidr('2001:db8::/129'));
        $this->assertNull(IpAddress::parseCidr('::ffff:10.0.0.0/64'), 'un prefijo menor que /96 no se puede expresar en IPv4');
        [$net, $bits] = IpAddress::parseCidr('::ffff:10.0.0.0/104') ?? [null, null];
        $this->assertSame([IpAddress::pack('10.0.0.0'), 8], [$net, $bits], 'una red IPv4 mapeada se expresa en IPv4');

        $ten = IpAddress::pack('10.1.2.3');
        $this->assertTrue(IpAddress::inAnyCidr((string) $ten, ['192.168.0.0/16', '10.0.0.0/8']));
        $this->assertFalse(IpAddress::inAnyCidr((string) IpAddress::pack('11.0.0.1'), ['10.0.0.0/8']));
        $this->assertTrue(IpAddress::inAnyCidr((string) IpAddress::pack('172.31.255.255'), ['172.16.0.0/12']));
        $this->assertFalse(IpAddress::inAnyCidr((string) IpAddress::pack('172.32.0.0'), ['172.16.0.0/12']));
        $this->assertTrue(IpAddress::inAnyCidr((string) IpAddress::pack('fd12:3456::1'), ['fc00::/7']));
        $this->assertFalse(IpAddress::inAnyCidr((string) IpAddress::pack('fe80::1'), ['fc00::/7']), 'familias y bloques distintos');
        $this->assertFalse(IpAddress::inAnyCidr((string) $ten, ['fc00::/7']), 'una IPv4 nunca cae en una red IPv6');
        $this->assertTrue(IpAddress::inAnyCidr((string) $ten, ['10.1.2.3']), 'una IP suelta es /32');
    }
}
