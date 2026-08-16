<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * NOV-038 (2026-06-25): Importa clientes do legado para suppliers que
 * nao passaram pelo bridge export_users.php (ex: Drop Auto Pecas).
 *
 * Busca todos os id_login distintos na tabela integracao do legado
 * para o id_empresa do supplier informado, e cria User+Client no
 * NovoHubAI com legacy_id_login preenchido.
 *
 * Isso e pre-requisito para o ImportLegacyOrdersJob funcionar.
 */
class ImportLegacyClientsBySupplier extends Command
{
    protected $signature = 'import:legacy-clients-by-supplier
                                {supplier_id : ID do supplier no NovoHubAI}
                                {--dry-run   : Simula sem gravar}
                                {--limit=    : Limita quantidade de logins a processar}';

    protected $description = 'Importa clientes do legado para um supplier especifico via legacy_empresa_id';

    public function handle(): int
    {
        $supplierId = (int) $this->argument('supplier_id');
        $dryRun     = (bool) $this->option('dry-run');
        $limit      = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $supplier = DB::table('suppliers')
            ->where('id', $supplierId)
            ->select(['id', 'company_name', 'legacy_empresa_id'])
            ->first();

        if (! $supplier || ! $supplier->legacy_empresa_id) {
            $this->error('Supplier ' . $supplierId . ' nao encontrado ou sem legacy_empresa_id');
            return self::FAILURE;
        }

        $empresaId = (int) $supplier->legacy_empresa_id;
        $this->info('Supplier: id=' . $supplier->id . ' empresa_legado=' . $empresaId . ' (' . $supplier->company_name . ')');

        // Busca todos os id_login distintos do legado para esse id_empresa
        $loginIds = DB::connection('legacy')
            ->table('integracao')
            ->where('id_empresa', $empresaId)
            ->distinct()
            ->pluck('id_login')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($limit) {
            $loginIds = array_slice($loginIds, 0, $limit);
        }

        $this->info('Logins distintos no legado: ' . count($loginIds));

        if ($dryRun) {
            $this->warn('--dry-run ativo: nenhum dado sera gravado');
            $alreadyExists = Client::whereIn('legacy_id_login', $loginIds)->count();
            $this->info('Clientes ja existentes no NovoHubAI: ' . $alreadyExists);
            $this->info('A criar: ' . (count($loginIds) - $alreadyExists));
            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = 0;

        $bar = $this->output->createProgressBar(count($loginIds));
        $bar->start();

        foreach ($loginIds as $loginId) {
            try {
                $legacyLogin = DB::connection('legacy')
                    ->table('login')
                    ->where('id', (int) $loginId)
                    ->select(['id', 'email'])
                    ->first();

                if (! $legacyLogin || empty(trim($legacyLogin->email ?? ''))) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $email = trim(strtolower($legacyLogin->email));
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $user = User::updateOrCreate(
                    ['email' => $email],
                    [
                        'name'      => $email,
                        'password'  => Hash::make(Str::random(32)),
                        'role'      => 'client',
                        'is_active' => true,
                    ]
                );

                // Busca client por legacy_id_login primeiro; se nao encontrar,
                // busca pelo user_id (client pode existir com legacy_id_login=NULL).
                // Isso evita violacao da unique constraint clients_user_id_unique.
                $client = Client::where('legacy_id_login', (int) $loginId)->first()
                    ?? Client::where('user_id', $user->id)->first();

                $clientBefore = $client !== null;

                // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
                if ($client) {
                    // Atualiza: preenche legacy_id_login se estava NULL
                    $client->update([
                        'legacy_id_login' => (int) $loginId,
                        'user_id'         => $user->id,
                    ]);
                } else {
                    Client::updateOrCreate(
                        ['user_id' => $user->id],
                        [
                            'legacy_id_login' => (int) $loginId,
                        ]
                    );
                }

                if ($clientBefore) {
                    $updated++;
                } else {
                    $created++;
                }
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn('Erro login_id=' . $loginId . ': ' . $e->getMessage());
                $errors++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Clientes criados: ' . $created . ' | atualizados: ' . $updated . ' | ignorados: ' . $skipped . ' | erros: ' . $errors);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
