<?php

namespace Modules\Treasury\Http\Controllers;

use Modules\Treasury\Models\BankAccountType;
use QCH\Core\Http\Controllers\Controller;

class BankAccountTypeController extends Controller
{
    protected $model = BankAccountType::class;

    protected $keyForPermission = 'bank_account_type';

    public function includes(): array
    {
        return [];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'key'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
