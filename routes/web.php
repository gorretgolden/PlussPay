<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgentPerformanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashHandoverController;
use App\Http\Controllers\ChinaDeskController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CurrencyExchangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\MyAccountController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

// Password enrollment and the forced change screen sit at the top level: part of them must be
// reachable before an admin permission exists, and part of them must be behind the session.
require __DIR__.'/user-management.php';

Route::get('/admin/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/', fn () => redirect()->route('login'));
Route::post('/admin/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::get('/two-factor', [AuthController::class, 'showTwoFactor'])->name('two-factor.challenge');
Route::post('/two-factor', [AuthController::class, 'verifyTwoFactor'])->name('two-factor.verify');
Route::post('/two-factor/cancel', [AuthController::class, 'cancelTwoFactor'])->name('two-factor.cancel');

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('can:view-dashboard')->name('dashboard');

    // Everyone who can sign in reads and changes their own password here, whatever their role.
    Route::get('/account', [MyAccountController::class, 'show'])->name('account.show');

    Route::get('/wallet', [WalletController::class, 'index'])->middleware('can:view-wallets')->name('wallet.index');
    Route::post('/wallets/adjust', [WalletController::class, 'storeAdjustment'])->middleware(['can:view-wallets', 'can:adjust-wallets'])->name('wallets.adjust');
    // An agent asks the super agent to take the cash; the super agent decides it. Nothing reaches
    // the ledger until the approval, so a pending request is not a movement of money.
    Route::post('/cash-handovers', [CashHandoverController::class, 'store'])->middleware('can:view-wallets')->name('cash-handovers.store');
    // The usual way in: the agent ticks the transfers whose cash is in hand and sends those names up,
    // and may call the request back again while nobody has decided it.
    Route::post('/cash-handovers/from-transactions', [CashHandoverController::class, 'storeFromTransactions'])->middleware('can:view-wallets')->name('cash-handovers.from-transactions');
    Route::post('/cash-handovers/{cashHandover}/cancel', [CashHandoverController::class, 'cancel'])->middleware('can:view-wallets')->name('cash-handovers.cancel');
    // The desk page for the requests, the sheet off the same filters, and one pen for approving a
    // stack of them. The words export and bulk are never read as a hand-in id.
    Route::get('/cash-handovers', [CashHandoverController::class, 'index'])->middleware('can:view-wallets')->name('cash-handovers.index');
    Route::get('/cash-handovers/export', [CashHandoverController::class, 'exportExcel'])->middleware('can:view-wallets')->name('cash-handovers.export');
    Route::post('/cash-handovers/bulk', [CashHandoverController::class, 'bulk'])->middleware('can:approve-cash-handover')->name('cash-handovers.bulk');
    Route::post('/cash-handovers/{cashHandover}/approve', [CashHandoverController::class, 'approve'])->middleware('can:approve-cash-handover')->name('cash-handovers.approve');
    Route::post('/cash-handovers/{cashHandover}/decline', [CashHandoverController::class, 'decline'])->middleware('can:approve-cash-handover')->name('cash-handovers.decline');
    Route::get('/export/cash-history', [ExportController::class, 'cashHistory'])->middleware('can:view-wallets')->name('export.cash-history');

    Route::middleware('can:view-transactions')->group(function () {
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/create', [TransactionController::class, 'create'])->middleware('can:create-transaction')->name('transactions.create');
        Route::post('/transactions', [TransactionController::class, 'store'])->middleware('can:create-transaction')->name('transactions.store');
        Route::get('/transactions/bulk/import', [TransactionController::class, 'showBulkImport'])->middleware('can:bulk-import-transactions')->name('transactions.bulk-import');
        Route::post('/transactions/bulk/import', [TransactionController::class, 'bulkImport'])->middleware('can:bulk-import-transactions')->name('transactions.bulk-import.store');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::patch('/transactions/{transaction}/status', [TransactionController::class, 'updateStatus'])->middleware('can:approve-transaction')->name('transactions.update-status');
        Route::get('/export/transactions', [ExportController::class, 'transactions'])->middleware('can:export-transactions')->name('export.transactions');
    });

    // The corridor's second half: dollars out of Uganda into the desk in China, then the yuan that
    // desk pays to the receiver. Both movements sit on the transfer itself, so this page is the
    // only place the book is told which side of the world the money is on.
    Route::middleware('can:view-transactions')->group(function () {
        Route::get('/china', [ChinaDeskController::class, 'index'])->name('china.index');
        Route::post('/china/send', [ChinaDeskController::class, 'send'])->middleware('can:send-to-china')->name('china.send');
        Route::post('/china/payout', [ChinaDeskController::class, 'payout'])->middleware('can:confirm-china-payout')->name('china.payout');
    });

    Route::middleware('can:view-exchanges')->group(function () {
        Route::get('/exchanges', [CurrencyExchangeController::class, 'index'])->name('exchanges.index');
        Route::get('/exchanges/create', [CurrencyExchangeController::class, 'create'])->middleware('can:create-exchange')->name('exchanges.create');
        Route::post('/exchanges', [CurrencyExchangeController::class, 'store'])->middleware('can:create-exchange')->name('exchanges.store');
        Route::get('/export/exchanges', [ExportController::class, 'exchanges'])->middleware('can:export-exchanges')->name('export.exchanges');
        Route::get('/exchanges/{exchange}', [CurrencyExchangeController::class, 'show'])->name('exchanges.show');
        Route::get('/exchanges/{exchange}/receipt', [CurrencyExchangeController::class, 'receipt'])->middleware('can:print-receipt')->name('exchanges.receipt');
        Route::post('/exchanges/{exchange}/void', [CurrencyExchangeController::class, 'void'])->middleware('can:void-exchange')->name('exchanges.void');
    });

    Route::middleware('can:view-receipts')->group(function () {
        Route::get('/receipts', [ReceiptController::class, 'index'])->name('receipts.index');
        Route::get('/receipts/{receiptNumber}', [ReceiptController::class, 'show'])->name('receipts.show');
        Route::get('/receipts/{receiptNumber}/print', [ReceiptController::class, 'print'])->middleware('can:print-receipt')->name('receipts.print');
        Route::get('/export/receipts', [ExportController::class, 'receipts'])->middleware('can:export-receipts')->name('export.receipts');
    });
    Route::middleware('can:view-reports')->group(function () {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportsController::class, 'exportExcel'])->middleware('can:export-reports')->name('reports.export');
        Route::get('/export/profit-report', [ExportController::class, 'profitReport'])->middleware(['can:export-reports', 'can:view-profit'])->name('export.profit-report');
    });
    Route::get('/agents/performance', [AgentPerformanceController::class, 'index'])->middleware(['can:view-agent-performance', 'can:view-all-transactions', 'can:view-profit'])->name('agents.performance');
    Route::get('/export/agent-performance', [ExportController::class, 'agentPerformance'])->middleware(['can:view-agent-performance', 'can:view-all-transactions', 'can:view-profit', 'can:export-reports'])->name('export.agent-performance');

    Route::middleware('can:view-accounts')->group(function () {
        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/daily-balances', [AccountController::class, 'dailyBalances'])->name('accounts.daily-balances');
        Route::get('/accounts/movements', [AccountController::class, 'movements'])->name('accounts.movements');
        Route::get('/accounts/entries/create', [AccountController::class, 'createEntry'])->middleware('can:create-account-entry')->name('accounts.entries.create');
        Route::post('/accounts/entries', [AccountController::class, 'storeEntry'])->middleware('can:create-account-entry')->name('accounts.entries.store');
        Route::get('/accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
        Route::get('/export/account-ledger', [ExportController::class, 'accountLedger'])->middleware('can:export-accounts')->name('export.account-ledger');
        Route::get('/export/daily-balances', [ExportController::class, 'dailyBalances'])->middleware('can:export-accounts')->name('export.daily-balances');
        Route::get('/export/movements', [ExportController::class, 'movements'])->middleware('can:export-accounts')->name('export.movements');
    });
    Route::middleware('can:view-expenses')->group(function () {
        // Named ahead of any /expenses/{expense} so the word categories is never read as a record.
        Route::get('/expenses/categories', [ExpenseCategoryController::class, 'index'])->middleware('can:manage-expense-categories')->name('expense-categories.index');
        Route::post('/expenses/categories', [ExpenseCategoryController::class, 'store'])->middleware('can:manage-expense-categories')->name('expense-categories.store');
        Route::put('/expenses/categories/{category}', [ExpenseCategoryController::class, 'update'])->middleware('can:manage-expense-categories')->name('expense-categories.update');
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])->middleware('can:create-expense')->name('expenses.create');
        Route::post('/expenses', [ExpenseController::class, 'store'])->middleware('can:create-expense')->name('expenses.store');
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show'])->name('expenses.show');
        // Fixing the words of an entry is the same reach as recording one, so no new ability is
        // needed: the person who may write it may correct it, and only on their own rows.
        Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->middleware('can:create-expense')->name('expenses.edit');
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->middleware('can:create-expense')->name('expenses.update');
        Route::get('/expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
        Route::post('/expenses/{expense}/void', [ExpenseController::class, 'void'])->middleware('can:void-expense')->name('expenses.void');
        Route::get('/export/expenses', [ExportController::class, 'expenses'])->middleware('can:export-expenses')->name('export.expenses');
    });
    Route::middleware('can:view-customers')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/create', [CustomerController::class, 'create'])->middleware('can:create-customer')->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->middleware('can:create-customer')->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->middleware('can:edit-customer')->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('can:edit-customer')->name('customers.update');
        Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->middleware('can:edit-customer');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('can:delete-customer')->name('customers.destroy');
        Route::get('/export/customers', [ExportController::class, 'customers'])->middleware('can:export-customers')->name('export.customers');
    });
    Route::middleware('can:view-exchange-rates')->group(function () {
        Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
        Route::patch('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'update'])->middleware('can:manage-exchange-rates')->name('exchange-rates.update');
        Route::patch('/exchange-rates/{exchangeRate}/toggle', [ExchangeRateController::class, 'toggle'])->middleware('can:manage-exchange-rates')->name('exchange-rates.toggle');
        Route::get('/export/exchange-rates', [ExportController::class, 'exchangeRates'])->middleware('can:export-exchange-rates')->name('export.exchange-rates');
    });

    Route::middleware('can:view-users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->middleware('can:create-user')->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->middleware('can:create-user')->name('users.store');
        Route::post('/users/bulk', [UserController::class, 'bulk'])->middleware('can:edit-user')->name('users.bulk');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->middleware('can:edit-user')->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->middleware('can:edit-user')->name('users.update');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('can:reset-user-password')->name('users.reset-password');
        Route::patch('/users/{user}/toggle-lock', [UserController::class, 'toggleLock'])->middleware('can:lock-user')->name('users.toggle-lock');
        Route::patch('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->middleware('can:toggle-user-status')->name('users.toggle-active');
        Route::get('/users/{user}/two-factor', [UserController::class, 'twoFactorForm'])->middleware('can:manage-user-two-factor')->name('users.two-factor');
        Route::post('/users/{user}/two-factor', [UserController::class, 'twoFactorEnable'])->middleware('can:manage-user-two-factor')->name('users.two-factor.enable');
        Route::post('/users/{user}/two-factor/reset', [UserController::class, 'twoFactorReset'])->middleware('can:manage-user-two-factor')->name('users.two-factor.reset');
        Route::delete('/users/{user}/two-factor', [UserController::class, 'twoFactorDisable'])->middleware('can:manage-user-two-factor')->name('users.two-factor.disable');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('can:delete-user')->name('users.destroy');
        Route::get('/export/users', [ExportController::class, 'users'])->middleware('can:export-users')->name('export.users');
    });

    Route::get('/settings', [SettingController::class, 'index'])->middleware('can:view-settings')->name('settings.index');
    Route::put('/settings', [SettingController::class, 'update'])->middleware(['can:view-settings', 'can:manage-settings'])->name('settings.update');
    Route::post('/settings/market-rates/pull', [SettingController::class, 'pullMarketRates'])
        ->middleware(['can:view-settings', 'can:manage-settings'])->name('settings.market-rates.pull');

    Route::middleware('can:view-roles')->group(function () {
        Route::get('/admin/roles', [RolePermissionController::class, 'index'])->name('admin.roles');
        Route::post('/admin/roles', [RolePermissionController::class, 'store'])->middleware('can:create-role')->name('admin.roles.store');
        Route::post('/admin/roles/{role}/duplicate', [RolePermissionController::class, 'duplicate'])->middleware('can:create-role')->name('admin.roles.duplicate');
        Route::put('/admin/roles/{role}', [RolePermissionController::class, 'update'])->middleware('can:edit-role')->name('admin.roles.update');
        Route::patch('/admin/roles/{role}/rename', [RolePermissionController::class, 'rename'])->middleware('can:edit-role')->name('admin.roles.rename');
        Route::delete('/admin/roles/{role}', [RolePermissionController::class, 'destroy'])->middleware('can:delete-role')->name('admin.roles.destroy');
        Route::get('/admin/permissions', [RolePermissionController::class, 'permissions'])->name('admin.permissions');
        Route::post('/admin/permissions/seed', [RolePermissionController::class, 'seed'])->middleware('can:seed-permissions')->name('admin.permissions.seed');
        Route::post('/admin/permissions', [RolePermissionController::class, 'storePermission'])->middleware('can:assign-permissions')->name('admin.permissions.store');
        Route::put('/admin/permissions/{permission}', [RolePermissionController::class, 'updatePermission'])->middleware('can:assign-permissions')->name('admin.permissions.update');
        Route::delete('/admin/permissions/{permission}', [RolePermissionController::class, 'destroyPermission'])->middleware('can:assign-permissions')->name('admin.permissions.destroy');
        Route::post('/admin/permissions/bulk', [RolePermissionController::class, 'bulk'])->middleware(['can:edit-role', 'can:assign-permissions'])->name('admin.permissions.bulk');
        Route::post('/admin/permissions/{permission}/roles/{role}/toggle', [RolePermissionController::class, 'toggleRolePermission'])->middleware(['can:edit-role', 'can:assign-permissions'])->name('admin.permissions.toggle-role');
    });
    Route::get('/admin/activity-logs', [AuditLogController::class, 'activityLogs'])->middleware('can:view-activity-logs')->name('admin.activity-logs');
    Route::get('/admin/activity-logs/{activityLog}/details', [AuditLogController::class, 'details'])->middleware('can:view-activity-logs')->name('admin.activity-logs.details');
    Route::get('/admin/login-logs', [AuditLogController::class, 'loginLogs'])->middleware('can:view-login-logs')->name('admin.login-logs');
    Route::get('/export/activity-logs', [ExportController::class, 'activityLogs'])->middleware(['can:view-activity-logs', 'can:export-logs'])->name('export.activity-logs');
    Route::get('/export/login-logs', [ExportController::class, 'loginLogs'])->middleware(['can:view-login-logs', 'can:export-logs'])->name('export.login-logs');
    Route::get('/admin/system-logs', [SystemLogController::class, 'index'])->middleware('can:view-system-logs')->name('admin.system-logs');
    Route::patch('/admin/system-logs/{systemLog}/resolve', [SystemLogController::class, 'resolve'])->middleware('can:resolve-system-logs')->name('admin.system-logs.resolve');
    Route::get('/export/system-logs', [ExportController::class, 'systemLogs'])->middleware('can:export-system-logs')->name('export.system-logs');

    // What happened in the book. No ability gates this page: a notice is only ever written for
    // a part of the book its reader can already open, and the controller proves that.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
});
