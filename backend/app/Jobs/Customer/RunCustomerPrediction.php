<?php

declare(strict_types=1);

namespace App\Jobs\Customer;

/**
 * Predicción diaria del Customer Core (operation = predict): carga los modelos en producción y recalcula las variables a
 * la fecha; la recencia cambia aunque no haya una venta nueva. No entrena.
 */
class RunCustomerPrediction extends RunCustomerOperation {}
