<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantApiCredential;
use App\Models\TenantWebhookEndpoint;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Supplier Core / Fase 3 / M6 — Onboarding interativo por whitelabel.
 *
 * Uso: php artisan tenant:onboard {slug} [--webhook-url=...] [--no-webhook]
 *
 * Faz tudo de uma vez:
 *  1. Emite API key (mostra UMA VEZ)
 *  2. (Opcional) Cria endpoint de webhook em modo SHADOW
 *  3. write_enabled CONTINUA OFF — habilitacao real e manual depois.
 *  4. Mostra resumo + exemplos curl.
 */
class TenantOnboardCommand extends Command
{
    protected $signature = 'tenant:onboard
        {slug : Tenant slug (ex: dropksr)}
        {--webhook-url= : URL do endpoint webhook do whitelabel (opcional)}
        {--no-webhook : Pular criacao de endpoint}
        {--scopes=orders:read,suppliers:read,products:read,events:read : Scopes da chave}';
    protected $description = 'Onboarding tecnico de um whitelabel (emite key + endpoint webhook shadow)';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $tenant = Tenant::where('slug', $slug)->first();
        if (!$tenant) {
            $this->error("Tenant '{$slug}' nao encontrado.");
            return self::FAILURE;
        }
        if ($tenant->status === 'archived') {
            $this->warn("Tenant '{$slug}' esta archived. Reativar via 'UPDATE tenants SET status=active' antes de onboardar.");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info("=== Onboarding tecnico — {$tenant->name} ({$slug}) ===");
        $this->line("UUID: {$tenant->id}");
        $this->line("status atual: {$tenant->status} | write_enabled: " . ($tenant->write_enabled ? 'YES' : 'no'));
        $this->newLine();

        // 1) Emitir API key
        $scopes = array_values(array_filter(array_map('trim', explode(',', $this->option('scopes')))));
        $invalid = array_diff($scopes, TenantApiCredential::SCOPES);
        if (!empty($invalid)) {
            $this->error('Scopes invalidos: ' . implode(',', $invalid));
            return self::FAILURE;
        }

        $secret = Str::random(48);
        $keyId  = 'ht_live_' . Str::random(16);
        $token  = $keyId . '.' . $secret;
        TenantApiCredential::create([
            'tenant_id' => $tenant->id,
            'key_id'    => $keyId,
            'key_hash'  => password_hash($secret, PASSWORD_BCRYPT),
            'scopes'    => $scopes,
        ]);
        $this->info('1. API key emitida.');
        $this->line('   key_id: ' . $keyId);
        $this->line('   scopes: ' . implode(',', $scopes));
        $this->warn('   TOKEN (mostrado UMA VEZ — copie agora):');
        $this->line('   ' . $token);
        $this->newLine();

        // 2) Webhook endpoint
        $endpointSecret = null;
        if (!$this->option('no-webhook')) {
            $url = $this->option('webhook-url') ?: $this->ask('URL do webhook do whitelabel (deixe vazio pra pular)');
            if ($url) {
                $endpointSecret = Str::random(48);
                $ep = TenantWebhookEndpoint::create([
                    'tenant_id' => $tenant->id,
                    'url'       => $url,
                    'events'    => ['order.created', 'order.status_changed'],
                    'secret'    => $endpointSecret,
                    'active'    => true,
                    'shadow'    => true,
                ]);
                $this->info('2. Webhook endpoint criado (modo SHADOW).');
                $this->line('   id: ' . $ep->id);
                $this->line('   url: ' . $url);
                $this->line('   events: order.created, order.status_changed');
                $this->warn('   SECRET HMAC (entregue ao whitelabel pra validacao):');
                $this->line('   ' . $endpointSecret);
            } else {
                $this->line('2. Webhook endpoint NAO criado (sem URL informada).');
            }
        }

        $this->newLine();
        $this->info('=== Resumo / proximos passos ===');
        $this->line('Base URL: https://api.hubai.io/api/tenant-api/v1');
        $this->newLine();
        $this->line('Exemplo curl:');
        $this->line('  curl -H "Authorization: Bearer ' . $token . '" \\');
        $this->line('       "https://api.hubai.io/api/tenant-api/v1/orders?limit=10"');
        $this->newLine();

        if ($endpointSecret) {
            $this->line('Validacao HMAC do webhook (Node.js exemplo):');
            $this->line('  const sig = crypto.createHmac("sha256", "' . $endpointSecret . '")');
            $this->line('                    .update(rawBody).digest("hex");');
            $this->line('  if ("sha256=" + sig !== req.headers["x-hubai-signature"]) reject(401);');
            $this->newLine();
        }

        $this->line('Status atual:');
        $this->line('  - readonly: ATIVO');
        $this->line('  - write: DESLIGADO (write_enabled=0)');
        if ($endpointSecret) {
            $this->line('  - webhook: SHADOW (envia mas marca X-HubAI-Shadow: true)');
        }
        $this->newLine();
        $this->line('Pra ativar write quando estiver pronto:');
        $this->line("  UPDATE tenants SET write_enabled=1 WHERE slug='{$slug}';");
        $this->newLine();
        $this->line('Pra tirar webhook do modo shadow:');
        $this->line("  UPDATE tenant_webhook_endpoints SET shadow=0 WHERE tenant_id='{$tenant->id}';");
        $this->newLine();
        $this->line('Doc operacional: Obsidian/Projetos/Supplier Core - Onboarding Tecnico Whitelabel.md');

        return self::SUCCESS;
    }
}
