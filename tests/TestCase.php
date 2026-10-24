<?php

namespace Tests;

use App\Models\Group;
use Database\Seeders\InstallSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication, RefreshDatabase;

    protected $seed = true;
    protected $seeder = InstallSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests use an isolated database without an installation lock.
        View::composer('*', function ($view) {
            $view->with([
                '_group' => Auth::user()?->group ?? Group::query()->where('is_guest', true)->first(),
                '_is_notice' => '',
            ]);
        });
    }

    protected function tearDown(): void
    {
        foreach (config('filesystems.links') as $link => $target) {
            if (is_link($link)) unlink($link);
        }
        parent::tearDown();
    }
}
