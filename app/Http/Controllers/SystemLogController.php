<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use App\Services\SystemLogReader;
use Illuminate\Http\Request;

class SystemLogController extends Controller
{
    /**
     * The queryable error table is the primary source: it survives log rotation and can be
     * filtered and marked resolved. The laravel.log reader stays underneath as a secondary
     * view for raw entries that predate the table or were never caught by the handler.
     */
    public function index(Request $request)
    {
        $logs = SystemLog::with('resolver')
            ->filtered($request)
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $counts = [
            'critical' => SystemLog::where('level', 'critical')->count(),
            'error' => SystemLog::where('level', 'error')->count(),
            'warning' => SystemLog::where('level', 'warning')->count(),
            'unresolved' => SystemLog::where('resolved', false)->count(),
        ];

        // A short tail of the live log file, so nothing that only reached the file is hidden.
        $meta = SystemLogReader::read(null, 2);
        $fileEntries = array_slice($meta['entries'], 0, 20);

        return view('admin.system-logs', compact('logs', 'counts', 'meta', 'fileEntries'));
    }

    public function resolve(Request $request, SystemLog $systemLog)
    {
        $validated = $request->validate([
            'note' => 'nullable|string|max:2000',
        ]);

        $systemLog->update([
            'resolved' => true,
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'note' => $validated['note'] ?? $systemLog->note,
        ]);

        return back()->with('success', 'Error marked resolved.');
    }
}
