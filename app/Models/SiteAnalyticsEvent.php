<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * INF-030 (12/08) — evento proprio de analytics do site publico (click / video_progress).
 * Ver comentario da migration create_site_analytics_events_table pro contexto completo.
 *
 * @property int         $id
 * @property string|null $visitor_uid   visitorId nativo do Matomo (correlacao), pode ser NULL
 * @property string      $event_type    click|video_progress
 * @property string      $page_url
 * @property string|null $page_title
 * @property string|null $video_id
 * @property int|null    $video_pct
 * @property float|null  $x_pct
 * @property float|null  $y_pct
 * @property string|null $el_selector
 * @property string|null $el_text
 * @property array|null  $meta
 * @property string|null $ip
 * @property string|null $user_agent
 */
class SiteAnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $table = 'site_analytics_events';

    protected $fillable = [
        'visitor_uid', 'event_type', 'page_url', 'page_title', 'video_id', 'video_pct',
        'x_pct', 'y_pct', 'el_selector', 'el_text', 'meta', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'video_pct' => 'integer',
        'x_pct' => 'float',
        'y_pct' => 'float',
        'created_at' => 'datetime',
    ];
}
