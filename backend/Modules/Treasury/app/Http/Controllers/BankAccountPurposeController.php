<?php

namespace Modules\Treasury\Http\Controllers;

use Modules\Treasury\Models\BankAccountPurpose;
use QCH\Core\Http\Controllers\Controller;

class BankAccountPurposeController extends Controller
{
    protected $model = BankAccountPurpose::class;

    protected $keyForPermission = 'bank_account_purpose';

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
