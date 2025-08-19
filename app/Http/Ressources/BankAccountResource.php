<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BankAccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'external_id'          => $this->external_id,
            'numero_compte'        => $this->numero_compte,
            'numero_compte_masque' => $this->numero_compte_masque,
            'banque_nom'           => $this->banque_nom,
            'intitule'             => $this->intitule,
            'titulaire_nom'        => $this->titulaire_nom,
            'statut'               => $this->statut,
            'is_active'            => $this->isActive(),
            'created_at'           => $this->created_at?->toIso8601String(),
            'updated_at'           => $this->updated_at?->toIso8601String(),
        ];
    }
}
