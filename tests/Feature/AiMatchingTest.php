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

    public function test_catalog_endpoint_reports_search_space(): void
    {
        $res = $this->getJson('/api/ai/catalog');

        $res->assertOk()->assertJsonStructure(['active_offers', 'active_suppliers', 'categories', 'is_thin']);
        $this->assertGreaterThan(0, $res->json('active_offers'));
    }

    public function test_keywords_do_not_wipe_out_results(): void
    {
        // A keyword nobody has in their title used to empty the whole result set.
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');

        $res = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороба для e-com маркетплейс, Москва',
        ]);

        $res->assertOk();
        $this->assertNotEmpty($res->json('offers'), 'keyword filter must not remove all offers');
    }

    public function test_mismatched_size_is_not_reported_as_exact(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');

        $res = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороб 1000x900x800 Москва',
        ]);

        $res->assertOk();
        foreach ($res->json('offers') as $offer) {
            $this->assertNotSame(
                'exact',
                $offer['match_tier'],
                'an offer whose size does not fit must never be an exact match'
            );
        }
    }

    public function test_exact_match_scores_high_and_explains_itself(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');

        $res = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороб 400x300x200 четырёхклапанный Т-23 Москва 5000 шт',
        ]);

        $res->assertOk();
        $top = $res->json('offers.0');
        $this->assertGreaterThanOrEqual(70, $top['match_score']);
        $this->assertNotEmpty($top['match_reasons']);
        $this->assertArrayHasKey('match_gaps', $top);
    }

    public function test_understood_summary_is_human_readable(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');

        $res = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'самосбор 400x300x200, бурый, 5000 шт, Москва',
        ]);

        $res->assertOk();
        $understood = collect($res->json('understood'));
        $this->assertNotEmpty($understood);

        $keys = $understood->pluck('key')->all();
        $this->assertContains('dimensions', $keys);
        $this->assertContains('qty', $keys);
        $this->assertSame(
            '400×300×200 мм (±10%)',
            $understood->firstWhere('key', 'dimensions')['value']
        );
    }

    public function test_stream_endpoint_emits_sse_frames(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');

        $res = $this->post(
            "/api/ai/sessions/{$sessionId}/stream",
            ['message' => 'гофрокороб 400x300x200 Москва'],
            ['Accept' => 'text/event-stream']
        );

        $res->assertOk();
        $res->assertHeader('X-Accel-Buffering', 'no');

        $body = $res->streamedContent();
        $this->assertStringContainsString('event: understood', $body);
        $this->assertStringContainsString('event: results', $body);
        $this->assertStringContainsString('event: done', $body);
    }

    public function test_cheaper_intent_keeps_real_scores(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');
        $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороба Москва 5000 шт',
        ])->assertOk();

        $res = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'покажи дешевле',
        ]);

        $res->assertOk();
        foreach ($res->json('offers') as $offer) {
            // The old implementation stamped every re-sorted row with score 50.
            $this->assertNotSame(
                ['Из текущего shortlist'],
                $offer['match_reasons'],
                're-sorting must preserve genuine match reasons'
            );
        }
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
