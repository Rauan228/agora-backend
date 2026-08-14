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
                    ];
                }
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

            return $b['avg_score'] <=> $a['avg_score'];
        });

        $full = array_values(array_filter($bundles, fn ($b) => $b['kind'] === 'full_cover'));
        $recommended = $full[0] ?? null;

        return [
            'multi' => true,
            'needed' => $needed,
            'recommended' => $recommended,
            'bundles' => array_slice($bundles, 0, 4),
            'full_cover_count' => count($full),
            'split' => $this->splitAlternative($lines),
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

        $rec = $plan['recommended'] ?? null;
        if (is_array($rec)) {
            foreach ($rec['lines'] as $line) {
                if (! empty($line['match'])) {
                    $push($line['match']);
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
