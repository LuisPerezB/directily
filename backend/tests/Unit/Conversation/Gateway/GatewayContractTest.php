<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation\Gateway;

use App\Enums\CommercialRole;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Services\Auth\Commercial\CommercialScope as AuthScope;
use App\Services\Conversation\Channels\ConfirmedMessage;
use App\Services\Conversation\Channels\DraftMessage;
use App\Services\Conversation\Gateway\DataScope;
use App\Services\Conversation\Gateway\Schema\ArgumentSchema;
use App\Services\Conversation\Gateway\Scope\GatewayScope;
use App\Services\Conversation\Tools\AssistantTool;
use App\Services\Conversation\Tools\ToolRegistry;
use App\Services\Conversation\Tools\ToolResult;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * V2.5 · W6-D: invariantes ESTRUCTURALES del catálogo de herramientas del gateway (esquema ↔ reglas, claves reservadas, solo lectura, habilidades reales) y la matriz del
 * alcance comercial. Si alguien agrega una herramienta que rompa uno de estos contratos, falla aquí.
 */
class GatewayContractTest extends TestCase
{
    /** @return list<AssistantTool> */
    private function tools(): array
    {
        return array_map(fn (string $class): AssistantTool => app($class), ToolRegistry::DEFAULT_TOOLS);
    }

    public function test_the_catalog_has_the_twelve_typed_tools_with_unique_stable_names(): void
    {
        $names = array_map(fn (AssistantTool $t) => $t->name(), $this->tools());

        $this->assertSame([
            'dashboard_summary', 'product_forecast', 'forecast_accuracy', 'inventory_recommendations', 'customer_predictions', 'supply_plan',
            'radar_opportunities', 'explain_recommendation', 'decisions_summary', 'outcomes', 'roi_summary', 'model_quality',
        ], $names);
        $this->assertSame($names, array_values(array_unique($names)));
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,59}$/', $name);
        }
        $this->assertSame($names, (new ToolRegistry)->names());
    }

    public function test_every_tool_schema_is_closed_without_reserved_keys_and_matches_its_validation_rules(): void
    {
        // Algunas reglas se acotan a la empresa activa (TenantRule): basta un contexto en memoria, sin base de datos.
        app(TenantContext::class)->set((new Tenant)->forceFill(['id' => 1]));
        foreach ($this->tools() as $tool) {
            $schema = $tool->parameters();
            $this->assertSame('object', $schema['type'], $tool->name());
            $this->assertFalse($schema['additionalProperties'], "{$tool->name()}: el esquema debe ser cerrado");
            $properties = array_map('strval', array_keys((array) $schema['properties']));
            $this->assertSame([], ArgumentSchema::reservedKeys(array_fill_keys($properties, 1)), "{$tool->name()}: ninguna herramienta puede aceptar empresa, usuario ni permisos del modelo");
            $ruleKeys = array_keys($tool->rules());
            sort($properties);
            sort($ruleKeys);
            $this->assertSame($properties, $ruleKeys, "{$tool->name()}: las reglas de validación y el esquema deben declarar las mismas claves");
            foreach ((array) ($schema['required'] ?? []) as $required) {
                $this->assertContains($required, $properties);
            }
        }
    }

    public function test_every_tool_requires_a_real_ability_declares_a_data_scope_and_summarizes_without_business_values(): void
    {
        $known = array_values(array_unique(array_merge(...array_map(fn (TenantRole $r) => $r->abilities(), TenantRole::cases()))));
        foreach ($this->tools() as $tool) {
            $this->assertContains($tool->ability(), $known, "{$tool->name()}: habilidad inexistente");
            $this->assertInstanceOf(DataScope::class, $tool->dataScope());
            $this->assertNotSame('', trim($tool->description()));
            $summary = $tool->summarize(ToolResult::failure('error', 'Cemento secreto'));
            $this->assertSame('error', $summary['status']);
            $this->assertStringNotContainsString('Cemento', json_encode($summary), "{$tool->name()}: el resumen de auditoría no debe copiar texto del resultado");
        }
    }

    public function test_customer_level_and_management_tools_declare_a_restrictive_data_scope(): void
    {
        $scopes = [];
        foreach ($this->tools() as $tool) {
            $scopes[$tool->name()] = $tool->dataScope();
        }
        foreach (['customer_predictions', 'radar_opportunities', 'explain_recommendation', 'decisions_summary'] as $customerTool) {
            $this->assertSame(DataScope::CustomerPortfolio, $scopes[$customerTool], $customerTool);
        }
        // Paridad con la API (EnforceCommercialScope): resultados medidos y ROI son de EQUIPO, no se acotan por cartera.
        foreach (['outcomes', 'roi_summary'] as $managementTool) {
            $this->assertSame(DataScope::TenantManagement, $scopes[$managementTool], $managementTool);
        }
        foreach (['dashboard_summary', 'product_forecast', 'forecast_accuracy', 'inventory_recommendations', 'supply_plan', 'model_quality'] as $operational) {
            $this->assertSame(DataScope::Operational, $scopes[$operational], $operational);
        }
    }

    public function test_no_tool_writes_data_or_reaches_the_messaging_channel(): void
    {
        $writes = '/(->save\(|(?<!Request)::create\(|->create\(|forceCreate|->insert\(|->insertGetId|->update\(|->updateOrCreate|->delete\(|->forceDelete|::destroy\(|->truncate\(|DB::statement|DB::unprepared|Http::|Mail::|Notification::|dispatch\(|Queue::|Artisan::|Channels\\\\|HumanConfirmation|MessagingChannel|->transition\()/';
        $offenders = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Services/Conversation/Tools'), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && preg_match($writes, (string) file_get_contents($file->getPathname()), $m) === 1) {
                $offenders[] = $file->getFilename().' → '.$m[0];
            }
        }
        $this->assertSame([], $offenders, 'Las herramientas del gateway son de SOLO LECTURA: no escriben, no encolan, no llaman a la red ni al canal de mensajería.');
    }

    public function test_only_the_human_confirmation_step_can_issue_a_confirmed_message(): void
    {
        // Análisis LÉXICO (no por texto): se ignoran comentarios y cadenas; se buscan `ConfirmedMessage::issue`, `new ConfirmedMessage` y `newInstanceWithoutConstructor`.
        $violations = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php') || in_array($file->getFilename(), ['HumanConfirmation.php', 'ConfirmedMessage.php'], true)) {
                continue;
            }
            $tokens = array_values(array_filter(\PhpToken::tokenize((string) file_get_contents($file->getPathname())), fn (\PhpToken $t) => ! $t->isIgnorable()));
            foreach ($tokens as $i => $t) {
                $isName = in_array($t->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ($t->text === 'ConfirmedMessage' || str_ends_with($t->text, '\\ConfirmedMessage'));
                if ($isName && (($tokens[$i + 1]->text ?? '') === '::' && ($tokens[$i + 2]->text ?? '') === 'issue' || ($tokens[$i - 1]->id ?? null) === T_NEW)) {
                    $violations[] = $file->getFilename().': '.$t->text.' en la línea '.$t->line;
                }
                if ($t->id === T_STRING && strtolower($t->text) === 'newinstancewithoutconstructor') {
                    $violations[] = $file->getFilename().': newInstanceWithoutConstructor en la línea '.$t->line;
                }
            }
        }
        $this->assertSame([], $violations, 'Solo HumanConfirmation puede emitir un ConfirmedMessage.');
        $this->assertTrue((new \ReflectionClass(ConfirmedMessage::class))->getConstructor()->isPrivate());
    }

    public function test_issuing_a_confirmed_message_from_anywhere_else_fails_at_runtime(): void
    {
        $now = CarbonImmutable::now();
        $draft = new DraftMessage('d1', 1, 'whatsapp', 'opportunity_follow_up', 1, 1, 'Hola', hash('sha256', 'Hola'), 1, $now, $now->addMinute());

        $this->expectException(\LogicException::class);
        ConfirmedMessage::issue($draft, 1, $now);
    }

    /** @return array<string, array{0: GatewayScope, 1: bool, 2: bool, 3: bool}> operativo, por cliente, de toda la empresa */
    public static function matrix(): array
    {
        $from = fn (?CommercialRole $role, array $customers = [], array $zones = [], array $team = []) => GatewayScope::from(new AuthScope(5, $role, $customers, $zones, [], $team), 'prueba');

        return [
            'sin rol comercial' => [$from(null), true, true, true],
            'gerente sin cartera (su equipo = empresa)' => [$from(CommercialRole::Gerente), true, true, true],
            'gerente con zona' => [$from(CommercialRole::Gerente, [], ['NORTE']), true, true, false],
            'gerente con equipo' => [$from(CommercialRole::Gerente, [], [], [9]), true, true, false],
            'vendedor con cartera' => [$from(CommercialRole::Vendedor, [1]), true, true, false],
            'vendedor sin cartera (falla cerrado)' => [$from(CommercialRole::Vendedor), true, true, false],
            'servicio sin alcance guardado (techo de solo lectura en el rol de acceso)' => [$from(CommercialRole::Servicio), true, true, true],
            'servicio con cartera' => [$from(CommercialRole::Servicio, [], ['SUR']), true, true, false],
            'sin resolver (sin persona o rol desconocido)' => [GatewayScope::unresolved(), true, false, false],
        ];
    }

    #[DataProvider('matrix')]
    public function test_commercial_scope_permission_matrix(GatewayScope $scope, bool $operational, bool $customer, bool $management): void
    {
        $this->assertSame($operational, $scope->permits(DataScope::Operational));
        $this->assertSame($customer, $scope->permits(DataScope::CustomerPortfolio));
        $this->assertSame($management, $scope->permits(DataScope::TenantManagement));
    }

    public function test_describe_never_exposes_the_portfolio_ids(): void
    {
        $described = GatewayScope::from(new AuthScope(5, CommercialRole::Vendedor, [101, 102], ['ZONA-SECRETA'], [7], [8]), 'request')->describe();
        $this->assertSame(['role' => 'vendedor', 'restricted' => true, 'source' => 'request'], $described);
        $this->assertSame(['role' => 'unresolved', 'restricted' => true, 'source' => 'unresolved'], GatewayScope::unresolved()->describe());
    }
}
