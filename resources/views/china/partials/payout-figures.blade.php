{{-- A set of payout totals, one line per currency. A desk that owes both yuan and dollars sees both
     figures; one that owes only yuan is never shown a zero it has to work out the meaning of. --}}
@forelse($payouts as $currency => $figure)
<p class="text-xl font-semibold text-gray-900 tabular-nums">{{ \App\Models\Account::format($currency, $figure) }}</p>
@empty
<p class="text-xl font-semibold text-gray-400">Nothing</p>
@endforelse
