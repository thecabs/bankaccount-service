<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClaimBankAccountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // Permet soit l’ID, soit le RIB compact (23 chars) depuis l’app
            'account_id'                 => 'nullable|uuid',
            'numero_compte'              => 'nullable|string|max:32',

            'preuves.last4'              => 'required|digits:4',
            'preuves.identifiant_national' => 'sometimes|string|max:64',
            'preuves.otp'                => 'sometimes|digits:6',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $id  = $this->input('account_id');
            $rib = $this->input('numero_compte');
            if (!$id && !$rib) {
                $v->errors()->add('account', 'Fournir "account_id" OU "numero_compte".');
            }
        });
    }
}
