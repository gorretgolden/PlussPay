<div class="user-grid">
    <div class="user-field">
        <label for="password">{{ $passwordLabel ?? 'Password' }}</label>
        <div class="user-password">
            <input id="password" name="password" type="password" class="user-control" autocomplete="new-password" minlength="8" maxlength="72" pattern="(?=.*[A-Za-z])(?=.*[0-9]).{8,72}" data-validation-message="Use 8 to 72 characters, with letters and numbers." @required($passwordRequired ?? true) aria-describedby="help-password error-password strength-label">
            <button type="button" data-toggle-password="password" aria-label="Show password" aria-pressed="false">Show</button>
        </div>
        <p id="help-password" class="user-help">8 to 72 characters, including letters and numbers.</p>
        <meter id="password-strength" min="0" max="4" value="0" aria-label="Password strength" class="w-full"></meter>
        <p id="strength-label" class="user-help" aria-live="polite">Password strength</p>
        <p id="error-password" data-field-error="password" class="user-error">@error('password'){{ $message }}@enderror</p>
    </div>
    <div class="user-field">
        <label for="password_confirmation">Confirm password</label>
        <div class="user-password">
            <input id="password_confirmation" name="password_confirmation" type="password" class="user-control" autocomplete="new-password" minlength="8" maxlength="72" @required($passwordRequired ?? true) data-match="password" aria-describedby="error-password_confirmation">
            <button type="button" data-toggle-password="password_confirmation" aria-label="Show confirmation password" aria-pressed="false">Show</button>
        </div>
        <p id="error-password_confirmation" data-field-error="password_confirmation" class="user-error">@error('password_confirmation'){{ $message }}@enderror</p>
    </div>
</div>
