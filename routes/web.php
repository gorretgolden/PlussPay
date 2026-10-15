<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgentPerformanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CurrencyExchangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/', function () {
    return redirect()->route('login');
});
Route::post('/admin/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Two-factor challenge: the password is proven but the session is not trusted yet.
Route::get('/two-factor', [AuthController::class, 'showTwoFactor'])->name('two-factor.challenge');
Route::post('/two-factor', [AuthController::class, 'verifyTwoFactor'])->name('two-factor.verify');
Route::post('/two-factor/cancel', [AuthController::class, 'cancelTwoFactor'])->name('two-factor.cancel');

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

    Route::middleware(['role:admin|superadmin'])->group(function () {
        Route::post('/wallets/adjust', [WalletController::class, 'storeAdjustment'])->name('wallets.adjust');
    });

    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');

    // Booking a transfer is desk work, so the create form stays with the desks that run it.
    Route::middleware(['role:admin|superadmin|agent'])->group(function () {
        Route::get('/transactions/create', [TransactionController::class, 'create'])->name('transactions.create');
        Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    });

    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');

    // Changing money over the counter is desk work, so the same roles that book a transfer can
    // price and record a currency deal. Cancelling one puts money back in the till, so that
    // stays with the admins.
    Route::middleware(['role:admin|superadmin|accountant|agent'])->group(function () {
        Route::get('/exchanges', [CurrencyExchangeController::class, 'index'])->name('exchanges.index');
        Route::get('/exchanges/create', [CurrencyExchangeController::class, 'create'])->name('exchanges.create');
        Route::post('/exchanges', [CurrencyExchangeController::class, 'store'])->name('exchanges.store');
        Route::get('/export/exchanges', [ExportController::class, 'exchanges'])->name('export.exchanges');
        Route::get('/exchanges/{exchange}', [CurrencyExchangeController::class, 'show'])->name('exchanges.show');

        Route::middleware(['role:admin|superadmin'])->group(function () {
            Route::post('/exchanges/{exchange}/void', [CurrencyExchangeController::class, 'void'])->name('exchanges.void');
        });
    });

    Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::get('/receipts/{receiptNumber}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::get('/receipts/{receiptNumber}/print', [ReceiptController::class, 'print'])->name('receipts.print');

    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportsController::class, 'exportExcel'])->name('reports.export');

    Route::get('/export/transactions', [ExportController::class, 'transactions'])->name('export.transactions');
    Route::get('/export/receipts', [ExportController::class, 'receipts'])->name('export.receipts');

    // The books: open to the accountant as well as the admins who own the system.
    Route::middleware(['role:admin|superadmin|accountant'])->group(function () {
        Route::get('/export/customers', [ExportController::class, 'customers'])->name('export.customers');
        Route::get('/export/agent-performance', [ExportController::class, 'agentPerformance'])->name('export.agent-performance');
        Route::get('/export/account-ledger', [ExportController::class, 'accountLedger'])->name('export.account-ledger');
        Route::get('/export/daily-balances', [ExportController::class, 'dailyBalances'])->name('export.daily-balances');
        Route::get('/export/profit-report', [ExportController::class, 'profitReport'])->name('export.profit-report');
        Route::get('/export/expenses', [ExportController::class, 'expenses'])->name('export.expenses');
        Route::get('/export/movements', [ExportController::class, 'movements'])->name('export.movements');

        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/daily-balances', [AccountController::class, 'dailyBalances'])->name('accounts.daily-balances');
        Route::get('/accounts/movements', [AccountController::class, 'movements'])->name('accounts.movements');
        Route::get('/accounts/entries/create', [AccountController::class, 'createEntry'])->name('accounts.entries.create');
        Route::post('/accounts/entries', [AccountController::class, 'storeEntry'])->name('accounts.entries.store');
        Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');

        // What the business spent on itself. Every entry takes money out of the account that paid
        // for it, so it belongs with the books rather than with the desks.
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::post('/expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');

        Route::resource('customers', CustomerController::class);

        Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');

        Route::get('/agents/performance', [AgentPerformanceController::class, 'index'])->name('agents.performance');

        // System administration stays with the admins alone.
        Route::middleware(['role:admin|superadmin'])->group(function () {
            Route::get('/export/users', [ExportController::class, 'users'])->name('export.users');
            Route::get('/export/activity-logs', [ExportController::class, 'activityLogs'])->name('export.activity-logs');
            Route::get('/export/login-logs', [ExportController::class, 'loginLogs'])->name('export.login-logs');
            Route::get('/export/exchange-rates', [ExportController::class, 'exchangeRates'])->name('export.exchange-rates');

            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::post('/users/bulk', [UserController::class, 'bulk'])->name('users.bulk');
            Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
            Route::patch('/users/{user}/toggle-lock', [UserController::class, 'toggleLock'])->name('users.toggle-lock');
            Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
            Route::get('/users/{user}/two-factor', [UserController::class, 'twoFactorForm'])->name('users.two-factor');
            Route::post('/users/{user}/two-factor', [UserController::class, 'twoFactorEnable'])->name('users.two-factor.enable');
            Route::post('/users/{user}/two-factor/reset', [UserController::class, 'twoFactorReset'])->name('users.two-factor.reset');
            Route::delete('/users/{user}/two-factor', [UserController::class, 'twoFactorDisable'])->name('users.two-factor.disable');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

            // Prices are set in System Settings, where the two pairs the system actually uses are
            // the only choices. This page is the trail, so it only ever corrects a row already there
            // or brings an older price back into use.
            Route::patch('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'update'])->name('exchange-rates.update');
            Route::patch('/exchange-rates/{exchangeRate}/toggle', [ExchangeRateController::class, 'toggle'])->name('exchange-rates.toggle');

            Route::patch('/transactions/{transaction}/status', [TransactionController::class, 'updateStatus'])->name('transactions.update-status');

            Route::get('/transactions/bulk/import', [TransactionController::class, 'showBulkImport'])->name('transactions.bulk-import');
            Route::post('/transactions/bulk/import', [TransactionController::class, 'bulkImport'])->name('transactions.bulk-import.store');

            Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
            Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');

            Route::get('/admin/roles', [RolePermissionController::class, 'index'])->name('admin.roles');
            Route::post('/admin/roles', [RolePermissionController::class, 'store'])->name('admin.roles.store');
            Route::put('/admin/roles/{role}', [RolePermissionController::class, 'update'])->name('admin.roles.update');
            Route::delete('/admin/roles/{role}', [RolePermissionController::class, 'destroy'])->name('admin.roles.destroy');
            Route::get('/admin/permissions', [RolePermissionController::class, 'permissions'])->name('admin.permissions');
            Route::post('/admin/permissions', [RolePermissionController::class, 'storePermission'])->name('admin.permissions.store');
            Route::put('/admin/permissions/{permission}', [RolePermissionController::class, 'updatePermission'])->name('admin.permissions.update');
            Route::delete('/admin/permissions/{permission}', [RolePermissionController::class, 'destroyPermission'])->name('admin.permissions.destroy');
            Route::post('/admin/permissions/bulk', [RolePermissionController::class, 'bulk'])->name('admin.permissions.bulk');
            Route::post('/admin/permissions/{permission}/roles/{role}/toggle', [RolePermissionController::class, 'toggleRolePermission'])->name('admin.permissions.toggle-role');

            Route::get('/admin/activity-logs', [AuditLogController::class, 'activityLogs'])->name('admin.activity-logs');
            Route::get('/admin/activity-logs/{activityLog}/details', [AuditLogController::class, 'details'])->name('admin.activity-logs.details');
            Route::get('/admin/login-logs', [AuditLogController::class, 'loginLogs'])->name('admin.login-logs');

            // The error log names files, classes and stack traces. That is system
            // internals, so it stays with the Super Admin alone even though Admins read
            // the audit and login trails. Read-only apart from marking an error resolved.
            Route::middleware(['role:superadmin'])->group(function () {
                Route::get('/admin/system-logs', [SystemLogController::class, 'index'])->name('admin.system-logs');
                Route::patch('/admin/system-logs/{systemLog}/resolve', [SystemLogController::class, 'resolve'])->name('admin.system-logs.resolve');
                Route::get('/export/system-logs', [ExportController::class, 'systemLogs'])->name('export.system-logs');
            });
        });
    });
});
