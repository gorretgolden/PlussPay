<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Errors - PlusPay</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body class="bg-white p-6 text-gray-900">
    <div class="max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-4">
            <div>
                <h1 class="text-xl font-bold">System Errors - PlusPay</h1>
                <p class="text-xs text-gray-600">Generated {{ now()->format('M d, Y h:i A') }} - {{ $logs->count() }} record(s)</p>
            </div>
            <button type="button" onclick="window.print()" class="no-print bg-green-800 text-white px-4 py-2 rounded-md text-sm">Save as PDF</button>
        </div>

        <table class="w-full text-sm border border-gray-300">
            <thead>
                <tr class="bg-gray-100 text-left">
                    <th class="border border-gray-300 px-2 py-1">Date</th>
                    <th class="border border-gray-300 px-2 py-1">Level</th>
                    <th class="border border-gray-300 px-2 py-1">Message</th>
                    <th class="border border-gray-300 px-2 py-1">Where</th>
                    <th class="border border-gray-300 px-2 py-1">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                <tr>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $log->created_at->format('Y-m-d h:i A') }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ strtoupper($log->level) }}</td>
                    <td class="border border-gray-300 px-2 py-1 break-words">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $log->message), 200) }}</td>
                    <td class="border border-gray-300 px-2 py-1 break-all">{{ $log->file ? basename(str_replace('\\', '/', $log->file)) . ($log->line ? ':' . $log->line : '') : '-' }}</td>
                    <td class="border border-gray-300 px-2 py-1 whitespace-nowrap">{{ $log->resolved ? 'Resolved' : 'Open' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="border border-gray-300 px-2 py-2 text-center text-gray-500">No system errors match these filters.</td>
                </tr>
                @endforelse
            </tbody>
        </table>

        <p class="text-xs text-gray-500 mt-4">Total records: {{ $logs->count() }}</p>
    </div>

    <script>window.print();</script>
</body>
</html>
