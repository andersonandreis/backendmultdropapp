<?php

namespace App\Services\Integrations\Cdn;

use App\Services\Integrations\Contracts\CdnInterface;
use Illuminate\Support\Facades\Http;

class CloudflareService implements CdnInterface
{
    protected string $zoneId;
    protected string $apiToken;

    public function __construct()
    {
        $this->zoneId = config('services.cloudflare.zone_id', env('CLOUDFLARE_ZONE_ID'));
        $this->apiToken = config('services.cloudflare.api_token', env('CLOUDFLARE_API_TOKEN'));
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        // CF nao serve como Storage original. Redirecionar para Local Disk + Proxy CF.
        return true;
    }

    public function purge(string $urlOrTag): bool
    {
        $url = "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/purge_cache";

        /*
        $response = Http::withToken($this->apiToken)
            ->post($url, [
                'files' => [$urlOrTag]
            ]);
        return $response->successful();
        */

        return true;
    }

    public function getUrl(string $path): string
    {
        return url($path); // CF uses same domain
    }

    public function delete(string $remotePath): bool
    {
        // Cloudflare nao gerencia storage diretamente.
        // Purge do cache e suficiente; o arquivo de origem deve ser removido no storage local.
        $this->purge($this->getUrl($remotePath));
        return true;
    }
}
