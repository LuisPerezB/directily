<?php

use App\Providers\AnalyticsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BridgeServiceProvider;

return [
    AppServiceProvider::class,
    AnalyticsServiceProvider::class, // backend-analytics
    BridgeServiceProvider::class, // V2.5 · W6-B: límites de tasa del Bridge
];
