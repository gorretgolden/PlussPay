<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The board is the first thing a desk reads each shift, so the greeting has to match the hour
 * it is read at, name a person when there is one, and never carry the role slug as a name.
 */
class DashboardGreetingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.24, 7.24);
    }

    private function user(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function greetingAt(string $hour): string
    {
        $this->travelTo(now()->setTime((int) $hour, 14, 0));
        // Each band is read as its own visit. Without this the jump past half an hour trips
        // the idle sign-out and the page never renders.
        $this->flushSession();
        $html = $this->actingAs($this->user('agent', 'Amina Nabukenya'))->get('/dashboard')->getContent();

        preg_match('/<p class="text-gray-600 mt-1">([^<]*)<\/p>/', $html, $match);

        return trim($match[1] ?? '');
    }

    public function test_the_greeting_bands_follow_the_clock_including_the_night_shift(): void
    {
        $this->assertSame('Hello, Amina.', $this->greetingAt('00'));
        $this->assertSame('Hello, Amina.', $this->greetingAt('03'));
        $this->assertSame('Good morning, Amina.', $this->greetingAt('05'));
        $this->assertSame('Good morning, Amina.', $this->greetingAt('11'));
        $this->assertSame('Good afternoon, Amina.', $this->greetingAt('12'));
        $this->assertSame('Good afternoon, Amina.', $this->greetingAt('17'));
        $this->assertSame('Good evening, Amina.', $this->greetingAt('18'));
        $this->assertSame('Good evening, Amina.', $this->greetingAt('23'));
    }

    public function test_an_account_named_after_its_role_is_not_greeted_by_the_first_word(): void
    {
        $this->travelTo(now()->setTime(3, 14));

        $html = $this->actingAs($this->user('superadmin', 'Super Admin'))->get('/dashboard')->getContent();
        $this->assertStringContainsString('<p class="text-gray-600 mt-1">Hello.</p>', $html);
        $this->assertStringNotContainsString('Super,', $html);

        $html = $this->actingAs($this->user('agent', 'Gorret Oketayut'))->get('/dashboard')->getContent();
        $this->assertStringContainsString('<p class="text-gray-600 mt-1">Hello, Gorret.</p>', $html);
    }

    public function test_the_badge_carries_the_role_display_name_the_sidebar_uses(): void
    {
        $html = $this->actingAs($this->user('superadmin', 'Super Admin'))->get('/dashboard')->getContent();

        preg_match('/<span class="inline-flex items-center[^"]*bg-green-100[^"]*">([^<]*)<\/span>/', $html, $match);

        $this->assertSame('Super Admin', trim($match[1] ?? ''));
        // The sidebar line sits beside the desk country, so the two now say the same thing.
        $this->assertStringContainsString('Super Admin ·', $html);
    }

    public function test_the_mark_by_the_greeting_matches_the_same_hours_as_the_words(): void
    {
        // The two paths are the sun and the moon drawn in the band, so the assertions read as
        // which mark is up rather than which colour blob happened to land nearby.
        $sun = 'M12 4V2';
        $moon = 'M20.5 13.5';

        foreach ([6 => 'sun', 11 => 'sun', 17 => 'sun', 18 => 'moon', 22 => 'moon', 2 => 'moon'] as $hour => $expected) {
            $html = $this->pageAt($hour);
            $wanted = $expected === 'sun' ? $sun : $moon;
            $other = $expected === 'sun' ? $moon : $sun;

            $this->assertSame(1, substr_count($html, $wanted), "Expected the {$expected} mark at {$hour}:00.");
            $this->assertSame(0, substr_count($html, $other), "The other mark must not be on the page at {$hour}:00.");
        }
    }

    private function pageAt(int $hour): string
    {
        $this->travelTo(now()->setTime($hour, 14, 0));
        $this->flushSession();

        return $this->actingAs($this->user('agent', 'Amina Nabukenya'))->get('/dashboard')->getContent();
    }

    public function test_an_empty_wallet_is_said_in_three_words_and_links_out(): void
    {
        $html = $this->actingAs($this->user('superadmin', 'Super Admin'))->get('/dashboard')->getContent();

        $this->assertSame(2, substr_count($html, 'No balance yet.'));
        $this->assertStringNotContainsString('Nothing in this wallet yet', $html);
        $this->assertStringNotContainsString('Open it to record money in', $html);
        $this->assertStringContainsString('Add money', $html);
    }
}
