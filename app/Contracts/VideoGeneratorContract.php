<?php
namespace App\Contracts;

/**
 * SEL-429 -- Contrato para motores de geracao de video.
 */
interface VideoGeneratorContract
{
    /**
     * @param string $taskId   ID externo do job
     * @param string $kind     image2video | text2video
     * @param array  $payload  Dados do job
     * @return array           {ok, output_url, took_s, ...}
     */
    public function generate(string $taskId, string $kind, array $payload): array;
}
