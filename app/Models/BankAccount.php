<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BankAccount extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        // 'id',                 // ❌ jamais dans fillable
        'external_id',
        'agency_id',            // ✅ scoping d’agence
        'code_banque',
        'code_agence',
        'numero_compte_core',
        'cle_rib',
        'numero_compte',
        'banque_nom',
        'intitule',
        'titulaire_nom',
        'identifiant_national_hash',
        'telephone_reference',
        'meta_core_ref',
        'statut',
        'created_by',
        'created_via',
    ];

    protected $hidden = [
        // ❗ champs sensibles non exposés côté APIs user
        'numero_compte_core',
        'cle_rib',
        'identifiant_national_hash',
        'meta_core_ref',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    /** Scope: comptes appartenant à un external_id (user) */
    public function scopeForUser($query, string $externalId)
    {
        return $query->where('external_id', $externalId);
    }

    /** Scope: seulement vérifiés */
    public function scopeVerified($query)
    {
        return $query->where('statut', 'verifie');
    }

    /** Accessor: numéro de compte masqué xxxx****yyyy (robuste/compat) */
    protected function numeroCompteMasque(): Attribute
    {
        return Attribute::make(
            get: function ($value, array $attributes) {
                $raw = (string) ($attributes['numero_compte'] ?? '');
                if ($raw === '' || strlen($raw) < 6) {
                    return '********';
                }
                return substr($raw, 0, 4) . '****' . substr($raw, -4);
            }
        );
    }

    /** Helper statut (insensible à la casse, pas de changement fonctionnel attendu) */
    public function isActive(): bool
    {
        return strcasecmp((string) $this->statut, 'verifie') === 0;
    }
}
