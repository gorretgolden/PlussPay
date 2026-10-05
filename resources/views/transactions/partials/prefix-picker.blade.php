@php
    $prefixes = [
        ['code' => '+256', 'name' => 'Uganda'],
        ['code' => '+86', 'name' => 'China'],
        ['code' => '+1', 'name' => 'United States'],
        ['code' => '+44', 'name' => 'United Kingdom'],
        ['code' => '+254', 'name' => 'Kenya'],
        ['code' => '+255', 'name' => 'Tanzania'],
        ['code' => '+250', 'name' => 'Rwanda'],
        ['code' => '+971', 'name' => 'United Arab Emirates'],
        ['code' => '+91', 'name' => 'India'],
        ['code' => '+27', 'name' => 'South Africa'],
        ['code' => '+81', 'name' => 'Japan'],
        ['code' => '+82', 'name' => 'South Korea'],
    ];
@endphp
<div class="relative prefix-picker">
    <button type="button" class="prefix-btn px-3 py-3 border border-r-0 border-gray-300 rounded-l-lg bg-gray-50 text-sm hover:bg-gray-100 focus:ring-2 focus:ring-green-500 focus:border-green-500 flex items-center gap-2">
        <span class="prefix-label whitespace-nowrap"></span>
        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <input type="hidden" id="{{ $who }}_prefix" value="{{ $default }}">
    <div class="prefix-panel absolute z-30 left-0 top-full mt-1 w-72 bg-white rounded-lg shadow-xl border border-gray-200 hidden text-left">
        <div class="p-2 border-b border-gray-100">
            <input type="text" class="prefix-search w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none" placeholder="Search country or code...">
        </div>
        <ul class="max-h-56 overflow-y-auto">
            @foreach($prefixes as $p)
            <li class="prefix-option px-3 py-2 text-sm hover:bg-green-50 cursor-pointer flex items-center gap-2 {{ $p['code'] === $default ? 'bg-green-50' : '' }}"
                data-value="{{ $p['code'] }}" data-label="{{ $p['name'] }} {{ $p['code'] }}" data-search="{{ strtolower($p['name'] . ' ' . $p['code'] . ' ' . ltrim($p['code'], '+')) }}">
                <span class="text-gray-700">{{ $p['name'] }}</span>
                <span class="ml-auto text-gray-500">{{ $p['code'] }}</span>
            </li>
            @endforeach
        </ul>
    </div>
</div>
