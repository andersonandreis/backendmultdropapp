<?php

namespace App\Providers\Filament\App;

use Filament\Navigation\NavigationGroup;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\Enums\ThemeMode;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Support\HtmlString;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Patch JS: corrige "showModal on already-open dialog" (Filament 3 + Livewire race condition)
        // e "classList of null" causado por referências stale em NodeList.forEach
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn(): HtmlString => new HtmlString('
                <script>
                (function() {
                    // Fix: showModal chamado em dialog ja aberto como nao-modal
                    const _orig = HTMLDialogElement.prototype.showModal;
                    HTMLDialogElement.prototype.showModal = function() {
                        if (this.open) { try { this.close(); } catch(e) {} }
                        return _orig.call(this);
                    };

                    // Fix: classList de null em NodeList.forEach (tabs Filament stale refs)
                    // Intercepta erros silenciosamente sem quebrar UX
                    window.addEventListener("error", function(e) {
                        if (e.message && e.message.includes("classList") && e.message.includes("null")) {
                            e.preventDefault();
                            return true;
                        }
                    }, true);
                })();
                </script>
            '),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_START,
            fn(): HtmlString => new HtmlString('
                <style>
                    /* ═══════════════════════════════════════════════════
                       HubAI Seller — Theme v3  (Dark + Light)
                       ═══════════════════════════════════════════════════ */

                    /* ── 1. DESIGN TOKENS ── */
                    :root {
                        --hub-emerald: 16,185,129;
                        --hub-cyan: 6,182,212;
                        --hub-red: 239,68,68;
                        --hub-amber: 245,158,11;
                        --hub-radius-sm: 8px;
                        --hub-radius-md: 12px;
                        --hub-radius-lg: 16px;
                        --hub-radius-xl: 20px;
                        --hub-t: 150ms ease;
                        --hub-t-slow: 300ms ease;
                    }

                    /* ── 2. GLOBAL RESETS ── */
                    body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
                    footer, .fi-topbar-item[href*="filamentphp.com"] { display: none !important; }
                    ::-webkit-scrollbar { width: 5px; height: 5px; }
                    ::-webkit-scrollbar-track { background: transparent; }

                    /* ── 3. LAYOUT ── */
                    .fi-main { padding: 1.5rem !important; }
                    .fi-main-ctn { max-width: 100% !important; padding: 0 !important; }
                    .fi-topbar { width: 100% !important; margin: 0 !important; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); transition: background var(--hub-t), border-color var(--hub-t); }
                    .fi-topbar > nav { max-width: 100% !important; padding: 0 1.5rem !important; }
                    .fi-layout > .fi-sidebar-active { padding-left: 0 !important; }

                    /* ── 4. SECTIONS & CARDS ── */
                    .fi-section { border-radius: var(--hub-radius-lg) !important; transition: border-color var(--hub-t), box-shadow var(--hub-t); }
                    .fi-wi-stats-overview-stat { border-radius: var(--hub-radius-lg) !important; transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s; font-variant-numeric: tabular-nums; }
                    .fi-wi-stats-overview-stat:hover { transform: translateY(-2px); }
                    .fi-wi-chart { border-radius: var(--hub-radius-lg) !important; }

                    /* ── 5. TABLES ── */
                    .fi-ta-ctn { border-radius: var(--hub-radius-xl) !important; overflow: hidden; transition: border-color var(--hub-t); }
                    .fi-ta-header-ctn { background: transparent !important; border: none !important; }
                    .fi-ta-content { border: none !important; }
                    .fi-ta-table { border-spacing: 0 3px !important; border-collapse: separate !important; }
                    .fi-ta-row { border-radius: var(--hub-radius-sm) !important; transition: background var(--hub-t); }
                    .fi-ta-cell { border: none !important; }
                    .fi-ta-record-action { transition: all 0.2s; }

                    /* ── 6. SIDEBAR ── */
                    .fi-sidebar-nav { padding: 0.5rem !important; }
                    .fi-sidebar-item { margin-bottom: 1px !important; }
                    .fi-sidebar-item-button { border-radius: var(--hub-radius-sm) !important; padding: 0.55rem 0.75rem !important; font-size: 0.84rem !important; transition: all var(--hub-t); }
                    .fi-sidebar-group { margin-bottom: 0 !important; padding: 0 !important; }
                    .fi-sidebar-group-items { gap: 1px !important; }
                    .fi-sidebar-group-label { display: none !important; }
                    .fi-sidebar-group-collapse-button { display: none !important; }

                    /* ── 7. INPUTS & BUTTONS ── */
                    .fi-input-wrp { border-radius: 10px !important; transition: border-color var(--hub-t), box-shadow var(--hub-t); }
                    .fi-btn-color-primary { background: linear-gradient(135deg, rgb(var(--hub-emerald)) 0%, rgb(5,150,105) 100%) !important; border: none !important; box-shadow: 0 2px 8px rgba(var(--hub-emerald),0.25) !important; transition: box-shadow 0.2s, transform 0.15s; }
                    .fi-btn-color-primary:hover { box-shadow: 0 4px 20px rgba(var(--hub-emerald),0.35) !important; transform: translateY(-1px); }
                    .fi-btn-color-primary:active { transform: translateY(0); }
                    .fi-progress-bar { background: linear-gradient(90deg, rgb(var(--hub-emerald)), rgb(var(--hub-cyan))) !important; }

                    /* ── 8. ANIMATIONS ── */
                    @keyframes hub-fade-in { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
                    .fi-section, .fi-wi-stats-overview-stat, .fi-ta-ctn { animation: hub-fade-in 0.3s ease both; }
                    .fi-wi-stats-overview-stat:nth-child(2) { animation-delay: 50ms; }
                    .fi-wi-stats-overview-stat:nth-child(3) { animation-delay: 100ms; }
                    .fi-wi-stats-overview-stat:nth-child(4) { animation-delay: 150ms; }

                    /* ── 9. BADGES ── */
                    .fi-badge { font-weight: 600 !important; border-radius: 6px !important; }

                    /* ══════════════════════════════════════
                       DARK THEME
                       ══════════════════════════════════════ */
                    .dark .fi-topbar { border-bottom: 1px solid rgba(var(--hub-emerald),0.08) !important; background: rgba(2,6,16,0.92) !important; }
                    .dark .fi-sidebar { border-right: 1px solid rgba(var(--hub-emerald),0.06) !important; background: linear-gradient(180deg, #010409 0%, #060e12 100%) !important; }
                    .dark .fi-panel { background: linear-gradient(160deg, #010409 0%, #081214 40%, #060d10 100%) !important; }

                    .dark .fi-section { border: 1px solid rgba(var(--hub-emerald),0.08) !important; box-shadow: 0 2px 16px rgba(0,0,0,0.35), 0 0 1px rgba(var(--hub-emerald),0.1) !important; background: rgba(8,18,22,0.9) !important; }
                    .dark .fi-wi-stats-overview-stat { background: rgba(8,18,22,0.95) !important; border: 1px solid rgba(var(--hub-emerald),0.1) !important; box-shadow: 0 2px 12px rgba(0,0,0,0.25) !important; }
                    .dark .fi-wi-stats-overview-stat:hover { border-color: rgba(var(--hub-emerald),0.3) !important; box-shadow: 0 8px 32px rgba(var(--hub-emerald),0.08), 0 0 0 1px rgba(var(--hub-emerald),0.15) !important; }

                    .dark .fi-ta-ctn { border: 1px solid rgba(var(--hub-emerald),0.06) !important; box-shadow: 0 4px 24px rgba(0,0,0,0.35) !important; background: rgba(8,18,22,0.9) !important; }
                    .dark .fi-ta-row { background: rgba(10,22,28,0.7) !important; }
                    .dark .fi-ta-row:nth-child(even) { background: rgba(12,26,32,0.5) !important; }
                    .dark .fi-ta-row:hover { background: rgba(var(--hub-emerald),0.06) !important; }

                    .dark .fi-sidebar-item-button { color: rgba(248,250,252,0.55) !important; }
                    .dark .fi-sidebar-item-button:hover { background: rgba(var(--hub-emerald),0.08) !important; color: rgba(248,250,252,0.9) !important; }
                    .dark .fi-sidebar-item-button.fi-active { background: rgba(var(--hub-emerald),0.12) !important; color: rgb(110,231,183) !important; font-weight: 600; box-shadow: inset 3px 0 0 rgb(var(--hub-emerald)); }

                    .dark .fi-section-header-heading { color: rgb(248,250,252) !important; }
                    .dark .fi-wi-stats-overview-stat-label { color: rgba(248,250,252,0.5) !important; font-size: 0.78rem !important; }
                    .dark .fi-wi-stats-overview-stat-value { color: rgb(248,250,252) !important; font-size: 1.5rem !important; font-weight: 700 !important; }

                    .dark .fi-badge[data-color="success"] { background: rgba(var(--hub-emerald),0.12) !important; color: rgb(52,211,153) !important; border: 1px solid rgba(var(--hub-emerald),0.25) !important; }
                    .dark .fi-badge[data-color="warning"] { background: rgba(var(--hub-amber),0.12) !important; color: rgb(251,191,36) !important; border: 1px solid rgba(var(--hub-amber),0.25) !important; }
                    .dark .fi-badge[data-color="danger"]  { background: rgba(var(--hub-red),0.12) !important; color: rgb(252,165,165) !important; border: 1px solid rgba(var(--hub-red),0.25) !important; }
                    .dark .fi-badge[data-color="info"]    { background: rgba(var(--hub-cyan),0.12) !important; color: rgb(103,232,249) !important; border: 1px solid rgba(var(--hub-cyan),0.25) !important; }
                    .dark .fi-badge[data-color="primary"] { background: rgba(var(--hub-emerald),0.12) !important; color: rgb(110,231,183) !important; border: 1px solid rgba(var(--hub-emerald),0.25) !important; }

                    .dark .fi-input-wrp { border-color: rgba(var(--hub-emerald),0.12) !important; background: rgba(8,18,22,0.7) !important; }
                    .dark .fi-input-wrp:focus-within { border-color: rgba(var(--hub-emerald),0.4) !important; box-shadow: 0 0 0 3px rgba(var(--hub-emerald),0.08) !important; }

                    .dark .fi-simple-page { background: radial-gradient(ellipse at 50% 30%, rgba(var(--hub-emerald),0.06) 0%, #010409 70%) !important; }
                    .dark .fi-wi-chart { background: rgba(8,18,22,0.9) !important; border: 1px solid rgba(var(--hub-emerald),0.06) !important; }
                    .dark .fi-modal-window { background: rgba(8,18,22,0.98) !important; border: 1px solid rgba(var(--hub-emerald),0.1) !important; border-radius: var(--hub-radius-lg) !important; }
                    .dark .fi-no-notification { background: rgba(8,18,22,0.95) !important; border: 1px solid rgba(var(--hub-emerald),0.08) !important; border-radius: var(--hub-radius-md) !important; }

                    .dark ::-webkit-scrollbar-thumb { background: rgba(var(--hub-emerald),0.18); border-radius: 10px; }
                    .dark ::-webkit-scrollbar-thumb:hover { background: rgba(var(--hub-emerald),0.3); }

                    /* ══════════════════════════════════════
                       LIGHT THEME
                       ══════════════════════════════════════ */
                    html:not(.dark) .fi-topbar { border-bottom: 1px solid rgba(0,0,0,0.05) !important; background: rgba(255,255,255,0.85) !important; }
                    html:not(.dark) .fi-sidebar { border-right: 1px solid rgba(0,0,0,0.05) !important; background: #f8fafc !important; }
                    html:not(.dark) .fi-panel { background: #f1f5f9 !important; }

                    html:not(.dark) .fi-section { border: 1px solid rgba(0,0,0,0.06) !important; box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.02) !important; background: #ffffff !important; }
                    html:not(.dark) .fi-wi-stats-overview-stat { background: #ffffff !important; border: 1px solid rgba(0,0,0,0.06) !important; box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important; }
                    html:not(.dark) .fi-wi-stats-overview-stat:hover { border-color: rgba(var(--hub-emerald),0.3) !important; box-shadow: 0 4px 16px rgba(var(--hub-emerald),0.08) !important; }

                    html:not(.dark) .fi-ta-ctn { border: 1px solid rgba(0,0,0,0.06) !important; box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important; background: #ffffff !important; }
                    html:not(.dark) .fi-ta-row { background: #ffffff !important; }
                    html:not(.dark) .fi-ta-row:nth-child(even) { background: #fafbfc !important; }
                    html:not(.dark) .fi-ta-row:hover { background: rgba(var(--hub-emerald),0.04) !important; }

                    html:not(.dark) .fi-sidebar-item-button { color: rgb(100,116,139) !important; }
                    html:not(.dark) .fi-sidebar-item-button:hover { background: rgba(var(--hub-emerald),0.06) !important; color: rgb(15,23,42) !important; }
                    html:not(.dark) .fi-sidebar-item-button.fi-active { background: rgba(var(--hub-emerald),0.1) !important; color: rgb(5,150,105) !important; font-weight: 600; box-shadow: inset 3px 0 0 rgb(var(--hub-emerald)); }

                    html:not(.dark) .fi-section-header-heading { color: rgb(15,23,42) !important; }
                    html:not(.dark) .fi-wi-stats-overview-stat-label { color: rgb(100,116,139) !important; font-size: 0.78rem !important; }
                    html:not(.dark) .fi-wi-stats-overview-stat-value { color: rgb(15,23,42) !important; font-size: 1.5rem !important; font-weight: 700 !important; }

                    html:not(.dark) .fi-input-wrp { border-color: rgb(203,213,225) !important; background: #ffffff !important; }
                    html:not(.dark) .fi-input-wrp:focus-within { border-color: rgb(var(--hub-emerald)) !important; box-shadow: 0 0 0 3px rgba(var(--hub-emerald),0.1) !important; }

                    html:not(.dark) .fi-simple-page { background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%) !important; }
                    html:not(.dark) .fi-wi-chart { background: #ffffff !important; border: 1px solid rgba(0,0,0,0.06) !important; }
                    html:not(.dark) .fi-modal-window { background: #ffffff !important; border: 1px solid rgba(0,0,0,0.08) !important; border-radius: var(--hub-radius-lg) !important; }

                    html:not(.dark) ::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.12); border-radius: 10px; }
                    html:not(.dark) ::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.2); }
                </style>
            '),
        );
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->login()
            ->defaultThemeMode(ThemeMode::Dark)
            ->colors([
                'primary' => Color::Emerald,
                'danger' => Color::Red,
                'gray' => Color::Slate,
                'info' => Color::Cyan,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->brandName(config('app.name').' Seller')
            ->favicon(asset('favicon.svg'))
            ->maxContentWidth(MaxWidth::Full)
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->font('Inter')
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                \App\Filament\App\Pages\AppDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([
                \App\Filament\App\Widgets\WalletBalanceWidget::class,
                \App\Filament\App\Widgets\OrderStatsWidget::class,
                \App\Filament\App\Widgets\SalesChartWidget::class,
                \App\Filament\App\Widgets\LatestOrdersWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
