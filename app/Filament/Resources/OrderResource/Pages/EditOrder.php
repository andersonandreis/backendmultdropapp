<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Payment;
use Filament\Notifications\Notification;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // MUL-217: popup com o JSON de resposta da API do marketplace formatado
            Actions\Action::make('jsonMarketplace')
                ->label('JSON Marketplace')
                ->icon('heroicon-o-code-bracket')
                ->color('info')
                ->modalHeading('Resposta da API do Marketplace (raw_payload)')
                ->modalWidth('5xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Fechar')
                ->form([
                    Forms\Components\Placeholder::make('raw_payload_json')
                        ->label('')
                        ->content(function (): HtmlString {
                            $raw = $this->getRecord()->raw_payload;
                            $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

                            if ($decoded === null || $decoded === []) {
                                return new HtmlString('<p style="opacity:0.5; font-size:0.85rem;">Este pedido não tem payload do marketplace (raw_payload vazio).</p>');
                            }

                            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                            return new HtmlString(
                                '<pre style="max-height:65vh; overflow:auto; font-size:0.75rem; line-height:1.5; padding:12px; border-radius:8px; background:#0f172a; color:#e2e8f0; white-space:pre-wrap; word-break:break-word;">'
                                . e($pretty)
                                . '</pre>'
                            );
                        }),
                ]),

            // ================================================================
            // NOV-207 Etapa 3d — Confirmar recebimento externo
            // Aparece quando: wallet_paid_at IS NULL E (label OK OU shipped/delivered)
            // ================================================================
            Actions\Action::make('confirmarRecebimentoExterno')
                ->label('Confirmar recebimento externo')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(function () {
                    $r = $this->getRecord();
                    if ($r->wallet_paid_at !== null) return false;
                    if ($r->canonical_status === 'cancelled') return false;
                    $temEtiqueta = ! empty($r->label_url) || ! empty($r->manual_label_path);
                    $jaEnviado   = in_array($r->canonical_status, ['shipped', 'delivered'], true);
                    return $temEtiqueta || $jaEnviado;
                })
                ->modalHeading('Confirmar que recebeu pagamento por fora do sistema')
                ->modalDescription('Registra o recebimento externo no sistema. Gera par credito+debito compensatorios na wallet (saldo liquido zero) + Payment gateway=external. Deixa auditoria completa.')
                ->modalSubmitActionLabel('Confirmar recebimento')
                ->form([
                    Forms\Components\Textarea::make('observacoes')
                        ->label('Observacoes do recebimento')
                        ->helperText('Explique como o pagamento aconteceu (PIX externo, transferencia, etc). Minimo 10 caracteres.')
                        ->rows(4)
                        ->required()
                        ->minLength(10)
                        ->maxLength(2000),
                ])
                ->action(function (array $data) {
                    $req = Request::create('', 'POST', ['observacoes' => $data['observacoes']]);
                    $req->setUserResolver(fn () => Auth::user());
                    try {
                        $resp = app(\App\Http\Controllers\Api\V1\ManualOrderController::class)
                            ->confirmExternalPayment($req, (int) $this->getRecord()->id);
                        $body = $resp->getData(true);
                        if ($resp->getStatusCode() === 200) {
                            Notification::make()
                                ->title('Recebimento externo confirmado')
                                ->body('Pedido #' . $this->getRecord()->id . ' marcado como pago. Auditoria em admin_note.')
                                ->success()->send();
                            $this->refreshFormData(['wallet_paid_at', 'admin_note']);
                        } else {
                            Notification::make()
                                ->title('Nao foi possivel confirmar')
                                ->body($body['message'] ?? $body['error'] ?? 'Erro desconhecido.')
                                ->danger()->send();
                        }
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Erro ao confirmar')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                }),

            // ================================================================
            // NOV-207 Etapa 3d — Estornar confirmacao externa
            // Aparece quando: existe Payment gateway=external status=paid
            // Estorno eh na wallet digital (dinheiro volta pro cliente).
            // ================================================================
            Actions\Action::make('estornarConfirmacaoExterna')
                ->label('Estornar confirmacao externa')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(function () {
                    $r = $this->getRecord();
                    return Payment::where('order_id', $r->id)
                        ->where('gateway', 'external')
                        ->where('status', 'paid')
                        ->exists();
                })
                ->modalHeading('Estornar confirmacao externa')
                ->modalDescription('CUIDADO: o valor volta pra wallet digital do cliente (credito real). O pedido volta a estar nao pago no sistema.')
                ->modalSubmitActionLabel('Estornar')
                ->modalSubmitAction(fn (\Filament\Actions\StaticAction $action) => $action->color('danger'))
                ->form([
                    Forms\Components\Checkbox::make('confirm')
                        ->label('Confirmo que quero estornar este pagamento externo')
                        ->required()
                        ->accepted()
                        ->validationMessages(['accepted' => 'Voce precisa confirmar para prosseguir.']),
                    Forms\Components\Textarea::make('motivo')
                        ->label('Motivo do estorno')
                        ->helperText('Explique o motivo. Minimo 10 caracteres.')
                        ->rows(4)
                        ->required()
                        ->minLength(10)
                        ->maxLength(2000),
                ])
                ->action(function (array $data) {
                    $req = Request::create('', 'POST', [
                        'confirm' => $data['confirm'] ? '1' : '',
                        'motivo'  => $data['motivo'],
                    ]);
                    $req->setUserResolver(fn () => Auth::user());
                    try {
                        $resp = app(\App\Http\Controllers\Api\V1\ManualOrderController::class)
                            ->revertExternalPayment($req, (int) $this->getRecord()->id);
                        $body = $resp->getData(true);
                        if ($resp->getStatusCode() === 200) {
                            Notification::make()
                                ->title('Confirmacao externa estornada')
                                ->body('Pedido #' . $this->getRecord()->id . '. R$ ' . number_format($body['balance_credited'] ?? 0, 2, ',', '.') . ' creditados na wallet do cliente.')
                                ->success()->send();
                            $this->refreshFormData(['wallet_paid_at', 'admin_note']);
                        } else {
                            Notification::make()
                                ->title('Nao foi possivel estornar')
                                ->body($body['message'] ?? $body['error'] ?? 'Erro desconhecido.')
                                ->danger()->send();
                        }
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Erro ao estornar')
                            ->body($e->getMessage())
                            ->danger()->send();
                    }
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
