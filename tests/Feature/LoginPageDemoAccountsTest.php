<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageDemoAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function renderLogin(): string
    {
        return $this->get('/admin/login')->getContent();
    }

    public function test_the_demo_buttons_show_locally_for_convenience(): void
    {
        $html = $this->renderLogin();

        $this->assertStringContainsString('Demo accounts', $html);
        $this->assertStringContainsString('admin@plussave.com', $html);
    }

    public function test_no_valid_username_is_printed_on_the_live_login_page(): void
    {
        app()['env'] = 'production';

        try {
            $html = $this->renderLogin();
        } finally {
            app()['env'] = 'testing';
        }

        foreach (['superadmin@', 'admin@', 'agent@', 'staff@'] as $email) {
            $this->assertStringNotContainsString($email . 'plussave.com', $html);
        }

        $this->assertStringNotContainsString('Demo accounts', $html);

        // The form itself must survive, or the guard would lock everyone out.
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('name="password"', $html);
    }
}
