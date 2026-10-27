<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas de Predictily (zona horaria America/Guayaquil)
|--------------------------------------------------------------------------
| Cada sección tiene un responsable; editar solo la propia.
*/

// ── backend-core: retención, eliminación diferida de empresas, limpieza de archivos ──
// (por definir por backend-core)

// ── backend-analytics: evaluación vs reales, pronósticos programados ──
// El entrenamiento NO se dispara por cada factura: solo por programación, por el usuario
// o al completar una importación inicial.
Schedule::command('predictily:evaluate-actuals')
    ->dailyAt('02:00')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();
Schedule::command('predictily:schedule-forecasts')
    ->dailyAt('03:00')->timezone('America/Guayaquil')
    ->withoutOverlapping()->onOneServer();
