<?php

namespace App\Services\Ai;

/**
 * Decides what a user turn *means* for the conversation before any matching
 * happens: is it small talk, a fresh search, a refinement of the running query,
 * a topic switch, or a request to drop a constraint?
 *
 * This is what makes the assistant feel like it remembers: the running
 * StructuredQuery is carried forward, amended, or deliberately reset — instead
 * of blindly accumulating every parameter ever mentioned.
 */
class TurnInterpreter
{
    public const KIND_SMALL_TALK = 'small_talk';

    public const KIND_SEARCH = 'search';

    public const KIND_REFINE = 'refine';

    public const KIND_TOPIC_SWITCH = 'topic_switch';

    public const KIND_RESET = 'reset';

    public const KIND_SORT = 'sort';

    public const KIND_META = 'meta';

    /**
     * Fields that only make sense for a specific product family. On a topic
     * switch these are dropped; everything else (city, qty, deadline) carries
     * over because it describes the buyer, not the product.
     */
    private const CATEGORY_SCOPED_FIELDS = [
        'box_type',
        'length_mm',
        'width_mm',
        'height_mm',
        'board_grade',
        'flute_profile',
        'liner_color',
    ];

    /** Constraint keys the buyer can drop by name. */
    private const DROPPABLE = [
        'liner_color' => ['цвет', 'бурый', 'белый', 'крафт'],
        'board_grade' => ['марка', 'марку', 'т-2', 'т-23', 'т-24', 'п-3'],
        'box_type' => ['тип', 'самосбор', 'четырёхклапан', 'четырехклапан'],
        'print_needed' => ['печать', 'логотип', 'брендирование'],
        'lead_time_days_max' => ['срок', 'срочность'],
        'qty' => ['объём', 'объем', 'количество', 'тираж'],
        'city' => ['город', 'москва', 'доставка'],
        'delivery_moscow' => ['город', 'москва', 'доставка'],
        'moq_max' => ['moq'],
    ];

    /**
     * @param  array<string, mixed>  $previous  running query before this turn
     * @return array{
     *     kind: string,
     *     base_query: array<string, mixed>,
     *     dropped: list<string>,
     *     switched_from: list<string>,
     *     should_match: bool,
     *     is_first_search: bool
     * }
     */
    public function interpret(
        string $message,
        array $previous,
        bool $hadPriorSearch,
        IntentParser $parser,
    ): array {
        $text = mb_strtolower(trim($message));
        $hasPrevious = $this->hasAnyConstraint($previous);

        // ---- explicit reset -------------------------------------------------
        if ($this->looksLikeReset($text)) {
            return [
                'kind' => self::KIND_RESET,
                'base_query' => $parser->emptyQuery(),
                'dropped' => [],
                'switched_from' => [],
                'should_match' => false,
                'is_first_search' => false,
            ];
        }

        // ---- "drop the colour" / "без печати" -------------------------------
        $dropped = $this->detectDrops($text, $previous);
        if ($dropped !== []) {
            $base = $previous;
            foreach ($dropped as $key) {
                $base[$key] = null;
            }

            return [
                'kind' => self::KIND_REFINE,
                'base_query' => $base,
                'dropped' => $dropped,
                'switched_from' => [],
                'should_match' => true,
                'is_first_search' => false,
            ];
        }

        // ---- meta questions about the catalog / the bot itself --------------
        if (! $this->mentionsProduct($text) && $this->looksLikeMeta($text)) {
            return [
                'kind' => self::KIND_META,
                'base_query' => $previous,
                'dropped' => [],
                'switched_from' => [],
                'should_match' => false,
                'is_first_search' => false,
            ];
        }

        // ---- pure small talk / greeting -------------------------------------
        if ($this->looksLikeSmallTalk($text) && ! $this->mentionsProduct($text)) {
            return [
                'kind' => self::KIND_SMALL_TALK,
                'base_query' => $previous,
                'dropped' => [],
                'switched_from' => [],
                // Nothing new to search for; don't burn a match on "привет".
                'should_match' => $hasPrevious && $hadPriorSearch,
                'is_first_search' => false,
            ];
        }

        // ---- sorting / comparison over the current shortlist ----------------
        if ($hadPriorSearch && $this->looksLikeSort($text) && ! $this->mentionsProduct($text)) {
            return [
                'kind' => self::KIND_SORT,
                'base_query' => $previous,
                'dropped' => [],
                'switched_from' => [],
                'should_match' => true,
                'is_first_search' => false,
            ];
        }

        // ---- topic switch: a different product family ------------------------
        $newSlugs = $parser->categoriesFor($message);
        $oldSlugs = is_array($previous['category_slugs'] ?? null) ? $previous['category_slugs'] : [];

        if ($newSlugs !== [] && $oldSlugs !== [] && array_intersect($newSlugs, $oldSlugs) === []) {
            $base = $previous;
            $switched = [];
            foreach (self::CATEGORY_SCOPED_FIELDS as $field) {
                if (! empty($base[$field])) {
                    $switched[] = $field;
                }
                $base[$field] = null;
            }
            // The new category replaces the old one outright.
            $base['category_slugs'] = $newSlugs;

            return [
                'kind' => self::KIND_TOPIC_SWITCH,
                'base_query' => $base,
                'dropped' => [],
                'switched_from' => $switched,
                'should_match' => true,
                'is_first_search' => false,
            ];
        }

        // ---- refinement of a query already in flight -------------------------
        if ($hasPrevious && $hadPriorSearch) {
            return [
                'kind' => self::KIND_REFINE,
                'base_query' => $previous,
                'dropped' => [],
                'switched_from' => [],
                'should_match' => true,
                'is_first_search' => false,
            ];
        }

        return [
            'kind' => self::KIND_SEARCH,
            'base_query' => $previous,
            'dropped' => [],
            'switched_from' => [],
            'should_match' => true,
            'is_first_search' => true,
        ];
    }

    /**
     * Which constraints this turn actually added, compared to the base — used
     * to say «понял, добавил бурый цвет» instead of repeating everything.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<string>
     */
    public function addedFields(array $before, array $after): array
    {
        $watch = [
            'category_slugs', 'city', 'delivery_moscow', 'box_type',
            'length_mm', 'width_mm', 'height_mm', 'board_grade',
            'flute_profile', 'liner_color', 'print_needed', 'branding_needed',
            'qty', 'moq_max', 'lead_time_days_max',
        ];

        $added = [];
        foreach ($watch as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($this->isBlank($new)) {
                continue;
            }
            if ($this->isBlank($old) || $this->normalizeForCompare($old) !== $this->normalizeForCompare($new)) {
                $added[] = $key;
            }
        }

        return $added;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function hasAnyConstraint(array $query): bool
    {
        foreach ([
            'category_slugs', 'city', 'box_type', 'length_mm', 'width_mm',
            'height_mm', 'board_grade', 'flute_profile', 'liner_color',
            'print_needed', 'qty', 'moq_max', 'lead_time_days_max',
        ] as $key) {
            if (! $this->isBlank($query[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function isBlank(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [] || $v === false;
    }

    private function normalizeForCompare(mixed $v): string
    {
        if (is_array($v)) {
            $copy = $v;
            sort($copy);

            return implode(',', array_map(fn ($x) => (string) $x, $copy));
        }

        return is_bool($v) ? ($v ? '1' : '0') : mb_strtolower((string) $v);
    }

    /**
     * @param  array<string, mixed>  $previous
     * @return list<string>
     */
    private function detectDrops(string $text, array $previous): array
    {
        // Only treat it as a drop when the sentence is actually negative.
        // «не важно» and «неважна» must both match, in any inflection.
        if (! preg_match('/(убер|удали|сбрось|забудь|отмени|\bбез\b|не\s*важ|не\s*нужн|не\s*критич|не\s*принципиал|любой|любая|любые)/u', $text)) {
            return [];
        }

        $dropped = [];
        foreach (self::DROPPABLE as $key => $words) {
            if ($this->isBlank($previous[$key] ?? null)) {
                continue;
            }
            foreach ($words as $w) {
                if (str_contains($text, $w)) {
                    $dropped[] = $key;
                    break;
                }
            }
        }

        // «неважно какой цвет» drops the colour; «убери всё» is a reset instead.
        return array_values(array_unique($dropped));
    }

    private function looksLikeReset(string $text): bool
    {
        if (preg_match('/^(сброс|заново|очисти|обнули|начн(ём|ем) заново)/u', $text)) {
            return true;
        }

        return (bool) preg_match('/(сбрось|убер[иь]|очисти|забудь)\s+(вс[её]|все фильтры|весь запрос)/u', $text);
    }

    private function looksLikeSmallTalk(string $text): bool
    {
        if (mb_strlen($text) > 60) {
            return false;
        }

        return (bool) preg_match(
            '/^(прив|здоров|здравств|добр(ый|ое) (день|утро|вечер)|хай|hi|hello|ку|ага|ок|окей|спасиб|благодар|пока|давай|понял|ясно|тест|проверка|\?+|!+)/u',
            $text
        );
    }

    private function looksLikeMeta(string $text): bool
    {
        return (bool) preg_match(
            '/(что ты (можешь|умеешь)|как (это|ты) работа|кто ты|сколько (у вас|в каталоге)|что есть в каталоге|какие категории|помощь|help|что дальше)/u',
            $text
        );
    }

    private function looksLikeSort(string $text): bool
    {
        return (bool) preg_match(
            '/(дешевл|подешев|дороже|ниже цен|по цене|быстрее|срочн|короче срок|по сроку|сравни|сравнени|топ\s*-?\s*\d)/u',
            $text
        );
    }

    /** Does the message name a product, a size, a grade or a quantity? */
    private function mentionsProduct(string $text): bool
    {
        if (preg_match('/\d{2,4}\s*[xх×*]\s*\d{2,4}/u', $text)) {
            return true;
        }
        if (preg_match('/\b[тt]-?\d{2}\b|\b[пp]-?\d{2}\b/ui', $text)) {
            return true;
        }
        if (preg_match('/\d+\s*(шт|штук|тыс|к\b|рулон|лист|паллет)/u', $text)) {
            return true;
        }

        return (bool) preg_match(
            '/(короб|гофро|картон|плёнк|пленк|стрейч|стретч|скотч|лент|пакет|паллет|поддон|лоток|этикетк|наполнител|упаков|тара|пузырчат|термоусад|зип|zip|самосбор|фефко|fefco|печат|логотип|бурый|белый|крафт|москв)/u',
            $text
        );
    }
}
