<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function activityLogs(Request $request)
    {
        $logs = ActivityLog::with(['user', 'transaction'])
            ->filtered($request)
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $actions = ActivityLog::distinct()->whereNotNull('action')->pluck('action')->sort()->values();
        $users = User::orderBy('name')->get();

        return view('admin.activity-logs', compact('logs', 'actions', 'users'));
    }

    public function details(ActivityLog $activityLog)
    {
        $activityLog->load(['user', 'transaction']);

        return view('admin.partials._activity-details', ['log' => $activityLog]);
    }

    /**
     * The dedicated sign-in trail: every outcome, with the machine it came from and a
     * warning when one address or account is being tried over and over in a short window.
     */
    public function loginLogs(Request $request)
    {
        $logs = LoginLog::with('user')
            ->filtered($request)
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $users = User::orderBy('name')->get();
        $suspicious = LoginLog::suspiciousSince();

        return view('admin.login-logs', compact('logs', 'users', 'suspicious'));
    }
}
