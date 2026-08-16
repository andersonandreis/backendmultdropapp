<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Client;

class MigrateUsersFromLegacy extends Command
{
    protected $signature   = 'users:migrate-from-legacy
                                {--dry-run : Apenas contar, nao inserir}
                                {--page=0 : Pagina especifica (0=todas)}';
    protected $description = 'Migra usuarios ativos do legado (goolhub) para api.hubai.io via bridge HMAC';

    private string $bridgeKey = 'hb-bridge-2026-xK9mP3qR7vL2nW8';
    private string $bridgeUrl = 'https://goolhub.io/api/bridge/export_users.php';

    public function handle(): int
    {
        $dryRun   = $this->option('dry-run');
        $onlyPage = (int) $this->option('page');

        // 1. Buscar primeira pagina para obter total
        $first = $this->fetchPage(1);
        if (! $first) {
            $this->error('Bridge export_users.php inacessivel ou retornou erro');
            return self::FAILURE;
        }

        $total = $first['total'] ?? 0;
        $pages = $first['pages'] ?? 1;
        $this->info("Total de usuarios a migrar: {$total} em {$pages} paginas");

        if ($dryRun) {
            $this->warn('--dry-run ativo: nenhum dado sera gravado');
            return self::SUCCESS;
        }

        // 2. Iterar paginas
        $startPage = $onlyPage > 0 ? $onlyPage : 1;
        $endPage   = $onlyPage > 0 ? $onlyPage : $pages;
        $migrated  = 0;
        $skipped   = 0;
        $errors    = 0;

        for ($page = $startPage; $page <= $endPage; $page++) {
            $data = ($page === 1) ? $first : $this->fetchPage($page);
            if (! $data) {
                $this->error("Falha ao buscar pagina {$page}");
                $errors++;
                continue;
            }

            foreach ($data['users'] as $legacy) {
                try {
                    $result = $this->migrateUser($legacy);
                    if ($result === 'migrated') {
                        $migrated++;
                    } else {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $this->warn("Erro user id={$legacy['id']}: " . $e->getMessage());
                    $errors++;
                }
            }

            $this->info("Pagina {$page}/{$endPage} processada — migrados: {$migrated}, skipped: {$skipped}, erros: {$errors}");
        }

        $this->info("Migracao concluida. Total migrados: {$migrated}, Skipped: {$skipped}, Erros: {$errors}");
        return self::SUCCESS;
    }

    // -----------------------------------------------------------------------

    private function fetchPage(int $page): ?array
    {
        $perPage = 500;
        $sig     = hash_hmac('sha256', "exportusers:{$page}:{$perPage}", $this->bridgeKey);
        $url     = "{$this->bridgeUrl}?page={$page}&per_page={$perPage}&sig={$sig}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || ! $body) {
            return null;
        }

        $data = json_decode($body, true);
        return (is_array($data) && isset($data['users'])) ? $data : null;
    }

    private function migrateUser(array $legacy): string
    {
        $email = trim(strtolower($legacy['email'] ?? ''));
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'skipped';
        }

        // Determinar estrategia de senha
        $hasPlaintext = ! empty($legacy['senha_plaintext']) && strlen($legacy['senha_plaintext']) <= 20;
        $hasSha256    = ! empty($legacy['senha_hash'])      && strlen($legacy['senha_hash']) === 64;

        if ($hasPlaintext) {
            $passwordHash       = Hash::make($legacy['senha_plaintext']);
            $legacyPasswordType = 'plaintext_migrated';
        } elseif ($hasSha256) {
            // SHA-256 nao pode ser convertido para bcrypt — usuario devera resetar a senha
            $passwordHash       = Hash::make(Str::random(32));
            $legacyPasswordType = 'sha256_needs_reset';
        } else {
            $passwordHash       = Hash::make(Str::random(32));
            $legacyPasswordType = 'no_password_needs_reset';
        }

        // Criar ou atualizar User (email como chave)
        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'     => $legacy['username'] ?? $email,
                'password' => $passwordHash,
                'role'     => 'client',
                'is_active' => true,
            ]
        );

        // Criar ou atualizar Client (legacy_id_login como chave)
        // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
        Client::updateOrCreate(
            ['legacy_id_login' => (int) $legacy['id']],
            [
                'user_id'              => $user->id,
                'legacy_password_type' => $legacyPasswordType,
                'legacy_sha256_hash'   => $hasSha256 ? $legacy['senha_hash'] : null,
            ]
        );

        return 'migrated';
    }
}
