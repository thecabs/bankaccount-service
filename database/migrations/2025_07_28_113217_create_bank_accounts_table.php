<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Lien vers l’utilisateur Keycloak (nullable tant que non lié)
            $table->uuid('external_id')->nullable()->comment('Keycloak UUID - sera lié après provisioning');

            // Scoping d’agence (directeurs d’agence)
            $table->string('agency_id', 64)->nullable()->index();

            // RIB détaillé (CEMAC)
            $table->string('code_banque', 5)->index();
            $table->string('code_agence', 5)->index();
            $table->string('numero_compte_core', 11);
            $table->string('cle_rib', 2);
            $table->string('numero_compte', 23)->unique(); // version compacte (sans espaces)

            // Métadonnées
            $table->string('banque_nom', 100);
            $table->string('intitule', 100);

            // Données d’ancrage identité (optionnelles selon ton parcours KYC)
            $table->string('titulaire_nom')->nullable();
            $table->string('identifiant_national_hash')->nullable();
            $table->string('telephone_reference')->nullable();
            $table->string('meta_core_ref')->nullable();

            // Statut cycle de vie
            $table->enum('statut', ['pre_associe', 'inactif', 'verifie', 'rejete'])->default('pre_associe');

            // Audit
            $table->string('created_by')->nullable();    // admin/agent_kyc/automate
            $table->string('created_via')->nullable();   // backoffice|import|api|claim

            $table->timestamps();

            $table->index('external_id');
            $table->index(['statut', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
