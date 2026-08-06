<?php

namespace App\Http\Requests;

use App\Rules\Inn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $supplierId = $this->route('supplier')->id;

        return [
            'commercial_name' => ['required', 'string', 'max:255'],
            'legal_name'      => ['nullable', 'string', 'max:255'],
            'inn'             => ['required', new Inn(), Rule::unique('suppliers', 'inn')->ignore($supplierId)],
            'legal_address'   => ['nullable', 'string', 'max:255'],
            'contact_person'  => ['nullable', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:50'],
            'email'           => ['nullable', 'email', 'max:255'],
            'website'         => ['nullable', 'url', 'max:255'],
            'telegram'        => ['nullable', 'string', 'max:255'],
            'is_active'       => ['boolean'],
            'logo'            => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'], // до 5 МБ
            'remove_logo'     => ['sometimes', 'boolean'],
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
