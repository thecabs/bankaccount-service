<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkBankAccountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'external_id' => 'required|uuid',
            'agency_id'   => 'sometimes|string|max:64',
        ];
    }
}
