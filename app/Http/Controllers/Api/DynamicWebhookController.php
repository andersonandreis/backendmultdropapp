<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\WebhookConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class DynamicWebhookController extends Controller
{
    public function handle(Request $request, string $slug)
    {
        // 1. Localiza a configuracao ativa baseada na rota da plataforma
        $config = WebhookConfig::where('slug', $slug)->where('is_active', true)->first();

        if (!$config) {
            Log::warning("Webhook recebido para slug desconhecido ou inativo: {$slug}");
            return response()->json(['error' => 'Webhook nao configurado'], 404);
        }

        // 2. Validacao de Seguranca (Header), se configurado
        if ($config->security_header) {
            $headerValue = $request->header($config->security_header);
            if (!$headerValue) {
                Log::warning("Tentativa de webhook sem o header de seguranca exigido: {$config->security_header}");
                return response()->json(['error' => 'Acesso Negado'], 403);
            }
        }

        $payload = $request->all();

        // 3. Verifica o Status / Evento do JSON
        $eventStatus = null;
        if ($config->event_field) {
            $eventStatus = Arr::get($payload, $config->event_field);
            if ($config->expected_event_value && $eventStatus !== $config->expected_event_value) {
                // Evento nao e o esperado (ex: estorno, boleto gerado). Registra e ignora sem erro.
                Log::info("Webhook [{$slug}] recebido, mas evento '{$eventStatus}' ignorado. Esperado: '{$config->expected_event_value}'");
                return response()->json(['status' => 'ignored']);
            }
        }

        // 4. Capturar Email do Cliente
        $email = null;
        if ($config->customer_email_field) {
            $email = Arr::get($payload, $config->customer_email_field);
        }

        if (!$email) {
            Log::error("Webhook [{$slug}] nao encontrou email no caminho: {$config->customer_email_field}");
            return response()->json(['error' => 'Cliente nao identificado'], 422);
        }

        // 5. Associar a um Lojista (User -> Client)
        $user = \App\Models\User::where('email', $email)->first();
        if (!$user) {
            Log::info("Webhook [{$slug}] nao encontrou usuario com e-mail: {$email}");
            return response()->json(['status' => 'user_not_found']);
        }

        // 6. Aprovar Pagamento - Logica HubAI
        // TODO: Acionar Job/Servico (ex: Ativar Assinatura, Liberar Modulos)
        Log::info("DynamicWebhook: pagamento recebido", [
            'slug'  => $slug,
            'user'  => $user->email,
            'event' => $eventStatus,
        ]);

        return response()->json(['status' => 'success']);
    }
}
