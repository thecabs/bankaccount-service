<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;

class InternalAccountController extends Controller
{
    /**
     * GET /api/internal/accounts/status/{external_id}
     * Retourne si l'utilisateur possède au moins un compte au statut "verifie".
     * Sécurisé S2S : 'admin' ou 'svc_bankaccount'
     */
    public function status(string $external_id): JsonResponse
    {
        // NOTE: adapte 'statut' si ta colonne s'appelle autrement (ex: 'status')
        $verifiedCount = BankAccount::query()
            ->where('external_id', $external_id)
            ->where('statut', 'verifie')
            ->count();

        return response()->json([
            'external_id' => $external_id,
            'verified'    => $verifiedCount > 0,
            'counts'      => [
                'verified' => $verifiedCount,
            ],
        ]);
    }
}
