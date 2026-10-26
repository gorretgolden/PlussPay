{{-- How many bookings the desk has taken today. Green, never the news yellow: this is the
     day's traffic, not something left unread. The title names what the number is, so the
     badge is never just a mystery digit. --}}
@if(($todayTransactions ?? 0) > 0)
<span title="{{ number_format($todayTransactions) }} {{ $todayTransactions === 1 ? 'booking' : 'bookings' }} today" class="ml-2 bg-green-400 text-green-950 text-xs font-semibold rounded-full px-2 py-0.5 tabular-nums">{{ number_format($todayTransactions) }}</span>
@endif
