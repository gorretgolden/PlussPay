@php
    $companyName = \App\Models\Setting::get('company_name', 'Plus Save Financial Solutions');
    $companyPhone = \App\Models\Setting::get('company_phone');
    $companyEmail = \App\Models\Setting::get('company_email');
    $companyAddress = \App\Models\Setting::get('company_address');
    $companyWebsite = \App\Models\Setting::get('company_website');
    $companyLogo = is_file(public_path('plussave-logo.png'));

    // The slip is signed by the person who wrote the booking, not by whoever opens the receipt.
    $issuedBy = $transaction->creator?->name ?: 'The office';
    $destination = $transaction->destinationLabel();
    $toUganda = $transaction->isToUganda();
    $intake = strtoupper((string) $transaction->currency);
    $payoutCurrency = $transaction->payoutCurrency();

    $usdRate = $transaction->shillingRateCell();
    $rmbRate = $transaction->yuanRateCell();
    // Yuan is only a price this customer paid when the route turns money through it. A dollar
    // payout to China never reads a yuan price, so the column must not invent one.
    $touchesYuan = $toUganda ? $intake === 'RMB' : $payoutCurrency === 'RMB';

    $rateLines = [];
    if ($usdRate['text'] !== 'n/a') {
        $rateLines[] = 'UGX '.$usdRate['text'];
    }
    if ($touchesYuan && $rmbRate['text'] !== 'n/a') {
        $rateLines[] = 'RMB '.$rmbRate['text'];
    }

    $payoutFigure = (float) $transaction->payoutAmount();
    $payoutText = match (true) {
        $payoutFigure <= 0 => 'Not recorded',
        $payoutCurrency === 'UGX' => 'UGX '.number_format((float) $transaction->ugx_amount),
        $payoutCurrency === 'USD' => '$'.number_format((float) $transaction->total_usd_equivalent, 1).' USD',
        default => '¥'.number_format((float) $transaction->rmb_amount, 1).' RMB',
    };
    $amountHeading = $intake === $payoutCurrency
        ? 'AMOUNT ('.$payoutCurrency.')'
        : 'AMOUNT ('.$intake.'/'.$payoutCurrency.')';

    // The charge sits inside the shillings handed over on the way to China, beside the dollars on a
    // dollar booking, and comes out of the shillings paid over on the way into Uganda. The slip says
    // which of the three it is, because the same column would otherwise read three ways.
    $chargeNote = match (true) {
        $toUganda => 'The charge is taken out of the shillings the receiver is paid.',
        $intake === 'UGX' => 'The charge is included in the total cash handed over.',
        default => 'The charge is paid alongside the cash handed over.',
    };

    $addressLines = $companyAddress ? preg_split('/\r\n|\r|\n/', $companyAddress) : [];
@endphp

<article class="slip" aria-label="Receipt {{ $transaction->receipt_number }}">
    <header class="slip-head">
        <img class="slip-pluspay" src="{{ asset('logo.webp') }}" alt="PlusPay" width="640" height="232">
        <div class="slip-ident">
            <span class="slip-pill">Receipt</span>
            <div class="slip-ident-line">
                <span class="slip-ident-label">No.</span>
                <span class="slip-ident-value">{{ $transaction->receipt_number }}</span>
            </div>
            <div class="slip-ident-line">
                <span class="slip-ident-label">Date</span>
                <span class="slip-ident-value">{{ $transaction->created_at->format('d/m/Y g:i A') }}</span>
            </div>
        </div>
        @if($companyLogo)
            <img class="slip-company" src="{{ asset('plussave-logo.png') }}" alt="{{ $companyName }}" width="447" height="447">
        @endif
    </header>

    @if($transaction->status !== 'completed')
        <p class="slip-status slip-status--{{ $transaction->status }}">
            @if($transaction->status === 'pending')
                Pending. The money is not released until this transfer is approved.
            @else
                Cancelled. Do not pay out against this receipt.
            @endif
        </p>
    @endif

    <h1 class="slip-title">Send money to {{ $destination }}</h1>

    <div class="slip-parties">
        <div class="slip-party">
            <span class="slip-label">Sender's Name</span>
            <span class="slip-name">{{ $transaction->sender_name }}</span>
            @if($transaction->sender_contact)
                <span class="slip-contact">Contact {{ $transaction->sender_contact }}</span>
            @endif
        </div>
        <div class="slip-party">
            <span class="slip-label">Receiver's Name</span>
            <span class="slip-name">{{ $transaction->receiver_name }}</span>
            @if($transaction->receiver_contact)
                <span class="slip-contact">Contact {{ $transaction->receiver_contact }}</span>
            @endif
        </div>
    </div>

    <div class="slip-meta">
        <span class="slip-meta-item">Money taken by <span class="slip-meta-value">{{ $issuedBy }}</span> at the <span class="slip-meta-value">{{ $transaction->deskLabel() }}</span> desk</span>
        @if($transaction->purpose)
            <span class="slip-meta-item">Reason <span class="slip-meta-value">{{ $transaction->purpose }}</span></span>
        @endif
        @if($transaction->payment_method)
            <span class="slip-meta-item">Paid by <span class="slip-meta-value">{{ $transaction->payment_method }}</span></span>
        @endif
        @if($transaction->payment_reference)
            <span class="slip-meta-item">Confirmation <span class="slip-meta-value">{{ $transaction->payment_reference }}</span></span>
        @endif
    </div>

    <div class="slip-well">
        <span class="slip-watermark" aria-hidden="true">{{ $companyName }}</span>
        <table class="slip-table">
            <thead>
                <tr>
                    <th class="slip-col-particulars" scope="col">PARTICULARS</th>
                    <th class="slip-col-rate" scope="col">RATE</th>
                    <th class="slip-col-charge" scope="col">CHARGE</th>
                    <th class="slip-col-amount slip-right" scope="col">{{ $amountHeading }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="slip-particulars">
                        <strong>Sending money to {{ $destination }}</strong>
                    </td>
                    <td>
                        @forelse($rateLines as $rateLine)
                            <span class="slip-rate">{{ $rateLine }}<span class="slip-rate-unit">per 1 USD</span></span>
                        @empty
                            <span class="slip-payout">Not recorded</span>
                        @endforelse
                    </td>
                    <td>
                        <span class="slip-figure">{{ $transaction->feeLabel() }}</span>
                    </td>
                    <td class="slip-right">
                        <span class="slip-figure">{{ $transaction->principalLabel() }}</span>
                        <span class="slip-payout">({{ $payoutText }})</span>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="slip-right slip-total-label" scope="row">Total cash handed over</th>
                    <td class="slip-right slip-total">{{ $transaction->collectedAmount() }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="slip-note">{{ $chargeNote }}</p>

    <div class="slip-official">
        <p class="slip-official-title">For official use only</p>
        <div class="slip-signatures">
            <div class="slip-sign">
                <span class="slip-sign-line"></span>
                <span class="slip-sign-name">{{ $issuedBy }}</span>
                <span class="slip-sign-caption">Signature and name, for {{ $companyName }}</span>
            </div>
            <div class="slip-sign">
                <span class="slip-sign-line"></span>
                <span class="slip-sign-name">{{ $transaction->sender_name }}</span>
                <span class="slip-sign-caption">Signature and name, customer</span>
            </div>
        </div>
    </div>

    <p class="slip-reminder">Keep this receipt as proof of payment. Quote {{ $transaction->receipt_number }} in any call about this money.</p>

    @if($companyPhone || $companyEmail || $companyAddress || $companyWebsite)
        <footer class="slip-foot">
            @if($companyPhone || $companyEmail)
                <div>
                    <span class="slip-foot-title">Contact us</span>
                    @if($companyPhone)
                        <span>Call / WhatsApp: {{ $companyPhone }}</span>
                    @endif
                    @if($companyEmail)
                        <span>{{ $companyEmail }}</span>
                    @endif
                </div>
            @endif
            @if($companyAddress || $companyWebsite)
                <div class="slip-foot-address">
                    @if($companyAddress)
                        <span class="slip-foot-title">Visit us</span>
                    @endif
                    @foreach($addressLines as $line)
                        <span>{{ trim($line) }}</span>
                    @endforeach
                    @if($companyWebsite)
                        <span>{{ $companyWebsite }}</span>
                    @endif
                </div>
            @endif
        </footer>
    @endif
</article>
