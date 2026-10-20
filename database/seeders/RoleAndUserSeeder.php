<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleAndUserSeeder extends Seeder
{
    /**
     * Demo accounts. They are created on every environment, including production, because the
     * login page's demo buttons need them whenever Site Mode is Testing. The button column is
     * what the sign-in page labels them with, so the two agent desks are never confused.
     *
     * Passwords, in the order what is available: what is typed at a terminal while the seed runs,
     * then the SEED_*_PASSWORD value from the environment, then the shared demo password saved in
     * System Settings (the one the sign-in buttons carry), then a generated one printed once.
     * Nothing working is ever saved in the repository.
     */
    public const DEMO_ACCOUNTS = [
        ['email' => 'admin@plussave.com', 'name' => 'Admin', 'button' => 'Admin', 'role' => 'admin', 'env' => 'SEED_ADMIN_PASSWORD', 'country' => 'UG'],
        ['email' => 'superadmin@plussave.com', 'name' => 'Super Admin', 'button' => 'Superadmin', 'role' => 'superadmin', 'env' => 'SEED_SUPERADMIN_PASSWORD', 'country' => 'UG'],
        ['email' => 'agent@plussave.com', 'name' => 'Agent', 'button' => 'Agent Uganda', 'role' => 'agent', 'env' => 'SEED_AGENT_PASSWORD', 'country' => 'UG'],
        ['email' => 'china.agent@plussave.com', 'name' => 'China Agent', 'button' => 'Agent China', 'role' => 'agent', 'env' => 'SEED_CHINA_AGENT_PASSWORD', 'country' => 'CN'],
        ['email' => 'superagent@plussave.com', 'name' => 'Super Agent', 'button' => 'Super Agent', 'role' => 'super-agent', 'env' => 'SEED_SUPER_AGENT_PASSWORD', 'country' => 'UG'],
        ['email' => 'accountant@plussave.com', 'name' => 'Accountant', 'button' => 'Accountant', 'role' => 'accountant', 'env' => 'SEED_ACCOUNTANT_PASSWORD', 'country' => 'UG'],
    ];

    public function run(): void
    {
        // The roles themselves, and what each one may open, come from the catalogue. Without this
        // call a machine seeded only from here holds accounts whose role has never been made.
        // Seeding an already initialised role grants nothing new and takes nothing away.
        $this->call(PermissionsSeeder::class);

        $ask = $this->terminalAsker();

        if ($ask === null) {
            $this->say('No terminal to ask in, so every account keeps the password it already has.');
        }

        foreach (self::DEMO_ACCOUNTS as $account) {
            $this->ensureAccount($account, $ask);
        }
    }

    /**
     * Make the account, or leave the one that exists alone apart from its role. The asker is how
     * an account whose generated password was never written down gets back to being usable.
     *
     * @param  callable(string,bool):?string|null  $ask  given the email and whether the account
     *         already exists, returns the password to set, or null to leave it be
     */
    public function ensureAccount(array $account, ?callable $ask = null): void
    {
        $email = $account['email'];
        $user = User::where('email', $email)->first();
        $typed = $ask ? $ask($email, $user !== null) : null;
        $country = $account['country'] ?? User::DEFAULT_COUNTRY;

        if ($user === null) {
            // The shared demo password is the one the sign-in buttons fill, so a demo account
            // seeded on the live site works from the first click without anyone retyping it.
            $shared = ($typed || env($account['env'])) ? null : Setting::get('demo_password');
            $password = $typed ?: env($account['env']) ?: $shared ?: Str::random(20);

            $user = User::create([
                'name' => $account['name'],
                'email' => $email,
                'phone' => '',
                'password' => $password,
                'is_active' => true,
                'country' => $country,
                'timezone' => User::timezoneForCountry($country),
            ]);
            $user->assignRole($account['role']);
            $this->ensureWallets($user);

            if ($typed) {
                $this->say("Created {$email} with the password just typed.");

                return;
            }

            if ($shared) {
                // The sign-in page already gives this password to anyone who looks at the page
                // source while autofill is on, but the deploy output is not the place for it.
                $this->say("Created {$email} with the shared demo password from System Settings.");

                return;
            }

            $this->say("Created {$email} with password: {$password}");
            $this->say('Store that password somewhere safe. It is not saved in the repository.');

            return;
        }

        $user->assignRole($account['role']);
        $this->ensureWallets($user);

        if ($typed) {
            $user->password = $typed;
            $user->save();
            $this->say("Password set for {$email}.");
        }
    }

    /**
     * The wallets this person's country gives them are made along with the account, so a desk in
     * China opens holding a dollar and a yuan wallet and the dollars sent to it have somewhere to
     * land. Without them the China Desk has no account of that desk to pay the receiver out of.
     */
    private function ensureWallets(User $user): void
    {
        Account::walletsFor($user);
    }

    /**
     * A real terminal is asked for each password, so she chooses the one she will remember and it
     * lands in neither git nor .env. Any other run, piped, scripted or under the test suite, stays
     * headless rather than hanging on a question nobody is there to answer.
     */
    private function terminalAsker(): ?\Closure
    {
        if ($this->command?->getLaravel()?->runningUnitTests()) {
            return null;
        }

        if (!defined('STDIN') || !function_exists('stream_isatty') || !@stream_isatty(STDIN)) {
            return null;
        }

        return function (string $email, bool $exists): ?string {
            $answer = $this->command->secret($exists
                ? "Password for {$email} (press Enter to keep the current one)"
                : "Password for {$email} (press Enter to let the seeder choose one)");

            return $answer ?: null;
        };
    }

    /**
     * This framework build removed the Seeder console helpers, so write through the
     * command when there is one and stay silent otherwise. Seeding still has to run
     * headless in tests, where no command is attached.
     */
    private function say(string $message): void
    {
        $this->command?->getOutput()->writeln($message);
    }
}
