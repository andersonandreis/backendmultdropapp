<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ApiToken extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static ?string $navigationGroup = 'Configurações';
    protected static ?string $navigationLabel = 'API Token';
    protected static ?string $title = 'API Token de Acesso';
    protected static ?string $slug = 'api-token';
    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.api-token';

    public ?string $token = null;
    public bool $tokenGerado = false;

    public function mount(): void
    {
        $user = auth()->user();
        // Tenta carregar token existente de personal access tokens
        if (class_exists(\Laravel\Sanctum\PersonalAccessToken::class)) {
            $tokenRecord = $user->tokens()->latest()->first();
            if ($tokenRecord) {
                $this->token = 'Token existente — gere um novo para exibir o valor completo.';
                $this->tokenGerado = true;
            }
        }
    }

    public function gerarToken(): void
    {
        $user = auth()->user();

        // Revogar tokens anteriores
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        // Gerar novo token via Sanctum se disponivel
        if (method_exists($user, 'createToken')) {
            $newToken = $user->createToken('api-token-admin');
            $this->token = $newToken->plainTextToken;
        } else {
            // Fallback: JWT simples assinado
            $payload = base64_encode(json_encode([
                'sub'  => $user->id,
                'role' => $user->role,
                'iat'  => time(),
                'exp'  => time() + (365 * 24 * 3600),
            ]));
            $signature = hash_hmac('sha256', $payload, config('app.key'));
            $this->token = "hubai.{$payload}.{$signature}";
        }

        $this->tokenGerado = true;

        Notification::make()
            ->title('Token gerado!')
            ->body('Copie agora — o valor completo só é exibido uma vez.')
            ->warning()
            ->send();
    }

    public function revogarTokens(): void
    {
        $user = auth()->user();
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        $this->token = null;
        $this->tokenGerado = false;

        Notification::make()->title('Todos os tokens foram revogados.')->success()->send();
    }
}
