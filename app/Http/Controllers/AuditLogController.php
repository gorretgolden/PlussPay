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

        // Ten rows is the reading default; a desk tracing a month of trails can widen the page
        // or take every record at once. Anything else typed in the address bar falls back to ten.
        $choice = $request->input('per_page', '10');
        $perPage = match (true) {
            $choice === '50' => 50,
            $choice === '100' => 100,
            $choice === 'all' => null,
            default => 10,
        };

        if ($tab === 'login') {
            $query = LoginLog::with('user')->filtered($request)->latest();
            $logs = $query->paginate($perPage ?? max((clone $query)->count(), 1))
                ->withQueryString();

            $suspicious = LoginLog::suspiciousSince();
        } else {
            $query = ActivityLog::with(['user', 'transaction'])->filtered($request)->latest();
            $logs = $query->paginate($perPage ?? max((clone $query)->count(), 1))
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
