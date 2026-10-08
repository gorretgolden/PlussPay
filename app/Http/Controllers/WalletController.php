<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;

class WalletController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->hasRole(['admin', 'superadmin'])) {
            $agents = User::role('agent')->where('is_active', true)->get();
            $allWallets = [];

            foreach ($agents as $agent) {
                $allWallets[$agent->id] = [
                    'user' => $agent,
                    'wallets' => Account::walletsFor($agent),
                ];
            }

            return view('wallets.index', compact('allWallets'));
        }

        $wallets = Account::walletsFor($user);

        return view('wallets.my', compact('wallets'));
    }
}
