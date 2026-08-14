<?php

namespace Tests\Feature;

use App\Models\AiSession;
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

    public function test_idle_active_session_closes_after_24_hours(): void
    {
        $freshId = $this->postJson('/api/ai/sessions')->json('session_id');
        $this->postJson("/api/ai/sessions/{$freshId}/messages", [
            'message' => 'гофрокороб Москва',
        ])->assertOk();

        $oldId = $this->postJson('/api/ai/sessions')->json('session_id');
        $this->postJson("/api/ai/sessions/{$oldId}/messages", [
            'message' => 'стрейч Москва',
        ])->assertOk();

        $this->travel(25)->hours();

        $this->postJson('/api/ai/sessions')->assertCreated();

        $this->assertSame('closed', AiSession::query()->findOrFail($oldId)->status);
        $this->assertSame('closed', AiSession::query()->findOrFail($freshId)->status);

        $this->travelBack();
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
        $this->assertNotEmpty($show->json('offers'));
        $this->assertArrayHasKey('order_plan', $show->json());
        $this->assertArrayNotHasKey('cost', $show->json());
        $this->assertArrayNotHasKey('session_cost', $show->json());
        foreach ($show->json('messages') as $m) {
            $this->assertArrayNotHasKey('cost', $m);
            if (is_array($m['meta'] ?? null)) {
                $this->assertArrayNotHasKey('cost', $m['meta']);
            }
        }
    }

    public function test_get_session_restores_kit_plan(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');
        $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'мне нужен гофрокороб и гофролист, Москва',
        ])->assertOk();

        $show = $this->getJson("/api/ai/sessions/{$sessionId}");
        $show->assertOk();
        $this->assertTrue($show->json('order_plan.multi'));
        $this->assertSame('ПаллетПром', $show->json('order_plan.recommended.supplier_name'));
        $this->assertNotEmpty($show->json('offers'));
        $this->assertSame('restore', $show->json('turn.kind'));
    }

    public function test_public_message_has_no_cost_fields(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');
        $msg = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороб 400x300x200 Москва',
        ])->assertOk();

        $this->assertArrayNotHasKey('cost', $msg->json());
        $this->assertArrayNotHasKey('session_cost', $msg->json());
    }

    public function test_admin_message_includes_cost_meter(): void
    {
        $user = \App\Models\User::factory()->create([
            'email' => 'ai-cost@agora.local',
            'password' => bcrypt('password'),
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $create = $this->withToken($token)->postJson('/api/admin/ai/sessions');
        $create->assertCreated();
        $this->assertArrayHasKey('session_cost', $create->json());

        $sessionId = $create->json('session_id');
        $msg = $this->withToken($token)->postJson("/api/admin/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороб 400x300x200 Москва',
        ])->assertOk();

        $this->assertArrayHasKey('cost', $msg->json());
        $this->assertArrayHasKey('session_cost', $msg->json());
        $this->assertArrayHasKey('match_search_usd', $msg->json('cost'));
        $this->assertSame(0, $msg->json('cost.match_search_usd'));
    }

    public function test_box_and_sheet_recommends_one_supplier_bundle(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');

        $res = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'мне нужен гофрокороб и гофролист, Москва',
        ]);

        $res->assertOk();
        $this->assertContains('corrugated-boxes', $res->json('structured_query.category_slugs'));
        $this->assertContains('corrugated-sheet', $res->json('structured_query.category_slugs'));

        $plan = $res->json('order_plan');
        $this->assertTrue($plan['multi']);
        $this->assertSame(2, $plan['needed']);
        $this->assertNotNull($plan['recommended']);
        $this->assertSame('full_cover', $plan['recommended']['kind']);
        $this->assertSame('ПаллетПром', $plan['recommended']['supplier_name']);
        $this->assertSame(2, $plan['recommended']['covers']);
        $this->assertCount(2, $plan['recommended']['lines']);

        $covered = collect($plan['recommended']['lines'])->pluck('slug')->all();
        $this->assertContains('corrugated-boxes', $covered);
        $this->assertContains('corrugated-sheet', $covered);
        foreach ($plan['recommended']['lines'] as $line) {
            $this->assertTrue($line['covered']);
            $this->assertNotNull($line['offer']);
        }

        $this->assertTrue(
            collect($res->json('offers'))->contains(fn ($o) => $o['in_recommended_bundle'] === true)
        );
        $this->assertArrayNotHasKey('cost', $res->json());
        $this->assertStringContainsStringIgnoringCase('ПаллетПром', $res->json('assistant_message'));
    }

    public function test_single_category_has_no_bundle(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');
        $res = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороб 400x300x200 Москва',
        ])->assertOk();

        $this->assertFalse($res->json('order_plan.multi'));
        $this->assertNull($res->json('order_plan.recommended'));
    }

    public function test_two_lines_two_suppliers_is_not_sold_as_savings(): void
    {
        $sessionId = $this->postJson('/api/ai/sessions')->json('session_id');
        $res = $this->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'message' => 'гофрокороб 600x400x300 и стрейч, Москва, 8000 шт',
        ])->assertOk();

        $this->assertTrue($res->json('order_plan.multi'));
        $pack = $res->json('order_plan.pack');
        if (is_array($pack) && (int) ($pack['rfq_count'] ?? 0) >= 2) {
            $this->assertFalse($pack['saves_rfqs']);
            $this->assertStringNotContainsString('вместо 2', (string) ($pack['label'] ?? ''));
        }
    }
}
