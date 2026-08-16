<?php

namespace App\Services\Integrations\Cdn;

use App\Services\Integrations\Contracts\CdnInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Implementacao de CdnInterface usando o filesystem local.
 * Ideal para desenvolvimento e ambientes sem CDN configurada.
 */
class LocalStorageService implements CdnInterface
{
    protected string $disk;

    public function __construct()
    {
        $this->disk = config('cdn.local_disk', 'public');
    }

    public function upload(string $localPath, string $remotePath): bool
    {
        if (!file_exists($localPath)) {
            return false;
        }

        return Storage::disk($this->disk)->put($remotePath, file_get_contents($localPath));
    }

    public function purge(string $urlOrTag): bool
    {
        // Sem cache para purgar no storage local
        return true;
    }

    public function getUrl(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }

    public function delete(string $remotePath): bool
    {
        return Storage::disk($this->disk)->delete($remotePath);
    }
}
