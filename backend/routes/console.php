<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas de Predictily (zona horaria America/Guayaquil)
|--------------------------------------------------------------------------
| Cada sección tiene un responsable; editar solo la propia.
*/

// ── backend-core: retención, eliminación diferida de empresas, limpieza de archivos ──
Schedule::command('predictily:purge')->dailyAt('03:30')->timezone('America/Guayaquil')->withoutOverlapping()->onOneServer();
// V2.5 · W2-B: retención de Bronze por empresa (ADR-V25-24); purga auditada de los objetos vencidos.
Schedule::command('predictily:bronze-purge')->dailyAt('03:45')->timezone('America/Guayaquil')->withoutOverlapping()->onOneServer();

// ── backend-analytics: evaluación vs reales, pronósticos programados ──
// El entrenamiento NO se dispara por cada factura: solo por programación, por el usuario
// o al completar una importación inicial.
Schedule::command('predictily:evaluate-actuals')
    ->dailyAt('02:00')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();
Schedule::command('predictily:schedule-forecasts')
    ->dailyAt('03:00')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();

// ── datacore (etapa 2 · P3): sincronización incremental de fuentes SQL programadas (hourly/daily) ──
Schedule::command('predictily:sync-sources')
    ->hourlyAt(17)->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();

// ── comportamiento de compra (etapa 2 · P2): entrenamiento semanal y predicción diaria (la recencia cambia sin ventas nuevas) ──
Schedule::command('predictily:customer-train')
    ->weeklyOn(0, '02:40')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();
Schedule::command('predictily:customer-predict')
    ->dailyAt('04:20')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();

// ── Factor Intelligence (V2.5 · W2-F): vencimiento de eventos externos y de concesiones de pronóstico compartido (idempotente) ──
Schedule::command('predictily:intelligence-expire')
    ->dailyAt('01:30')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();

// ── MLOps (V2.5 · W4-A): foto diaria del almacenamiento por empresa (Bronze y artefactos) para el modelo de costos; solo cantidades, sin precios ──
Schedule::command('predictily:usage-snapshot')
    ->dailyAt('05:00')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();

// ── Revenue Radar (V2.5 · W5-A): tras la predicción diaria de comportamiento (04:20), propone al Decision Engine las oportunidades de la predicción vigente (idempotente) ──
Schedule::command('predictily:radar-materialize')
    ->dailyAt('04:50')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();

// ── Decision Engine (V2.5 · W5-B): genera las recomendaciones tras la predicción de comportamiento (04:20) y el radar (04:50); vence las caducadas cada hora (ambos idempotentes, por empresa) ──
Schedule::command('predictily:decisions-generate')
    ->dailyAt('05:30')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();
Schedule::command('predictily:decisions-expire')
    ->hourlyAt(25)->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();

// ── Outcomes (V2.5 · W5-C): mide lo ocurrido en la ventana de cada recomendación cuya ventana ya cerró (más el margen de espera de documentos tardíos), tras generar y vencer las decisiones (05:30 y cada hora); idempotente, por empresa ──
Schedule::command('predictily:outcomes-measure')
    ->dailyAt('06:10')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();
