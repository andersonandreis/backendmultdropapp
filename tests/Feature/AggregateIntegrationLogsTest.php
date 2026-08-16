<?php

namespace Tests\Feature;

use App\Models\IntegrationLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HUB-032 — Garante que o agregador eh idempotente:
 * rodar duas vezes nao duplica linhas.
 */
class AggregateIntegrationLogsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_agregador_eh_idempotente(): void
    {
        if (! Schema::hasTable('webhook_logs')) {
            $this->markTestSkipped('webhook_logs nao existe neste ambiente');
        }

        $marker = 'hub-032-test-' . uniqid();

        $id1 = DB::table('webhook_logs')->insertGetId([
            'platform' => 'mercadolivre', 'topic' => 'orders',
            'resource' => '/' . $marker . '/1', 'user_id' => '1',
            'status' => 'processed', 'payload' => json_encode(['marker' => $marker]),
            'created_at' => now(),
        ]);
        $id2 = DB::table('webhook_logs')->insertGetId([
            'platform' => 'shopee', 'topic' => 'orders',
            'resource' => '/' . $marker . '/2', 'user_id' => '2',
            'status' => 'failed', 'payload' => json_encode(['marker' => $marker]),
            'error_message' => 'boom',
            'created_at' => now(),
        ]);

        $countQuery = fn () => IntegrationLog::query()
            ->where('source_table', 'webhook_logs')
            ->whereIn('source_id', [(string) $id1, (string) $id2])
            ->count();

        $this->artisan('integration:aggregate-logs', ['--full' => true])->assertSuccessful();
        $this->assertSame(2, $countQuery());

        // segunda execucao nao duplica
        $this->artisan('integration:aggregate-logs')->assertSuccessful();
        $this->assertSame(2, $countQuery());
    }
}
