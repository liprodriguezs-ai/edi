<?php

namespace Modules\Treasury\Http\Controllers;

use Modules\Treasury\Models\ExpenseCategory;
use QCH\Core\Http\Controllers\Controller;

class ExpenseCategoryController extends Controller
{
    protected $model = ExpenseCategory::class;

    protected $keyForPermission = 'expense_category';

    public function includes(): array
    {
        return ['parent', 'children'];
    }

    public function sortableBy(): array
    {
        return ['name', 'order', 'is_active'];
    }

    public function searchableBy(): array
    {
        return ['name'];
    }

    public function filterableBy(): array
    {
        return ['parent_id', 'is_active'];
    }
}
