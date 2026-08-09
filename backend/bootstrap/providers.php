<?php

use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;
use App\Providers\TenancyServiceProvider;
use Barryvdh\DomPDF\ServiceProvider;
use Maatwebsite\Excel\ExcelServiceProvider;
use OwenIt\Auditing\AuditingServiceProvider;

return [
    AppServiceProvider::class,
    TelescopeServiceProvider::class,
    TenancyServiceProvider::class,
    ServiceProvider::class,
    ExcelServiceProvider::class,
    AuditingServiceProvider::class,
];
