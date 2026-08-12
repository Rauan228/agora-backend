<?php

namespace App\Services\Ai;

use App\Models\Offer;
use Illuminate\Support\Collection;

/**
 * Deterministic scoring of active offers against StructuredQuery.
 */
class OfferMatcher
{
    /**
     * @param  array<string, mixed>  $query
     * @return list<array{offer: Offer, score: int, reasons: list<string>}>
     */
    public function match(array $query, int $limit = 8): array
    {
        $limit = max(1, min($limit, 20));

        $q = Offer::query()
            ->where('is_active', true)
            ->whereHas('supplier', fn ($s) => $s->where('is_active', true))
            ->with(['supplier.cities', 'category']);

        $slugs = $query['category_slugs'] ?? [];
        if ($slugs !== []) {
            $q->whereHas('category', fn ($c) => $c->whereIn('slug', $slugs));
        }

        // Soft text filter
        $keywords = $query['keywords'] ?? [];
        if ($keywords !== []) {
            $q->where(function ($qq) use ($keywords) {
                foreach ($keywords as $kw) {
                    $qq->orWhere('offer_title', 'like', '%'.$kw.'%')
                        ->orWhere('description_short', 'like', '%'.$kw.'%');
                }
            });
        }

        /** @var Collection<int, Offer> $offers */
        $offers = $q->limit(200)->get();

        $scored = [];
        foreach ($offers as $offer) {
            [$score, $reasons] = $this->score($offer, $query);
            if ($score < 15) {
                continue;
            }
            $scored[] = [
                'offer' => $offer,
                'score' => $score,
                'reasons' => $reasons,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        // If nothing in category filter, retry without category (broader)
        if ($scored === [] && $slugs !== []) {
            return $this->match(array_merge($query, ['category_slugs' => []]), $limit);
        }

        // Still empty — return top active by price in preferred category without hard score cut
        if ($scored === []) {
            $fallback = Offer::query()
                ->where('is_active', true)
                ->whereHas('supplier', fn ($s) => $s->where('is_active', true))
                ->with(['supplier.cities', 'category'])
                ->when($slugs !== [], fn ($qq) => $qq->whereHas('category', fn ($c) => $c->whereIn('slug', $slugs)))
                ->orderBy('price_value')
                ->limit($limit)
                ->get();

            foreach ($fallback as $offer) {
                $scored[] = [
                    'offer' => $offer,
                    'score' => 10,
                    'reasons' => ['Ближайшие варианты в каталоге (точный матч не найден)'],
                ];
            }
        }

        return array_slice($scored, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{0: int, 1: list<string>}
     */
    private function score(Offer $offer, array $query): array
    {
        $score = 0;
        $reasons = [];
        $specs = $offer->specs ?? [];

        $slug = $offer->category?->slug;
        $slugs = $query['category_slugs'] ?? [];
        if ($slugs === [] || ($slug && in_array($slug, $slugs, true))) {
            $score += 30;
            if ($offer->category) {
                $reasons[] = 'Категория: '.$offer->category->name;
            }
        }

        // geo
        $regions = $offer->delivery_regions ?? [];
        $wantMsk = $query['delivery_moscow'] === true
            || in_array(mb_strtolower((string) ($query['city'] ?? '')), ['москва', 'мск', 'мо'], true);

        if ($wantMsk) {
            $hit = false;
            foreach ($regions as $r) {
                if (preg_match('/москв|мо\b|цфо|россия/ui', (string) $r)) {
                    $hit = true;
                    break;
                }
            }
            $cityHit = $offer->supplier?->cities?->contains(
                fn ($c) => preg_match('/москв/ui', (string) $c->name)
            );
            if ($hit || $cityHit) {
                $score += 15;
                $reasons[] = 'Доставка / присутствие: Москва/МО';
            }
        }

        // dimensions (boxes / sheets)
        $tol = max(1, (int) ($query['size_tolerance_pct'] ?? 10)) / 100;
        $dimKeys = [
            'length_mm' => ['box_inner_length_mm', 'sheet_length_mm', 'box_outer_length_mm'],
            'width_mm' => ['box_inner_width_mm', 'sheet_width_mm', 'box_outer_width_mm'],
            'height_mm' => ['box_inner_height_mm', 'box_outer_height_mm'],
        ];
        $dimScore = 0;
        $dimReasons = [];
        foreach ($dimKeys as $qKey => $specKeys) {
            if (empty($query[$qKey])) {
                continue;
            }
            $want = (float) $query[$qKey];
            $actual = $this->firstSpecNumber($specs, $specKeys);
            if ($actual === null) {
                continue;
            }
            $delta = abs($actual - $want) / max($want, 1);
            if ($delta <= $tol) {
                $dimScore += 8;
                $dimReasons[] = sprintf('%s ≈ %s мм', $qKey === 'length_mm' ? 'Д' : ($qKey === 'width_mm' ? 'Ш' : 'В'), (int) $actual);
            } elseif ($delta <= $tol * 2) {
                $dimScore += 3;
            }
        }
        if ($dimScore > 0) {
            $score += min(25, $dimScore);
            if ($dimReasons !== []) {
                $reasons[] = 'Размеры: '.implode(', ', $dimReasons);
            }
        }

        // box type
        if (! empty($query['box_type'])) {
            $bt = (string) ($specs['box_type'] ?? '');
            if ($bt !== '' && mb_stripos($bt, (string) $query['box_type']) !== false
                || $bt !== '' && mb_stripos((string) $query['box_type'], $bt) !== false) {
                $score += 12;
                $reasons[] = 'Тип: '.$bt;
            } elseif ($bt === '' && mb_stripos($offer->offer_title, (string) $query['box_type']) !== false) {
                $score += 6;
                $reasons[] = 'Тип в названии';
            }
        }

        // board grade
        if (! empty($query['board_grade'])) {
            $g = (string) ($specs['box_board_grade'] ?? $specs['board_grade'] ?? '');
            if ($g !== '' && $this->normGrade($g) === $this->normGrade((string) $query['board_grade'])) {
                $score += 10;
                $reasons[] = 'Марка: '.$g;
            }
        }

        // flute
        if (! empty($query['flute_profile'])) {
            $f = (string) ($specs['box_flute_profile'] ?? $specs['flute_profile'] ?? '');
            if ($f !== '' && mb_strtoupper($f) === mb_strtoupper((string) $query['flute_profile'])) {
                $score += 6;
                $reasons[] = 'Профиль: '.$f;
            }
        }

        // color
        if (! empty($query['liner_color'])) {
            $c = (string) ($specs['box_liner_color'] ?? '');
            if ($c !== '' && mb_stripos($c, (string) $query['liner_color']) !== false) {
                $score += 6;
                $reasons[] = 'Цвет: '.$c;
            }
        }

        // print / branding
        if ($query['print_needed'] === true || $query['branding_needed'] === true) {
            $print = (bool) ($specs['box_print_available'] ?? false);
            if ($print || $offer->branding_available) {
                $score += 10;
                $reasons[] = 'Печать / брендирование доступны';
            } elseif ($offer->custom_manufacturing) {
                $score += 5;
                $reasons[] = 'Возможно изготовление на заказ';
            }
        }

        // MOQ
        $qty = $query['qty'] ?? null;
        $moqMax = $query['moq_max'] ?? null;
        if ($qty) {
            if ($offer->moq_value <= (int) $qty) {
                $score += 10;
                $reasons[] = 'MOQ '.$offer->moq_value.' ≤ вашего объёма';
            } elseif ($offer->moq_value <= (int) $qty * 1.5) {
                $score += 4;
            }
        }
        if ($moqMax && $offer->moq_value <= (int) $moqMax) {
            $score += 5;
        }

        // lead time
        if (! empty($query['lead_time_days_max'])) {
            $lead = ($offer->production_lead_days ?? 0) + ($offer->delivery_lead_days ?? 0);
            if ($lead > 0 && $lead <= (int) $query['lead_time_days_max']) {
                $score += 8;
                $reasons[] = "Срок ~{$lead} дн.";
            }
        }

        // stock
        if ($offer->stock_status === 'В наличии') {
            $score += 5;
            $reasons[] = 'В наличии';
        }

        // completeness
        if ($offer->photo_path) {
            $score += 2;
        }
        if ($offer->supplier?->logo_path) {
            $score += 1;
        }

        // title keyword soft boost
        foreach ($query['keywords'] ?? [] as $kw) {
            if (mb_stripos($offer->offer_title, (string) $kw) !== false) {
                $score += 3;
            }
        }

        return [$score, array_values(array_unique($reasons))];
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

    private function normGrade(string $g): string
    {
        $g = mb_strtoupper(trim($g));
        $g = str_replace(['T', 'P', ' '], ['Т', 'П', ''], $g);
        if (preg_match('/^(Т|П)(\d{2})$/u', $g, $m)) {
            return $m[1].'-'.$m[2];
        }

        return $g;
    }
}
