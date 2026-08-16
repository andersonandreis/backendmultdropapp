<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateDiscountRampCommand extends Command
{
    protected $signature = 'discount:update-daily';

    protected $description = 'Atualiza o desconto gradual dos clientes (1%/dia apos ramp_start, ate 0%)';

    public function handle(): int
    {
        $this->info('Iniciando atualizacao de descontos...');

        // Clientes com ramp ativo E desconto ainda maior que zero
        $clientes = Client::whereNotNull('discount_ramp_start')
            ->where('current_discount_percent', '>', 0)
            ->get(['id', 'discount_ramp_start', 'current_discount_percent']);

        $atualizados = 0;
        $zerados     = 0;

        foreach ($clientes as $client) {
            $diasDesdeRamp = (int) now()->diffInDays($client->discount_ramp_start);
            // Desconto inicial 50, reduz 1% por dia desde ramp_start
            $novoDesconto = max(0, 50 - $diasDesdeRamp);

            if ($novoDesconto === (int) $client->current_discount_percent) {
                continue; // sem mudanca
            }

            $client->current_discount_percent = $novoDesconto;
            $client->saveQuietly();

            if ($novoDesconto === 0) {
                $zerados++;
            }
            $atualizados++;
        }

        $this->info("Clientes atualizados: {$atualizados} (zerados: {$zerados})");

        Log::info('[discount:update-daily] concluido', [
            'atualizados' => $atualizados,
            'zerados'     => $zerados,
        ]);

        return self::SUCCESS;
    }
}
