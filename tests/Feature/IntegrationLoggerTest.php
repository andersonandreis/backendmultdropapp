<?php

namespace Tests\Feature;

use App\Models\IntegrationLog;
use App\Services\IntegrationLogger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * HUB-032 — Testes do logger central de integracoes.
 */
class IntegrationLoggerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_inbound_grava_linha_em_integration_logs(): void
    {
        $log = IntegrationLogger::inbound([
            'integration_name' => 'pagarme',
            'method'           => 'POST',
            'url'              => '/webhook/pagarme',
            'status_code'      => 200,
            'status'           => 'processed',
            'request_payload'  => ['event' => 'charge.paid'],
        ]);

        $this->assertNotNull($log);
        $this->assertSame('inbound', $log->direction);
        $this->assertDatabaseHas('integration_logs', [
            'integration_name' => 'pagarme',
            'direction'        => 'inbound',
            'status_code'      => 200,
        ]);
    }

    public function test_outbound_grava_linha_em_integration_logs(): void
    {
        $log = IntegrationLogger::outbound([
            'integration_name' => 'mercadolivre',
            'method'           => 'GET',
            'url'              => 'https://api.mercadolibre.com/orders/123',
            'status_code'      => 200,
        ]);

        $this->assertNotNull($log);
        $this->assertSame('outbound', $log->direction);
    }

    public function test_sanitize_mascara_tokens_e_secrets(): void
    {
        $log = IntegrationLogger::outbound([
            'integration_name' => 'bling',
            'url'              => 'https://api.bling.com.br/orders',
            'status_code'      => 200,
            'request_payload'  => [
                'access_token' => 'tok_AbCdEfGhIjK',
                'client_secret' => 's3cr3t',
                'order_id' => 99,
                'nested' => [
                    'api_key' => 'KEY_123',
                    'safe' => 'ok',
                ],
            ],
        ]);

        $payload = $log->request_payload;
        $this->assertSame('***', $payload['access_token']);
        $this->assertSame('***', $payload['client_secret']);
        $this->assertSame(99, $payload['order_id']);
        $this->assertSame('***', $payload['nested']['api_key']);
        $this->assertSame('ok', $payload['nested']['safe']);
    }

    public function test_trunca_payload_acima_de_8kb(): void
    {
        $big = ['data' => str_repeat('x', 20000)];
        $log = IntegrationLogger::outbound([
            'integration_name' => 'openai',
            'url'              => 'https://api.openai.com/v1/chat',
            'status_code'      => 200,
            'request_payload'  => $big,
        ]);

        $payload = $log->request_payload;
        $this->assertTrue($payload['_truncated']);
        $this->assertGreaterThan(IntegrationLogger::MAX_PAYLOAD_BYTES, $payload['_original_size']);
    }

    public function test_scope_failed_filtra_erros(): void
    {
        IntegrationLogger::outbound(['integration_name' => 'a', 'status_code' => 200]);
        IntegrationLogger::outbound(['integration_name' => 'b', 'status_code' => 500]);
        IntegrationLogger::outbound(['integration_name' => 'c', 'status' => 'failed']);

        $failed = IntegrationLog::query()->failed()->get();
        $this->assertCount(2, $failed);
    }
}
