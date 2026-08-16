<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * INF-030 (Ruan 12/08, ampliação) — 1 chunk de gravação de sessão (rrweb).
 * Ver migration create_site_session_recordings_table pro contexto completo
 * (compressão, LGPD, por que não usamos PostHog/OpenReplay inteiro).
 *
 * @property string      $visitor_uid
 * @property string      $session_id
 * @property int         $seq
 * @property string|null $page_url
 * @property bool         $is_gzip
 * @property int         $events_count
 * @property int         $raw_bytes
 * @property string      $payload_b64
 */
class SiteSessionRecording extends Model
{
    public $timestamps = false;

    protected $table = 'site_session_recordings';

    protected $fillable = [
        'visitor_uid', 'session_id', 'seq', 'page_url', 'is_gzip',
        'events_count', 'raw_bytes', 'payload_b64', 'created_at',
    ];

    protected $casts = [
        'is_gzip' => 'boolean',
        'seq' => 'integer',
        'events_count' => 'integer',
        'raw_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    /** Decodifica o chunk pro array de eventos rrweb originais. */
    public function decodeEvents(): array
    {
        $raw = base64_decode($this->payload_b64, true);
        if ($raw === false) {
            return [];
        }
        if ($this->is_gzip) {
            $raw = @gzdecode($raw);
            if ($raw === false) {
                return [];
            }
        }
        $events = json_decode($raw, true);
        return is_array($events) ? $events : [];
    }
}
