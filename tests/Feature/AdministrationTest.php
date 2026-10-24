<?php

namespace Tests\Feature;

use App\Enums\ConfigKey;
use App\Enums\StrategyKey;
use App\Models\Group;
use App\Models\Strategy;
use App\Models\User;
use App\Utils;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_adminer' => true])->save();
        $this->actingAs($user->refresh());
        return $user;
    }

    public function test_group_crud_and_default_group_protection(): void
    {
        $this->admin();
        $data = ['name' => 'Test group', 'is_default' => false, 'is_guest' => false, 'configs' => config('convention.group')];
        $this->postJson('/admin/groups/create', $data)->assertJsonPath('status', true);
        $group = Group::query()->where('name', 'Test group')->firstOrFail();
        $this->get('/admin/groups/'.$group->id)->assertOk();
        $data['name'] = 'Updated group';
        $this->putJson('/admin/groups/'.$group->id, $data)->assertJsonPath('status', true);
        $this->assertSame('Updated group', $group->fresh()->name);
        $this->deleteJson('/admin/groups/'.$group->id.'/clear-cache')->assertJsonPath('status', true);
        $this->deleteJson('/admin/groups/'.$group->id)->assertJsonPath('status', true);
        $this->assertModelMissing($group);
        $this->deleteJson('/admin/groups/'.Group::query()->where('is_default', true)->value('id'))->assertJsonPath('status', false);
    }

    public function test_local_storage_strategy_crud_and_absolute_symlink(): void
    {
        $this->admin();
        $data = ['key' => StrategyKey::Local, 'name' => 'Container storage', 'configs' => ['url' => 'http://localhost/test-storage'], 'groups' => [Group::query()->first()->id]];
        $this->postJson('/admin/strategies/create', $data)->assertJsonPath('status', true);
        $strategy = Strategy::query()->where('name', 'Container storage')->firstOrFail();
        $this->assertSame(storage_path('app/uploads'), readlink(public_path('test-storage')));
        unlink(public_path('test-storage'));
        $this->artisan('lsky:storage-link')->assertExitCode(0);
        $this->assertTrue(is_link(public_path('test-storage')));
        $this->get('/admin/strategies/'.$strategy->id)->assertOk();
        $data['name'] = 'Updated storage';
        $this->putJson('/admin/strategies/'.$strategy->id, $data)->assertJsonPath('status', true);
        $this->assertSame('Updated storage', $strategy->fresh()->name);
        $this->deleteJson('/admin/strategies/'.$strategy->id)->assertJsonPath('status', true);
        $this->assertFalse(is_link(public_path('test-storage')));
    }

    public function test_user_management_and_password_change(): void
    {
        $this->admin();
        $user = User::factory()->create();
        $this->get('/admin/users/'.$user->id)->assertOk();
        $this->putJson('/admin/users/'.$user->id, [
            'name' => 'Updated user', 'group_id' => $user->group_id, 'capacity' => 2048,
            'status' => 1, 'password' => 'new-password',
        ])->assertJsonPath('status', true);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertSame(2048.0, $user->fresh()->capacity);
        $this->deleteJson('/admin/users/'.$user->id)->assertJsonPath('status', true);
        $this->assertModelMissing($user);
    }

    public function test_site_configuration_persists_and_invalidates_cache(): void
    {
        $this->admin();
        Utils::config();
        $this->putJson('/admin/settings/save', [ConfigKey::AppName => 'Container test'])->assertJsonPath('status', true);
        $this->assertSame('Container test', Utils::config(ConfigKey::AppName));
    }

    public function test_user_preferences_and_default_strategy(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->putJson('/settings', [
            'name' => 'Changed user', 'url' => 'https://example.com', 'password' => 'new-password',
            'configs' => config('convention.user'),
        ])->assertJsonPath('status', true);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $strategy = Strategy::query()->firstOrFail();
        $this->putJson('/settings/set-strategy', ['id' => $strategy->id])->assertJsonPath('status', true);
        $this->assertSame($strategy->id, $user->fresh()->configs->get('default_strategy'));
    }

    public function test_update_check_and_progress_contract(): void
    {
        $this->admin();
        // Verifies parsing and controller behavior, not upstream update availability.
        Http::fake(['api.lsky.pro/*' => Http::response([['name' => 'V 2.2', 'changelog' => 'Test release']])]);
        $this->getJson('/admin/settings/check-update')->assertJsonPath('status', true)->assertJsonPath('data.is_update', true);
        $this->getJson('/admin/settings/upgrade/progress')->assertJsonPath('status', true);
    }
}
