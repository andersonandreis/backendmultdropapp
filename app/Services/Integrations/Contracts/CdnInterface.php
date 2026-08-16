<?php

namespace App\Services\Integrations\Contracts;

interface CdnInterface
{
    /**
     * Faz upload do arquivo para a Edge / CDN
     */
    public function upload(string $localPath, string $remotePath): bool;

    /**
     * Forca purge/invalidacao do Cache na rota URL fornecida
     */
    public function purge(string $urlOrTag): bool;

    /**
     * Retorna a URL publica resolvivel pro arquivo
     */
    public function getUrl(string $path): string;

    /**
     * Remove um arquivo da CDN / storage remoto
     */
    public function delete(string $remotePath): bool;
}
