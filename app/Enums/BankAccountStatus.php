<?php

namespace App\Enums;

enum BankAccountStatus: string
{
    case VERIFIE = 'verifie';
    case REJETE = 'rejete';
    case INACTIF = 'inactif';

    public function label(): string
    {
        return match($this) {
            self::VERIFIE => 'Vérifié',
            self::REJETE => 'Rejeté',
            self::INACTIF => 'Inactif',
        };
    }

    public function isActive(): bool
    {
        return $this === self::VERIFIE;
    }
}
