<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-GERENTE (09/08 tarde) — comissao que o GERENTE define pra cada afiliado
 * do seu time, dentro do teto do proprio pool (manager_override_rate).
 *
 * NAO reaproveita a coluna 'commission_rate' que ja existe em affiliates
 * porque aquela tem outro significado hoje (grep confirmado no servidor:
 * usada em AffiliateController::adminApprove/adminUpdateQuotas como a taxa
 * base do afiliado direto — nada a ver com o split dentro do pool do
 * gerente). Coluna nova e isolada evita colisao de sentido.
 *
 * IMPORTANTE (divergencia sinalizada no relatorio da tarefa): esta coluna
 * so GUARDA o valor que o gerente configurou e e VALIDADA (0 <= rate <=
 * pool do gerente). Ela ainda NAO é lida por AffiliateCommissionService::
 * registerPayment (que hoje paga 30%/10% fixo pra QUALQUER afiliado) nem
 * por AffiliateManagerService::registerOverrideForSale (que credita
 * manager_override_rate cheio pro gerente, sem descontar o que foi dado
 * ao sub-afiliado). Ligar isso ao calculo real de comissao muda a divisao
 * de dinheiro pra TODOS os gerentes/afiliados e pede confirmacao explicita
 * antes de mexer nesses dois services compartilhados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliates', 'manager_commission_rate')) {
                $table->decimal('manager_commission_rate', 5, 2)->nullable()->after('manager_override_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            if (Schema::hasColumn('affiliates', 'manager_commission_rate')) {
                $table->dropColumn('manager_commission_rate');
            }
        });
    }
};
