<?php

namespace Tests\Feature;

use Database\Seeders\CategorySeeder;
use Database\Seeders\OfferSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            CategorySeeder::class,
            SupplierSeeder::class,
            OfferSeeder::class,
        ]);
        // Force heuristic path in tests
        config(['services.wavespeed.key' => null, 'services.wavespeed.enabled' => false]);
    }

    public function test_create_session_and_match_boxes(): void
    {
        $create = $this->postJson('/api/ai/sessions');
        $create->assertCreated();
        $sessionId = $create->json('session_id');
        $this->assertNotEmpty($sessionId);

        $msg = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'Нужны гофрокороба 400x300x200 самосбор или 4-клапан, бурый, Москва, около 5000 шт',
        ]);

        $msg->assertOk()
            ->assertJsonPath('session_id', $sessionId)
            ->assertJsonStructure([
                'assistant_message',
                'structured_query' => ['category_slugs', 'length_mm', 'width_mm'],
                'offers',
                'suppliers',
                'comparison',
                'suggested_replies',
                'cta',
            ]);

        $this->assertContains('corrugated-boxes', $msg->json('structured_query.category_slugs'));
        $this->assertSame(400, $msg->json('structured_query.length_mm'));
        $this->assertNotEmpty($msg->json('offers'));
        $this->assertArrayHasKey('match_score', $msg->json('offers.0'));
        $this->assertArrayHasKey('match_reasons', $msg->json('offers.0'));
    }

    public function test_handoff(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');
        $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороб 400x300x200 Москва',
        ])->assertOk();

        $handoff = $this->postJson("/api/ai/sessions/{$sessionId}/handoff", [
            'contact' => '+7 999 000-00-00',
            'note' => 'Срочно',
        ]);

        $handoff->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'handed_off');
        $this->assertStringContainsString('Бриф', $handoff->json('brief'));
    }

    public function test_session_history(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');
        $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'стрейч пленка 500 мм',
        ])->assertOk();

        $show = $this->getJson("/api/ai/sessions/{$sessionId}");
        $show->assertOk();
        $this->assertGreaterThanOrEqual(2, count($show->json('messages')));
    }
}
