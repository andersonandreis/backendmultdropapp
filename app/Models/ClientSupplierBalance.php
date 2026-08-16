<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientSupplierBalance extends Model
{
    protected $fillable = [
        'client_id',
        'supplier_id',
        'balance',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
