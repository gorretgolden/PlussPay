<?php

namespace App\Http\Controllers;

use App\Services\SystemLogReader;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class SystemLogController extends Controller
{
    public function index(Request $request)
    {
        $meta = SystemLogReader::read($request->input('file'), SystemLogReader::window($request));
        $entries = SystemLogReader::filter($meta['entries'], $request);

        $perPage = 25;
        $page = max(1, (int) $request->input('page', 1));

        $logs = new LengthAwarePaginator(
            array_slice($entries, ($page - 1) * $perPage, $perPage),
            count($entries),
            $perPage,
            $page,
            ['path' => route('admin.system-logs'), 'query' => $request->query()]
        );

        return view('admin.system-logs', [
            'logs' => $logs,
            'counts' => SystemLogReader::countsByLevel($entries),
            'allLevels' => SystemLogReader::countsByLevel($meta['entries']),
            'files' => SystemLogReader::files(),
            'meta' => $meta,
            'totalRead' => count($meta['entries']),
        ]);
    }
}
