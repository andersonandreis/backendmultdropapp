<?php

namespace App\Services\Mail;

use App\Models\Setting;

class SmtpConfigService
{
    /**
     * Aplica as configuracoes SMTP dinamicas salvas no banco de dados.
     * Se nao houver configuracoes no banco, mantém os valores padroes do .env.
     */
    public function apply(): void
    {
        $settings = Setting::whereIn('key', [
            'mail_host',
            'mail_port',
            'mail_username',
            'mail_password',
            'mail_encryption',
            'mail_from_address',
            'mail_from_name',
        ])->pluck('value', 'key');

        if ($settings->isEmpty()) {
            return;
        }

        config([
            'mail.mailers.smtp.host'       => $settings->get('mail_host', config('mail.mailers.smtp.host')),
            'mail.mailers.smtp.port'       => $settings->get('mail_port', config('mail.mailers.smtp.port')),
            'mail.mailers.smtp.username'   => $settings->get('mail_username', config('mail.mailers.smtp.username')),
            'mail.mailers.smtp.password'   => $settings->get('mail_password', config('mail.mailers.smtp.password')),
            'mail.mailers.smtp.encryption' => $settings->get('mail_encryption', config('mail.mailers.smtp.encryption')),
            'mail.from.address'            => $settings->get('mail_from_address', config('mail.from.address')),
            'mail.from.name'               => $settings->get('mail_from_name', config('mail.from.name')),
        ]);
    }
}
