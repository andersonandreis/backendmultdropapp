<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * HUB-XXX: torna marketplace_accounts.client_id nullable
     *
     * Motivo: WLs (fornecefy/multdrop/mestore) cadastram contas de sellers
     * que nao tem cliente no hubaiapp. Ate hoje o FK NOT NULL forcava setar
     * client_id de qualquer coisa (pattern meia-boca do MultDrop). Com esta
     * migration a coluna passa a aceitar NULL — ownership real fica em
     * wl_client_id.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE marketplace_accounts DROP FOREIGN KEY marketplace_accounts_client_id_foreign');
        DB::statement('ALTER TABLE marketplace_accounts MODIFY client_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE marketplace_accounts ADD CONSTRAINT marketplace_accounts_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE marketplace_accounts DROP FOREIGN KEY marketplace_accounts_client_id_foreign');
        DB::statement('ALTER TABLE marketplace_accounts MODIFY client_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE marketplace_accounts ADD CONSTRAINT marketplace_accounts_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE');
    }
};
