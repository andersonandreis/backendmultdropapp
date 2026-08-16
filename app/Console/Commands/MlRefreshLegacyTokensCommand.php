<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ml:refresh-legacy-tokens
 *
 * Renova access_token de contas com platform='ml' (formato legado importado
 * do Goolhub). Essas contas usam os campos genéricos access_token/refresh_token
 * (plaintext TG-xxx) ao invés dos campos ml_access_token/ml_refresh_token
 * (criptografados) usados pelo fluxo OAuth PKCE novo.
 *
 * O TokenRefreshService nao trata essas contas — este comando preenche essa lacuna.
 * Deve ser agendado junto com tokens:proactive-refresh.
 *
 * Criado: NOV-017 / 2026-06-25
 */
class MlRefreshLegacyTokensCommand extends Command
{
    protected $signature = 'ml:refresh-legacy-tokens
                            {--dry-run : Lista contas elegiveis sem executar}
                            {--limit=100 : Limite de contas por execucao}
                            {--all : Incluir contas inactive (nao so active)}';

    protected $description = 'Renova tokens ML legados (platform=ml, campos access_token/refresh_token plaintext)';

    private string $baseUrl    = 'https://api.mercadolibre.com';
    private string $appId;
    private string $secretKey;

    public function handle(): int
    {
        $this->appId     = config('services.mercadolivre.app_id', '');
        $this->secretKey = config('services.mercadolivre.secret_key', config('services.mercadolivre.client_secret', ''));

        if (!$this->appId || !$this->secretKey) {
            $this->error('[ML-Legacy] ML_APP_ID ou ML_SECRET_KEY nao configurados no .env');
            return self::FAILURE;
        }

        $isDryRun = $this->option('dry-run');
        $limit    = (int) $this->option('limit');
        $all      = $this->option('all');

        $this->info('[ML-Legacy] Buscando contas ml com token expirado e refresh_token valido...');

        $query = MarketplaceAccount::where('platform', 'ml')
            ->whereNotNull('refresh_token')
            ->where('refresh_token', '!=', '')
            ->where('token_expires_at', '<', now());

        if (!$all) {
            $query->where('status', 'active');
        }

        $accounts = $query->limit($limit)->get();

        $this->info('[ML-Legacy] ' . $accounts->count() . ' conta(s) elegivel(is).');

        if ($isDryRun) {
            $this->warn('[DRY-RUN] Nenhuma acao executada. Contas:');
            foreach ($accounts as $acc) {
                $this->line('  ID=' . $acc->id . ' status=' . $acc->status . ' expires=' . $acc->token_expires_at . ' rt=' . substr($acc->refresh_token, 0, 20) . '...');
            }
            return self::SUCCESS;
        }

        if ($accounts->isEmpty()) {
            $this->info('[ML-Legacy] Nenhuma conta elegivel.');
            return self::SUCCESS;
        }

        $renewed  = 0;
        $permanent = 0;
        $temporary = 0;

        foreach ($accounts as $account) {
            $this->line('  Tentando ID=' . $account->id . ' (status=' . $account->status . ')...');

            $result = $this->doRefresh($account);

            if ($result === 'ok') {
                $renewed++;
                $this->info('    [OK] ID=' . $account->id . ' renovado.');
            } elseif ($result === 'permanent') {
                $permanent++;
                $account->update([
                    'status'             => 'needs_reauth',
                    'last_error_message' => 'invalid_grant: refresh_token invalido ou expirado',
                ]);
                $this->warn('    [PERM] ID=' . $account->id . ' invalid_grant — needs_reauth.');
            } else {
                $temporary++;
                $this->warn('    [TEMP] ID=' . $account->id . ' erro temporario, retry depois.');
            }
        }

        $this->newLine();
        $this->table(
            ['Resultado', 'Qtd'],
            [
                ['Renovadas com sucesso', $renewed],
                ['Reauth permanente (invalid_grant)', $permanent],
                ['Erro temporario', $temporary],
            ]
        );

        Log::info('[ML-Legacy] Varredura concluida', [
            'total'     => $accounts->count(),
            'renewed'   => $renewed,
            'permanent' => $permanent,
            'temporary' => $temporary,
        ]);

        $this->info('[ML-Legacy] Concluido.');
        return self::SUCCESS;
    }

    private function doRefresh(MarketplaceAccount $account): string
    {
        $refreshToken = $account->refresh_token;

        try {
            $decoded = decrypt($refreshToken);
            $refreshToken = $decoded;
        } catch (\Throwable) {
            // plaintext — usar como esta
        }

        try {
            $response = Http::timeout(15)->asForm()->post('https://api.mercadolibre.com/oauth/token', [
                'grant_type'    => 'refresh_token',
                'client_id'     => $this->appId,
                'client_secret' => $this->secretKey,
                'refresh_token' => $refreshToken,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ML-Legacy] Excecao HTTP', ['account_id' => $account->id, 'error' => $e->getMessage()]);
            return 'temporary';
        }

        if ($response->successful()) {
            $data = $response->json();
            $account->update([
                'access_token'          => $data['access_token'],
                'refresh_token'         => $data['refresh_token'],
                'token_expires_at'      => now()->addSeconds($data['expires_in'] ?? 21600),
                'status'                => 'active',
                'sync_errors_count'     => 0,
                'refresh_errors_count'  => 0,
                'sync_blocked_at'       => null,
                'last_error_message'    => null,
                'last_token_refresh_at' => now(),
            ]);
            Log::info('[ML-Legacy] Token renovado', ['account_id' => $account->id]);
            return 'ok';
        }

        $status = $response->status();
        $body   = $response->json();
        $error  = $body['error'] ?? '';

        Log::warning('[ML-Legacy] Falha no refresh', [
            'account_id'  => $account->id,
            'http_status' => $status,
            'error'       => $error,
        ]);

        $permanentErrors = ['invalid_grant', 'invalid_refresh_token', 'token_revoked'];
        if ($status === 400 && in_array($error, $permanentErrors)) {
            return 'permanent';
        }

        return 'temporary';
    }
}
