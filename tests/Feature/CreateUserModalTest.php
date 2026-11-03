<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateUserModalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionsSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true])->assignRole('admin');
    }

    public function test_users_index_shows_modal_for_authorized_user(): void
    {
        $response = $this->actingAs($this->admin)->get('/users');
        $response->assertOk();
        $response->assertSee('id="create-user-modal"', false);
        $response->assertSee('id="create-user-form"', false);
        $response->assertSee('data-open-create-modal', false);
    }

    public function test_users_index_hides_modal_for_unauthorized_user(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo('view-users');
        $response = $this->actingAs($viewer)->get('/users');
        $response->assertOk();
        $response->assertDontSee('id="create-user-modal"', false);
    }

    public function test_failed_create_reopens_modal_with_errors(): void
    {
        $response = $this->actingAs($this->admin)->post('/users', [
            '_modal' => 'create-user',
            '_token' => csrf_token(),
            'name' => '',
            'email' => '',
        ]);

        $response->assertSessionHasErrors(['name', 'email', 'role', 'password']);
        $response->assertSessionHas('_old_input');

        $followUp = $this->actingAs($this->admin)->get('/users');
        $followUp->assertOk();
        $followUp->assertSee('id="create-user-modal"', false);
    }
}
