<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * E2-24 (autonomía) a nivel de código: ningún módulo de los cores (backend ni motor) referencia la capa conversacional. Si un core
 * la importara, su ausencia podría bloquearlo; la dependencia solo existe en sentido contrario (el asistente consume resultados).
 */
final class CoresDoNotDependOnAssistantTest extends TestCase
{
    /** Únicos lugares autorizados a nombrar la capa conversacional. */
    private const ALLOWED = [
        'app/Services/Conversation/',
        'app/Http/Controllers/Api/Conversation/',
        'app/Http/Requests/Conversation/',
        'routes/api/platform.php',          // registro de las dos rutas /assistant
        'config/conversation.php',
    ];

    /** @return list<string> rutas relativas al backend de los archivos PHP */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $base = base_path();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = ltrim(str_replace($base, '', $file->getPathname()), '/');
            }
        }

        return $files;
    }

    public function test_backend_cores_never_reference_the_conversation_layer(): void
    {
        $offenders = [];
        foreach (['app', 'routes', 'database', 'config', 'bootstrap'] as $dir) {
            foreach ($this->phpFiles($dir) as $path) {
                foreach (self::ALLOWED as $allowed) {
                    if (str_starts_with($path, $allowed)) {
                        continue 2;
                    }
                }
                if (preg_match('/Conversation\\\\|config\(\s*[\'"]conversation|ConversationProvider|OllamaCloud|AssistantController/', (string) file_get_contents(base_path($path))) === 1) {
                    $offenders[] = $path;
                }
            }
        }
        $this->assertSame([], $offenders, 'Archivos fuera de la capa conversacional que la referencian.');
    }

    public function test_the_prediction_engine_has_no_conversational_code(): void
    {
        $engine = base_path('../engine/predictily_engine');
        if (! is_dir($engine)) {
            $this->markTestSkipped('El motor no está en el árbol (ejecución solo del backend).');
        }
        $offenders = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($engine, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.py')
                && preg_match('/\b(ollama|conversation\w*|chatbot|assistant|openai|llm)\b/i', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders);
    }

    public function test_conversation_routes_are_the_only_ones_that_use_the_assistant_controller(): void
    {
        $assistant = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'assistant'));
        $this->assertSame(['api/v1/assistant/messages', 'api/v1/assistant/status'], $assistant->map->uri()->sort()->values()->all());
        foreach ($assistant as $route) {
            $this->assertContains('tenant.ability:data.view', $route->gatherMiddleware());
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        }
    }
}
