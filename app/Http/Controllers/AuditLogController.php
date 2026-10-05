<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function activityLogs(Request $request)
    {
        $logs = ActivityLog::with(['user', 'transaction'])
            ->filtered($request)
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $actions = ActivityLog::distinct()->whereNotNull('action')->pluck('action')->sort()->values();
        $users = User::orderBy('name')->get();

        return view('admin.activity-logs', compact('logs', 'actions', 'users'));
    }

    public function loginLogs(Request $request)
    {
        $logs = ActivityLog::with('user')
            ->whereIn('action', ['login', 'logout'])
            ->filtered($request)
            ->latest()
            ->paginate(50)
            ->withQueryString();

        $users = User::orderBy('name')->get();

        return view('admin.login-logs', compact('logs', 'users'));
    }
}
