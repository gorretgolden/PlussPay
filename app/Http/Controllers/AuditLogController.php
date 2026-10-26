<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * One audit page with two tabs: the activity trail and the sign-in trail. The old
     * /admin/activity-logs and /admin/login-logs URLs redirect here with a tab query.
     */
    public function auditLogs(Request $request)
    {
        $user = $request->user();
        $canActivity = $user->can('view-activity-logs');
        $canLogin = $user->can('view-login-logs');

        if (! $canActivity && ! $canLogin) {
            abort(403);
        }

        $tab = $request->input('tab');
        if ($tab !== 'login' && $tab !== 'activity') {
            $tab = $canActivity ? 'activity' : 'login';
        } elseif ($tab === 'login' && ! $canLogin) {
            $tab = 'activity';
        } elseif ($tab === 'activity' && ! $canActivity) {
            $tab = 'login';
        }

        $users = User::orderBy('name')->get();
        $actions = collect();
        $suspicious = ['ips' => collect(), 'emails' => collect()];

        if ($tab === 'login') {
            $logs = LoginLog::with('user')
                ->filtered($request)
                ->latest()
                ->paginate(10)
                ->withQueryString();

            $suspicious = LoginLog::suspiciousSince();
        } else {
            $logs = ActivityLog::with(['user', 'transaction'])
                ->filtered($request)
                ->latest()
                ->paginate(10)
                ->withQueryString();

            $actions = ActivityLog::distinct()->whereNotNull('action')->pluck('action')->sort()->values();
        }

        return view('admin.audit-logs', compact('logs', 'tab', 'actions', 'users', 'suspicious'));
    }

    public function details(ActivityLog $activityLog)
    {
        $activityLog->load(['user', 'transaction']);

        return view('admin.partials._activity-details', ['log' => $activityLog]);
    }
}
