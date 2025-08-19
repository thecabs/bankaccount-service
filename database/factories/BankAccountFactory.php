<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Enums\BankAccountStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition()
    {
        return [
            'id' => Str::uuid(),
            'external_id' => Str::uuid(),
            'numero_compte' => 'FR76' . $this->faker->numerify('################'),
            'banque_nom' => $this->faker->company . ' Banque',
            'intitule' => $this->faker->name,
            'statut' => $this->faker->randomElement(BankAccountStatus::cases()),
        ];
    }

    public function verified()
    {
        return $this->state([
            'statut' => BankAccountStatus::VERIFIE
        ]);
    }

    public function rejected()
    {
        return $this->state([
            'statut' => BankAccountStatus::REJETE
        ]);
    }
}