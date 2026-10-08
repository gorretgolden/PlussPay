@extends('layouts.app')

@section('title', 'Two-factor sign-in')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-2xl font-bold text-gray-900">Two-factor sign-in: {{ $user->name }}</h1>
    <a href="{{ route('users.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Back to Users</a>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-2xl mx-auto">
    <p class="text-sm text-gray-600 mb-4">
        {{ $user->name }} ({{ $user->email }}) signs in with a password as usual, then enters a 6-digit code that
        changes every 30 seconds. The codes come from an authenticator app on their phone, such as Google Authenticator
        or Microsoft Authenticator. No SMS is sent.
    </p>

    @if($user->two_factor_enabled)
    <div class="border border-green-200 bg-green-50 rounded-md p-4 mb-6">
        <p class="text-sm font-medium text-green-900">Two-factor sign-in is ON for this account.</p>
        <p class="text-xs text-green-800 mt-1">Keep this page for reference. If they lose the phone, turn it off here and set it up again.</p>
    </div>
    @else
    <ol class="list-decimal list-inside space-y-4 mb-6">
        <li class="text-sm text-gray-700">
            On the phone, open the authenticator app and choose <span class="font-medium">Add account</span>, then
            <span class="font-medium">Enter setup key</span> if there is no camera.
            <div class="mt-3 flex flex-wrap items-start gap-6">
                <div id="qr" class="bg-white p-2 border border-gray-200 rounded"></div>
                <div>
                    <p class="text-xs text-gray-500 mb-1">Secret key</p>
                    <div class="flex items-center gap-2">
                        <code id="secret" class="text-sm font-mono bg-gray-100 px-3 py-2 rounded tracking-wider select-all">{{ \App\Services\TotpService::formatSecret($secret) }}</code>
                        <button type="button" data-copy-secret class="text-xs text-green-800 hover:underline">Copy</button>
                    </div>
                    <p id="qrNote" class="text-xs text-gray-500 mt-2">Type this key into the app with SHA1, 6 digits, 30 seconds.</p>
                </div>
            </div>
        </li>
        <li class="text-sm text-gray-700">
            Enter the 6-digit code the app is showing right now to confirm the setup works.
        </li>
    </ol>

    <form method="POST" action="{{ route('users.two-factor.enable', $user) }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">Confirmation code</label>
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="6"
                class="w-40 px-3 py-2 border border-gray-300 rounded-md text-sm tracking-widest focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none">
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Turn on two-factor</button>
        @error('code')<p class="text-red-500 text-sm w-full">{{ $message }}</p>@enderror
    </form>
    @endif

    <div class="border-t border-gray-200 mt-6 pt-4 flex flex-wrap gap-3">
        <form method="POST" action="{{ route('users.two-factor.reset', $user) }}">
            @csrf
            <button type="submit" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">New setup key</button>
        </form>

        @if($user->two_factor_enabled || $user->two_factor_secret)
        <form method="POST" action="{{ route('users.two-factor.disable', $user) }}" onsubmit="return confirm('Turn two-factor sign-in off for {{ addslashes($user->name) }}?')">
            @csrf
            @method('DELETE')
            <button type="submit" class="bg-red-50 text-red-700 border border-red-200 px-4 py-2 rounded-md text-sm hover:bg-red-100">
                Turn off two-factor
            </button>
        </form>
        @endif
    </div>

    <p class="text-xs text-gray-500 mt-4">
        A new setup key invalidates the old one. Nothing changes for the account until a code is confirmed.
    </p>
</div>
@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    const uri = @json($provisioningUri);
    const box = document.getElementById('qr');

    if (box && window.QRCode) {
        new QRCode(box, { text: uri, width: 148, height: 148, correctLevel: QRCode.CorrectLevel.M });
    } else if (box) {
        box.remove();
        const note = document.getElementById('qrNote');
        if (note) note.textContent = 'The QR image could not load on this computer. Type the secret key into the app instead.';
    }

    const copy = document.querySelector('[data-copy-secret]');

    if (copy) {
        copy.addEventListener('click', async () => {
            const secret = document.getElementById('secret').innerText.replace(/\s/g, '');
            try {
                await navigator.clipboard.writeText(secret);
                copy.textContent = 'Copied';
            } catch (error) {
                copy.textContent = 'Select and copy manually';
            }
            setTimeout(() => { copy.textContent = 'Copy'; }, 2000);
        });
    }
})();
</script>
@endpush
