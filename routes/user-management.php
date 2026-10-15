<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPasswordController;
use Illuminate\Support\Facades\Route;

// Include at the top level of web.php, not inside an admin/auth group.
Route::get('/password/enroll', [UserPasswordController::class, 'enroll'])->name('password.enroll');
Route::post('/password/enroll', [UserPasswordController::class, 'reset'])
    ->middleware('throttle:6,1')->name('password.enroll.store');

Route::middleware(['auth', 'account.active'])->group(function () {
    Route::get('/password/change-required', [UserPasswordController::class, 'required'])->name('password.required');
    Route::post('/password/change-required', [UserPasswordController::class, 'change'])
        ->middleware('throttle:6,1')->name('password.change');

    // Authorization is create-user OR edit-user in the controller (Gate bypass applies).
    Route::get('/users/availability', [UserController::class, 'availability'])
        ->middleware('throttle:30,1')->name('users.availability');
});
