<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\BankAccount;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BankAccountService
{
    private PendingRequest $http;
    private KeycloakTokenService $kc;

    public function __construct(HttpFactory $factory, KeycloakTokenService $kc)
    {
        $this->kc = $kc;

        // HTTP client robuste
        $timeout = (int) config('http.timeout', 20);
        $retries = 2;
        $delayMs = 200;

        $this->http = $factory
            ->timeout($timeout)
            ->retry($retries, $delayMs);
    }

    // ------- Lecture --------

    public function listByUser(string $externalId)
    {
        return BankAccount::query()
            ->forUser($externalId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function listVerifiedByUser(string $externalId)
    {
        return BankAccount::query()
            ->forUser($externalId)
            ->verified()
            ->orderByDesc('created_at')
            ->get();
    }

    public function countVerifiedByUser(string $externalId): int
    {
        return BankAccount::query()
            ->forUser($externalId)
            ->verified()
            ->count();
    }

    public function getById(string $id, string $externalId): BankAccount
    {
        $acc = BankAccount::query()->where('id', $id)->firstOrFail();

        // Propriété stricte: l’utilisateur ne voit que ses comptes
        if ($acc->external_id !== $externalId) {
            // on cache l’existence → 404
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Compte non trouvé');
        }

        return $acc;
    }

    // ------- Vérifs côté User Management --------

    public function assertExternalUserExists(string $externalId): void
    {
        $base = rtrim((string) config('services.user_management.base_url'), '/');
        $url  = $base . '/api/users/external/' . $externalId;

        try {
            $resp = $this->http
                ->withToken($this->kc->getServiceToken())
                ->acceptJson()
                ->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException('User Management non joignable');
        }

        if ($resp->status() === 404) {
            throw new RuntimeException('Utilisateur cible introuvable (User Management)');
        }
        if ($resp->failed()) {
            throw new RuntimeException('User Management erreur: ' . $resp->status());
        }
    }

    // ------- Vérifs identité (adaptable) --------

    public function assertIdentityMatch(BankAccount $account, string $externalId): void
    {
        if (empty($account->numero_compte)) {
            throw new RuntimeException('RIB incomplet sur le compte à lier');
        }
        // TODO: matcher avec UM/Core si besoin
    }

    public function assertClaimProofs(BankAccount $account, array $preuves): void
    {
        if (!isset($preuves['last4']) || substr($account->numero_compte, -4) !== $preuves['last4']) {
            throw new RuntimeException('Preuves insuffisantes (last4)');
        }
        // TODO: hash CNI & OTP SMS vers telephone_reference
    }
}
