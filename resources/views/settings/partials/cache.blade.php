@php
    $size = $cache['views_bytes'] >= 1048576
        ? number_format($cache['views_bytes'] / 1048576, 1).' MB'
        : number_format($cache['views_bytes'] / 1024, 1).' KB';
@endphp
<div class="bg-white rounded-lg border border-gray-200 p-5 sm:p-6">
    <h2 class="text-lg font-semibold text-gray-900">Cache</h2>
    <p class="mt-1 text-sm text-gray-600">Settings and compiled pages are kept ready so screens open fast. Saving a setting refreshes its cache by itself.</p>

    @error('cache')
        <p role="alert" class="mt-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>
    @enderror

    <div class="mt-5 grid gap-4 md:grid-cols-2">
        <section class="rounded-lg border border-gray-200 p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-medium text-gray-900">Settings</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ $cache['settings'] }} values kept in one copy instead of a database read per value.</p>
                </div>
                @if ($cache['settings_cached'])
                    <span class="shrink-0 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-800">Cached</span>
                @else
                    <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">Not cached</span>
                @endif
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <form method="POST" action="{{ route('settings.cache') }}">
                    @csrf
                    <input type="hidden" name="target" value="settings">
                    <input type="hidden" name="action" value="warm">
                    <button type="submit" class="rounded-md bg-green-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700">Rebuild settings</button>
                </form>
                <form method="POST" action="{{ route('settings.cache') }}">
                    @csrf
                    <input type="hidden" name="target" value="settings">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-red-300 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700">Clear settings</button>
                </form>
            </div>
        </section>

        <section class="rounded-lg border border-gray-200 p-4">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="font-medium text-gray-900">Page templates</h3>
                    <p class="mt-1 text-xs text-gray-500">{{ $cache['views'] }} compiled files, {{ $size }} on disk.</p>
                </div>
                @if ($cache['views'] > 0)
                    <span class="shrink-0 rounded-full bg-green-50 px-2.5 py-1 text-xs font-medium text-green-800">Compiled</span>
                @else
                    <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">Not compiled</span>
                @endif
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <form method="POST" action="{{ route('settings.cache') }}">
                    @csrf
                    <input type="hidden" name="target" value="views">
                    <input type="hidden" name="action" value="warm">
                    <button type="submit" class="rounded-md bg-green-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700">Compile pages</button>
                </form>
                <form method="POST" action="{{ route('settings.cache') }}">
                    @csrf
                    <input type="hidden" name="target" value="views">
                    <input type="hidden" name="action" value="clear">
                    <button type="submit" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:border-red-300 hover:text-red-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700">Clear pages</button>
                </form>
            </div>
        </section>
    </div>

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-md bg-gray-50 px-4 py-3">
        <p class="text-xs text-gray-500">Cache store <span class="font-medium text-gray-700">{{ $cache['driver'] }}</span></p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('settings.cache') }}">
                @csrf
                <input type="hidden" name="target" value="all">
                <input type="hidden" name="action" value="warm">
                <button type="submit" class="rounded-md bg-green-800 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700">Rebuild everything</button>
            </form>
            <form method="POST" action="{{ route('settings.cache') }}">
                @csrf
                <input type="hidden" name="target" value="all">
                <input type="hidden" name="action" value="clear">
                <button type="submit" class="rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">Clear everything</button>
            </form>
        </div>
    </div>
</div>
