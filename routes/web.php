<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgentPerformanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/', function () {
    return redirect()->route('login');
});
Route::post('/admin/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');

    Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::get('/receipts/{receiptNumber}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::get('/receipts/{receiptNumber}/print', [ReceiptController::class, 'print'])->name('receipts.print');

    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportsController::class, 'exportExcel'])->name('reports.export');

    Route::get('/export/transactions', [ExportController::class, 'transactions'])->name('export.transactions');
    Route::get('/export/receipts', [ExportController::class, 'receipts'])->name('export.receipts');

    Route::middleware(['role:admin|superadmin'])->group(function () {
        Route::get('/export/users', [ExportController::class, 'users'])->name('export.users');
        Route::get('/export/customers', [ExportController::class, 'customers'])->name('export.customers');
        Route::get('/export/activity-logs', [ExportController::class, 'activityLogs'])->name('export.activity-logs');
        Route::get('/export/login-logs', [ExportController::class, 'loginLogs'])->name('export.login-logs');
        Route::get('/export/agent-performance', [ExportController::class, 'agentPerformance'])->name('export.agent-performance');
        Route::get('/export/exchange-rates', [ExportController::class, 'exchangeRates'])->name('export.exchange-rates');
        Route::get('/export/account-ledger', [ExportController::class, 'accountLedger'])->name('export.account-ledger');
        Route::get('/export/daily-balances', [ExportController::class, 'dailyBalances'])->name('export.daily-balances');
        Route::get('/export/profit-report', [ExportController::class, 'profitReport'])->name('export.profit-report');

        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/daily-balances', [AccountController::class, 'dailyBalances'])->name('accounts.daily-balances');
        Route::get('/accounts/entries/create', [AccountController::class, 'createEntry'])->name('accounts.entries.create');
        Route::post('/accounts/entries', [AccountController::class, 'storeEntry'])->name('accounts.entries.store');
        Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::patch('/users/{user}/toggle-lock', [UserController::class, 'toggleLock'])->name('users.toggle-lock');
        Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
        Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])->name('exchange-rates.store');
        Route::patch('/exchange-rates/{exchangeRate}/toggle', [ExchangeRateController::class, 'toggle'])->name('exchange-rates.toggle');

        Route::resource('customers', CustomerController::class);

        Route::patch('/transactions/{transaction}/status', [TransactionController::class, 'updateStatus'])->name('transactions.update-status');

        Route::get('/transactions/bulk/import', [TransactionController::class, 'showBulkImport'])->name('transactions.bulk-import');
        Route::post('/transactions/bulk/import', [TransactionController::class, 'bulkImport'])->name('transactions.bulk-import.store');

        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');

        Route::get('/agents/performance', [AgentPerformanceController::class, 'index'])->name('agents.performance');

        Route::get('/admin/roles', [RolePermissionController::class, 'index'])->name('admin.roles');
        Route::post('/admin/roles', [RolePermissionController::class, 'store'])->name('admin.roles.store');
        Route::put('/admin/roles/{role}', [RolePermissionController::class, 'update'])->name('admin.roles.update');
        Route::delete('/admin/roles/{role}', [RolePermissionController::class, 'destroy'])->name('admin.roles.destroy');
        Route::get('/admin/permissions', [RolePermissionController::class, 'permissions'])->name('admin.permissions');

        Route::get('/admin/activity-logs', [AuditLogController::class, 'activityLogs'])->name('admin.activity-logs');
        Route::get('/admin/login-logs', [AuditLogController::class, 'loginLogs'])->name('admin.login-logs');
    });
});
