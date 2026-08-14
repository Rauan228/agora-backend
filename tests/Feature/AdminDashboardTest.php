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

    public function test_admin_can_list_and_read_sessions(): void
    {
        $this->seed([CategorySeeder::class, SupplierSeeder::class, OfferSeeder::class]);
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');
        $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороб Москва',
        ])->assertOk();

        $list = $this->withToken($token)->getJson('/api/admin/ai/sessions');
        $list->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->assertTrue(collect($list->json('data'))->contains('id', $sessionId));

        $read = $this->withToken($token)->getJson("/api/admin/ai/sessions/{$sessionId}");
        $read->assertOk();
        $this->assertGreaterThanOrEqual(2, count($read->json('messages')));
        $this->assertArrayHasKey('session_cost', $read->json());
    }
}
