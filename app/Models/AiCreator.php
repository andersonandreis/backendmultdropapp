<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-199 — Model para criadores IA fake
 * (Tokfy + scrape hashtag + manuais)
 *
 * @property int         $id
 * @property string      $handle
 * @property string      $name
 * @property string|null $avatar_url
 * @property string|null $bio
 * @property int         $followers
 * @property int         $videos_count
 * @property float       $estimated_revenue
 * @property float|null  $gmv
 * @property float|null  $commission         comissão R$ estimada (campo Tokfy "COMISSÃO")
 * @property int|null    $commission_items   quantidade de itens comissionados
 * @property int|null    $following
 * @property int|null    $likes_count
 * @property string      $country
 * @property int|null    $rank_position
 * @property string      $source         tokfy|tokfy_real|scrape|manual
 * @property string|null $tokfy_sync_id  ID em social_profiles Supabase Tokfy
 * @property array|null  $raw
 * @property bool        $is_visible
 * @property bool        $is_approved
 * @property string|null $admin_notes
 * @property \Carbon\Carbon|null $tokfy_synced_at
 */
class AiCreator extends Model
{
    protected $fillable = [
        'handle',
        'name',
        'avatar_url',
        'bio',
        'followers',
        'videos_count',
        'estimated_revenue',
        'rank_position',
        'source',
        'raw',
        'is_visible',
        'is_approved',
        'admin_notes',
        // SEL-213/214: colunas Tokfy
        'gmv',
        'following',
        'likes_count',
        'commission_items',
        'country',
        // SEL-217: sync Tokfy + comissão monetária
        'commission',
        'tokfy_sync_id',
        'tokfy_synced_at',
    ];

    protected $casts = [
        'followers'          => 'integer',
        'videos_count'       => 'integer',
        'estimated_revenue'  => 'float',
        'rank_position'      => 'integer',
        'raw'                => 'array',
        'is_visible'         => 'boolean',
        'is_approved'        => 'boolean',
        // SEL-214: casts pros novos campos
        'gmv'                => 'float',
        'following'          => 'integer',
        'likes_count'        => 'integer',
        'commission_items'   => 'integer',
        // SEL-217
        'commission'         => 'float',
        'tokfy_synced_at'    => 'datetime',
    ];

    /**
     * Scope público: visíveis e aprovados.
     * Admin usa withoutGlobalScopes() ou query direta.
     */
    public function scopePublic($query)
    {
        return $query->where('is_visible', true)->where('is_approved', true);
    }

    /**
     * Ordenação por rank_position (nulls last) depois por estimated_revenue desc.
     */
    public function scopeRanked($query)
    {
        return $query->orderByRaw('rank_position IS NULL, rank_position ASC')
                     ->orderByDesc('estimated_revenue');
    }
}
