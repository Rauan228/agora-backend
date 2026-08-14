<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\Supplier;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\OfferSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_ten_suppliers_and_a_fat_varied_catalog_without_touching_live_skus(): void
    {
        $this->seed([CategorySeeder::class, SupplierSeeder::class, OfferSeeder::class]);
        $liveSkus = Offer::query()->pluck('sku')->all();
        $this->assertNotEmpty($liveSkus);

        $this->seed(DemoCatalogSeeder::class);

        $this->assertSame(10, Supplier::query()->where('email', 'like', '%@seed.agora.local')->count());
        $seedOffers = Offer::query()->where('sku', 'like', 'SEED-%')->get();
        $this->assertGreaterThanOrEqual(200, $seedOffers->count());
        $this->assertLessThanOrEqual(300, $seedOffers->count());

        foreach ($liveSkus as $sku) {
            $this->assertTrue(Offer::query()->where('sku', $sku)->exists(), "live sku {$sku} must survive");
        }

        $perSupplier = $seedOffers->groupBy('supplier_id');
        $this->assertCount(10, $perSupplier);
        foreach ($perSupplier as $rows) {
            $this->assertGreaterThanOrEqual(20, $rows->count());
            $cats = $rows->pluck('category_id')->unique();
            $this->assertGreaterThanOrEqual(5, $cats->count(), 'each factory must span 5 product families');
        }

        DemoCatalogSeeder::wipe();

        $this->assertSame(0, Offer::query()->where('sku', 'like', 'SEED-%')->count());
        $this->assertSame(0, Supplier::query()->where('email', 'like', '%@seed.agora.local')->count());
        foreach ($liveSkus as $sku) {
            $this->assertTrue(Offer::query()->where('sku', $sku)->exists());
        }
    }
}
