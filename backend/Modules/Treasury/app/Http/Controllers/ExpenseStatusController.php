<?php

namespace Modules\Treasury\Http\Controllers;

use Modules\Treasury\Models\ExpenseStatus;
use QCH\Core\Http\Controllers\Controller;

class ExpenseStatusController extends Controller
{
    protected $model = ExpenseStatus::class;

    protected $keyForPermission = 'expense_status';

    public function sortableBy(): array
    {
        return ['name', 'order', 'key'];
    }

    public function searchableBy(): array
    {
        return ['name', 'key'];
    }
}
