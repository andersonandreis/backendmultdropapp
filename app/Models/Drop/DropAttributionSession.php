<?php

namespace App\Models\Drop;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

class DropAttributionSession extends Model
{
    protected $table = 'drop_attribution_sessions';

    protected $fillable = [
        'client_id',
        'session_id',
        'fbp',
        'fbc',
        'gclid',
        'ttclid',
        'utm_source',
        'utm_campaign',
        'utm_medium',
        'utm_content',
        'utm_term',
        'landing_url',
        'user_agent',
        'ip_hash',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
