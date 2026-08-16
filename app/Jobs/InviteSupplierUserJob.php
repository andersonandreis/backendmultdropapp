<?php

namespace App\Jobs;

use App\Models\SupplierUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** NOV-131 — Envio de e-mail de convite. */
class InviteSupplierUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public int $supplierUserId, public string $email) {}

    public function handle(): void
    {
        $su = SupplierUser::withoutGlobalScopes()->find($this->supplierUserId);
        if (!$su) return;

        $link = url('/team/invite/'.$su->invite_token);
        $supplierName = $su->supplier?->company_name ?? 'sua loja';

        try {
            Mail::raw(
                "Olá!\n\nVocê foi convidado para fazer parte da equipe de {$supplierName} na plataforma HubAI.\n\nClique no link abaixo para aceitar o convite:\n\n{$link}\n\nObrigado!",
                function ($m) use ($supplierName) {
                    $m->to($this->email)->subject("Convite para {$supplierName} — HubAI");
                }
            );
            Log::info('[NOV-131] Convite enviado', ['supplier_user_id' => $su->id, 'email' => $this->email]);
        } catch (\Throwable $e) {
            Log::error('[NOV-131] Falha envio convite', ['err' => $e->getMessage()]);
        }
    }
}
