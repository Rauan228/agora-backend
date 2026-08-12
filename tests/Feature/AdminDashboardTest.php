<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\OfferSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_auth(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_dashboard_payload(): void
    {
        $this->seed([CategorySeeder::class, SupplierSeeder::class, OfferSeeder::class]);
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/admin/dashboard?days=30');
        $res->assertOk()
            ->assertJsonStructure([
                'catalog' => ['offers_active', 'suppliers_active', 'completeness'],
                'ai' => ['sessions', 'cost', 'tokens', 'messages', 'daily'],
                'rates',
            ]);
        $this->assertGreaterThan(0, $res->json('catalog.offers_active'));
    }

    public function test_ai_ledger(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/api/admin/ai/ledger')->assertOk()->assertJsonStructure(['data', 'meta']);
    }
}
