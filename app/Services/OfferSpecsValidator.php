<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Валидация offers.specs по схеме категории из config/agora.php.
 */
class OfferSpecsValidator
{
    /**
     * @param  array<string, mixed>  $specs
     * @return array<string, mixed>  очищенные specs (только известные ключи)
     *
     * @throws ValidationException
     */
    public function validate(Category $category, array $specs): array
    {
        $fields = $category->fieldSchema() ?? [];
        $rules = [];
        $attributes = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $attributes["specs.$key"] = $field['label'] ?? $key;
            $fieldRules = [];

            if (! empty($field['required'])) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            switch ($field['type'] ?? 'string') {
                case 'number':
                    $fieldRules[] = 'numeric';
                    if (isset($field['min'])) {
                        $fieldRules[] = 'min:'.$field['min'];
                    }
                    if (isset($field['max'])) {
                        $fieldRules[] = 'max:'.$field['max'];
                    }
                    break;
                case 'enum':
                    $fieldRules[] = 'string';
                    $dictKey = $field['dictionary'] ?? null;
                    $options = $dictKey ? config("agora.dictionaries.$dictKey", []) : [];
                    if ($options !== []) {
                        $fieldRules[] = 'in:'.implode(',', $options);
                    }
                    break;
                case 'boolean':
                    $fieldRules[] = 'boolean';
                    break;
                default:
                    $fieldRules[] = 'string';
                    $fieldRules[] = 'max:255';
            }

            $rules["specs.$key"] = $fieldRules;
        }

        $validator = Validator::make(
            ['specs' => $specs],
            $rules,
            [],
            $attributes
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $allowed = collect($fields)->pluck('key')->all();
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $specs) && $specs[$key] !== '' && $specs[$key] !== null) {
                $clean[$key] = $specs[$key];
            }
        }

        return $clean;
    }
}
