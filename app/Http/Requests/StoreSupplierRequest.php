<?php

namespace App\Http\Requests;

use App\Rules\Inn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // доступ ограничен middleware auth на группе роутов
    }

    public function rules(): array
    {
        return [
            'commercial_name' => ['required', 'string', 'max:255'],
            'legal_name'      => ['nullable', 'string', 'max:255'],
            'inn'             => ['required', new Inn(), Rule::unique('suppliers', 'inn')],
            'legal_address'   => ['nullable', 'string', 'max:255'],
            'contact_person'  => ['nullable', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'email'           => ['nullable', 'email', 'max:255'],
            'website'         => ['nullable', 'url', 'max:255'],
            'telegram'        => ['nullable', 'string', 'max:255'],
            'is_active'       => ['boolean'],
            // Логотип: квадрат 200–1024px рекомендуется; хранится в storage/app/public/logos
            'logo'            => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'], // до 5 МБ

            'cities'          => ['array'],
            'cities.*'        => ['string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'commercial_name' => 'коммерческое название',
            'legal_name'      => 'юридическое название',
            'inn'             => 'ИНН',
            'legal_address'   => 'адрес регистрации',
            'logo'            => 'логотип',
        ];
    }
}
