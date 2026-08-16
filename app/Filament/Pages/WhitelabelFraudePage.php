<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * NOV-214 / Antifraude WL MVP — tela de revisao de suspeitas de fraude de cobranca WL.
 * URL: /admin/whitelabel-fraude
 *
 * Lista clientes que foram excluidos/desativados ate 3 dias antes do fechamento do ciclo
 * e reativados/recriados ate 3 dias depois — padrao classico de fuga de cobranca.
 *
 * Botao "Cobrar Mesmo Assim" insere ajuste manual em wl_client_audit_log marcando
 * que a suspeita foi revisada e a cobranca foi mantida.
 */
class WhitelabelFraudePage extends Page
{
    protected static ?string $navigationIcon  = "heroicon-o-shield-exclamation";
    protected static ?string $title           = "Antifraude WL";
    protected static ?string $navigationLabel = "Antifraude WL";
    protected static ?string $navigationGroup = "Whitelabels";
    protected static ?string $slug            = "whitelabel-fraude";
    protected static ?int    $navigationSort  = 90;
    protected static string  $view            = "filament.pages.whitelabel-fraude";

    public string $cycleEnd  = "";
    public int    $empresaId = 0;
    public int    $window    = 3;

    public array $suspects = [];
    public int   $total    = 0;
    public string $message  = "";

    // Mapa empresa_id => nome legivel
    protected array $empresaNames = [
        15 => "PlugLar",
        17 => "JTDrop",
        20 => "MEStoreDrop",
        21 => "DropKsr",
        22 => "Fornecefy",
        24 => "MultDrop",
    ];

    public static function canAccess(): bool
    {
        return auth()->user()?->role === "super_admin";
    }

    public function mount(): void
    {
        // Default: ciclo mais recente (01/07-01/08)
        $this->cycleEnd  = "2026-08-01";
        $this->empresaId = 24; // MultDrop como default
    }

    public function search(): void
    {
        $this->suspects = [];
        $this->total    = 0;

        if (! $this->empresaId || ! $this->cycleEnd) {
            $this->message = "Preencha empresa e data de fechamento.";
            return;
        }

        $cycleEnd = Carbon::parse($this->cycleEnd);
        $window   = max(1, min(14, $this->window));

        // Emails com acao DELETE/deactivate antes do fechamento
        $emailsBefore = DB::table("wl_client_audit_log")
            ->where("empresa_id", $this->empresaId)
            ->whereIn("action", ["delete", "deactivate"])
            ->whereBetween("changed_at", [
                $cycleEnd->copy()->subDays($window)->startOfDay(),
                $cycleEnd->copy()->endOfDay(),
            ])
            ->whereNotNull("email")
            ->pluck("email")
            ->unique()
            ->toArray();

        if (empty($emailsBefore)) {
            $this->message = "Nenhuma acao suspeita nos {$window} dias antes do fechamento.";
            return;
        }

        // Desses emails, quais voltaram depois
        $returned = DB::table("wl_client_audit_log")
            ->where("empresa_id", $this->empresaId)
            ->where("action", "reactivate")
            ->whereBetween("changed_at", [
                $cycleEnd->copy()->startOfDay(),
                $cycleEnd->copy()->addDays($window)->endOfDay(),
            ])
            ->whereIn("email", $emailsBefore)
            ->pluck("email")
            ->toArray();

        // Complemento via snapshot
        $returnedViaSnapshot = DB::table("wl_client_snapshots")
            ->where("empresa_id", $this->empresaId)
            ->where("is_active", 1)
            ->whereNull("blocked_at")
            ->whereBetween("snapshot_date", [
                $cycleEnd->copy()->toDateString(),
                $cycleEnd->copy()->addDays($window + 7)->toDateString(),
            ])
            ->whereIn("email", $emailsBefore)
            ->pluck("email")
            ->toArray();

        $suspicious = array_unique(array_merge($returned, $returnedViaSnapshot));

        if (empty($suspicious)) {
            $this->message = "Nenhum retorno detectado apos o fechamento — sem suspeitas de fraude.";
            return;
        }

        // Constroi lista
        foreach ($suspicious as $email) {
            $origActions = DB::table("wl_client_audit_log")
                ->where("empresa_id", $this->empresaId)
                ->whereIn("action", ["delete", "deactivate"])
                ->whereBetween("changed_at", [
                    $cycleEnd->copy()->subDays($window)->startOfDay(),
                    $cycleEnd->copy()->endOfDay(),
                ])
                ->where("email", $email)
                ->orderBy("changed_at")
                ->get(["action", "changed_at", "client_id"]);

            $this->suspects[] = [
                "email"        => $email,
                "actions"      => $origActions->map(fn($a) => [
                    "action"     => $a->action,
                    "changed_at" => Carbon::parse($a->changed_at)->format("d/m H:i"),
                    "client_id"  => $a->client_id,
                ])->toArray(),
                "fraud_score"  => $origActions->count() >= 2 ? "Alto" : "Medio",
                "reviewed"     => false,
            ];
        }

        $this->total   = count($this->suspects);
        $this->message = "Encontrados {$this->total} cliente(s) suspeito(s) de manipulacao pre-fechamento.";
    }

    public function chargeAnyway(string $email): void
    {
        try {
            DB::table("wl_client_audit_log")->insert([
                "empresa_id"  => $this->empresaId,
                "wl_database" => "hubaiapp",
                "client_id"   => 0,
                "email"       => $email,
                "action"      => "reactivate",
                "before"      => json_encode(["reviewed" => true, "decision" => "charge_anyway"]),
                "after"       => json_encode(["cycle_end" => $this->cycleEnd, "reviewed_by" => auth()->user()?->email]),
                "changed_at"  => now(),
                "changed_by_user_id" => auth()->id(),
                "ip_address"  => request()->ip(),
                "created_at"  => now(),
                "updated_at"  => now(),
            ]);

            Notification::make()
                ->title("Cobranca mantida para {$email}")
                ->success()
                ->send();

            // Remove da lista local
            $this->suspects = array_values(
                array_filter($this->suspects, fn($s) => $s["email"] !== $email)
            );
            $this->total = count($this->suspects);

        } catch (\Throwable $e) {
            Notification::make()
                ->title("Erro ao registrar decisao: " . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}
