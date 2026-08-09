<?php

namespace Modules\Collections\Http\Controllers;

use Modules\Collections\Models\PaymentStatus;
use QCH\Core\Http\Controllers\Controller;

class PaymentStatusController extends Controller
{
    protected $model = PaymentStatus::class;

    protected $keyForPermission = 'payment_status';

    public function sortableBy(): array
    {
        return ['order', 'name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
