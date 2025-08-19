<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\InternalAccountController;

/**
 * Health (public)
 */
Route::get('/health', fn () => response()->json([
    'service'   => 'BankAccount Service',
    'status'    => 'OK',
    'timestamp' => now()->toIso8601String(),
    'version'   => '1.1.0',
]))->name('health.check');

/**
 * Health DB (public) — à restreindre en prod si besoin
 */
Route::get('/health/database', function () {
    try {
        DB::connection()->getPdo();
        return response()->json([
            'database'   => 'OK',
            'connection' => config('database.default'),
            'timestamp'  => now()->toIso8601String(),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'database'  => 'ERROR',
            'message'   => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ], 500);
    }
})->name('health.database');

/**
 * Périmètre protégé ZT : JWT + contexte + tag FINANCIAL + PDP
 */
Route::middleware(['keycloak', 'context.enricher', 'resource.tag:FINANCIAL', 'pdp'])->group(function () {

    // ----- Endpoints utilisateur (lecture)
    Route::middleware('check.role:client_bancaire,client_non_bancaire')->group(function () {
        Route::get('/bank-accounts', [BankAccountController::class, 'index'])
            ->name('bankaccounts.index');
    });

    // ----- Endpoints client bancaire (lecture + claim)
    Route::middleware('check.role:client_bancaire')->group(function () {
        Route::get('/bank-accounts/{id}', [BankAccountController::class, 'show'])
            ->name('bankaccounts.show');

        // Auto-claim par l'utilisateur (WRITE ⇒ throttle + idempotency)
        Route::post('/bank-accounts/claim', [BankAccountController::class, 'claim'])
            ->middleware(['throttle:bank-write', 'idempotency'])
            ->name('bankaccounts.claim');
    });

    // ----- Back-office (provisionnement & association)
    Route::middleware('check.role:admin,agent_kyc')->group(function () {
        // Provisioning (peut être sans external_id)
        Route::post('/admin/bank-accounts', [BankAccountController::class, 'store'])
            ->middleware(['throttle:bank-write', 'idempotency'])
            ->name('bankaccounts.store');

        // Lier un compte existant à un external_id après création UM
        Route::post('/admin/bank-accounts/{id}/link', [BankAccountController::class, 'link'])
            ->middleware(['throttle:bank-write', 'idempotency'])
            ->name('bankaccounts.link');
    });
});

/**
 * Endpoint INTERNE S2S (pour userm & autres) — indique si l'utilisateur
 * possède au moins un compte bancaire "verifie".
 * Accès : admin OU svc_bankaccount
 */
Route::middleware(['keycloak', 'check.role:admin,svc_bankaccount'])
    ->get('/internal/accounts/status/{external_id}', [InternalAccountController::class, 'status'])
    ->whereUuid('external_id')
    ->name('internal.accounts.status');
