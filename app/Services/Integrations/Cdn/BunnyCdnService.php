<?php

namespace App\Services\Integrations\Cdn;

use App\Services\Integrations\Contracts\CdnInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BunnyCdnService implements CdnInterface
{
    protected string $storageZone;
    protected string $accessKey;
    protected string $pullZoneBaseUrl;
    protected string $tenantPrefix;
    protected string $storageHost;

    public function __construct()
    {
        $this->storageZone = (string) (config('services.bunnycdn.storage_zone') ?? env('BUNNYCDN_STORAGE_ZONE') ?? '');
        $this->accessKey = (string) (config('services.bunnycdn.access_key') ?? env('BUNNYCDN_ACCESS_KEY') ?? '');
        $this->pullZoneBaseUrl = (string) (config('services.bunnycdn.pull_zone_url') ?? env('BUNNYCDN_PULL_ZONE_URL', 'https://hub-imgcdn.b-cdn.net'));
        $this->tenantPrefix = trim((string) config('services.bunnycdn.tenant_prefix', ''), '/');
        $this->storageHost = (string) config('services.bunnycdn.storage_host', 'storage.bunnycdn.com');
    }

    /** fix Bug3: se accessKey nao configurado, skip silently em vez de crashar TypeError */
    private function ready(): bool
    {
        if ($this->accessKey === '' || $this->storageZone === '') {
            Log::warning('[BunnyCdn] pulou operacao — BUNNYCDN_ACCESS_KEY/STORAGE_ZONE nao configurado no .env');
            return false;
        }
        return true;
    }

    /** MUL-208 Fase 4: prefixa $remotePath com tenant_prefix se configurado */
    private function tenantPath(string $remotePath): string
    {
        $remotePath = ltrim($remotePath, '/');
        return $this->tenantPrefix ? $this->tenantPrefix.'/'.$remotePath : $remotePath;
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        if (!$this->ready())
            return false;
        if (!file_exists($localPath))
            return false;

        $url = "https://{$this->storageHost}/{$this->storageZone}/" . $this->tenantPath($remotePath);

        $response = Http::withHeaders([
            'AccessKey' => $this->accessKey,
            'Content-Type' => 'application/octet-stream',
        ])->send('PUT', $url, [
                    'body' => file_get_contents($localPath)
                ]);

        return $response->successful();
    }

    public function purge(string $urlOrTag): bool
    {
        // BunnyCDN Purge API mock
        /*
        $response = Http::post("https://api.bunny.net/purge?url=" . urlencode($urlOrTag), [
             // headers token etc
        ]);
        */
        return true;
    }

    public function getUrl(string $path): string
    {
        return rtrim($this->pullZoneBaseUrl, '/') . '/' . $this->tenantPath($path);
    }

    public function delete(string $remotePath): bool
    {
        if (!$this->ready())
            return false;

        $url = "https://{$this->storageHost}/{$this->storageZone}/" . $this->tenantPath($remotePath);

        $response = Http::withHeaders([
            'AccessKey' => $this->accessKey,
        ])->delete($url);

        if ($response->failed()) {
            Log::warning('[BunnyCdn] Falha ao deletar arquivo', [
                'path'   => $remotePath,
                'status' => $response->status(),
            ]);
            return false;
        }

        return true;
    }
}
