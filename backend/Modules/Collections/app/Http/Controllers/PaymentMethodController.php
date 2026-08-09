<?php

namespace Modules\Collections\Http\Controllers;

use Modules\Collections\Models\PaymentMethod;
use QCH\Core\Http\Controllers\Controller;

class PaymentMethodController extends Controller
{
    protected $model = PaymentMethod::class;

    protected $keyForPermission = 'payment_method';

    public function sortableBy(): array
    {
        return ['order', 'name'];
    }

    public function filterableBy(): array
    {
        return ['is_active'];
    }
}
