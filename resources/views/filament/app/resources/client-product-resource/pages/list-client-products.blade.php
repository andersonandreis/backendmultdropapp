<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="flex flex-col gap-y-6">
        <x-filament-panels::resources.tabs />

        {{-- Banner de limite de SKUs por plano (upsell) --}}
        @if($this->hasSkuLimitReached)
            @include("filament.components.sku-limit-banner", [
                "planName" => $this->skuLimitInfo["plan_name"],
                "maxSkus"  => $this->skuLimitInfo["max_skus"],
                "current"  => $this->skuLimitInfo["current"],
            ])
        @endif

        {{-- Banner de alerta de conta bloqueada --}}
        @if($this->hasAccountError)
            @include('filament.components.account-alert-banner', [
                'hasAccountError' => $this->hasAccountError,
                'errorMessage'    => $this->accountErrorMessage,
                'isBlocked'       => $this->accountBlocked,
            ])
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>
</x-filament-panels::page>
