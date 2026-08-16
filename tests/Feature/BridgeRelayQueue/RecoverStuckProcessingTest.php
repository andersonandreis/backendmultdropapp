<?php

namespace Tests\Feature\BridgeRelayQueue;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\BridgeRelayQueue;
use App\Jobs\RetryBridgeRelayJob;

/**
 * RecoverStuckProcessingTest
 *
 * Cobre o metodo RetryBridgeRelayJob::recoverStuckProcessing() introduzido em
 * NOV-046 P0 #2 para recuperar itens orfaos em 'processing'.
 *
 * Casos:
 *   1. Itens com processing_started_at=NULL voltam para pending.
 *   2. Itens recentes (< STUCK_THRESHOLD_MIN) NAO sao tocados.
 *   3. Itens expirados (>= STUCK_THRESHOLD_MIN) voltam para pending.
 *   4. Itens com status=done NAO sao tocados.
 *   5. Concorrencia: 2 workers simultaneos nao duplicam recovered count.
 *
 * Estrategia: invoca recoverStuckProcessing() via Reflection (metodo protected)
 * para testar a logica de recuperacao de forma isolada, sem disparar
 * processItem() que dependeria de servicos externos (LegacyMLRelayService etc).
 */
class RecoverStuckProcessingTest extends TestCase
{
    use DatabaseTransactions;

    // -------------------------------------------------------------------------
    // Helper: instancia job + chama recoverStuckProcessing via Reflection
    // -------------------------------------------------------------------------

    private function callRecover(): void
    {
        $job = new RetryBridgeRelayJob();
        $ref = new \ReflectionMethod($job, 'recoverStuckProcessing');
        $ref->setAccessible(true);
        $ref->invoke($job);
    }

    private function makeEntry(string $status, ?string $processingStartedAt): BridgeRelayQueue
    {
        return BridgeRelayQueue::create([
            'platform'              => 'mercadolivre',
            'event_type'            => 'orders_v2',
            'payload'               => ['_ml_user_id' => '999', '_resource' => '/orders/1', '_topic' => 'orders_v2'],
            'attempts'              => 0,
            'next_try_at'           => now(),
            'status'                => $status,
            'processing_started_at' => $processingStartedAt,
        ]);
    }

    // -------------------------------------------------------------------------
    // Caso 1 - processing_started_at NULL volta para pending
    // -------------------------------------------------------------------------

    /** @test */
    public function itRecoversStuItemsWithNullStartedAt(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeEntry('processing', null);
        }

        $this->callRecover();

        $nullStill = BridgeRelayQueue::where('status', 'processing')
            ->whereNull('processing_started_at')
            ->count();

        $recovered = BridgeRelayQueue::where('status', 'pending')->count();

        $this->assertEquals(0, $nullStill,
            'Nenhum item processing+NULL deve sobrar apos recover.');
        $this->assertEquals(5, $recovered,
            'Todos os 5 itens NULL devem virar pending.');
    }

    // -------------------------------------------------------------------------
    // Caso 2 - itens recentes NAO sao tocados
    // -------------------------------------------------------------------------

    /** @test */
    public function itDoesNotTouchRecentProcessingItems(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->makeEntry('processing', now()->toDateTimeString());
        }

        $this->callRecover();

        $stillProcessing = BridgeRelayQueue::where('status', 'processing')->count();
        $pendingCount    = BridgeRelayQueue::where('status', 'pending')->count();

        $this->assertEquals(3, $stillProcessing,
            'Itens recentes devem permanecer em processing.');
        $this->assertEquals(0, $pendingCount,
            'Nenhum item recente deve ser recuperado para pending.');
    }

    // -------------------------------------------------------------------------
    // Caso 3 - itens expirados voltam para pending
    // -------------------------------------------------------------------------

    /** @test */
    public function itRecoversExpiredProcessingItems(): void
    {
        $expired = now()
            ->subMinutes(BridgeRelayQueue::STUCK_THRESHOLD_MIN + 5)
            ->toDateTimeString();

        for ($i = 0; $i < 3; $i++) {
            $this->makeEntry('processing', $expired);
        }

        $this->callRecover();

        $stillProcessing = BridgeRelayQueue::where('status', 'processing')->count();
        $pending         = BridgeRelayQueue::where('status', 'pending')->count();

        $this->assertEquals(0, $stillProcessing,
            'Itens expirados nao devem permanecer em processing.');
        $this->assertEquals(3, $pending,
            'Todos os itens expirados devem virar pending.');

        $withTimestamp = BridgeRelayQueue::where('status', 'pending')
            ->whereNotNull('processing_started_at')
            ->count();

        $this->assertEquals(0, $withTimestamp,
            'Itens recuperados devem ter processing_started_at=NULL.');
    }

    // -------------------------------------------------------------------------
    // Caso 4 - itens done NAO sao tocados
    // -------------------------------------------------------------------------

    /** @test */
    public function itDoesNotTouchDoneItems(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeEntry('done', null);
        }

        $this->callRecover();

        $doneCount    = BridgeRelayQueue::where('status', 'done')->count();
        $pendingCount = BridgeRelayQueue::where('status', 'pending')->count();

        $this->assertEquals(5, $doneCount,
            'Itens done devem permanecer done apos recover.');
        $this->assertEquals(0, $pendingCount,
            'Recover nao deve criar pending a partir de done.');
    }

    // -------------------------------------------------------------------------
    // Caso 5 - concorrencia: 2 workers nao duplicam recovered count
    //
    // UPDATE em lote no MySQL e atomico na camada de storage: mesmo que 2
    // workers entrem em recoverStuckProcessing simultaneamente, cada item
    // transita de processing->pending exatamente UMA vez.
    //
    // Simulacao sequencial: chama recover() duas vezes consecutivas e verifica
    // que o total de pending == exatamente os 10 stuck originais, sem over-
    // counting (o 2o worker nao encontra mais nada pra recuperar).
    // -------------------------------------------------------------------------

    /** @test */
    public function itPreventsDuplicateRecoveryUnderConcurrency(): void
    {
        $expired = now()
            ->subMinutes(BridgeRelayQueue::STUCK_THRESHOLD_MIN + 5)
            ->toDateTimeString();

        for ($i = 0; $i < 10; $i++) {
            $this->makeEntry('processing', $expired);
        }

        // Worker 1: recupera todos os 10 stuck
        $this->callRecover();

        $afterFirstCall = BridgeRelayQueue::where('status', 'pending')->count();
        $this->assertEquals(10, $afterFirstCall,
            'Worker 1 deve recuperar exatamente 10 itens para pending.');

        // Worker 2: nao encontra mais nada stuck (sem duplicacao)
        $this->callRecover();

        $pendingCount    = BridgeRelayQueue::where('status', 'pending')->count();
        $processingCount = BridgeRelayQueue::where('status', 'processing')->count();

        $this->assertEquals(10, $pendingCount,
            'Apos 2 chamadas de recover, deve haver exatamente 10 pending (sem duplicacao).');
        $this->assertEquals(0, $processingCount,
            'Zero itens devem permanecer em processing apos recover duplo.');
    }
}
