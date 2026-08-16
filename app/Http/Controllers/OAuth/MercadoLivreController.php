<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use App\Services\MercadoLivreService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MercadoLivreController extends Controller
{
    public function __construct(
        protected MercadoLivreService $mlService
    ) {}

    /**
     * Inicia o fluxo OAuth. Redireciona o seller para a página de autorização do ML.
     */
    public function redirect(MarketplaceAccount $account): RedirectResponse
    {
        // Garante que a conta pertence ao cliente logado
        abort_unless(auth()->user()->client?->id === $account->client_id, 403);

        $authUrl = $this->mlService->getAuthUrl($account);

        return redirect($authUrl);
    }

    /**
     * Callback do ML. Troca o code pelo token e salva na MarketplaceAccount.
     */
    public function callback(Request $request): RedirectResponse
    {
        $request->validate([
            'code'  => 'required|string',
            'state' => 'required|integer',
        ]);

        $accountId   = (int) $request->input('state');
        $account     = MarketplaceAccount::findOrFail($accountId);

        // Garante que a conta pertence ao cliente logado
        abort_unless(auth()->user()->client?->id === $account->client_id, 403);

        // Recupera o code_verifier salvo no redirect
        $verifierKey = 'ml_code_verifier_' . $accountId;
        $codeVerifier = session($verifierKey);

        if (! $codeVerifier) {
            return redirect()->route('filament.app.resources.minhas-lojas.index')
                ->with('error', 'Sessão OAuth expirada. Tente novamente.');
        }

        try {
            $tokenData = $this->mlService->exchangeCode($request->input('code'), $codeVerifier);

            // Busca dados do usuário ML para armazenar o ml_user_id
            $mlUser = $this->mlService->getMe($tokenData['access_token']);

            $account->update([
                'ml_user_id'         => $mlUser['id'],
                'ml_access_token'    => encrypt($tokenData['access_token']),
                'ml_refresh_token'   => encrypt($tokenData['refresh_token']),
                'ml_token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 21600),
                'seller_nickname'    => $mlUser['nickname'] ?? $account->seller_nickname,
                'status'             => 'active',
            ]);

            // Limpa o verifier da session
            session()->forget($verifierKey);

            return redirect()->route('filament.app.resources.minhas-lojas.index')
                ->with('success', 'Conta do Mercado Livre conectada com sucesso!');

        } catch (\Throwable $e) {
            return redirect()->route('filament.app.resources.minhas-lojas.index')
                ->with('error', 'Erro ao conectar conta ML: ' . $e->getMessage());
        }
    }
}
