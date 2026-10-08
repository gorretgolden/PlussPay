@php $changes = $log->changes(); @endphp

<dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 pb-5 border-b border-gray-200">
    <div>
        <dt class="text-xs uppercase tracking-wide text-gray-500">Action</dt>
        <dd class="text-gray-900">{{ ucwords(str_replace('_', ' ', $log->action)) }}</dd>
    </div>
    <div>
        <dt class="text-xs uppercase tracking-wide text-gray-500">Performed by</dt>
        <dd class="text-gray-900">
            {{ $log->user?->name ?? 'System' }}
            @if($log->user)<span class="text-gray-500">({{ $log->user->email }})</span>@endif
        </dd>
    </div>
    <div>
        <dt class="text-xs uppercase tracking-wide text-gray-500">When</dt>
        <dd class="text-gray-900">{{ $log->created_at->format('M d, Y h:i A') }}</dd>
    </div>
    <div>
        <dt class="text-xs uppercase tracking-wide text-gray-500">Recorded from</dt>
        <dd class="text-gray-900">
            {{ $log->ip_address ?? 'not recorded' }}
            @if($log->request_path)<span class="text-gray-500">via {{ $log->request_path }}</span>@endif
        </dd>
    </div>
    @if($log->transaction)
    <div class="sm:col-span-2">
        <dt class="text-xs uppercase tracking-wide text-gray-500">Transaction</dt>
        <dd>
            <a href="{{ route('transactions.show', $log->transaction) }}" class="text-green-700 hover:underline">
                {{ $log->transaction->receipt_number }}
            </a>
        </dd>
    </div>
    @endif
</dl>

@if($log->description)
<p class="pt-4 text-gray-700">{{ $log->description }}</p>
@endif

<div class="pt-5">
    @if($changes['kind'] === 'none')
        <p class="text-gray-500">No before and after values were recorded for this entry.</p>

    @elseif($changes['kind'] === 'scalar')
        <h4 class="text-xs uppercase tracking-wide text-gray-500 mb-2">Before and after</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="bg-gray-50 rounded-lg p-3">
                <span class="block text-xs text-gray-500 mb-1">Was</span>
                <span class="text-gray-900 break-words">{{ $changes['old'] }}</span>
            </div>
            <div class="bg-green-50 rounded-lg p-3">
                <span class="block text-xs text-gray-500 mb-1">Now</span>
                <span class="text-gray-900 break-words">{{ $changes['new'] }}</span>
            </div>
        </div>

    @elseif($changes['kind'] === 'fields')
        <h4 class="text-xs uppercase tracking-wide text-gray-500 mb-2">Every field on the record</h4>
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-200">
                    <th class="py-2 font-medium">Field</th>
                    <th class="py-2 font-medium">Was</th>
                    <th class="py-2 font-medium">Now</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($changes['rows'] as $row)
                <tr class="{{ $row['changed'] ? '' : 'text-gray-400' }}">
                    <td class="py-2 pr-3">{{ $row['label'] }}</td>
                    <td class="py-2 pr-3 break-words">{{ $row['old'] }}</td>
                    <td class="py-2 break-words font-medium {{ $row['changed'] ? 'text-green-800' : '' }}">{{ $row['new'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-xs text-gray-500 pt-2">Greyed rows were left as they were.</p>

    @else
        <h4 class="text-xs uppercase tracking-wide text-gray-500 mb-3">What was added and removed</h4>

        @if(count($changes['added']) > 0)
        <div class="mb-4">
            <p class="text-xs font-medium text-green-800 mb-1">Added ({{ count($changes['added']) }})</p>
            <div class="flex flex-wrap gap-1">
                @foreach($changes['added'] as $item)
                <span class="bg-green-100 text-green-800 px-2 py-0.5 rounded text-xs">{{ $item }}</span>
                @endforeach
            </div>
        </div>
        @endif

        @if(count($changes['removed']) > 0)
        <div class="mb-4">
            <p class="text-xs font-medium text-red-800 mb-1">Removed ({{ count($changes['removed']) }})</p>
            <div class="flex flex-wrap gap-1">
                @foreach($changes['removed'] as $item)
                <span class="bg-red-100 text-red-800 px-2 py-0.5 rounded text-xs">{{ $item }}</span>
                @endforeach
            </div>
        </div>
        @endif

        @if(count($changes['kept']) > 0)
        <p class="text-xs text-gray-500">
            Unchanged ({{ count($changes['kept']) }}): {{ implode(', ', $changes['kept']) }}
        </p>
        @endif
    @endif
</div>
