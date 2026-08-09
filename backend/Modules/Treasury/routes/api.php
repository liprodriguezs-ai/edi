<?php

use Illuminate\Support\Facades\Route;
use Modules\Treasury\Http\Controllers\BankAccountController;
use Modules\Treasury\Http\Controllers\BankAccountPurposeController;
use Modules\Treasury\Http\Controllers\BankAccountTypeController;
use Modules\Treasury\Http\Controllers\BeneficiaryTypeController;
use Modules\Treasury\Http\Controllers\ExpenseCategoryController;
use Modules\Treasury\Http\Controllers\ExpenseController;
use Modules\Treasury\Http\Controllers\ExpenseStatusController;
use Modules\Treasury\Http\Controllers\PublicSummaryController;
use Modules\Treasury\Http\Controllers\TreasuryReportController;
use Orion\Facades\Orion;

Route::middleware('throttle:api')->group(function () {
    // Bank account catalogs
    Orion::resource('bank_account_type', BankAccountTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('bank_account_purpose', BankAccountPurposeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('bank_account', BankAccountController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('expense_category', ExpenseCategoryController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Expense catalogs
    Orion::resource('expense_status', ExpenseStatusController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    Orion::resource('beneficiary_type', BeneficiaryTypeController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Expenses
    Orion::resource('expense', ExpenseController::class)
        ->withSoftDeletes()
        ->middleware(['auth:sanctum', 'initializeTenancy']);

    // Custom void action
    Route::post('expense/{id}/void', [ExpenseController::class, 'void'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Attach a comprobante (receipt) or CFDI file to an expense (§9.4.2, §9.4.3).
    Route::post('expense/{id}/media', [ExpenseController::class, 'uploadMedia'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Public summary — resident-facing (§9.5): auth required but no permission check
    // (all authenticated users can see). Returns aggregates only; per-account
    // balances require read.bank_account (§9.6 r7).
    Route::get('treasury/public-summary', [PublicSummaryController::class, 'summary'])
        ->middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy']);

    // Dashboard report endpoints — permission-gated, read-only analytics.
    Route::middleware(['auth:sanctum', 'rejectRefreshToken', 'authActive', 'initializeTenancy'])->group(function () {
        // P&L rolling series (read.report_pnl).
        Route::get('treasury/income-expense-series', [TreasuryReportController::class, 'incomeExpenseSeries']);
        // Cashflow series with bank account balances (read.report_treasury).
        Route::get('treasury/cashflow-series', [TreasuryReportController::class, 'cashflowSeries']);
        // Expense breakdown by category / provider / month (read.report_expenses).
        Route::get('treasury/expenses-breakdown', [TreasuryReportController::class, 'expensesBreakdown']);
    });
});
