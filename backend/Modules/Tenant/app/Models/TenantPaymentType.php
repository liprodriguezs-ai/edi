<?php

namespace Modules\Tenant\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use QCH\Core\Models\CentralCatalogModel;
use QCH\Core\Models\GlobalObserver;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

#[ObservedBy([GlobalObserver::class])]
class TenantPaymentType extends CentralCatalogModel
{
    use CentralConnection;
    use HasFactory;

    const CASH = '8ba4b4b3-8bde-4626-99ff-9c33818c9d99';

    const SUBSCRIPTION = 'b0138862-8840-415c-947a-8b224714824b';

    const BANK_TRANSFER = '588f3b2c-91d8-48e6-97a5-4c8700074070';

    protected $table = 'tenant_payment_type';

    protected $fillable = ['id', 'name', 'description'];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
        ];
    }
}
