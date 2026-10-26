@extends('layouts.app')

@section('title', 'Settings')
@section('inline-validation', true)

@section('content')
@php
    $tabs = ['rates' => 'Rates', 'transactions' => 'Transactions', 'company' => 'Company', 'datetime' => 'Date & Time'];
    if (auth()->user()->hasRole('superadmin')) $tabs['site'] = 'Site Mode';
    $activeTab = old('settings_tab', session('settings_saved_tab', 'rates'));
    if (!isset($tabs[$activeTab])) $activeTab = 'rates';
    $trimRate = fn ($value) => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    $pairs = [
        'ugx' => [
            'unit' => 'UGX', 'pair' => $usdUgxPair, 'row' => $usdUgxRow, 'min' => '0.01', 'max' => '999999.99',
            'helper' => 'Dollars to shillings.',
            'rule' => 'Selling at or above buying keeps a spread.',
            'flat' => 'No spread: both prices are the same, so this leg earns nothing.',
            'warn' => 'Selling is below buying, so this leg loses money.',
            'more' => 'Buying: shillings paid for a dollar. Selling: shillings charged for one. Margin = selling - buying: positive means bought cheap, sold dear.',
        ],
        'rmb' => [
            'unit' => 'RMB', 'pair' => $usdRmbPair, 'row' => $usdRmbRow, 'min' => '0.000001', 'max' => '999999.999999',
            'helper' => 'Dollars to yuan for China payouts.',
            'rule' => 'Buying at or above selling keeps a spread.',
            'flat' => 'No spread: both prices are the same, so this leg earns nothing.',
            'warn' => 'Buying is below selling, so this leg loses money.',
            'more' => 'Selling: yuan credited to the receiver. Buying: what the yuan cost on the China side. Margin = buying - selling: positive means the payout was priced below the settlement.',
        ],
    ];
    $bounds = \App\Models\Setting::transactionBounds();
    $fields = [
        'transactions' => [
            'min_transaction_ugx' => ['label' => 'Minimum shillings', 'default' => (string) $bounds['UGX']['min'], 'unit' => 'UGX', 'min' => '0', 'helper' => 'Smallest shilling booking taken.'],
            'max_transaction_ugx' => ['label' => 'Maximum shillings', 'default' => (string) $bounds['UGX']['max'], 'unit' => 'UGX', 'min' => '0', 'helper' => 'Largest shilling booking taken.'],
            'min_transaction_usd' => ['label' => 'Minimum dollars', 'default' => (string) $bounds['USD']['min'], 'unit' => 'USD', 'min' => '0', 'helper' => 'Smallest dollar booking taken.'],
            'max_transaction_usd' => ['label' => 'Maximum dollars', 'default' => (string) $bounds['USD']['max'], 'unit' => 'USD', 'min' => '0', 'helper' => 'Largest dollar booking taken.'],
            'min_transaction_rmb' => ['label' => 'Minimum yuan', 'default' => (string) $bounds['RMB']['min'], 'unit' => 'RMB', 'min' => '0', 'helper' => 'Smallest yuan booking taken.'],
            'max_transaction_rmb' => ['label' => 'Maximum yuan', 'default' => (string) $bounds['RMB']['max'], 'unit' => 'RMB', 'min' => '0', 'helper' => 'Largest yuan booking taken.'],
            'receipt_prefix' => ['label' => 'Receipt prefix', 'default' => 'PS', 'maxlength' => 10, 'pattern' => '[A-Za-z0-9]+', 'helper' => 'Letters and numbers only.'],
            'default_charge_ugx' => ['label' => 'Default charge', 'default' => '0', 'unit' => 'UGX', 'min' => '0', 'helper' => 'Opens the charge box on a new booking.'],
        ],
        'company' => [
            'company_name' => ['label' => 'Company name', 'default' => 'Plus Save Financial Solutions', 'maxlength' => 100],
            'company_email' => ['label' => 'Email', 'type' => 'email', 'maxlength' => 100],
            'company_phone' => ['label' => 'Call / WhatsApp', 'type' => 'tel', 'maxlength' => 60],
            'company_address' => ['label' => 'Address', 'type' => 'textarea', 'maxlength' => 255, 'helper' => 'Each line prints separately on receipts.'],
            'company_website' => ['label' => 'Website', 'maxlength' => 100],
        ],
        'datetime' => [
            'timezone' => ['label' => 'Time zone', 'type' => 'select', 'default' => \App\Models\Setting::timezone(), 'options' => \App\Models\Setting::TIMEZONES, 'helper' => 'Sets clocks and report day boundaries.'],
        ],
        'site' => [
            'site_mode' => ['label' => 'Mode', 'type' => 'select', 'default' => 'testing', 'options' => ['production' => 'Production', 'testing' => 'Testing'], 'helper' => 'Only superadmins can change this.'],
            'demo_login_autofill' => ['label' => 'Demo button autofill', 'type' => 'select', 'default' => 'off', 'options' => ['off' => 'Off', 'on' => 'On'], 'helper' => 'On: each demo button fills the password as well as the email, so one click signs a tester straight in. The password then travels to every browser that opens the sign-in page, so anyone who looks at the page source can read it. Turn this off before real customers arrive.'],
            'demo_password' => ['label' => 'Demo password', 'default' => '', 'maxlength' => 60, 'helper' => 'One password for every demo account. Saving it resets those sign-ins and nothing else.'],
        ],
    ];
@endphp
<div id="settings-page" class="mx-auto max-w-5xl pb-10" data-active-tab="{{ $activeTab }}">
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
        <p class="mt-1 text-sm text-gray-600">Manage one section at a time.</p>
    </header>

    @if (!$testingModeBlocked && \App\Models\Setting::get('site_mode', 'testing') === 'testing')
        <div role="status" class="mb-5 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            <strong>Testing</strong><span class="ml-2">Demo logins are visible on the sign-in page</span>
        </div>
    @endif

    <div role="tablist" aria-label="Settings sections" class="settings-tabs flex gap-1 overflow-x-auto border-b border-gray-200 mb-5">
        @foreach ($tabs as $tab => $label)
            <button type="button" role="tab" id="tab-{{ $tab }}" aria-controls="panel-{{ $tab }}" aria-selected="{{ $activeTab === $tab ? 'true' : 'false' }}" tabindex="{{ $activeTab === $tab ? '0' : '-1' }}" data-settings-tab="{{ $tab }}" class="shrink-0 px-4 py-3 text-sm font-medium border-b-2 border-transparent text-gray-600 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-700">{{ $label }}</button>
        @endforeach
    </div>

    @foreach ($tabs as $tab => $label)
        <section role="tabpanel" id="panel-{{ $tab }}" aria-labelledby="tab-{{ $tab }}" tabindex="0" @if ($activeTab !== $tab) hidden @endif>
            @if ($tab === 'rates')
                @include('settings.partials.market-rate', ['market' => $market])
            @endif
            <form id="settings-form-{{ $tab }}" method="POST" action="{{ route('settings.update') }}" data-validate data-lock data-settings-form="{{ $tab }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="settings_tab" value="{{ $tab }}">
                <div class="bg-white rounded-lg border border-gray-200 p-5 sm:p-6">
                    <h2 class="mb-5 text-lg font-semibold text-gray-900">{{ $label }}</h2>

                    @if ($tab === 'rates')
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            @foreach ($pairs as $currency => $rate)
                                @php
                                    $pair = $rate['pair'];
                                    $margin = $pair ? ($currency === 'ugx' ? $pair['sell'] - $pair['buy'] : $pair['buy'] - $pair['sell']) : null;
                                    // Three states, because a zero spread is a choice she makes on
                                    // purpose and a reversed one is a mistake.
                                    $spread = $margin === null || $margin > 0 ? 'up' : ($margin == 0 ? 'flat' : 'down');
                                    $marketRate = $market['pairs'][$currency === 'ugx' ? 'UGX' : 'RMB'];
                                @endphp
                                <fieldset class="min-w-0 rounded-lg border border-gray-200 p-4" data-rate-pair="{{ $currency }}" data-margin-warn="{{ $rate['warn'] }}" data-margin-flat="{{ $rate['flat'] }}">
                                    <legend class="px-1 text-base font-semibold text-gray-900">USD → {{ $rate['unit'] }}</legend>
                                    <p class="mb-4 text-xs text-gray-500">{{ $rate['helper'] }} @if ($marketRate['latest']) Market mid <span class="tabular-nums">{{ $marketRate['display'] }}</span> {{ $rate['unit'] }}/USD.@endif</p>
                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        @foreach (['buying' => 'Buying rate', 'selling' => 'Selling rate'] as $side => $rateLabel)
                                            @php
                                                $key = 'usd_'.$currency.'_'.$side;
                                                $saved = $trimRate($pair[$side === 'buying' ? 'buy' : 'sell'] ?? null);
                                            @endphp
                                            <div class="min-w-0">
                                                <label for="{{ $key }}" class="block text-sm font-medium text-gray-700 mb-1">{{ $rateLabel }} <span class="text-gray-500">({{ $rate['unit'] }}/USD)</span></label>
                                                <div class="flex items-center rounded-md border border-gray-300 focus-within:ring-2 focus-within:ring-green-600">
                                                    <input id="{{ $key }}" name="{{ $key }}" type="text" inputmode="decimal" pattern="(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.[0-9]+)?" data-validation-message="Numbers only, with commas between thousands." data-money data-min="{{ $rate['min'] }}" data-max="{{ $rate['max'] }}" data-original="{{ $saved }}" value="{{ old($key, $saved) }}" aria-describedby="error-{{ $key }}" @if ($errors->has($key)) aria-invalid="true" @endif class="min-w-0 w-full bg-transparent px-3 py-2 outline-none tabular-nums">
                                                </div>
                                                <p id="error-{{ $key }}" data-field-error="{{ $key }}" class="mt-1 text-xs text-red-700">@error($key){{ $message }}@enderror</p>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="mt-4 flex items-center gap-2">
                                        <p data-margin class="min-w-0 flex-1 rounded-md bg-gray-50 px-3 py-2 text-sm font-medium tabular-nums {{ ['up' => 'text-green-800', 'flat' => 'text-amber-700', 'down' => 'text-red-700'][$spread] }}" aria-live="polite">Margin: {{ $margin === null ? 'Enter a selling rate' : ($margin > 0 ? '+' : '').$trimRate($margin).' '.$rate['unit'].'/USD' }}</p>
                                        <details class="relative shrink-0">
                                            <summary aria-label="How the {{ $rate['unit'] }} margin is worked out" class="list-none h-5 w-5 cursor-pointer rounded-full border border-gray-400 text-center text-xs leading-4 text-gray-600 hover:border-green-700 hover:text-green-800 focus-visible:outline focus-visible:outline-2 focus-visible:outline-green-700">?</summary>
                                            <p class="absolute right-0 top-7 z-20 w-64 rounded-lg border border-gray-200 bg-white p-3 text-xs text-gray-700 shadow-lg">{{ $rate['more'] }}</p>
                                        </details>
                                    </div>
                                    <p data-margin-rule data-ok="{{ $rate['rule'] }}" class="mt-1 text-xs {{ ['up' => 'text-gray-500', 'flat' => 'text-amber-700 font-medium', 'down' => 'text-red-700 font-medium'][$spread] }}">{{ ['up' => $rate['rule'], 'flat' => $rate['flat'], 'down' => $rate['warn']][$spread] }}</p>
                                    <p class="mt-3 text-xs text-gray-500">
                                        @if ($rate['row'])
                                            Updated <time datetime="{{ $rate['row']->updated_at->toIso8601String() }}" class="tabular-nums">{{ $rate['row']->updated_at->format('M d, Y h:i A') }}</time> by {{ $rate['row']->editor?->name ?? $rate['row']->creator?->name ?? 'nobody' }}.
                                        @else
                                            No price is in use for USD to {{ $rate['unit'] }}.
                                        @endif
                                        <a href="{{ route('exchange-rates.index') }}" class="font-medium text-green-800 underline underline-offset-4">Rate history</a>
                                    </p>
                                </fieldset>
                            @endforeach
                        </div>
                        <details class="mt-5 text-sm text-gray-600">
                            <summary class="cursor-pointer font-medium text-green-800">Learn more about rates</summary>
                            <div class="mt-3 space-y-2">
                                <p>Leave a box blank to keep the saved price on that side.</p>
                                <p>Changes over 5% need review but are not blocked. Previous rates stay in the history.</p>
                            </div>
                        </details>
                    @else
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            @foreach ($fields[$tab] as $key => $field)
                                @php
                                    $saved = \App\Models\Setting::get($key, $field['default'] ?? '');
                                    $value = old($key, $saved);
                                    $type = $field['type'] ?? 'text';
                                @endphp
                                <div>
                                    <label for="{{ $key }}" class="block text-sm font-medium text-gray-700 mb-1">{{ $field['label'] }}</label>
                                    <div class="flex items-center rounded-md border border-gray-300 bg-white focus-within:ring-2 focus-within:ring-green-600">
                                        @if ($type === 'select')
                                            <select id="{{ $key }}" name="{{ $key }}" data-original="{{ $saved }}" data-label="{{ $field['label'] }}" aria-describedby="help-{{ $key }} error-{{ $key }}" @if ($key === 'site_mode') required @endif class="w-full min-w-0 px-3 py-2 rounded-md bg-white outline-none">
                                                @foreach ($field['options'] as $option => $description)
                                                    <option value="{{ $option }}" @selected($value === $option) @disabled($key === 'site_mode' && $option === 'testing' && $testingModeBlocked)>{{ $description }}</option>
                                                @endforeach
                                            </select>
                                        @elseif ($type === 'textarea')
                                            <textarea id="{{ $key }}" name="{{ $key }}" rows="2" maxlength="{{ $field['maxlength'] }}" data-original="{{ $saved }}" data-label="{{ $field['label'] }}" aria-describedby="help-{{ $key }} error-{{ $key }}" class="w-full min-w-0 px-3 py-2 rounded-md outline-none">{{ $value }}</textarea>
                                        @else
                                            <input id="{{ $key }}" name="{{ $key }}" type="{{ $type }}" value="{{ $value }}" data-original="{{ $saved }}" data-label="{{ $field['label'] }}" aria-describedby="help-{{ $key }} error-{{ $key }}{{ isset($field['unit']) ? ' unit-'.$key : '' }}"
                                                @isset($field['maxlength']) maxlength="{{ $field['maxlength'] }}" @endisset
                                                @isset($field['pattern']) pattern="{{ $field['pattern'] }}" @endisset
                                                @isset($field['unit']) inputmode="decimal" data-money data-min="{{ $field['min'] }}" pattern="(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.[0-9]+)?" @endisset
                                                class="min-w-0 w-full bg-transparent px-3 py-2 rounded-md outline-none tabular-nums">
                                            @isset($field['unit'])<span id="unit-{{ $key }}" class="pr-3 text-xs text-gray-500 shrink-0">{{ $field['unit'] }}</span>@endisset
                                        @endif
                                    </div>
                                    <p id="help-{{ $key }}" class="mt-1 text-xs text-gray-500">{{ $field['helper'] ?? '' }}</p>
                                    <p id="error-{{ $key }}" data-field-error="{{ $key }}" class="mt-1 text-xs text-red-700">@error($key){{ $message }}@enderror</p>
                                </div>
                            @endforeach
                            @if ($tab === 'transactions')
                                <div class="self-end rounded-md bg-gray-50 px-4 py-3">
                                    <p class="text-xs text-gray-500 mb-1">Receipt preview</p>
                                    <output id="receipt-preview" for="receipt_prefix" data-date="{{ now()->format('Ymd') }}" class="text-sm font-medium tabular-nums" aria-live="polite">{{ old('receipt_prefix', \App\Models\Setting::get('receipt_prefix', 'PS')) ?: 'PS' }}-{{ now()->format('Ymd') }}-0001</output>
                                </div>
                            @elseif ($tab === 'datetime')
                                <div class="self-end rounded-md bg-gray-50 px-4 py-3 text-sm text-gray-600">
                                    <p class="text-xs mb-1">Current system time</p>
                                    <p class="font-medium tabular-nums">{{ now()->format('D d M Y, g:i A') }}</p>
                                </div>
                            @endif
                        </div>
                        @if ($tab === 'company')
                            <p class="mt-5 text-xs text-gray-500">Company details appear on receipts.</p>
                        @elseif ($tab === 'datetime')
                            <details class="mt-5 text-sm text-gray-600">
                                <summary class="cursor-pointer font-medium text-green-800">Learn more about time zones</summary>
                                <p class="mt-3">Changing the zone affects screen dates, new receipts and report day boundaries. Existing timestamps are not rewritten. Review older records when comparing reports across zones.</p>
                            </details>
                        @elseif ($tab === 'site')
                            @if ($testingModeBlocked)
                                <p role="status" class="mt-5 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">Testing is unavailable on production.</p>
                            @endif
                            <div id="testing-mode-warning" role="status" @if ($value !== 'testing' || $testingModeBlocked) hidden @endif class="mt-5 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
                                <strong>Testing</strong><span class="ml-2">Demo logins are visible on the sign-in page</span>
                            </div>
                            <details class="mt-5 text-sm text-gray-600">
                                <summary class="cursor-pointer font-medium text-green-800">Learn more about site mode</summary>
                                <p class="mt-3">Testing exposes demo sign-in shortcuts. Production hides them. Testing is blocked in the production environment or on the configured non-local canonical host. This setting does not change server configuration.</p>
                            </details>
                        @endif
                    @endif
                </div>
                <div class="sticky bottom-0 z-10 mt-5 flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-5 py-3 shadow-sm">
                    <p data-save-status role="status" class="text-sm text-gray-500">{{ session('settings_saved_tab') === $tab ? session('settings_saved_text', 'Settings saved.') : 'No unsaved changes' }}</p>
                    <button type="submit" data-save disabled class="shrink-0 rounded-md bg-green-800 px-5 py-2 text-sm font-medium text-white hover:bg-green-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700 disabled:opacity-50 disabled:cursor-not-allowed">{{ $tab === 'rates' ? 'Save rates' : 'Save changes' }}</button>
                </div>
            </form>
        </section>
    @endforeach
</div>
@endsection

@push('styles')
<style>
    #settings-page [hidden] { display: none !important; }
    #settings-page [role="tab"][aria-selected="true"] { color: #166534; border-bottom-color: #166534; }
</style>
@endpush

@push('scripts')
<script src="{{ asset('js/settings.js') }}" defer></script>
@endpush
