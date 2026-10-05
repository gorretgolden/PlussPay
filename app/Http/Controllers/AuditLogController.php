<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function activityLogs(Request $request)
    {
        $query = ActivityLog::with(['user', 'transaction']);

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $logs = $query->latest()->paginate(50);
        $actions = ActivityLog::distinct()->pluck('action');
        $users = \App\Models\User::orderBy('name')->get();

        return view('admin.activity-logs', compact('logs', 'actions', 'users'));
    }

    public function loginLogs(Request $request)
    {
        $query = DB::table('activity_logs')
            ->join('users', 'activity_logs.user_id', '=', 'users.id')
            ->whereIn('activity_logs.action', ['login', 'logout'])
            ->select('activity_logs.*', 'users.name as user_name', 'users.email')
            ->orderBy('activity_logs.created_at', 'desc');

        $logs = $query->paginate(50);

        return view('admin.login-logs', compact('logs'));
    }
}
