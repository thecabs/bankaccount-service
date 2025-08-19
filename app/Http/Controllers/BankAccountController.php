<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Services\BankAccountService;
use App\Services\CeilingsClient;
use App\Support\Rib;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\MassAssignmentException; // ⬅️ AJOUT
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BankAccountController extends Controller
{
    public function __construct(
        private BankAccountService $bankAccountService,
        private CeilingsClient $ceilings
    ) {}

    // ---------------- Helpers identités / rôles ----------------------

    /** Identité normalisée (fourni par ContextEnricher) */
    private function externalId(Request $r): ?string {
        return data_get($r->attributes->get('zt.subject', []), 'sub');
    }

    /** Agence normalisée (fourni par ContextEnricher) */
    private function agencyId(Request $r): ?string {
        return data_get($r->attributes->get('zt.subject', []), 'agency_id');
    }

    /** Rôles agrégés realm + resource_access (posés par ContextEnricher) */
    private function roles(Request $r): array {
        return array_map('strtolower', (array) data_get($r->attributes->get('zt.subject', []), 'roles', []));
    }

    private function hasAnyRole(Request $r, array $required): bool {
        $roles = $this->roles($r);
        foreach ($required as $rname) {
            if (in_array(strtolower($rname), $roles, true)) return true;
        }
        return false;
    }

    /** Qui agit (pour audit) */
    private function who(Request $r): ?string {
        $t = (array) $r->attributes->get('token_data', []);
        return data_get($t, 'preferred_username') ?: $this->externalId($r);
    }

    /** Hash PII (identifiant national) avec PEPPER dédié */
    private function hashId(?string $value): ?string {
        if (!$value) return null;
        $pepper = (string) env('HASH_PEPPER', config('app.key'));
        return hash_hmac('sha256', mb_strtolower(trim($value)), $pepper);
    }

    /** (Option) Scoping Agence pour profils BO (directeur_agence/gfc/agi) */
    private function assertSameAgency(Request $r, ?string $payloadAgencyId): void {
        $actorAgency = $this->agencyId($r);
        if ($this->hasAnyRole($r, ['directeur_agence','gfc','agi'])) {
            if ($payloadAgencyId && $actorAgency && $payloadAgencyId !== $actorAgency) {
                abort(response()->json(['error' => 'Forbidden - Cross-agency action'], 403));
            }
        }
    }

    // ---------------- Endpoints utilisateur ----------------

    /** Liste des comptes de l’utilisateur connecté */
    public function index(Request $request): JsonResponse
    {
        try {
            $externalId = $this->externalId($request);
            if (!$externalId) {
                return response()->json(['error' => 'Utilisateur non identifié'], 401);
            }

            $onlyVerified = filter_var($request->query('verified', false), FILTER_VALIDATE_BOOLEAN);

            $accounts = $onlyVerified
                ? $this->bankAccountService->listVerifiedByUser($externalId)
                : $this->bankAccountService->listByUser($externalId);

            return response()->json([
                'success' => true,
                'data' => $accounts->map(function (BankAccount $a) {
                    return [
                        'id'                   => $a->id,
                        'external_id'          => $a->external_id,
                        'numero_compte'        => $a->numero_compte,
                        'numero_compte_masque' => $a->numero_compte_masque,
                        'banque_nom'           => $a->banque_nom,
                        'intitule'             => $a->intitule,
                        'titulaire_nom'        => $a->titulaire_nom,
                        'statut'               => (string) $a->statut,
                        'statut_label'         => $a->isActive() ? 'Vérifié' : strtoupper((string)$a->statut),
                        'is_active'            => $a->isActive(),
                        'created_at'           => $a->created_at?->toIso8601String(),
                        'updated_at'           => $a->updated_at?->toIso8601String(),
                    ];
                }),
                'meta' => [
                    'total'          => $accounts->count(),
                    'verified_count' => $this->bankAccountService->countVerifiedByUser($externalId),
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Erreur lors de la récupération des comptes',
            ], 500);
        }
    }

    /** Détails d’un compte appartenant à l’utilisateur connecté */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $externalId = $this->externalId($request);
            if (!$externalId) {
                return response()->json(['error' => 'Utilisateur non identifié'], 401);
            }

            $a = $this->bankAccountService->getById($id, $externalId);

            return response()->json([
                'success' => true,
                'data' => [
                    'id'                   => $a->id,
                    'external_id'          => $a->external_id,
                    'numero_compte'        => $a->numero_compte,
                    'numero_compte_masque' => $a->numero_compte_masque,
                    'banque_nom'           => $a->banque_nom,
                    'intitule'             => $a->intitule,
                    'titulaire_nom'        => $a->titulaire_nom,
                    'statut'               => (string) $a->statut,
                    'statut_label'         => $a->isActive() ? 'Vérifié' : strtoupper((string) $a->statut),
                    'is_active'            => $a->isActive(),
                    'created_at'           => $a->created_at?->toIso8601String(),
                    'updated_at'           => $a->updated_at?->toIso8601String(),
                ],
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'error' => 'Compte non trouvé'], 404);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Erreur lors de la récupération du compte'], 500);
        }
    }

    /** Claim par l’utilisateur (auto-service) */
    public function claim(Request $request): JsonResponse
    {
        $externalId = $this->externalId($request);
        if (!$externalId) {
            return response()->json(['error' => 'Utilisateur non identifié'], 401);
        }

        $request->validate([
            'numero_compte'                 => 'required|string|max:32',
            'preuves.last4'                 => 'required|digits:4',
            'preuves.identifiant_national'  => 'sometimes|string',
            'preuves.otp'                   => 'sometimes|digits:6',
        ]);

        try {
            $numero = Rib::compactFromRaw((string) $request->string('numero_compte'));
            $account = BankAccount::query()->where('numero_compte', $numero)->first();

            if (!$account) {
                // Anti-énumération : ne pas révéler l'absence
                return response()->json(['success' => true, 'message' => 'Demande reçue'], 200);
            }

            $this->bankAccountService->assertClaimProofs($account, [
                'last4' => (string) $request->input('preuves.last4'),
            ]);

            $account->external_id = $externalId;
            $account->agency_id   = $account->agency_id ?: $this->agencyId($request);
            $account->statut      = 'verifie';
            $account->created_via = 'claim';
            $account->save();

            // 🔗 assurer le plafond côté UserCeiling (NON bloquant)
            try {
                $this->ceilings->ensureDefault($externalId, 'XAF');
            } catch (\Throwable $e) {
                Log::warning('ceilings.ensureDefault.failed', [
                    'request_id'  => $request->header('X-Request-Id'),
                    'external_id' => $externalId,
                    'error'       => $e->getMessage(),
                ]);
            }

            Log::channel('audit')->info('bankaccount.claimed', [
                'request_id'  => $request->header('X-Request-Id'),
                'external_id' => $externalId,
                'account_id'  => $account->id,
                'statut'      => $account->statut,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Compte associé avec succès',
                'data' => [
                    'id'          => $account->id,
                    'external_id' => $account->external_id,
                    'statut'      => $account->statut,
                ],
            ]);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Erreur lors du claim'], 400);
        }
    }

    // ---------------- Backoffice ----------------

    /** Provisioning d’un compte (avec ou sans external_id) */
    public function store(Request $request): JsonResponse
    {
        if (!$this->hasAnyRole($request, ['admin', 'agent_kyc'])) {
            return response()->json([
                'error'          => 'Access Denied - Missing role',
                'required_roles' => ['admin', 'agent_kyc'],
            ], 403);
        }

        $request->validate([
            'code_banque'         => 'required|string|size:5|regex:/^[0-9A-Z]+$/i',
            'code_agence'         => 'required|string|size:5|regex:/^[0-9A-Z]+$/i',
            'numero_compte_core'  => 'required|string|size:11|regex:/^[0-9A-Z]+$/i',
            'cle_rib'             => 'nullable|string|size:2|regex:/^[0-9A-Z]+$/i',
            'banque_nom'          => 'required|string|max:100',
            'intitule'            => 'required|string|max:100',
            'titulaire_nom'            => 'sometimes|string|max:255',
            'identifiant_national'     => 'sometimes|string|max:190',
            'telephone_reference'      => 'sometimes|string|max:30',
            'meta_core_ref'            => 'sometimes|string|max:190',
            'external_id'         => 'sometimes|uuid',
            'verify'              => 'sometimes|boolean',
            'agency_id'           => 'sometimes|string|max:64',
        ]);

        // Scoping agence pour profils BO non globaux (option)
        $this->assertSameAgency($request, $request->input('agency_id'));

        try {
            $codeBanque = strtoupper((string) $request->string('code_banque'));
            $codeAgence = strtoupper((string) $request->string('code_agence'));
            $compteCore = strtoupper((string) $request->string('numero_compte_core'));
            $cle        = $request->input('cle_rib');

            if ($cle === null || $cle === '') {
                $cle = Rib::computeKey($codeBanque, $codeAgence, $compteCore);
            }
            if (!Rib::isValid($codeBanque, $codeAgence, $compteCore, $cle)) {
                return response()->json(['error' => 'RIB invalide'], 422);
            }

            $numeroCompteCompact = Rib::compact($codeBanque, $codeAgence, $compteCore, $cle);

            $ownerExternalId = $request->filled('external_id') ? (string) $request->string('external_id') : null;

            // ⬇️ Toggle de test (bypass UM si SKIP_UM_CHECK=true)
            $skipUM = filter_var(env('SKIP_UM_CHECK', false), FILTER_VALIDATE_BOOLEAN);
            if ($ownerExternalId && !$skipUM) {
                $this->bankAccountService->assertExternalUserExists($ownerExternalId);
            }

            $statut = $request->boolean('verify')
                ? 'verifie'
                : ($ownerExternalId ? 'inactif' : 'pre_associe');

            $account = BankAccount::create([
                'external_id'              => $ownerExternalId,
                'agency_id'                => $request->input('agency_id') ?? $this->agencyId($request),
                'code_banque'              => $codeBanque,
                'code_agence'              => $codeAgence,
                'numero_compte_core'       => $compteCore,
                'cle_rib'                  => strtoupper($cle),
                'numero_compte'            => $numeroCompteCompact,
                'banque_nom'               => (string) $request->string('banque_nom'),
                'intitule'                 => (string) $request->string('intitule'),
                'titulaire_nom'            => (string) $request->string('titulaire_nom', ''),
                'identifiant_national_hash'=> $this->hashId($request->input('identifiant_national')),
                'telephone_reference'      => (string) $request->string('telephone_reference', ''),
                'meta_core_ref'            => (string) $request->string('meta_core_ref', ''),
                'statut'                   => $statut,
                'created_by'               => $this->who($request),
                'created_via'              => 'backoffice',
            ]);

            // 🔗 assurer le plafond si on a déjà un propriétaire (NON bloquant)
            if ($ownerExternalId) {
                try {
                    $this->ceilings->ensureDefault($ownerExternalId, 'XAF');
                } catch (\Throwable $e) {
                    Log::warning('ceilings.ensureDefault.failed', [
                        'request_id' => $request->header('X-Request-Id'),
                        'external_id'=> $ownerExternalId,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            Log::channel('audit')->info('bankaccount.provisioned', [
                'request_id'   => $request->header('X-Request-Id'),
                'actor'        => $this->who($request),
                'external_id'  => $ownerExternalId,
                'agency_id'    => $request->input('agency_id') ?? $this->agencyId($request),
                'account_id'   => $account->id,
                'numero_compte'=> $account->numero_compte,
                'statut'       => $statut,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Compte bancaire provisionné',
                'data' => [
                    'id'                   => $account->id,
                    'external_id'          => $account->external_id,
                    'numero_compte'        => $account->numero_compte,
                    'numero_compte_masque' => $account->numero_compte_masque,
                    'banque_nom'           => $account->banque_nom,
                    'intitule'             => $account->intitule,
                    'statut'               => $account->statut,
                    'created_at'           => $account->created_at?->toIso8601String(),
                ],
            ], 201);

        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return response()->json(['error' => 'Bank account already exists'], 409);
            }
            // ⬇️ détail SQL en 422 pour débogage
            return response()->json([
                'success'     => false,
                'error'       => 'SQL error',
                'sql_state'   => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
                'message'     => $e->errorInfo[2] ?? $e->getMessage(),
                'request_id'  => $request->header('X-Request-Id'),
            ], 422);
        } catch (MassAssignmentException $e) {
            Log::error('bankaccount.store.mass_assignment', [
                'request_id' => $request->header('X-Request-Id'),
                'message'    => $e->getMessage(),
            ]);
            return response()->json([
                'success'    => false,
                'error'      => 'Mass assignment error (check $fillable on BankAccount)',
                'request_id' => $request->header('X-Request-Id'),
            ], 500);
        } catch (\Throwable $e) {
            Log::error('bankaccount.store.error', [
                'request_id' => $request->header('X-Request-Id'),
                'class'      => get_class($e),
                'code'       => $e->getCode(),
                'message'    => $e->getMessage(),
            ]);
            $isLocal = app()->environment('local', 'development');
            return response()->json([
                'success'    => false,
                'error'      => $isLocal ? (get_class($e).': '.$e->getMessage()) : 'Erreur lors du provisioning du compte',
                'request_id' => $request->header('X-Request-Id'),
            ], 500);
        }
    }

    /** Lier un compte provisionné à un utilisateur (BO) */
    public function link(Request $request, string $id): JsonResponse
    {
        if (!$this->hasAnyRole($request, ['admin', 'agent_kyc'])) {
            return response()->json(['error' => 'Access Denied - Missing role'], 403);
        }

        $request->validate([
            'external_id' => 'required|uuid',
            'agency_id'   => 'sometimes|string|max:64',
        ]);

        // Scoping agence (option)
        $this->assertSameAgency($request, $request->input('agency_id'));

        try {
            $external = (string) $request->string('external_id');
            $this->bankAccountService->assertExternalUserExists($external);

            $a = BankAccount::query()->where('id', $id)->firstOrFail();

            $this->bankAccountService->assertIdentityMatch($a, $external);

            $a->external_id = $external;
            $a->agency_id   = $request->input('agency_id') ?? $this->agencyId($request);
            if ($a->statut === 'pre_associe') {
                $a->statut = 'verifie';
            }
            $a->save();

            // 🔗 assurer le plafond (NON bloquant)
            try {
                $this->ceilings->ensureDefault($external, 'XAF');
            } catch (\Throwable $e) {
                Log::warning('ceilings.ensureDefault.failed', [
                    'request_id'  => $request->header('X-Request-Id'),
                    'external_id' => $external,
                    'error'       => $e->getMessage(),
                ]);
            }

            Log::channel('audit')->info('bankaccount.linked', [
                'request_id'  => $request->header('X-Request-Id'),
                'actor'       => $this->who($request),
                'external_id' => $external,
                'agency_id'   => $request->input('agency_id') ?? $this->agencyId($request),
                'account_id'  => $a->id,
                'statut'      => $a->statut,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Compte lié à l’utilisateur',
                'data' => [
                    'id'          => $a->id,
                    'external_id' => $a->external_id,
                    'statut'      => $a->statut,
                ],
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'error' => 'Compte introuvable'], 404);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Erreur lors de l’association'], 500);
        }
    }
}
