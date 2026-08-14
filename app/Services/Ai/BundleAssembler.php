<?php

namespace App\Services\Ai;

use App\Models\Offer;

/**
 * Multi-category wholesale order → per-line search + supplier bundles.
 *
 * A buyer who says «короб и лист» is placing one order with two lines, not
 * two unrelated searches. If one supplier can cover every line we recommend
 * a single RFQ rather than splitting the wholesale across factories.
 *
 * Legal / logistics (who invoices, who ships) stay out of this class — we
 * only say «this supplier has matching offers for every line».
 */
class BundleAssembler
{
    /**
     * Box-construction fields. A sheet / film line must not be scored against
     * «400×300×200 самосбор» — that is a different SKU.
     */
    private const BOX_ONLY = [
        'box_type',
        'length_mm',
        'width_mm',
        'height_mm',
        'liner_color',
        'print_needed',
        'branding_needed',
    ];

    /** Board grade / flute belong to corrugated, not tape or stretch. */
    private const BOARD_SLUGS = [
        'corrugated-boxes',
        'corrugated-sheet',
    ];

    public function __construct(
        private OfferMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array{
     *     matches: list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string, line_slug?: string}>,
     *     stats: array<string, mixed>,
     *     lines: list<array<string, mixed>>,
     *     order_plan: array<string, mixed>
     * }
     */
    public function search(array $query, int $perLine = 6): array
    {
        $slugs = $this->slugs($query);

        if (count($slugs) < 2) {
            $one = $this->matcher->search($query, 8);

            return [
                'matches' => $one['matches'],
                'stats' => $one['stats'],
                'lines' => [],
                'order_plan' => $this->emptyPlan(),
            ];
        }

        $lines = [];
        foreach ($slugs as $slug) {
            $lineQuery = $this->lineQuery($query, $slug);
            $result = $this->matcher->search($lineQuery, $perLine);
            $tagged = [];
            foreach ($result['matches'] as $row) {
                $row['line_slug'] = $slug;
                $tagged[] = $row;
            }
            $lines[] = [
                'id' => $slug,
                'slug' => $slug,
                'name' => $this->categoryName($slug),
                'query' => $lineQuery,
                'matches' => $tagged,
                'stats' => $result['stats'],
            ];
        }

        $plan = $this->assemble($lines);
        $matches = $this->flatten($lines, $plan);

        return [
            'matches' => $matches,
            'stats' => $this->mergeStats($slugs, $lines, $plan, $matches),
            'lines' => $lines,
            'order_plan' => $plan,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    public function slugs(array $query): array
    {
        $slugs = $query['category_slugs'] ?? [];

        return is_array($slugs) ? array_values(array_filter($slugs, fn ($s) => is_string($s) && $s !== '')) : [];
    }

    public function isMulti(array $query): bool
    {
        return count($this->slugs($query)) >= 2;
    }

    /**
     * One category, plus shared buyer constraints. Box geometry stays on boxes.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function lineQuery(array $query, string $slug): array
    {
        $line = $query;
        $line['category_slugs'] = [$slug];

        if ($slug !== 'corrugated-boxes') {
            foreach (self::BOX_ONLY as $field) {
                $line[$field] = null;
            }
        }

        if (! in_array($slug, self::BOARD_SLUGS, true)) {
            $line['board_grade'] = null;
            $line['flute_profile'] = null;
            $line['liner_color'] = null;
        }

        return $line;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function assemble(array $lines): array
    {
        $needed = count($lines);
        if ($needed < 2) {
            return $this->emptyPlan();
        }

        $supplierIndex = [];
        foreach ($lines as $line) {
            foreach ($line['matches'] as $row) {
                /** @var Offer $offer */
                $offer = $row['offer'];
                $sid = $offer->supplier_id;
                if (! $sid) {
                    continue;
                }
                if (! isset($supplierIndex[$sid])) {
                    $supplierIndex[$sid] = [
                        'id' => (int) $sid,
                        'name' => $offer->supplier?->commercial_name ?? 'Поставщик',
                        'logo_url' => $offer->supplier?->logo_url,
                        'picked' => [],
                        'depth' => 0,
                    ];
                }
                $supplierIndex[$sid]['depth']++;
                $slug = $line['slug'];
                $prev = $supplierIndex[$sid]['picked'][$slug] ?? null;
                if ($prev === null || $row['score'] > $prev['score']) {
                    $supplierIndex[$sid]['picked'][$slug] = $row;
                }
            }
        }

        $bundles = [];
        foreach ($supplierIndex as $entry) {
            $picked = $entry['picked'];
            $covers = count($picked);
            if ($covers === 0) {
                continue;
            }
            $scores = array_map(fn ($r) => (int) $r['score'], $picked);
            $min = min($scores);
            $avg = (int) round(array_sum($scores) / max(1, count($scores)));
            $kind = $covers === $needed ? 'full_cover' : 'partial';
            $weak = $min < OfferMatcher::TIER_CLOSE;

            $lineRows = [];
            foreach ($lines as $line) {
                $hit = $picked[$line['slug']] ?? null;
                $lineRows[] = [
                    'slug' => $line['slug'],
                    'name' => $line['name'],
                    'covered' => $hit !== null,
                    'match' => $hit,
                ];
            }

            $names = array_map(fn ($l) => mb_strtolower((string) $l['name']), $lines);
            $kit = $this->joinRu($names);

            $bundles[] = [
                'kind' => $kind,
                'supplier_id' => $entry['id'],
                'supplier_name' => $entry['name'],
                'logo_url' => $entry['logo_url'],
                'covers' => $covers,
                'needed' => $needed,
                'coverage_pct' => (int) round(100 * $covers / $needed),
                'min_score' => $min,
                'avg_score' => $avg,
                'weak_line' => $kind === 'full_cover' && $weak,
                'label' => $kind === 'full_cover'
                    ? 'Одна заявка · '.$entry['name']
                    : $entry['name'].' закрывает '.$covers.' из '.$needed,
                'reason' => $kind === 'full_cover'
                    ? ($weak
                        ? $entry['name'].' закрывает комплект ('.$kit.'), но по одной позиции совпадение слабое — проверьте gaps.'
                        : $entry['name'].' закрывает обе позиции: '.$kit.'. Одна оптовая заявка, одна отгрузка.')
                    : $entry['name'].' закрывает не весь комплект — без второй позиции заявка разъедется.',
                'depth' => (int) $entry['depth'],
                'lines' => $lineRows,
            ];
        }

        usort($bundles, function (array $a, array $b): int {
            $rank = ['full_cover' => 0, 'partial' => 1];
            $ka = $rank[$a['kind']] ?? 9;
            $kb = $rank[$b['kind']] ?? 9;
            if ($ka !== $kb) {
                return $ka <=> $kb;
            }
            if ($a['covers'] !== $b['covers']) {
                return $b['covers'] <=> $a['covers'];
            }
            if ($a['min_score'] !== $b['min_score']) {
                return $b['min_score'] <=> $a['min_score'];
            }
            if ($a['avg_score'] !== $b['avg_score']) {
                return $b['avg_score'] <=> $a['avg_score'];
            }

            return ($b['depth'] ?? 0) <=> ($a['depth'] ?? 0);
        });

        $full = array_values(array_filter($bundles, fn ($b) => $b['kind'] === 'full_cover'));
        $pack = $this->buildPack($lines, $supplierIndex);

        $recommended = null;
        if (($pack['rfq_count'] ?? 0) === 1 && ($pack['groups'][0]['supplier_id'] ?? null)) {
            $sid = (int) $pack['groups'][0]['supplier_id'];
            foreach ($full as $b) {
                if ((int) $b['supplier_id'] === $sid) {
                    $recommended = $b;
                    break;
                }
            }
        }

        return [
            'multi' => true,
            'needed' => $needed,
            'recommended' => $recommended,
            'bundles' => array_slice($bundles, 0, 4),
            'full_cover_count' => count($full),
            'split' => $this->splitAlternative($lines),
            'pack' => $pack,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyPlan(): array
    {
        return [
            'multi' => false,
            'needed' => 1,
            'recommended' => null,
            'bundles' => [],
            'full_cover_count' => 0,
            'split' => null,
            'pack' => null,
        ];
    }

    /**
     * Recommended-bundle offers first (one per line), then the rest of the
     * per-line shortlists. Deduped by offer id.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $plan
     * @return list<array{offer: Offer, score: int, reasons: list<string>, gaps: list<string>, tier: string, line_slug?: string}>
     */
    private function flatten(array $lines, array $plan): array
    {
        $out = [];
        $seen = [];

        $push = function (array $row) use (&$out, &$seen): void {
            $id = $row['offer']->id ?? null;
            if ($id === null || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $out[] = $row;
        };

        $pack = $plan['pack'] ?? null;
        if (is_array($pack)) {
            foreach ($pack['groups'] ?? [] as $group) {
                foreach ($group['lines'] ?? [] as $line) {
                    if (! empty($line['match'])) {
                        $push($line['match']);
                    }
                }
            }
        } else {
            $rec = $plan['recommended'] ?? null;
            if (is_array($rec)) {
                foreach ($rec['lines'] as $line) {
                    if (! empty($line['match'])) {
                        $push($line['match']);
                    }
                }
            }
        }

        foreach ($lines as $line) {
            foreach ($line['matches'] as $row) {
                $push($row);
            }
        }

        return array_slice($out, 0, 10);
    }

    /**
     * Independent best-per-line — what happens if we ignore bundling.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function splitAlternative(array $lines): array
    {
        $picks = [];
        $supplierIds = [];
        foreach ($lines as $line) {
            $best = $line['matches'][0] ?? null;
            $sid = $best['offer']->supplier_id ?? null;
            if ($sid) {
                $supplierIds[] = (int) $sid;
            }
            $picks[] = [
                'slug' => $line['slug'],
                'name' => $line['name'],
                'supplier_id' => $sid ? (int) $sid : null,
                'supplier_name' => $best['offer']->supplier?->commercial_name ?? null,
                'score' => $best['score'] ?? null,
                'offer_id' => $best['offer']->id ?? null,
                'title' => $best['offer']->offer_title ?? null,
            ];
        }
        $unique = array_values(array_unique($supplierIds));

        return [
            'supplier_count' => count($unique),
            'extra_rfqs' => max(0, count($unique) - 1),
            'lines' => $picks,
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $matches
     * @return array<string, mixed>
     */
    private function mergeStats(array $slugs, array $lines, array $plan, array $matches): array
    {
        $inCat = 0;
        $scored = 0;
        $exact = 0;
        $top = 0;
        foreach ($lines as $line) {
            $inCat += (int) ($line['stats']['offers_in_requested_category'] ?? 0);
            $scored += (int) ($line['stats']['scored_candidates'] ?? 0);
            $exact += (int) ($line['stats']['exact_count'] ?? 0);
            $top = max($top, (int) ($line['stats']['top_score'] ?? 0));
        }

        $total = $lines[0]['stats']['active_offers_total'] ?? 0;

        return [
            'active_offers_total' => $total,
            'offers_in_requested_category' => $inCat,
            'scored_candidates' => $scored,
            'returned' => count($matches),
            'relaxed' => null,
            'exact_count' => $exact,
            'top_score' => $top,
            'requested_categories' => $slugs,
            'lines' => count($lines),
            'full_cover_suppliers' => $plan['full_cover_count'] ?? 0,
            'recommended_supplier' => $plan['recommended']['supplier_name'] ?? null,
        ];
    }

    /**
     * Minimum RFQ cover: 1 factory if it is solid on every line, otherwise
     * 2–3 factories (e.g. box at A, sheet+tape+stretch at B) instead of 4 tickets.
     *
     * A line is "solid" at ≥ exact-tier (75), "ok" at ≥ close-tier (50).
     * A weak 53% box must not beat two strong RFQs.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, array<string, mixed>>  $supplierIndex
     * @return array<string, mixed>
     */
    private function buildPack(array $lines, array $supplierIndex): array
    {
        $neededSlugs = [];
        $nameBySlug = [];
        foreach ($lines as $line) {
            $neededSlugs[] = $line['slug'];
            $nameBySlug[$line['slug']] = $line['name'];
        }

        $players = [];
        foreach ($supplierIndex as $entry) {
            $ok = [];
            $solid = [];
            foreach ($entry['picked'] as $slug => $row) {
                if ($row['score'] >= OfferMatcher::TIER_CLOSE) {
                    $ok[$slug] = $row;
                }
                if ($row['score'] >= OfferMatcher::TIER_EXACT) {
                    $solid[$slug] = $row;
                }
            }
            if ($ok === []) {
                continue;
            }
            $players[] = [
                'id' => (int) $entry['id'],
                'name' => $entry['name'],
                'logo_url' => $entry['logo_url'],
                'ok' => $ok,
                'solid' => $solid,
            ];
        }

        $best = $this->bestCover($players, $neededSlugs, true)
            ?? $this->bestCover($players, $neededSlugs, false);

        if ($best === null) {
            return [
                'rfq_count' => 0,
                'kind' => 'uncovered',
                'all_solid' => false,
                'label' => 'Комплект не собирается',
                'reason' => 'В каталоге нет комбинации заводов, которая закрывает все позиции.',
                'groups' => [],
                'uncovered' => $neededSlugs,
            ];
        }

        $groupsBySid = [];
        foreach ($best['assigned'] as $slug => $row) {
            $sid = (int) $row['_sid'];
            if (! isset($groupsBySid[$sid])) {
                $groupsBySid[$sid] = [
                    'supplier_id' => $sid,
                    'supplier_name' => $row['_sname'],
                    'logo_url' => $row['_logo'] ?? null,
                    'lines' => [],
                    'scores' => [],
                ];
            }
            $match = $row;
            unset($match['_sid'], $match['_sname'], $match['_logo']);
            $groupsBySid[$sid]['lines'][] = [
                'slug' => $slug,
                'name' => $nameBySlug[$slug] ?? $slug,
                'covered' => true,
                'match' => $match,
            ];
            $groupsBySid[$sid]['scores'][] = (int) $row['score'];
        }

        $groups = [];
        $bits = [];
        foreach ($groupsBySid as $g) {
            $min = min($g['scores']);
            $avg = (int) round(array_sum($g['scores']) / max(1, count($g['scores'])));
            $lineNames = array_map(fn ($l) => mb_strtolower((string) $l['name']), $g['lines']);
            $groups[] = [
                'kind' => 'pack_group',
                'supplier_id' => $g['supplier_id'],
                'supplier_name' => $g['supplier_name'],
                'logo_url' => $g['logo_url'],
                'covers' => count($g['lines']),
                'needed' => count($neededSlugs),
                'coverage_pct' => (int) round(100 * count($g['lines']) / max(1, count($neededSlugs))),
                'min_score' => $min,
                'avg_score' => $avg,
                'weak_line' => $min < OfferMatcher::TIER_EXACT,
                'label' => 'Заявка · '.$g['supplier_name'],
                'reason' => $g['supplier_name'].' — '.$this->joinRu($lineNames),
                'lines' => $g['lines'],
            ];
            $bits[] = $g['supplier_name'].' ('.$this->joinRu($lineNames).')';
        }

        $k = count($groups);
        $allSolid = (bool) $best['solid'];
        $label = $k === 1 ? 'Одна заявка' : $k.' заявки вместо '.count($neededSlugs);

        if ($k === 1) {
            $reason = $allSolid
                ? $groups[0]['supplier_name'].' закрывает весь комплект одной оптовой заявкой.'
                : $groups[0]['supplier_name'].' закрывает все линии, но по одной совпадение слабое.';
        } else {
            $reason = 'Одним заводом все '.count($neededSlugs).' позиций сильно не закрыть. Собираю в '.$k
                .' заявки: '.implode('; ', $bits).'.';
        }

        return [
            'rfq_count' => $k,
            'kind' => $k === 1 ? 'full_cover' : 'split_cover',
            'all_solid' => $allSolid,
            'label' => $label,
            'reason' => $reason,
            'groups' => $groups,
            'uncovered' => [],
            'min_score' => $best['min'],
            'avg_score' => $best['avg'],
        ];
    }

    /**
     * Smallest k that covers every needed slug. Quality first (solid), then size.
     *
     * @param  list<array<string, mixed>>  $players
     * @param  list<string>  $needed
     * @return array{k: int, assigned: array<string, mixed>, min: int, avg: int, solid: bool}|null
     */
    private function bestCover(array $players, array $needed, bool $solidOnly): ?array
    {
        $pool = [];
        foreach ($players as $p) {
            $map = $solidOnly ? $p['solid'] : $p['ok'];
            if ($map === []) {
                continue;
            }
            $p['map'] = $map;
            $pool[] = $p;
        }

        $n = count($pool);
        $maxK = min(3, count($needed), $n);
        for ($k = 1; $k <= $maxK; $k++) {
            $best = null;
            $this->eachCombination($pool, $k, function (array $set) use ($needed, &$best): void {
                $assigned = [];
                foreach ($needed as $slug) {
                    $winner = null;
                    foreach ($set as $p) {
                        if (! isset($p['map'][$slug])) {
                            continue;
                        }
                        $row = $p['map'][$slug];
                        if ($winner === null || $row['score'] > $winner['score']) {
                            $winner = $row + [
                                '_sid' => $p['id'],
                                '_sname' => $p['name'],
                                '_logo' => $p['logo_url'] ?? null,
                            ];
                        }
                    }
                    if ($winner === null) {
                        return;
                    }
                    $assigned[$slug] = $winner;
                }
                $scores = array_map(fn ($r) => (int) $r['score'], $assigned);
                $cand = [
                    'k' => count($set),
                    'assigned' => $assigned,
                    'min' => min($scores),
                    'avg' => (int) round(array_sum($scores) / count($scores)),
                ];
                if ($best === null
                    || $cand['min'] > $best['min']
                    || ($cand['min'] === $best['min'] && $cand['avg'] > $best['avg'])) {
                    $best = $cand;
                }
            });
            if ($best !== null) {
                $best['solid'] = $solidOnly;

                return $best;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $items
     * @param  callable(list<mixed>): void  $visit
     */
    private function eachCombination(array $items, int $k, callable $visit): void
    {
        $n = count($items);
        if ($k <= 0 || $k > $n) {
            return;
        }
        $idx = range(0, $k - 1);
        while (true) {
            $set = [];
            foreach ($idx as $i) {
                $set[] = $items[$i];
            }
            $visit($set);
            $t = $k - 1;
            while ($t >= 0 && $idx[$t] === $n - $k + $t) {
                $t--;
            }
            if ($t < 0) {
                return;
            }
            $idx[$t]++;
            for ($i = $t + 1; $i < $k; $i++) {
                $idx[$i] = $idx[$i - 1] + 1;
            }
        }
    }

    public function categoryName(string $slug): string
    {
        $hit = collect(config('agora.categories', []))->firstWhere('slug', $slug);

        return is_array($hit) ? (string) ($hit['name'] ?? $slug) : $slug;
    }

    /**
     * @param  list<string>  $parts
     */
    private function joinRu(array $parts): string
    {
        $parts = array_values($parts);
        if ($parts === []) {
            return '';
        }
        if (count($parts) === 1) {
            return $parts[0];
        }
        $last = array_pop($parts);

        return implode(', ', $parts).' и '.$last;
    }
}
