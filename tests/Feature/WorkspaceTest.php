<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_user_navigation_destinations_render_without_admin_controls(): void
    {
        $user = $this->makeUser('user');
        $this->actingAs($user);
        foreach (app(Navigation::class)->pages('user', $user) as $page) {
            $this->get($page['url'])->assertOk()->assertSee('command-palette')->assertDontSee('Menu quản trị');
        }
    }

    public function test_admin_navigation_uses_admin_shell(): void
    {
        $admin = $this->makeUser('admin');
        $this->actingAs($admin);
        foreach (app(Navigation::class)->pages('admin', $admin) as $page) {
            $this->get($page['url'])->assertOk()->assertSee('admin-workspace')->assertSee('Menu quản trị');
        }
    }

    public function test_unknown_workspace_pages_return_custom_404(): void
    {
        config(['app.debug' => false]);
        $this->actingAs($this->makeUser('user'))->get('/workspace/not-a-registered-page')
            ->assertNotFound()->assertSee('Không tìm thấy trang');
    }

    public function test_user_cannot_access_nested_admin_destinations_or_admin_navigation(): void
    {
        $user = $this->makeUser('user');
        $this->assertSame([], app(Navigation::class)->pages('admin', $user));
        $this->actingAs($user)->get('/admin/users')->assertForbidden()->assertSee('Bạn không có quyền truy cập');
    }

    public function test_server_error_has_a_vietnamese_page_without_debug_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/test-server-error', fn () => abort(500));
        $this->get('/test-server-error')->assertStatus(500)->assertSee('Hệ thống đang gặp gián đoạn')->assertDontSee('Stack trace');
    }

    private function makeUser(string $role): User
    {
        Role::findOrCreate($role, 'web');

        return User::factory()->create()->assignRole($role);
    }
}
