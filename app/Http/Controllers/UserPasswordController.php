<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class UserPasswordController extends Controller
{
    private const RULES = ['required', 'string', 'min:8', 'max:72', 'regex:/[a-zA-Z]/', 'regex:/[0-9]/', 'confirmed'];

    public function enroll()
    {
        // Tokens arrive in the URL fragment, never a server URL or access log.
        return response()->view('users.password-enroll')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function reset(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'email' => 'required|email|max:100',
            'token' => 'required|string|max:200',
            'password' => self::RULES,
        ]);
        if ($validator->fails()) {
            return $request->expectsJson()
                ? response()->json(['errors' => $validator->errors()], 422)
                : back()->withErrors($validator);
        }
        $credentials = $validator->validated();
        $credentials['password_confirmation'] = $request->input('password_confirmation');

        $status = DB::transaction(fn () => Password::reset($credentials, function (User $user, string $password) {
            // Serialize consumption so two requests cannot reuse a token.
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (!Password::tokenExists($user, request('token'))) {
                abort(422, 'This link is invalid or expired.');
            }
            $this->savePassword($user, $password);
            event(new PasswordReset($user));
        }));

        if ($status !== Password::PASSWORD_RESET) {
            $errors = ['email' => ['This link is invalid or expired. Ask an admin for another.']];
            return $request->expectsJson() ? response()->json(['errors' => $errors], 422) : back()->withErrors($errors);
        }

        if ($request->expectsJson()) {
            $request->session()->flash('success', 'Password saved. Sign in with your new password.');
            return response()->json(['redirect' => route('login')]);
        }
        return redirect()->route('login')->with('success', 'Password saved. Sign in with your new password.');
    }

    public function required()
    {
        return response()->view('users.password-change')->header('Cache-Control', 'no-store');
    }

    public function change(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [...self::RULES, 'different:current_password'],
        ]);

        $this->savePassword($request->user(), $validated['password']);
        $request->session()->regenerate();

        // A change started on My Account returns there so the person can read the confirmation.
        // The forced screen keeps sending them on to the work it interrupted.
        $next = $request->input('source') === 'account'
            ? route('account.show')
            : route('dashboard');

        return redirect($next)->with('success', 'Password changed successfully.');
    }

    private function savePassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'remember_token' => Str::random(60),
            ])->save();

            // Database sessions already in use must not outlive a reset.
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
            }

            ActivityLog::create(ActivityLog::subjectAttributes($user) + [
                'user_id' => $user->id,
                'action' => 'password_changed',
                'description' => 'Account password changed by its owner.',
                'new_value' => 'password_changed',
            ]);
        });
    }

    public static function sendLink(User $user): string
    {
        // A log transport writes the entire message, including its bearer token.
        if (self::logsMail(config('mail.default'))) {
            return 'Email delivery is not configured. Configure a non-log mail transport, then resend.';
        }

        try {
            $status = Password::sendResetLink(['email' => $user->email], function (User $recipient, string $token) {
                $url = route('password.enroll').'#'.http_build_query(['token' => $token, 'email' => $recipient->email]);
                $mail = (new Mailable)->subject('Set your PlusPay password')
                    ->view('users.password-email', ['url' => $url, 'minutes' => config('auth.passwords.users.expire', 60)]);
                Mail::to($recipient->email)->send($mail);
            });
        } catch (\Throwable) {
            // Mail exceptions may contain the message body. Never report credentials.
            Password::deleteToken($user);
            return 'Email could not be sent. The account was saved; try sending the link again.';
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return 'A link was requested recently. Wait a minute before resending.';
        }

        ActivityLog::create(ActivityLog::subjectAttributes($user) + [
            'user_id' => auth()->id(),
            'action' => 'password_link_sent',
            'description' => 'A set-password link was emailed to the account owner.',
        ]);

        return '';
    }

    private static function logsMail(?string $name, array $seen = []): bool
    {
        if (!$name || in_array($name, $seen, true)) {
            return true;
        }
        $mailer = config('mail.mailers.'.$name, []);
        if (($mailer['transport'] ?? 'log') === 'log') {
            return true;
        }
        foreach ($mailer['mailers'] ?? [] as $child) {
            if (self::logsMail($child, [...$seen, $name])) {
                return true;
            }
        }
        return false;
    }
}
