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
                    $fieldRules[] = 'nullable';
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

        $fieldsByKey = collect($fields)->keyBy('key');
        $clean = [];
        foreach ($fieldsByKey as $key => $field) {
            if (! array_key_exists($key, $specs) || $specs[$key] === '' || $specs[$key] === null) {
                continue;
            }
            $value = $specs[$key];
            if (($field['type'] ?? '') === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    continue;
                }
            }
            if (($field['type'] ?? '') === 'number' && is_numeric($value)) {
                $value = $value + 0; // int or float
            }
            $clean[$key] = $value;
        }

        return $clean;
    }
}

