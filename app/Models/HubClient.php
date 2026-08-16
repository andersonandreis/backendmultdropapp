<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MUL-160 - Model read-only para hub clients (hubaiapp.clients) via conexao hub_readonly.
 * Usado para mapear hub.client_id -> local.client_id via legacy_id_login.
 */
class HubClient extends Model
{
    protected $connection = "hub_readonly";
    protected $table = "clients";
    public $timestamps = false;
    protected $guarded = [];
}
