<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

class DropOrder extends Model
{
    protected $table = 'drop_orders';

    protected $fillable = [
        'client_id',
        'drop_store_id',
        'imported_product_id',
        'shopify_order_id',
        'shopify_order_number',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address',
        'total_amount',
        'currency',
        'status',
        'traffic_source',
        'utm_source',
        'utm_campaign',
        'utm_medium',
        'utm_content',
        'fbp',
        'fbc',
        'gclid',
        'event_id',
        'profit_estimate',
        'gateway_fee',
        'platform_fee',
        'notes',
        'order_key',
        'customer_cpf',
        'shipping_zip',
        'shipping_city',
        'shipping_state',
        'items_json',
        'payment_method',
        'tracking_code',
        'source',
    ];

    protected $casts = [
        'total_amount'    => 'decimal:4',
        'profit_estimate' => 'decimal:4',
        'gateway_fee'     => 'decimal:4',
        'items_json'      => 'array',
        'platform_fee'    => 'decimal:4',
    ];

    protected $hidden = ['customer_email', 'customer_phone', 'shipping_address'];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function dropStore()
    {
        return $this->belongsTo(DropStore::class, 'drop_store_id');
    }

    public function importedProduct()
    {
        return $this->belongsTo(ImportedProduct::class, 'imported_product_id');
    }

    public function supplierOrders()
    {
        return $this->hasMany(DropSupplierOrder::class, 'drop_order_id');
    }

    public function getCustomerEmailAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    public function setCustomerEmailAttribute($value)
    {
        $this->attributes['customer_email'] = $value ? encrypt($value) : null;
    }

    public function getCustomerPhoneAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    public function setCustomerPhoneAttribute($value)
    {
        $this->attributes['customer_phone'] = $value ? encrypt($value) : null;
    }

    public function getShippingAddressAttribute($value)
    {
        if (!$value) return null;
        $decrypted = decrypt($value);
        return is_string($decrypted) ? json_decode($decrypted, true) : $decrypted;
    }

    public function setShippingAddressAttribute($value)
    {
        if (!$value) {
            $this->attributes['shipping_address'] = null;
            return;
        }
        $encoded = is_array($value) ? json_encode($value) : $value;
        $this->attributes['shipping_address'] = encrypt($encoded);
    }
}
