<?php

namespace App\Console\Commands;

use App\Enums\StrategyKey;
use App\Models\Strategy;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class LinkStorage extends Command
{
    protected $signature = 'lsky:storage-link';
    protected $description = 'Restore public links for local storage strategies.';

    public function handle(): int
    {
        foreach (Strategy::query()->where('key', StrategyKey::Local)->cursor() as $strategy) {
            $link = public_path(Strategy::getRootPath($strategy->configs->get('url')));
            if (! file_exists($link) && ! is_link($link)) {
                (new Filesystem())->link($strategy->configs->get('root') ?: config('filesystems.disks.uploads.root'), $link);
            }
        }
        return self::SUCCESS;
    }
}
