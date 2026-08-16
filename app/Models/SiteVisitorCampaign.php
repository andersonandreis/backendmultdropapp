<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * INF-030 (Ruan 12/08, ampliação) — atribuição de campanha por visitante,
 * primeiro toque (first-touch), nunca sobrescrita. Ver migration
 * create_site_visitor_campaigns_table para o porquê de existir além do
 * Referrers.getCampaigns do Matomo.
 *
 * @property string      $visitor_uid
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 * @property string|null $utm_term
 * @property string|null $utm_id
 * @property string|null $fbclid
 * @property string|null $gclid
 * @property string|null $ttclid
 * @property string|null $referrer
 * @property string|null $landing_page
 * @property string|null $ip
 */
class SiteVisitorCampaign extends Model
{
    public $timestamps = false;

    protected $table = 'site_visitor_campaigns';

    protected $fillable = [
        'visitor_uid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'utm_id', 'fbclid', 'gclid', 'ttclid', 'referrer', 'landing_page', 'ip', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** Nome curto da origem pra escolher o logo (facebook/instagram/google/tiktok/whatsapp/youtube/direct/other). */
    public function sourceKey(): string
    {
        $src = strtolower((string) ($this->utm_source ?? ''));
        $ref = strtolower((string) ($this->referrer ?? ''));

        if ($this->fbclid || str_contains($src, 'facebook') || str_contains($ref, 'facebook.com')) {
            return 'facebook';
        }
        if (str_contains($src, 'instagram') || str_contains($ref, 'instagram.com')) {
            return 'instagram';
        }
        if ($this->gclid || str_contains($src, 'google') || str_contains($ref, 'google.')) {
            return 'google';
        }
        if ($this->ttclid || str_contains($src, 'tiktok') || str_contains($ref, 'tiktok.com')) {
            return 'tiktok';
        }
        if (str_contains($src, 'whatsapp') || str_contains($ref, 'wa.me') || str_contains($ref, 'whatsapp.com')) {
            return 'whatsapp';
        }
        if (str_contains($src, 'youtube') || str_contains($ref, 'youtube.com') || str_contains($ref, 'youtu.be')) {
            return 'youtube';
        }
        if ($ref === '' || $ref === null) {
            return 'direct';
        }
        return 'other';
    }
}
