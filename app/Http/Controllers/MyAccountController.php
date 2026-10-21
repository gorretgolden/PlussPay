<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MyAccountController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return view('account.show', [
            'user' => $user,
            'roles' => $user->getRoleNames()
                ->map(fn (string $role) => RolePermissionController::displayName($role))
                ->all(),
        ]);
    }
}
