<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>Set password · PlusPay</title><link rel="stylesheet" href="{{ asset('user-management.css') }}?v={{ @filemtime(public_path('user-management.css')) ?: 1 }}"></head>
<body class="user-enroll">
<main class="user-management user-enroll-card">
    <h1>Set your password</h1><p>Choose a password only you know.</p>
    <form method="POST" action="{{ route('password.enroll.store') }}" data-validate data-password-enroll>
        @csrf
        <input id="enrollment-token" name="token" type="hidden" autocomplete="off">
        <div class="user-field"><label for="email">Account email</label><input id="email" name="email" type="email" autocomplete="off" required maxlength="100" aria-describedby="error-email"><p id="error-email" data-field-error="email" class="user-error">@error('email'){{ $message }}@enderror</p></div>
        <p id="error-token" data-field-error="token" class="user-error">@error('token'){{ $message }}@enderror</p>
        @include('users.partials.password-fields')
        <p id="enrollment-notice" class="user-help" role="status"></p>
        <button type="submit" class="user-primary">Save password</button>
        <a href="{{ route('login') }}">Back to sign in</a>
    </form>
</main>
<script src="{{ asset('user-management.js') }}?v={{ @filemtime(public_path('user-management.js')) ?: 1 }}" defer></script>
</body></html>
