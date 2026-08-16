<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Erps\Bling\BlingAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BlingController extends Controller
{
    public function __construct(
        protected BlingAuthService $blingAuth
    ) {}

    /**
     * Inicia o fluxo OAuth2 do Bling.
     * Seller clica "Conectar Bling" → redireciona pro Bling.
     */
    public function redirect(MarketplaceAccount $account): RedirectResponse
    {
        abort_unless(auth()->user()->client?->id === $account->client_id, 403);

        // NOV-077: Bling so aceita 1 redirect_uri — usar relay centralizado api.hubai.io.
        // Em vez de BlingAuthService::getAuthUrl() (redirect_uri por WL), redirecionar
        // para OAuthController que usa BLING_REDIRECT_URI=api.hubai.io/bling/callback
        // e inclui source_system no state para relay HMAC pos-callback.
        $clientId    = $account->client_id;
        $supplierId  = $account->supplier_id;
        $accountName = $account->account_name ?: 'Bling';
        $returnUrl   = rtrim(config('app.frontend_url', url('/')), '/') . '/integracoes';
        $sourceSystem = config('bling.app_tenant', 'hubai');

        $redirectUrl = url('/api/oauth/bling/redirect') . '?' . http_build_query([
            'client_id'     => $clientId,
            'supplier_id'   => $supplierId,
            'account_name'  => $accountName,
            'source_system' => $sourceSystem,
            'return_url'    => $returnUrl,
        ]);

        return redirect($redirectUrl);
    }

    /**
     * Callback do Bling após o seller autorizar.
     * Troca o code pelo token e salva na conta.
     */
    public function callback(Request $request): RedirectResponse
    {
        $request->validate([
            "code" => "required|string",
            "state" => "required|string",
        ]);

        // State = "accountId|randomString"
        $state = $request->input("state");
        $savedState = session("bling_oauth_state");

        if ($state !== $savedState) {
            return redirect()->route("filament.app.resources.minhas-lojas.index")
                ->with("error", "Estado OAuth inválido. Tente novamente.");
        }

        $accountId = (int) explode("|", $state)[0];
        $account = MarketplaceAccount::findOrFail($accountId);

        abort_unless(auth()->user()->client?->id === $account->client_id, 403);

        try {
            $tokenData = $this->blingAuth->exchangeCode($request->input("code"));
            $this->blingAuth->saveTokens($account, $tokenData);

            session()->forget("bling_oauth_state");

            return redirect()->route("filament.app.resources.minhas-lojas.index")
                ->with("success", "Bling conectado com sucesso!");

        } catch (\Throwable $e) {
            return redirect()->route("filament.app.resources.minhas-lojas.index")
                ->with("error", "Erro ao conectar Bling: " . $e->getMessage());
        }
    }
}
