<?php

namespace Tests\Feature;

use Database\Seeders\CategorySeeder;
use Database\Seeders\OfferSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The conversational layer: does the assistant actually remember, amend and
 * forget the running query the way a human consultant would?
 */
class AiConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CategorySeeder::class, SupplierSeeder::class, OfferSeeder::class]);
        // Deterministic: heuristic parsing only, no LLM.
        config(['services.wavespeed.key' => null, 'services.wavespeed.enabled' => false]);
    }

    private function newSession(): string
    {
        return $this->postJson('/api/ai/sessions')->json('session_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function say(string $session, string $message): array
    {
        $res = $this->postJson("/api/ai/sessions/{$session}/messages", ['message' => $message]);
        $res->assertOk();

        return $res->json();
    }

    public function test_refinement_is_remembered_across_turns(): void
    {
        $s = $this->newSession();

        $this->say($s, 'Нужен гофрокороб 400x300x200 мм, доставка Москва');
        $second = $this->say($s, 'нужен бурый');

        $q = $second['structured_query'];
        // The new detail is applied...
        $this->assertSame('Бурый', $q['liner_color']);
        // ...and nothing from the first turn is lost.
        $this->assertSame(400, $q['length_mm']);
        $this->assertSame(300, $q['width_mm']);
        $this->assertSame(200, $q['height_mm']);
        $this->assertSame('Москва', $q['city']);

        $this->assertSame('refine', $second['turn']['kind']);
        $this->assertContains('liner_color', $second['turn']['added_fields']);
    }

    public function test_later_message_overrides_an_earlier_value(): void
    {
        $s = $this->newSession();

        $this->say($s, 'короба 400x300x200 Москва');
        $second = $this->say($s, 'нет, лучше 600x400x300');

        $q = $second['structured_query'];
        $this->assertSame(600, $q['length_mm']);
        $this->assertSame(400, $q['width_mm']);
        $this->assertSame(300, $q['height_mm']);
    }

    public function test_topic_switch_drops_incompatible_constraints_but_keeps_the_buyer_context(): void
    {
        $s = $this->newSession();

        $this->say($s, 'гофрокороб 400x300x200 бурый, 5000 шт, Москва');
        $switched = $this->say($s, 'Гофролист Т-23 оптом');

        $q = $switched['structured_query'];

        // Category replaced outright — not merged with the old one.
        $this->assertSame(['corrugated-sheet'], $q['category_slugs']);

        // Box-specific parameters are gone.
        $this->assertNull($q['length_mm'], 'box dimensions must not leak into a sheet query');
        $this->assertNull($q['liner_color']);

        // Buyer-level context survives.
        $this->assertSame('Москва', $q['city']);
        $this->assertSame(5000, $q['qty']);

        $this->assertSame('topic_switch', $switched['turn']['kind']);
        $this->assertContains('length_mm', $switched['turn']['switched_from']);
        $this->assertStringContainsStringIgnoringCase('гофролист', $switched['assistant_message']);
    }

    public function test_add_line_keeps_box_and_adds_sheet(): void
    {
        $s = $this->newSession();

        $this->say($s, 'гофрокороб 400x300x200 бурый, 5000 шт, Москва');
        $added = $this->say($s, 'ещё гофролист');

        $q = $added['structured_query'];
        $this->assertContains('corrugated-boxes', $q['category_slugs']);
        $this->assertContains('corrugated-sheet', $q['category_slugs']);
        $this->assertSame(400, $q['length_mm'], 'box size must stay on the kit when a line is added');
        $this->assertSame('add_line', $added['turn']['kind']);
        $this->assertTrue($added['order_plan']['multi']);
        $this->assertSame('ПаллетПром', $added['order_plan']['recommended']['supplier_name'] ?? null);
    }

    public function test_buyer_can_drop_a_constraint_in_words(): void
    {
        $s = $this->newSession();

        $this->say($s, 'гофрокороб 400x300x200 бурый Т-23, Москва');
        $dropped = $this->say($s, 'цвет не важен');

        $this->assertNull($dropped['structured_query']['liner_color']);
        $this->assertContains('liner_color', $dropped['turn']['dropped_fields']);
        // Everything else stays.
        $this->assertSame('Т-23', $dropped['structured_query']['board_grade']);
        $this->assertSame(400, $dropped['structured_query']['length_mm']);
    }

    public function test_inflected_negation_also_drops(): void
    {
        $s = $this->newSession();

        $this->say($s, 'гофрокороб 400x300x200 Т-23 Москва');
        $dropped = $this->say($s, 'и марка тоже неважна');

        $this->assertNull($dropped['structured_query']['board_grade']);
    }

    public function test_greeting_does_not_trigger_a_search(): void
    {
        $s = $this->newSession();
        $hello = $this->say($s, 'привет');

        $this->assertSame('small_talk', $hello['turn']['kind']);
        $this->assertFalse($hello['turn']['searched']);
        $this->assertSame([], $hello['offers'], 'a greeting must not dump the whole catalog');
        $this->assertStringContainsStringIgnoringCase('здравствуйте', $hello['assistant_message']);
    }

    public function test_meta_question_explains_instead_of_searching(): void
    {
        $s = $this->newSession();
        $meta = $this->say($s, 'что ты можешь?');

        $this->assertSame('meta', $meta['turn']['kind']);
        $this->assertSame([], $meta['offers']);
    }

    public function test_reset_clears_the_running_query(): void
    {
        $s = $this->newSession();

        $this->say($s, 'гофрокороб 400x300x200 бурый Москва 5000 шт');
        $reset = $this->say($s, 'начнём заново');

        $q = $reset['structured_query'];
        $this->assertSame('reset', $reset['turn']['kind']);
        $this->assertSame([], $q['category_slugs']);
        $this->assertNull($q['length_mm']);
        $this->assertNull($q['liner_color']);
        $this->assertNull($q['qty']);
        $this->assertSame([], $reset['understood']);
    }

    public function test_refine_endpoint_removes_a_constraint_without_llm(): void
    {
        $s = $this->newSession();
        $this->say($s, 'гофрокороб 400x300x200 бурый Т-23 Москва 5000 шт');

        $res = $this->postJson("/api/ai/sessions/{$s}/refine", ['remove' => ['liner_color']]);
        $res->assertOk();

        $this->assertNull($res->json('structured_query.liner_color'));
        $this->assertSame(400, $res->json('structured_query.length_mm'));
        $this->assertStringContainsStringIgnoringCase('цвет', $res->json('assistant_message'));

        // Removing dimensions clears all three at once.
        $res2 = $this->postJson("/api/ai/sessions/{$s}/refine", ['remove' => ['length_mm', 'width_mm', 'height_mm']]);
        $res2->assertOk();
        $this->assertNull($res2->json('structured_query.length_mm'));
        $this->assertNull($res2->json('structured_query.height_mm'));
    }

    public function test_understood_chips_carry_removable_fields(): void
    {
        $s = $this->newSession();
        $r = $this->say($s, 'гофрокороб 400x300x200 бурый Москва');

        $chips = collect($r['understood']);
        $size = $chips->firstWhere('key', 'dimensions');

        $this->assertNotNull($size);
        $this->assertEqualsCanonicalizing(['length_mm', 'width_mm', 'height_mm'], $size['fields']);
        $this->assertTrue($size['removable']);
    }

    public function test_does_not_ask_again_for_something_already_known(): void
    {
        $s = $this->newSession();

        $first = $this->say($s, 'короба 400x300x200 Москва');
        // Volume is unknown at this point, so asking for it is correct.
        $this->assertContains('qty', $first['structured_query']['missing_slots']);

        $second = $this->say($s, 'а объём 5000 шт');
        $this->assertSame(5000, $second['structured_query']['qty']);
        $this->assertNotContains(
            'qty',
            $second['structured_query']['missing_slots'],
            'the assistant must not ask for a volume it already has'
        );
        $this->assertStringNotContainsStringIgnoringCase('назовите объём', $second['assistant_message']);
    }
}
