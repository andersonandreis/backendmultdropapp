<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvisoRead extends Model
{
    public $timestamps = false;

    protected $fillable = ['aviso_id', 'client_id', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];

    public function aviso()
    {
        return $this->belongsTo(Aviso::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
