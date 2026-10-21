<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeding_is_repeatable_and_assigns_roles(): void
    {
        config(['zalomind.demo_accounts' => true]);
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 3);
        $this->assertTrue(User::where('email', 'admin@zalomind.test')->firstOrFail()->hasRole('admin'));
        $this->assertTrue(User::where('email', 'user1@zalomind.test')->firstOrFail()->hasRole('user'));
    }

    public function test_user_cannot_access_admin_area(): void
    {
        Role::findOrCreate('user', 'web');
        $user = User::factory()->create()->assignRole('user');
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_admin_can_access_admin_area(): void
    {
        Role::findOrCreate('admin', 'web');
        $user = User::factory()->create()->assignRole('admin');
        $this->actingAs($user)->get('/admin')->assertOk();
    }

    public function test_new_registration_receives_only_user_role(): void
    {
        $this->post('/register', [
            'name' => 'Khách hàng', 'email' => 'customer@example.test',
            'password' => 'password', 'password_confirmation' => 'password',
            'role' => 'admin',
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'customer@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('user'));
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/admin')->assertRedirect('/login');
    }
}
