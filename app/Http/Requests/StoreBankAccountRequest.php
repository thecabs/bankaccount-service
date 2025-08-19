<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'external_id'          => 'nullable|uuid',
            'agency_id'            => 'nullable|string|max:64',

            'code_banque'          => 'required|string|size:5|regex:/^[0-9A-Z]+$/i',
            'code_agence'          => 'required|string|size:5|regex:/^[0-9A-Z]+$/i',
            'numero_compte_core'   => 'required|string|size:11|regex:/^[0-9A-Z]+$/i',
            'cle_rib'              => 'nullable|string|size:2|regex:/^[0-9A-Z]+$/i',

            'banque_nom'           => 'required|string|max:100',
            'intitule'             => 'required|string|max:100',

            'titulaire_nom'        => 'sometimes|string|max:255',
            'identifiant_national' => 'sometimes|string|max:64',
            'telephone_reference'  => 'sometimes|string|max:32',
            'meta_core_ref'        => 'sometimes|string|max:255',

            'verify'               => 'sometimes|boolean',
        ];
    }
}
