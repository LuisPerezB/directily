<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Feature\Models\Support\ModelRegistryFixtures;
use Tests\Feature\Security\Support\SecurityFixtures;
use Tests\TestCase;

/**
 * V2.5 · W6-A (revisión M1 / prueba M7): acciones de la clase «aprobar» que un controlador decide EN LÍNEA con `can('models.approve')` y que solo exigían `forecast.run` en la
 * ruta: restaurar (= promover) un modelo aprobado que nunca se sirvió, y validar un modelo ajeno a la publicación. Con MFA activo y el segundo factor vencido eran una puerta lateral
 * para promover modelos sin segundo factor fresco.
 */
final class StepUpBypassModelsTest extends TestCase
{
    use ModelRegistryFixtures, RefreshDatabase, SecurityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegistry();
        $this->freezeClock();
    }

    protected function tearDown(): void
    {
        $this->tearDownRegistry();
        parent::tearDown();
    }

    public function test_m7_promoting_a_never_served_approved_model_through_rollback_needs_a_fresh_second_factor(): void
    {
        $c = $this->registryCompany();
        $current = $this->customerModel($c['tenant'], 'production');
        $approved = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'approved', 'approved_by' => $c['approver']->id, 'approved_at' => now()->subDay()], 'h7:probability');
        $mfa = $this->enableMfa($c['owner']);
        $api = fn () => $this->actingAsTenantUser($c['owner'], $c['tenant']);

        // Con el segundo factor vencido: 403 y NADA cambia (el aprobado sigue aprobado; el actual sigue en producción; ni un evento).
        $events = DB::table('model_registry_events')->where('tenant_id', $c['tenant']->id)->count();
        $api()->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $approved])->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->assertSame(['approved', 'production'], [$this->statusOf($approved), $this->statusOf($current)]);
        $this->assertSame($events, DB::table('model_registry_events')->where('tenant_id', $c['tenant']->id)->count());

        // Con el segundo factor fresco, se promueve (equivale a POST /models/{id}/promote).
        $api()->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $api()->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $approved])->assertOk();
        $this->assertSame('production', $this->statusOf($approved));
        // Vencido otra vez, no hay una segunda promoción.
        $other = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'approved', 'approved_by' => $c['approver']->id, 'approved_at' => now()], 'h14:probability');
        $this->travel(601)->seconds();
        $api()->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $other])->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->assertSame('approved', $this->statusOf($other));
    }

    public function test_m7_restoring_what_already_served_and_people_without_mfa_are_not_affected(): void
    {
        $c = $this->registryCompany();
        $current = $this->customerModel($c['tenant'], 'production');
        $approvedRetired = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'retired', 'approved_by' => $c['approver']->id, 'approved_at' => now()->subDays(2)]);
        $neverServed = $this->publishedCandidate($c['tenant'], $c['publisher'], ['status' => 'approved', 'approved_by' => $c['approver']->id, 'approved_at' => now()->subDay()], 'h7:probability');
        $this->enableMfa($c['analyst']);

        // Restaurar lo que YA sirvió no es promover: con forecast.run basta, también con MFA y sin segundo factor fresco.
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $approvedRetired])->assertOk();
        $this->assertSame('production', $this->statusOf($approvedRetired));
        // Un analista (sin models.approve) sigue recibiendo la negativa de siempre por habilidad, no una pista de MFA.
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $neverServed])
            ->assertForbidden()->assertJsonPath('code', 'forbidden')->assertJsonPath('required_ability', 'models.approve');
        // Un propietario SIN MFA promueve como siempre.
        $this->actingAsTenantUser($c['owner'], $c['tenant'])->postJson('/api/v1/models/customer/rollback', ['model_registry_id' => $neverServed])->assertOk();
        $this->assertSame('production', $this->statusOf($neverServed));
        $this->assertNotNull($current);
    }

    public function test_m7_validating_a_model_outside_the_publication_path_needs_a_fresh_second_factor(): void
    {
        $c = $this->registryCompany();
        $legacy = $this->customerModel($c['tenant'], 'candidate');   // del pipeline propio: no entró por la publicación → validarlo exige models.approve
        $published = $this->publishedCandidate($c['tenant'], $c['publisher']);
        $mfa = $this->enableMfa($c['approver']);
        $api = fn () => $this->actingAsTenantUser($c['approver'], $c['tenant']);

        $api()->postJson("/api/v1/models/{$legacy}/validate")->assertForbidden()->assertJsonPath('code', 'mfa_step_up_required');
        $this->assertSame('candidate', $this->statusOf($legacy));
        // Un publicado se valida con forecast.run: no necesita el segundo factor (no es de la clase «aprobar»).
        $api()->postJson("/api/v1/models/{$published}/validate")->assertOk();
        // Con el segundo factor fresco, el administrador valida el del pipeline propio (o el servicio dice por qué no, pero ya no es el segundo factor).
        $api()->postJson('/api/v1/auth/mfa/step-up', ['code' => $this->freshCode($mfa['secret'])])->assertOk();
        $after = $api()->postJson("/api/v1/models/{$legacy}/validate");
        $this->assertNotSame('mfa_step_up_required', $after->json('code'));
        $this->assertNotSame(403, $after->getStatusCode());
        // Un analista sin models.approve sigue recibiendo la negativa por habilidad.
        $this->actingAsTenantUser($c['analyst'], $c['tenant'])->postJson('/api/v1/models/'.$this->customerModel($c['tenant'], 'candidate').'/validate')
            ->assertForbidden()->assertJsonPath('required_ability', 'models.approve');
    }

    public function test_every_controller_that_decides_an_approve_class_action_inline_enforces_the_second_factor(): void
    {
        // Auditoría estática: un `can('models.approve')` / `can('decisions.approve')` en un controlador que DECIDE una acción (no solo qué mostrar) debe pasar por MfaStepUp.
        // Los usos de solo lectura (ver el grupo de control, conteos) están en la lista con su motivo.
        $readOnlyUses = [
            'Http/Controllers/Api/Decision/DecisionController.php' => 'decide qué se MUESTRA (grupo de control, conteo); aprobar viaja por la ruta de transición con mfa.step_up:approval',
            'Http/Controllers/Api/Decision/OutcomeController.php' => 'decide qué se MUESTRA (resultados del grupo de control)',
            'Http/Controllers/Api/Radar/RadarController.php' => 'decide qué se MUESTRA (pertenencia a un experimento)',
            'Http/Controllers/Api/Core/TenantExportController.php' => 'la descarga es la de un enlace firmado emitido DESPUÉS del segundo factor (la creación de la exportación exige tenant.export_all, habilidad sensible); aquí solo se vuelve a comprobar la membresía y la habilidad',
        ];
        $found = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Http/Controllers'), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match("/can\\('(models\\.approve|decisions\\.approve|settings\\.manage|users\\.manage|datasources\\.manage|data\\.export|tenant\\.[a-z_]+|owners\\.manage)'\\)/", $source) !== 1) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(base_path('app/')));
            $found[] = $relative;
            if (! array_key_exists($relative, $readOnlyUses)) {
                $this->assertStringContainsString('approveClassAllowed', $source, "{$relative}: decide en línea una acción sensible y debe aplicar MfaStepUp::approveClassAllowed");
            }
        }
        $this->assertContains('Http/Controllers/Api/Models/ModelRegistryController.php', $found);
        $this->assertContains('Http/Controllers/Api/Customer/CustomerModelRollbackController.php', $found);
        $this->assertSame([], array_values(array_diff(array_keys($readOnlyUses), $found)), 'la lista de usos de solo lectura tiene una entrada que ya no existe');
    }
}
