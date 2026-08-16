<?php
namespace App\Filament\Resources\AffiliateResource\Pages;

use App\Filament\Resources\AffiliateResource;
use App\Models\Affiliate;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewAffiliate extends ViewRecord
{
    protected static string $resource = AffiliateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Editar'),
            Actions\DeleteAction::make()->label('Excluir')->requiresConfirmation(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Identificação')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('user.name')->label('Nome'),
                    Infolists\Components\TextEntry::make('user.email')->label('E-mail')->copyable(),
                    Infolists\Components\TextEntry::make('referral_code')->label('Código')->copyable()->weight('bold')->color('primary'),
                ]),
            Infolists\Components\Section::make('Status + Comissão')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('approval_status')->badge()
                        ->colors(['success'=>'approved','warning'=>'pending','danger'=>'rejected','gray'=>'suspended']),
                    Infolists\Components\TextEntry::make('status')->label('Ativo?')->badge(),
                    Infolists\Components\TextEntry::make('commission_rate')->label('Taxa')->suffix('%')->weight('bold'),
                    Infolists\Components\TextEntry::make('approved_at')->dateTime('d/m/Y H:i')->placeholder('—'),
                ]),
            Infolists\Components\Section::make('📊 KPIs (dinâmico do DB)')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('kpi_referrals')
                        ->label('Total Indicados')
                        ->state(fn(Affiliate $r) => $r->referrals()->count())
                        ->badge()->color('info'),
                    Infolists\Components\TextEntry::make('kpi_conversions')
                        ->label('Converteram (pagaram)')
                        ->state(fn(Affiliate $r) => $r->referrals()->where('status','converted')->count())
                        ->badge()->color('success'),
                    Infolists\Components\TextEntry::make('kpi_pending')
                        ->label('Comissão Pendente')
                        ->state(fn(Affiliate $r) => 'R$ '.number_format((float) $r->commissions()->where('status','pending')->sum('commission_amount'), 2, ',', '.'))
                        ->badge()->color('warning'),
                    Infolists\Components\TextEntry::make('kpi_paid')
                        ->label('Já Pago')
                        ->state(fn(Affiliate $r) => 'R$ '.number_format((float) $r->commissions()->where('status','paid')->sum('commission_amount'), 2, ',', '.'))
                        ->badge()->color('success')->weight('bold'),
                ]),
            Infolists\Components\Section::make('Perks / Quotas / PIX')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('max_ai_videos_per_month')->label('Videos IA/mês')->placeholder('0'),
                    Infolists\Components\TextEntry::make('granted_plan_slug')->label('Plano bônus')->placeholder('—'),
                    Infolists\Components\TextEntry::make('pix_key')->label('Chave PIX')->placeholder('—')->copyable(),
                ]),
            Infolists\Components\Section::make('Aplicação (form público)')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('application_phone')->label('WhatsApp')->placeholder('—'),
                    Infolists\Components\TextEntry::make('application_instagram')->label('Instagram')->placeholder('—'),
                    Infolists\Components\TextEntry::make('application_tiktok')->label('TikTok')->placeholder('—'),
                    Infolists\Components\TextEntry::make('application_motivation')->label('Motivação')->placeholder('—')->columnSpanFull(),
                ])->collapsed(),
        ]);
    }
}
