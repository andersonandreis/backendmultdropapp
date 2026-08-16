<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenantSupplier;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * NOV-141 — Configuracao SMTP por whitelabel.
 *
 * Cada supplier pode usar seu proprio SMTP (transactional emails saem com
 * remetente da WL, nao do HubAI). Senha armazenada criptografada.
 */
class SupplierSmtpConfig extends Model
{
    use BelongsToTenantSupplier;

    protected $table = 'supplier_smtp_configs';

    protected $fillable = [
        'supplier_id', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_password',
        'smtp_from_name', 'smtp_from_email', 'smtp_encryption', 'active',
        'last_test_at', 'last_test_success', 'last_test_error',
    ];

    protected $casts = [
        'smtp_port'         => 'integer',
        'active'            => 'boolean',
        'last_test_success' => 'boolean',
        'last_test_at'      => 'datetime',
    ];

    protected $hidden = ['smtp_password'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** Senha criptografada em repouso (Laravel APP_KEY). */
    protected function smtpPassword(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? Crypt::decryptString($value) : null,
            set: fn ($value) => $value ? Crypt::encryptString($value) : null,
        );
    }
}
