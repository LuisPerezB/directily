<?php

declare(strict_types=1);

namespace App\Jobs\Customer;

/**
 * Entrena el Customer Core de la empresa (operation = train): dataset de cortes, protocolo temporal por horizonte,
 * calibración, evaluación, registro de modelos y promoción condicionada a la regla versionada.
 */
class RunCustomerTraining extends RunCustomerOperation {}
