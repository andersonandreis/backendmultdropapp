<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SupplierPaymentSetting extends Model
{
    protected $fillable = [
        'supplier_id',
        'gateway',
        'api_key',
        'api_secret',
        'api_extra',
        'webhook_url',
        'webhook_secret',
        'pix_fee_type',
        'pix_fee_value',
        'allows_wallet_topup',
        'allows_wallet_payment',
        'refund_policy',
        'pix_only_orders',
        'pix_timeout_minutes',
        'is_active',
    ];

    protected $casts = [
        'api_key'              => 'encrypted',
        'api_secret'           => 'encrypted',
        'api_extra'            => 'array',
        'allows_wallet_topup'  => 'boolean',
        'allows_wallet_payment'=> 'boolean',
        'pix_only_orders'      => 'boolean',
        'is_active'            => 'boolean',
        'pix_fee_value'        => 'decimal:4',
    ];

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $setting) {
            // Gera o secret HMAC antes de salvar
            if (empty($setting->webhook_secret)) {
                $setting->webhook_secret = Str::random(64);
            }

            // Gera a URL de webhook baseada no supplier (carregado eager ou lazy)
            if (empty($setting->webhook_url) && $setting->supplier_id) {
                $supplier = Supplier::find($setting->supplier_id);
                if ($supplier) {
                    $setting->webhook_url = $setting->buildWebhookUrl($supplier);
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // Relacionamentos
    // -------------------------------------------------------------------------

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    // -------------------------------------------------------------------------
    // Metodos publicos
    // -------------------------------------------------------------------------

    /**
     * Retorna a URL de webhook para este supplier + gateway.
     * Formato: /webhooks/payment/{supplier_slug}/{gateway}
     */
    public function getWebhookUrl(): string
    {
        $supplier = $this->relationLoaded('supplier')
            ? $this->supplier
            : $this->supplier()->first();

        return $this->buildWebhookUrl($supplier);
    }

    /**
     * Regenera o webhook_secret e persiste.
     */
    public function generateWebhookSecret(): void
    {
        $this->webhook_secret = Str::random(64);
        $this->save();
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    private function buildWebhookUrl(Supplier $supplier): string
    {
        // Fallback defensivo: garante URL valida mesmo se slug for null
        $slug = $supplier->slug ?: ('sup-' . $supplier->id);

        return url("/webhooks/payment/{$slug}/{$this->gateway}");
    }
}
