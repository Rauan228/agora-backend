<?php

namespace App\Services\Ai;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Deterministic scoring of active offers against StructuredQuery.
 *
 * Score is normalized 0..100 relative to what the query actually asked for:
 * only criteria present in the query contribute to the maximum, so 90/100
 * means «matched 90% of your stated requirements», not «got a lot of bonuses».
 */
class OfferMatcher
{
    /** Weight of each criterion when it is present in the query. */
    private const WEIGHTS = [
        'category' => 30,
        'dimensions' => 25,
        'box_type' => 12,
        'geo' => 15,
        'board_grade' => 10,
        'flute_profile' => 6,
        'liner_color' => 6,
        'print' => 10,
        'qty_moq' => 10,
        'lead_time' => 8,
        'keywords' => 6,
    ];

    /** Always-on quality signals — small, and capped so they can't fake a match. */
    private const QUALITY_MAX = 8;

    /** Below this normalized score an offer is not a match at all. */
    private const MIN_SCORE = 35;

    /** A match at/above this is presented as «точное совпадение». */
    public const TIER_EXACT = 75;

    /** A match at/above this is presented as «близкий вариант». */
    public const TIER_CLOSE = 50;

    /**
     * @param  array<string, mixed>  $query
     * @return array{
     *     matches: list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>,
     *     stats: array<string, mixed>
     * }
     */
    public function search(array $query, int $limit = 8): array
    {
        $limit = max(1, min($limit, 20));

        $activeTotal = $this->baseQuery()->count();
        $slugs = $this->requestedSlugs($query);
        $inCategory = $slugs === []
            ? $activeTotal
            : $this->baseQuery()->whereHas('category', fn ($c) => $c->whereIn('slug', $slugs))->count();

        $scored = $this->scoreCandidates($query, $slugs);
        $relaxed = null;

        // Nothing passed the bar inside the requested category — widen to the
        // whole catalog rather than showing an empty screen, but say so.
        if ($scored === [] && $slugs !== []) {
            $wider = $this->scoreCandidates($query, []);
            if ($wider !== []) {
                $scored = $wider;
                $relaxed = 'category';
            }
        }

        // Still nothing — show the closest offers we have, clearly labelled.
        if ($scored === []) {
            $scored = $this->nearest($query, $slugs, $limit);
            $relaxed = $scored === [] ? null : 'all_criteria';
        }

        $matches = array_slice($scored, 0, $limit);

        return [
            'matches' => $matches,
            'stats' => [
                'active_offers_total' => $activeTotal,
                'offers_in_requested_category' => $inCategory,
                'scored_candidates' => count($scored),
                'returned' => count($matches),
                'relaxed' => $relaxed,
                'exact_count' => count(array_filter($matches, fn ($m) => $m['tier'] === 'exact')),
                'top_score' => $matches === [] ? 0 : $matches[0]['score'],
                'requested_categories' => $slugs,
            ],
        ];
    }

    /**
     * Stats shape for turns where no search ran (small talk, meta questions).
     *
     * @return array<string, mixed>
     */
    public function emptyStats(): array
    {
        $total = $this->baseQuery()->count();

        return [
            'active_offers_total' => $total,
            'offers_in_requested_category' => $total,
            'scored_candidates' => 0,
            'returned' => 0,
            'relaxed' => null,
            'exact_count' => 0,
            'top_score' => 0,
            'requested_categories' => [],
            'searched' => false,
        ];
    }

    /**
     * Backwards-compatible entry point.
     *
     * @param  array<string, mixed>  $query
     * @return list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>
     */
    public function match(array $query, int $limit = 8): array
    {
        return $this->search($query, $limit)['matches'];
    }

    /**
     * @return Builder<Offer>
     */
    private function baseQuery(): Builder
    {
        return Offer::query()
            ->where('is_active', true)
            ->whereHas('supplier', fn ($s) => $s->where('is_active', true));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function requestedSlugs(array $query): array
    {
        $slugs = $query['category_slugs'] ?? [];

        return is_array($slugs) ? array_values(array_filter($slugs)) : [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  list<string>  $slugs
     * @return list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>
     */
    private function scoreCandidates(array $query, array $slugs): array
    {
        $q = $this->baseQuery()->with(['supplier.cities', 'category']);

        if ($slugs !== []) {
            $q->whereHas('category', fn ($c) => $c->whereIn('slug', $slugs));
        }

        /** @var Collection<int, Offer> $offers */
        $offers = $q->limit(500)->get();

        $scored = [];
        foreach ($offers as $offer) {
            $result = $this->score($offer, $query);
            if ($result['score'] < self::MIN_SCORE) {
                continue;
            }
            $scored[] = [
                'offer' => $offer,
                'score' => $result['score'],
                'reasons' => $result['reasons'],
                'gaps' => $result['gaps'],
                'unknown' => $result['unknown'],
                'tier' => $this->tier($result['score'], $result['conflicts']),
            ];
        }

        usort($scored, function ($a, $b) {
            if ($b['score'] !== $a['score']) {
                return $b['score'] <=> $a['score'];
            }

            // Tie-break: cheaper first, hidden prices last.
            return $this->sortablePrice($a['offer']) <=> $this->sortablePrice($b['offer']);
        });

        return $scored;
    }

    /**
     * Last-resort list when nothing clears the scoring bar.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $slugs
     * @return list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string}>
     */
    private function nearest(array $query, array $slugs, int $limit): array
    {
        $offers = $this->baseQuery()
            ->with(['supplier.cities', 'category'])
            ->when($slugs !== [], fn ($qq) => $qq->whereHas('category', fn ($c) => $c->whereIn('slug', $slugs)))
            ->limit(200)
            ->get();

        $rows = [];
        foreach ($offers as $offer) {
            $result = $this->score($offer, $query);
            $rows[] = [
                'offer' => $offer,
                'score' => $result['score'],
                'reasons' => $result['reasons'],
                'gaps' => $result['gaps'],
                'unknown' => $result['unknown'],
                'tier' => 'fallback',
            ];
        }

        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($rows, 0, $limit);
    }

    private function tier(int $score, int $conflicts = 0): string
    {
        // Anything contradicting a stated requirement can never be «exact».
        if ($score >= self::TIER_EXACT && $conflicts === 0) {
            return 'exact';
        }
        if ($score >= self::TIER_CLOSE) {
            return 'close';
        }

        return 'weak';
    }

    private function sortablePrice(Offer $offer): float
    {
        if ($offer->price_hidden || $offer->price_value === null) {
            return PHP_FLOAT_MAX;
        }

        return (float) $offer->price_value;
    }

    /**
     * Scores one offer. Only criteria the buyer actually stated count toward
     * the denominator, so the percentage is meaningful.
     *
     * @param  array<string, mixed>  $query
     * @return array{score: int, reasons: list<string>, gaps: list<string>}
     */
    private function score(Offer $offer, array $query): array
    {
        $earned = 0.0;
        $possible = 0.0;
        $reasons = [];
        $gaps = [];
        /** @var list<string> $unknown criteria the card simply doesn't state */
        $unknown = [];
        /** @var int $conflicts criteria that actively contradict the request */
        $conflicts = 0;
        $specs = $offer->specs ?? [];

        // ---- category ------------------------------------------------------
        $slugs = $this->requestedSlugs($query);
        if ($slugs !== []) {
            $possible += self::WEIGHTS['category'];
            $slug = $offer->category?->slug;
            if ($slug !== null && in_array($slug, $slugs, true)) {
                $earned += self::WEIGHTS['category'];
                $reasons[] = 'Категория: '.$offer->category->name;
            } else {
                $gaps[] = 'другая категория'.($offer->category ? ' ('.$offer->category->name.')' : '');
                $conflicts++;
            }
        }

        // ---- dimensions ----------------------------------------------------
        $wantedDims = array_filter(
            ['length_mm' => $query['length_mm'] ?? null, 'width_mm' => $query['width_mm'] ?? null, 'height_mm' => $query['height_mm'] ?? null]
        );
        if ($wantedDims !== []) {
            $possible += self::WEIGHTS['dimensions'];
            $per = self::WEIGHTS['dimensions'] / count($wantedDims);
            $tol = max(1, (int) ($query['size_tolerance_pct'] ?? 10)) / 100;
            // Cards in the wild use both the canonical `box_*` keys and shorter
            // legacy variants — read every spelling or real offers score as
            // «dimensions not filled in» when they actually are.
            $specKeys = [
                'length_mm' => ['box_inner_length_mm', 'inner_length_mm', 'sheet_length_mm', 'box_outer_length_mm', 'outer_length_mm', 'length_mm'],
                'width_mm' => ['box_inner_width_mm', 'inner_width_mm', 'sheet_width_mm', 'box_outer_width_mm', 'outer_width_mm', 'width_mm'],
                'height_mm' => ['box_inner_height_mm', 'inner_height_mm', 'box_outer_height_mm', 'outer_height_mm', 'height_mm'],
            ];
            $labels = ['length_mm' => 'Д', 'width_mm' => 'Ш', 'height_mm' => 'В'];

            $hits = [];
            $missingSpec = false;
            $mismatch = false;
            foreach ($wantedDims as $key => $want) {
                $actual = $this->firstSpecNumber($specs, $specKeys[$key]);
                if ($actual === null) {
                    // Unknown is not a match: an unfilled card must not score
                    // like a confirmed fit.
                    $missingSpec = true;
                    continue;
                }
                $delta = abs($actual - (float) $want) / max((float) $want, 1);
                if ($delta <= $tol) {
                    $earned += $per;
                    $hits[] = $labels[$key].' '.(int) $actual;
                } elseif ($delta <= $tol * 2) {
                    $earned += $per * 0.4;
                    $hits[] = $labels[$key].' '.(int) $actual.' (±)';
                } else {
                    $mismatch = true;
                }
            }
            if ($hits !== []) {
                $reasons[] = 'Размеры: '.implode(' × ', $hits).' мм';
            }
            if ($missingSpec) {
                $gaps[] = 'размеры не заполнены в карточке';
                $unknown[] = 'размеры';
            }
            if ($mismatch) {
                $gaps[] = 'размеры не совпадают';
                // A wrong size is disqualifying for packaging — penalise hard.
                $conflicts++;
            }
        }

        // ---- box type ------------------------------------------------------
        if (! empty($query['box_type'])) {
            $possible += self::WEIGHTS['box_type'];
            $want = (string) $query['box_type'];
            $bt = trim((string) ($specs['box_type'] ?? $specs['type'] ?? ''));
            if ($bt !== '' && $this->looseMatch($bt, $want)) {
                $earned += self::WEIGHTS['box_type'];
                $reasons[] = 'Тип: '.$bt;
            } elseif ($bt === '' && mb_stripos($offer->offer_title, $want) !== false) {
                $earned += self::WEIGHTS['box_type'] * 0.5;
                $reasons[] = 'Тип указан в названии';
            } else {
                $gaps[] = $bt !== '' ? 'тип: '.$bt : 'тип не указан';
            }
        }

        // ---- geo -----------------------------------------------------------
        $wantMsk = ($query['delivery_moscow'] ?? null) === true
            || in_array(mb_strtolower((string) ($query['city'] ?? '')), ['москва', 'мск', 'мо'], true);
        if ($wantMsk) {
            $possible += self::WEIGHTS['geo'];
            $regionHit = false;
            foreach ($offer->delivery_regions ?? [] as $r) {
                if (preg_match('/москв|\bмо\b|цфо|росси/ui', (string) $r)) {
                    $regionHit = true;
                    break;
                }
            }
            $cityHit = (bool) $offer->supplier?->cities?->contains(
                fn ($c) => (bool) preg_match('/москв/ui', (string) $c->name)
            );
            if ($regionHit || $cityHit) {
                $earned += self::WEIGHTS['geo'];
                $reasons[] = 'Доставка / присутствие: Москва и МО';
            } else {
                $gaps[] = 'нет подтверждённой доставки в Москву';
                $unknown[] = 'доставка';
            }
        }

        // ---- board grade ---------------------------------------------------
        if (! empty($query['board_grade'])) {
            $possible += self::WEIGHTS['board_grade'];
            $g = trim((string) ($specs['box_board_grade'] ?? $specs['board_grade'] ?? $specs['grade'] ?? ''));
            if ($g !== '' && $this->normGrade($g) === $this->normGrade((string) $query['board_grade'])) {
                $earned += self::WEIGHTS['board_grade'];
                $reasons[] = 'Марка картона: '.$g;
            } elseif ($g !== '') {
                $gaps[] = 'марка '.$g.' вместо запрошенной';
                $conflicts++;
            } else {
                $gaps[] = 'марка картона не указана';
                $unknown[] = 'марка';
            }
        }

        // ---- flute ---------------------------------------------------------
        if (! empty($query['flute_profile'])) {
            $possible += self::WEIGHTS['flute_profile'];
            $f = trim((string) ($specs['box_flute_profile'] ?? $specs['flute_profile'] ?? $specs['profile'] ?? ''));
            if ($f !== '' && mb_strtoupper($f) === mb_strtoupper((string) $query['flute_profile'])) {
                $earned += self::WEIGHTS['flute_profile'];
                $reasons[] = 'Профиль: '.$f;
            } else {
                $gaps[] = 'профиль гофры не совпадает';
            }
        }

        // ---- liner color ---------------------------------------------------
        if (! empty($query['liner_color'])) {
            $possible += self::WEIGHTS['liner_color'];
            $c = trim((string) ($specs['box_liner_color'] ?? $specs['liner_color'] ?? $specs['color'] ?? ''));
            if ($c !== '' && $this->looseMatch($c, (string) $query['liner_color'])) {
                $earned += self::WEIGHTS['liner_color'];
                $reasons[] = 'Цвет: '.$c;
            } else {
                $gaps[] = $c !== '' ? 'цвет '.$c : 'цвет не указан';
            }
        }

        // ---- print / branding ----------------------------------------------
        if (($query['print_needed'] ?? null) === true || ($query['branding_needed'] ?? null) === true) {
            $possible += self::WEIGHTS['print'];
            $print = filter_var(
                $specs['box_print_available'] ?? $specs['print_available'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );
            if ($print || $offer->branding_available) {
                $earned += self::WEIGHTS['print'];
                $reasons[] = 'Печать и брендирование доступны';
            } elseif ($offer->custom_manufacturing) {
                $earned += self::WEIGHTS['print'] * 0.5;
                $reasons[] = 'Возможно изготовление на заказ';
            } else {
                $gaps[] = 'печать не заявлена';
            }
        }

        // ---- volume vs MOQ --------------------------------------------------
        $qty = $query['qty'] ?? null;
        $moqMax = $query['moq_max'] ?? null;
        if ($qty || $moqMax) {
            $possible += self::WEIGHTS['qty_moq'];
            $moq = (int) $offer->moq_value;
            $ceiling = (int) ($moqMax ?: $qty);
            if ($moq > 0 && $moq <= $ceiling) {
                $earned += self::WEIGHTS['qty_moq'];
                $reasons[] = 'MOQ '.$moq.' — проходит под ваш объём';
            } elseif ($moq > 0 && $moq <= $ceiling * 1.5) {
                $earned += self::WEIGHTS['qty_moq'] * 0.4;
                $gaps[] = 'MOQ '.$moq.' чуть выше объёма';
            } else {
                $gaps[] = 'MOQ '.$moq.' выше вашего объёма';
                $conflicts++;
            }
        }

        // ---- lead time ------------------------------------------------------
        if (! empty($query['lead_time_days_max'])) {
            $possible += self::WEIGHTS['lead_time'];
            $lead = ((int) ($offer->production_lead_days ?? 0)) + ((int) ($offer->delivery_lead_days ?? 0));
            if ($lead > 0 && $lead <= (int) $query['lead_time_days_max']) {
                $earned += self::WEIGHTS['lead_time'];
                $reasons[] = 'Срок ~'.$lead.' дн.';
            } else {
                $gaps[] = $lead > 0 ? 'срок ~'.$lead.' дн. — дольше нужного' : 'срок не указан';
            }
        }

        // ---- keywords -------------------------------------------------------
        $keywords = array_values(array_filter($query['keywords'] ?? []));
        if ($keywords !== []) {
            $possible += self::WEIGHTS['keywords'];
            $haystack = mb_strtolower($offer->offer_title.' '.($offer->description_short ?? ''));
            $hit = [];
            foreach ($keywords as $kw) {
                if (mb_stripos($haystack, mb_strtolower((string) $kw)) !== false) {
                    $hit[] = (string) $kw;
                }
            }
            if ($hit !== []) {
                $earned += self::WEIGHTS['keywords'] * min(1, count($hit) / count($keywords));
                $reasons[] = 'Упомянуто: '.implode(', ', $hit);
            }
        }

        // ---- always-on quality signals (small, capped) ----------------------
        $possible += self::QUALITY_MAX;
        $quality = 0.0;
        if ($offer->stock_status === 'В наличии') {
            $quality += 4;
            $reasons[] = 'В наличии';
        }
        if ($offer->photo_path) {
            $quality += 2;
        }
        if ($offer->price_hidden !== true && $offer->price_value !== null) {
            $quality += 2;
        } else {
            $gaps[] = 'цена по запросу';
        }
        $earned += min(self::QUALITY_MAX, $quality);

        // Query said nothing measurable — everything is equally «unranked», so
        // cap the score low rather than implying a real fit.
        if ($possible <= self::QUALITY_MAX) {
            return [
                'score' => (int) round(($earned / max($possible, 1)) * 40),
                'reasons' => array_values(array_unique($reasons)),
                'gaps' => array_values(array_unique($gaps)),
                'conflicts' => $conflicts,
                'unknown' => array_values(array_unique($unknown)),
            ];
        }

        $score = ($earned / $possible) * 100;

        // Each hard contradiction (wrong size, wrong category, MOQ above the
        // order) cuts the score — a card that conflicts on a stated requirement
        // is not a «close» match no matter how much else lines up.
        if ($conflicts > 0) {
            $score *= max(0.35, 1 - 0.3 * $conflicts);
        }

        return [
            'score' => (int) round(max(0, min(100, $score))),
            'reasons' => array_values(array_unique($reasons)),
            'gaps' => array_values(array_unique($gaps)),
            'conflicts' => $conflicts,
            'unknown' => array_values(array_unique($unknown)),
        ];
    }

    /**
     * @param  array<string, mixed>  $specs
     * @param  list<string>  $keys
     */
    private function firstSpecNumber(array $specs, array $keys): ?float
    {
        foreach ($keys as $k) {
            if (isset($specs[$k]) && is_numeric($specs[$k])) {
                return (float) $specs[$k];
            }
        }

        return null;
    }

    /** Substring match in either direction, case- and ё-insensitive. */
    private function looseMatch(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        $a = $this->fold($a);
        $b = $this->fold($b);

        return mb_stripos($a, $b) !== false || mb_stripos($b, $a) !== false;
    }

    /** «Четырёхклапанный» and «Четырехклапанный» must compare equal. */
    private function fold(string $s): string
    {
        return str_replace(['ё', 'Ё'], ['е', 'Е'], mb_strtolower(trim($s)));
    }

    private function normGrade(string $g): string
    {
        $g = mb_strtoupper(trim($g));
        $g = str_replace(['T', 'P', ' '], ['Т', 'П', ''], $g);
        if (preg_match('/^(Т|П)-?(\d{2})$/u', $g, $m)) {
            return $m[1].'-'.$m[2];
        }

        return $g;
    }
}
