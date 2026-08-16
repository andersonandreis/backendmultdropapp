<?php

namespace App\Models;

use App\Models\Order;
use App\Services\Integrations\Contracts\PaymentGatewayInterface;
use App\Services\Integrations\Factories\PaymentGatewayFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Supplier extends Model
{
    use \App\Traits\HasSlug;

    protected $fillable = [
        'legacy_id',
        'legacy_empresa_id',
        'legacy_loja_id',
        'user_id',
        'type',
        'company_name',
        'display_name',
        'theme_color',
        'document',
        'phone',
        'logo',
        'description',
        'address',
        'city',
        'state',
        'zipcode',
        'allows_direct_payment',
        'pix_fee',
        'is_factory',
        'supports_meli_flex',
        'flex_fee',
        'allows_direct_deposit',
        'is_active',
        'owner_client_id',
        'is_private',
        'prefix',
        'slug',
        'pix_key',
        'pix_key_type',
        'openai_api_key',
        'openai_model',
        'ie',
        'indicator_icms',
        'trade_name',
        'address_number',
        'address_complement',
        'neighborhood',
    ];

    protected $casts = [
        'openai_api_key' => 'encrypted',
        'is_active' => 'boolean',
        'is_factory' => 'boolean',
        'supports_meli_flex' => 'boolean',
        'is_private' => 'boolean',
        'allows_direct_payment' => 'boolean',
        'allows_direct_deposit' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($supplier) {
            if (empty($supplier->prefix)) {
                $supplier->prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $supplier->company_name), 0, 3));
            }
        });

        // Após a criacao, o ID esta disponivel — gerar slug com ID real
        static::created(function ($supplier) {
            if (empty($supplier->slug)) {
                $base = Str::slug($supplier->company_name) ?: 'sup';
                $supplier->slug = $base . '-' . $supplier->id;
                $supplier->saveQuietly();
            }
        });
    }

    protected function getSluggableField()
    {
        return 'company_name';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function activeProducts()
    {
        return $this->hasMany(Product::class)->where("is_active", true);
    }

    public function plans()
    {
        return $this->belongsToMany(Plan::class);
    }

    /**
     * Clients que possuem este supplier como extra (vinculo manual pelo admin).
     */
    public function clients()
    {
        return $this->belongsToMany(Client::class, 'client_supplier')
            ->withPivot('is_extra')
            ->withTimestamps();
    }

    public function paymentSetting()
    {
        return $this->hasOne(SupplierPaymentSetting::class);
    }

    public function eventSubscriptions()
    {
        return $this->hasMany(EventSubscription::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Retorna o gateway de pagamento configurado para este supplier.
     * Lanca RuntimeException se o supplier nao tiver configuracao ativa.
     */
    public function getPaymentGateway(): ?PaymentGatewayInterface
    {
        return PaymentGatewayFactory::makeForSupplier($this);
    }

    public function aiSetting(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\SupplierAiSetting::class);
    }
}
