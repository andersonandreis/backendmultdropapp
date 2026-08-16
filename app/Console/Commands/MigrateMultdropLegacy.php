<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Client;

/**
 * Migra os 35 clientes MultDrop legado (id_empresa=24, tudoonline_production)
 * para api.multdrop.app (multdropapp_production).
 *
 * Idempotente: usa legacy_id_login como chave de upsert no Client.
 * Roda via conexao legacy ja configurada em config/database.php.
 *
 * Uso:
 *   php artisan migrate:multdrop-legacy --dry-run
 *   php artisan migrate:multdrop-legacy --limit=5
 *   php artisan migrate:multdrop-legacy
 */
class MigrateMultdropLegacy extends Command
{
    protected $signature = 'migrate:multdrop-legacy
                            {--dry-run : Simula sem gravar nada no banco}
                            {--limit=0 : Limita o numero de usuarios processados (0=todos)}';

    protected $description = 'Migra clientes MultDrop legado (id_empresa=24) para multdropapp_production';

    private const ID_EMPRESA = 24;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit  = (int)  $this->option('limit');

        if ($dryRun) {
            $this->warn('[DRY-RUN] Nenhum dado sera gravado.');
        }

        $query = DB::connection('legacy')
            ->table('login')
            ->select(['id', 'login', 'email', 'celular', 'cpf_cnpj',
                      'nome_completo', 'conta_corrente', 'data_cad',
                      'status', 'plano', 'tipo', 'senha'])
            ->where('id_empresa', self::ID_EMPRESA)
            ->orderBy('id', 'desc');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $legacyUsers = $query->get();
        $total       = $legacyUsers->count();

        $this->info('Usuarios encontrados no legado (id_empresa=' . self::ID_EMPRESA . '): ' . $total);

        if ($total === 0) {
            $this->warn('Nenhum usuario encontrado. Verifique a conexao legacy e o id_empresa.');
            return self::FAILURE;
        }

        $created = 0; $updated = 0; $skipped = 0; $errors = 0;

        foreach ($legacyUsers as $row) {
            $email = $this->resolveEmail($row);
            if (! $email) {
                $this->warn('  SKIP id=' . $row->id . ': sem email valido');
                $skipped++;
                continue;
            }
            try {
                [$wasCreated, $wasUpdated] = $this->migrateUser($row, $email, $dryRun);
                if ($wasCreated)     { $created++; }
                elseif ($wasUpdated) { $updated++; }
                else                 { $skipped++; }
                $mk = $wasCreated ? '+' : ($wasUpdated ? '~' : '=');
                $this->line('  [' . $mk . '] id=' . $row->id . ' email=' . $email);
            } catch (\Throwable $e) {
                $this->error('  ERR id=' . $row->id . ': ' . $e->getMessage());
                $errors++;
            }
        }

        $this->newLine();
        $this->info('Resultado final:');
        $this->table(
            ['Criados', 'Atualizados', 'Skipped', 'Erros', 'Total'],
            [[$created, $updated, $skipped, $errors, $total]]
        );

        if ($dryRun) { $this->warn('[DRY-RUN] Nada foi gravado.'); }
        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveEmail(object $row): ?string
    {
        foreach ([$row->email ?? '', $row->login ?? ''] as $candidate) {
            $n = strtolower(trim((string) $candidate));
            if ($n && filter_var($n, FILTER_VALIDATE_EMAIL)) { return $n; }
        }
        return null;
    }

    private function migrateUser(object $row, string $email, bool $dryRun): array
    {
        $legacyIdLogin = (int) $row->id;
        $isActive      = ((int) $row->status === 1);
        $name          = $this->resolveName($row, $email);
        $phone    = preg_replace('/[^0-9]/', '', (string) ($row->celular ?? '')) ?: null;
        $document = preg_replace('/[^0-9]/', '', (string) ($row->cpf_cnpj ?? '')) ?: null;

        $passwordHash = Hash::make(Str::random(24));
        $passwordType = 'sha256_needs_reset';

        if (! empty($row->senha) && strlen((string) $row->senha) <= 20) {
            $passwordHash = Hash::make((string) $row->senha);
            $passwordType = 'plaintext_migrated';
        }

        $existingClient = Client::where('legacy_id_login', $legacyIdLogin)->first();

        if ($dryRun) {
            $action = $existingClient ? 'would_update' : 'would_create';
            $this->line('    [DRY] ' . $action . ' id=' . $legacyIdLogin . ' email=' . $email);
            return [$action === 'would_create', $action === 'would_update'];
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $passwordHash, 'role' => 'client', 'is_active' => $isActive]
        );

        $wasCreated = ! $existingClient;

        // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
        Client::updateOrCreate(
            ['legacy_id_login' => $legacyIdLogin],
            [
                'user_id'              => $user->id,
                'document'             => $document,
                'phone'                => $phone,
                'is_active'            => $isActive,
                'legacy_password_type' => $passwordType,
            ]
        );

        return [$wasCreated, ! $wasCreated];
    }

    private function resolveName(object $row, string $email): string
    {
        $nome = trim((string) ($row->nome_completo ?? ''));
        if ($nome && $nome !== 'NULL' && strlen($nome) > 1) { return $nome; }
        return explode('@', $email)[0];
    }
}
