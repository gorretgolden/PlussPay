@props([
    'at' => null,
    'label' => 'Updated',
    'emptyText' => 'No movement yet',
])

{{-- Finance reads the clock as part of the figure: a balance with no age on it cannot say whether
     the last event was a minute or a month ago. Only the age goes on the face of the card, because
     a date and an hour beside it wrapped onto three lines in a narrow panel and pushed the two
     wallet cards out of step with each other. The exact stamp, which is what gets quoted in a
     message, is carried in the tooltip and in the datetime attribute instead.

     The words are printed by the server first, so a browser without JavaScript still gets an age
     that was true when the page was made. The script below only ever rewrites it to keep it true
     while the page sits open, which is the one thing a stamp like this must not be. --}}
@if($at)
<p {{ $attributes->merge(['class' => 'text-[13px] text-gray-700']) }}>
    <time datetime="{{ $at->toIso8601String() }}" data-stamp-since="{{ $at->toIso8601String() }}" data-stamp-label="{{ $label }}" title="{{ $at->format('M j, Y, g:i A') }}">{{ $label }} {{ $at->diffForHumans() }}</time>
</p>
@else
<p {{ $attributes->merge(['class' => 'text-[13px] text-gray-700']) }}>{{ $emptyText }}</p>
@endif

@once
<script>
    (function () {
        if (window.refreshStamps) { return; }

        window.refreshStamps = function () {
            document.querySelectorAll('[data-stamp-since]').forEach(function (node) {
                var since = new Date(node.getAttribute('data-stamp-since')).getTime();
                if (isNaN(since)) { return; }

                var seconds = Math.round((Date.now() - since) / 1000);
                if (seconds < 0) { seconds = 0; }

                var words = function (value, unit) {
                    return value + ' ' + unit + (value === 1 ? '' : 's') + ' ago';
                };

                var minutes = Math.round(seconds / 60);
                var hours = Math.round(seconds / 3600);
                var days = Math.round(seconds / 86400);
                var months = Math.round(seconds / 2592000);

                var phrase;
                if (seconds < 45) { phrase = words(Math.max(seconds, 1), 'second'); }
                else if (minutes < 60) { phrase = words(Math.max(minutes, 1), 'minute'); }
                else if (hours < 24) { phrase = words(hours, 'hour'); }
                else if (days < 31) { phrase = words(days, 'day'); }
                else if (months < 12) { phrase = words(months, 'month'); }
                else { phrase = words(Math.round(seconds / 31536000), 'year'); }

                var prefix = node.getAttribute('data-stamp-label');
                var next = (prefix ? prefix + ' ' : '') + phrase;
                if (node.textContent !== next) { node.textContent = next; }
            });
        };

        // A board that keeps its own clock redraws the ages on that tick, so the clock and the
        // balance can never disagree. Anywhere else the age keeps its own time.
        if (!document.getElementById('liveClock')) {
            window.refreshStamps();
            setInterval(window.refreshStamps, 10000);
        }
    })();
</script>
@endonce
