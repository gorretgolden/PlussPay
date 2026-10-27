<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The audit trails open ten rows to a page so the board reads fast, and a desk tracing a longer
 * stretch can widen the page to 50, 100 or every record at once from either tab.
 */
class AuditLogPerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
    }

    private function viewer(): User
    {
        $user = User::create([
            'name' => 'Audit Reader',
            'email' => 'reader'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole('superadmin');

        return $user;
    }

    private function seedActivity(int $count): void
    {
        foreach (range(1, $count) as $i) {
            ActivityLog::create([
                'action' => 'updated',
                'description' => 'Seeded trail row '.$i,
            ]);
        }
    }

    private function seedLogins(User $user, int $count): void
    {
        foreach (range(1, $count) as $i) {
            LoginLog::create([
                'user_id' => $user->id,
                'ip_address' => '10.0.0.'.$i,
                'status' => 'success',
            ]);
        }
    }

    public function test_the_trail_opens_ten_rows_and_can_be_widened_or_taken_whole(): void
    {
        $viewer = $this->viewer();
        $this->seedActivity(60);

        $page = fn (string $query) => $this->actingAs($viewer)->get('/admin/audit-logs?'.$query)->assertOk();

        $this->assertSame(10, $page('tab=activity')->viewData('logs')->count());
        $this->assertSame(50, $page('tab=activity&per_page=50')->viewData('logs')->count());
        $this->assertSame(60, $page('tab=activity&per_page=all')->viewData('logs')->count());
        // A page size typed by hand that the selector does not offer falls back to the default.
        $this->assertSame(10, $page('tab=activity&per_page=7')->viewData('logs')->count());
    }

    public function test_the_sign_in_trail_widens_the_same_way(): void
    {
        $viewer = $this->viewer();
        $this->seedLogins($viewer, 25);

        $page = fn (string $query) => $this->actingAs($viewer)->get('/admin/audit-logs?'.$query)->assertOk();

        $this->assertSame(10, $page('tab=login')->viewData('logs')->count());
        $this->assertSame(25, $page('tab=login&per_page=100')->viewData('logs')->count());
        $this->assertSame(25, $page('tab=login&per_page=all')->viewData('logs')->count());
    }

    public function test_both_tabs_offer_the_choice_and_keep_it_across_the_pages(): void
    {
        $viewer = $this->viewer();
        $this->seedActivity(12);

        foreach (['activity', 'login'] as $tab) {
            $html = $this->actingAs($viewer)->get('/admin/audit-logs?tab='.$tab.'&per_page=50')
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('Records per page', $html);
            $this->assertStringContainsString('All records', $html);
            $this->assertStringContainsString('value="50" selected', $html, 'The widened page stays chosen.');
        }
    }
}
