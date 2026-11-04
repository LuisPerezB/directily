<?php

declare(strict_types=1);

namespace Tests\Unit\Conversation\Gateway;

use App\Services\Conversation\Gateway\Security\InjectionGuard;
use App\Services\Outcomes\OutcomeVocabulary;
use App\Services\Radar\RadarPolicy;
use PHPUnit\Framework\TestCase;

/**
 * V2.5 · W6-D (revisión M-3): tasas de falsos negativos (FN) y falsos positivos (FP) de la capa HEURÍSTICA sobre el corpus de la revisión adversarial y sobre un conjunto NO VISTO.
 *
 * Qué mide cada conjunto (esto NO es una promesa de cobertura):
 *  - corpus de la revisión (99 frases adversariales A–D, 160 nombres legítimos, 13 plantillas de chat): el guardia se ajustó con él, así que protege contra REGRESIONES;
 *  - conjunto no visto (30 frases + 30 nombres, escrito después de ajustar y medido una vez): la cobertura ante paráfrasis nuevas es BAJA (≈ 20 %) y por eso el guardia no es la garantía.
 */
final class InjectionCorpusTest extends TestCase
{
    /** @return array<string, array<string, string>> */
    private static function corpus(): array
    {
        return require __DIR__.'/../../../Fixtures/InjectionCorpus.php';
    }

    private static function misses(array $texts): array
    {
        $guard = new InjectionGuard([]);

        return array_keys(array_filter($texts, fn (string $t) => $guard->inspect($t) === []));
    }

    public function test_natural_language_groups_of_the_review_corpus_stay_under_20_percent_false_negatives(): void
    {
        $adversarial = self::corpus()['reviewer_adversarial'];
        foreach (['A', 'B', 'C', 'D'] as $group) {
            $texts = array_filter($adversarial, fn (string $id) => $id[0] === $group, ARRAY_FILTER_USE_KEY);
            $missed = self::misses($texts);
            $this->assertLessThanOrEqual((int) floor(0.2 * count($texts)), count($missed), "grupo {$group}: FN ".count($missed).'/'.count($texts).' ('.implode(', ', $missed).')');
        }
        $this->assertLessThanOrEqual(10, count(self::misses($adversarial)), 'FN global del corpus de la revisión');
    }

    public function test_chat_template_tokens_of_the_main_model_families_are_all_detected(): void
    {
        $this->assertSame([], self::misses(self::corpus()['chat_templates']));
    }

    public function test_the_160_legitimate_names_stay_under_5_percent_false_positives(): void
    {
        $benign = self::corpus()['reviewer_benign'];
        $guard = new InjectionGuard([]);
        $flagged = array_values(array_filter($benign, fn (string $t) => $guard->inspect($t) !== []));

        $this->assertLessThanOrEqual((int) floor(0.05 * count($benign)), count($flagged), 'FP: '.implode(' | ', $flagged));
        $this->assertCount(160, $benign);
    }

    public function test_unseen_legitimate_names_are_not_flagged(): void
    {
        $guard = new InjectionGuard([]);
        $this->assertSame([], array_values(array_filter(self::corpus()['unseen_benign'], fn (string $t) => $guard->inspect($t) !== [])));
    }

    public function test_the_unseen_set_documents_the_residual_risk_without_pretending_coverage(): void
    {
        $unseen = self::corpus()['unseen_adversarial'];
        $missed = count(self::misses($unseen));

        // Medido: 24 de 30 sin detectar (80 %). La prueba fija el hecho para que cualquier mejora o regresión sea visible y para que nadie lea este guardia como una garantía.
        $this->assertGreaterThan(0, $missed, 'si el guardia detectara TODO el conjunto no visto, actualice la documentación de riesgo residual');
        $this->assertLessThan(count($unseen), $missed, 'regresión: el guardia dejó de detectar todo');
    }

    public function test_static_engine_texts_are_never_flagged(): void
    {
        $guard = new InjectionGuard([]);
        $strings = array_merge(
            array_values(OutcomeVocabulary::NOTES),
            array_values(OutcomeVocabulary::LABELS),
            array_values(RadarPolicy::DEFINITIONS),
            [RadarPolicy::ATTRIBUTION_TEXT],
        );
        $this->assertSame([], array_values(array_filter($strings, fn (string $s) => $guard->inspect($s) !== [])));
    }

    public function test_name_like_fields_are_sanitized_structurally_even_when_nothing_matches(): void
    {
        $guard = new InjectionGuard([]);
        $result = $guard->quarantine(['data' => ['name' => "  Cemento\n\n<b>Holcim</b>   50kg\u{200B} ".str_repeat('a b ', 100), 'note' => "dejar  <b>igual</b>\ntexto"]], 'r');

        $this->assertSame([], $result['findings']);
        $name = $result['payload']['data']['name'];
        $this->assertStringStartsWith('Cemento Holcim 50kg', $name);
        $this->assertLessThanOrEqual(InjectionGuard::NAME_MAX_CHARS, mb_strlen($name));
        $this->assertStringNotContainsString('<', $name);
        $this->assertStringNotContainsString("\n", $name);
        $this->assertSame("dejar  <b>igual</b>\ntexto", $result['payload']['data']['note'], 'los campos que no son nombres no se tocan');
        $this->assertSame('texto de enlace', InjectionGuard::sanitizeName('[texto de enlace](https://evil.example/x)'));
    }
}
