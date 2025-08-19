<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\BankAccount;
use App\Enums\BankAccountStatus;

class BankAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $userId = 'test-user-uuid';
    private string $validToken = 'valid-jwt-token';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Créer des comptes de test
        BankAccount::factory()->create([
            'external_id' => $this->userId,
            'numero_compte' => 'FR7617106001011234567890189',
            'banque_nom' => 'Banque Test',
            'intitule' => 'John Doe',
            'statut' => BankAccountStatus::VERIFIE
        ]);

        BankAccount::factory()->create([
            'external_id' => $this->userId,
            'numero_compte' => 'FR7617106001011234567890190',
            'banque_nom' => 'Autre Banque',
            'intitule' => 'John Doe',
            'statut' => BankAccountStatus::REJETE
        ]);
    }

    public function test_list_user_bank_accounts()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken
        ])->get('/api/bank-accounts');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        '*' => [
                            'id',
                            'numero_compte',
                            'numero_compte_masque',
                            'banque_nom',
                            'intitule',
                            'statut',
                            'statut_label',
                            'is_active'
                        ]
                    ],
                    'meta' => [
                        'total',
                        'verified_count'
                    ]
                ]);
        
        $this->assertEquals(2, $response->json('meta.total'));
        $this->assertEquals(1, $response->json('meta.verified_count'));
    }

    public function test_list_only_verified_accounts()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken
        ])->get('/api/bank-accounts?verified=true');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
    }

    public function test_show_specific_bank_account()
    {
        $account = BankAccount::where('external_id', $this->userId)->first();
        
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken
        ])->get("/api/bank-accounts/{$account->id}");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'id',
                        'numero_compte',
                        'banque_nom',
                        'intitule',
                        'statut'
                    ]
                ]);
    }

    public function test_unauthorized_access_returns_401()
    {
        $response = $this->get('/api/bank-accounts');
        $response->assertStatus(401);
    }

    public function test_account_not_found_returns_404()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->validToken
        ])->get('/api/bank-accounts/non-existent-id');

        $response->assertStatus(404);
    }

    public function test_health_endpoint()
    {
        $response = $this->get('/health');
        
        $response->assertStatus(200)
                ->assertJson([
                    'service' => 'BankAccount Service',
                    'status' => 'OK'
                ]);
    }
}
