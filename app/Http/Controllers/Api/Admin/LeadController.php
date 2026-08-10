<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\LeadResource;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRM лидов: источник → карточка → обзвон.
 * Импорт CSV (Контур/Excel/ручной сбор). Без парсинга карт/классифайдов.
 */
class LeadController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('call_status', '');
        $source = (string) $request->query('source', '');
        $region = trim((string) $request->query('region', ''));
        $category = (string) $request->query('category_slug', '');

        $leads = Lead::query()
            ->with('supplier')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('company_name', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('phone_normalized', 'like', "%{$q}%")
                        ->orWhere('inn', 'like', "%{$q}%")
                        ->orWhere('website', 'like', "%{$q}%")
                        ->orWhere('contact_person', 'like', "%{$q}%");
                });
            })
            ->when($status !== '', fn ($query) => $query->where('call_status', $status))
            ->when($source !== '', fn ($query) => $query->where('source', $source))
            ->when($region !== '', fn ($query) => $query->where('region', 'like', "%{$region}%"))
            ->when($category !== '', fn ($query) => $query->where('category_slug', $category))
            ->orderByRaw("CASE call_status
                WHEN 'to_call' THEN 1
                WHEN 'new' THEN 2
                WHEN 'callback' THEN 3
                WHEN 'no_answer' THEN 4
                WHEN 'interested' THEN 5
                WHEN 'sent_kp' THEN 6
                ELSE 10 END")
            ->orderByDesc('updated_at')
            ->paginate(min(max((int) $request->integer('per_page', 30), 1), 100));

        return LeadResource::collection($leads);
    }

    public function stats()
    {
        $byStatus = Lead::query()
            ->selectRaw('call_status, count(*) as cnt')
            ->groupBy('call_status')
            ->pluck('cnt', 'call_status');

        return response()->json([
            'total' => Lead::count(),
            'by_status' => $byStatus,
            'to_call_queue' => Lead::whereIn('call_status', ['new', 'to_call', 'callback', 'no_answer'])->count(),
            'sources' => Lead::SOURCES,
            'call_statuses' => Lead::CALL_STATUSES,
        ]);
    }

    public function show(Lead $lead)
    {
        return new LeadResource($lead->load('supplier'));
    }

    public function store(Request $request)
    {
        $data = $this->validateLead($request);
        $data = $this->applyDedupeFlags($data);

        $lead = Lead::create($data);

        return (new LeadResource($lead->load('supplier')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Lead $lead)
    {
        $data = $this->validateLead($request, $lead);

        if ($request->has('call_status') && $request->input('call_status') !== $lead->call_status) {
            if (in_array($request->input('call_status'), ['no_answer', 'callback', 'interested', 'sent_kp', 'rejected', 'wrong_number', 'onboarded'], true)) {
                $data['last_called_at'] = now();
            }
        }

        $lead->update($data);

        return new LeadResource($lead->fresh()->load('supplier'));
    }

    public function destroy(Lead $lead)
    {
        $lead->delete();

        return response()->json(null, 204);
    }

    /**
     * CSV import. Header row required.
     * Columns (any order, case-insensitive):
     * company_name*, phone, email, website, city, region, inn, contact_person,
     * category_slug, source, source_url, source_query, notes, call_status, external_id
     */
    public function importCsv(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'default_source' => ['nullable', 'string', Rule::in(Lead::SOURCES)],
            'default_category_slug' => ['nullable', 'string', 'max:64'],
            'default_region' => ['nullable', 'string', 'max:255'],
            'skip_duplicates' => ['sometimes', 'boolean'],
        ]);

        $defaultSource = $request->input('default_source', 'csv');
        $defaultCategory = $request->input('default_category_slug', 'corrugated-boxes');
        $defaultRegion = $request->input('default_region', 'Москва');
        $skipDupes = $request->boolean('skip_duplicates', true);

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Не удалось прочитать файл'], 422);
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);

            return response()->json(['message' => 'Пустой файл'], 422);
        }
        // BOM
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
        $delimiter = str_contains($firstLine, ';') ? ';' : ',';
        $headers = array_map(function ($h) {
            return strtolower(trim((string) $h));
        }, str_getcsv($firstLine, $delimiter));

        $created = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 1;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $assoc = [];
            foreach ($headers as $i => $key) {
                $assoc[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            $company = $assoc['company_name'] ?? $assoc['company'] ?? $assoc['name'] ?? $assoc['организация'] ?? $assoc['название'] ?? '';
            if ($company === '') {
                $errors[] = "Строка {$rowNum}: нет company_name";
                $skipped++;

                continue;
            }

            $phone = $assoc['phone'] ?? $assoc['телефон'] ?? $assoc['tel'] ?? null;
            $inn = $assoc['inn'] ?? $assoc['инн'] ?? null;
            $payload = [
                'company_name' => $company,
                'phone' => $phone ?: null,
                'email' => $assoc['email'] ?? $assoc['почта'] ?? null,
                'website' => $assoc['website'] ?? $assoc['site'] ?? $assoc['сайт'] ?? null,
                'city' => $assoc['city'] ?? $assoc['город'] ?? null,
                'region' => $assoc['region'] ?? $assoc['регион'] ?? $defaultRegion,
                'inn' => $inn ?: null,
                'contact_person' => $assoc['contact_person'] ?? $assoc['contact'] ?? $assoc['контакт'] ?? null,
                'category_slug' => $assoc['category_slug'] ?? $assoc['category'] ?? $defaultCategory,
                'source' => $assoc['source'] ?? $defaultSource,
                'source_url' => $assoc['source_url'] ?? $assoc['url'] ?? $assoc['ссылка'] ?? null,
                'source_query' => $assoc['source_query'] ?? $assoc['query'] ?? null,
                'notes' => $assoc['notes'] ?? $assoc['заметки'] ?? null,
                'call_status' => $assoc['call_status'] ?? 'new',
                'external_id' => $assoc['external_id'] ?? $assoc['id'] ?? null,
            ];

            if (! in_array($payload['source'], Lead::SOURCES, true)) {
                $payload['source'] = $defaultSource;
            }
            if (! in_array($payload['call_status'], Lead::CALL_STATUSES, true)) {
                $payload['call_status'] = 'new';
            }

            $norm = Lead::normalizePhone($payload['phone']);
            if ($skipDupes && $this->isDuplicate($norm, $payload['inn'] ?? null, $payload['external_id'] ?? null)) {
                $skipped++;

                continue;
            }

            Lead::create($payload);
            $created++;
        }
        fclose($handle);

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 50),
        ]);
    }

    /** Быстрый шаблон CSV. */
    public function importTemplate()
    {
        $csv = "company_name,phone,email,website,city,region,inn,contact_person,category_slug,source,source_url,source_query,notes,call_status,external_id\n";
        $csv .= "ООО Пример Упаковка,+74951234567,sales@example.com,https://example.com,Москва,Москва,7707083893,Иван,corrugated-boxes,csv,https://example.com,,Нашли через поиск,new,\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="leads_import_template.csv"',
        ]);
    }

    private function validateLead(Request $request, ?Lead $lead = null): array
    {
        return $request->validate([
            'company_name' => [$lead ? 'sometimes' : 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'phone_extra' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'max:12'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'category_slug' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', Rule::in(Lead::SOURCES)],
            'source_url' => ['nullable', 'string', 'max:1000'],
            'source_query' => ['nullable', 'string', 'max:500'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'call_status' => ['nullable', 'string', Rule::in(Lead::CALL_STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'call_notes' => ['nullable', 'string', 'max:5000'],
            'next_call_at' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'meta' => ['nullable', 'array'],
        ]);
    }

    private function applyDedupeFlags(array $data): array
    {
        $norm = Lead::normalizePhone($data['phone'] ?? null);
        if ($this->isDuplicate($norm, $data['inn'] ?? null, $data['external_id'] ?? null)) {
            $data['call_status'] = $data['call_status'] ?? 'duplicate';
            $data['notes'] = trim(($data['notes'] ?? '')."\n[auto] Похожий лид уже есть (телефон/ИНН/external_id).");
        }

        return $data;
    }

    private function isDuplicate(?string $phoneNorm, ?string $inn, ?string $externalId): bool
    {
        if ($externalId) {
            if (Lead::where('external_id', $externalId)->exists()) {
                return true;
            }
        }
        if ($phoneNorm && strlen($phoneNorm) >= 10) {
            if (Lead::where('phone_normalized', $phoneNorm)->exists()) {
                return true;
            }
        }
        if ($inn && strlen(preg_replace('/\D+/', '', $inn) ?? '') >= 10) {
            $innClean = preg_replace('/\D+/', '', $inn);
            if (Lead::where('inn', $innClean)->exists()) {
                return true;
            }
        }

        return false;
    }
}
