<?php

namespace App\Console\Commands;

use Database\Seeders\CategorySeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agora:demo-catalog {--wipe : Delete only SEED- offers and *@seed.agora.local suppliers}')]
#[Description('Seed or wipe the disposable 10-supplier / ~250-offer catalog for AI tests')]
class DemoCatalogCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('wipe')) {
            $n = DemoCatalogSeeder::wipe();
            $this->info("Wiped {$n} demo rows (SEED- offers + seed.agora.local suppliers). Live AI-* catalog is untouched.");

            return self::SUCCESS;
        }

        $this->call(CategorySeeder::class);
        $this->call(DemoCatalogSeeder::class);

        return self::SUCCESS;
    }
}
