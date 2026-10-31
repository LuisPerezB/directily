<?php

declare(strict_types=1);

namespace App\Jobs\Supply;

/** Plan de reposición con restricciones (MILP, HiGHS). Sugerencias: no emite pedidos ni compromete fondos. */
class RunSupplyPlan extends RunSupplyOperation {}
