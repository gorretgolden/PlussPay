<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function updateTheme(Request $request)
    {
        $validated = $request->validate([
            'theme' => ['required', Rule::in(['light', 'dark'])],
        ]);
        $request->user()->forceFill(['theme' => $validated['theme']])->save();

        return back();
    }
}
