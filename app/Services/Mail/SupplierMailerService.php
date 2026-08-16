<?php

namespace App\Services\Mail;

use App\Models\SupplierSmtpConfig;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * NOV-141 — Servico de teste e envio SMTP por whitelabel.
 *
 * Cada supplier pode configurar seu proprio SMTP. Este service:
 *  - testa a conexao real (handshake SMTP) ao salvar/validar
 *  - envia email de teste opcional
 *  - atualiza last_test_* na config
 */
class SupplierMailerService
{
    /**
     * Tenta abrir conexao SMTP real usando as credenciais do supplier.
     * Atualiza last_test_at, last_test_success e last_test_error.
     */
    public function testConnection(SupplierSmtpConfig $config): bool
    {
        $success = false;
        $error   = null;

        try {
            $dsn = sprintf(
                '%s://%s:%s@%s:%d',
                $config->smtp_encryption === 'none' ? 'smtp' : 'smtps',
                urlencode($config->smtp_user),
                urlencode($config->smtp_password),
                $config->smtp_host,
                $config->smtp_port
            );

            $transport = Transport::fromDsn($dsn);
            // start() abre o socket — falha aqui = credenciais ou host invalido
            $transport->start();
            $transport->stop();
            $success = true;
        } catch (\Throwable $e) {
            $error = substr($e->getMessage(), 0, 1000);
            Log::warning('[SupplierMailer] teste SMTP falhou', [
                'supplier_id' => $config->supplier_id,
                'host'        => $config->smtp_host,
                'error'       => $error,
            ]);
        }

        $config->update([
            'last_test_at'      => now(),
            'last_test_success' => $success,
            'last_test_error'   => $error,
        ]);

        return $success;
    }

    /**
     * Envia um email de teste usando a config do supplier.
     */
    public function sendTestEmail(SupplierSmtpConfig $config, string $toEmail): bool
    {
        try {
            $dsn = sprintf(
                '%s://%s:%s@%s:%d',
                $config->smtp_encryption === 'none' ? 'smtp' : 'smtps',
                urlencode($config->smtp_user),
                urlencode($config->smtp_password),
                $config->smtp_host,
                $config->smtp_port
            );

            $transport = Transport::fromDsn($dsn);
            $mailer    = new Mailer($transport);

            $fromEmail = $config->smtp_from_email ?: $config->smtp_user;
            $fromName  = $config->smtp_from_name ?: 'Sistema';

            $email = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromEmail))
                ->to($toEmail)
                ->subject('Teste de conexao SMTP — ' . config('app.name'))
                ->text('Este e um email de teste enviado pelo painel admin. Se voce recebeu, seu SMTP esta funcionando.');

            $mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            Log::error('[SupplierMailer] envio de teste falhou', [
                'supplier_id' => $config->supplier_id,
                'error'       => $e->getMessage(),
            ]);
            return false;
        }
    }
}
