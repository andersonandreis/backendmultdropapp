<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantApiCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TenantIssueKeyCommand extends Command
{
    protected $signature = 'tenant:issue-key {slug : Tenant slug (ex: multdrop)} {--scopes=orders:read,suppliers:read,products:read,events:read}';
    protected $description = 'Emite uma API key pra um tenant. Mostra a key UMA VEZ — armazena hashed.';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $tenant = Tenant::where('slug', $slug)->first();
        if (!$tenant) {
            $this->error("Tenant '{$slug}' nao encontrado.");
            return self::FAILURE;
        }

        $scopes = array_values(array_filter(array_map('trim', explode(',', $this->option('scopes')))));
        $invalid = array_diff($scopes, TenantApiCredential::SCOPES);
        if (!empty($invalid)) {
            $this->error('Scopes invalidos: ' . implode(',', $invalid));
            $this->line('Validos: ' . implode(',', TenantApiCredential::SCOPES));
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

        $this->newLine();
        $this->info("API key emitida pra tenant '{$slug}'.");
        $this->line('Scopes: ' . implode(',', $scopes));
        $this->newLine();
        $this->warn('TOKEN (mostrado UMA VEZ — copie agora):');
        $this->line($token);
        $this->newLine();
        $this->line('Uso: curl -H "Authorization: Bearer ' . $token . '" https://api.hubai.io/api/tenant-api/v1/orders');

        return self::SUCCESS;
    }
}
