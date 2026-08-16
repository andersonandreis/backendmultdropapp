<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SEL-218: Vídeo viral do TikTok BR descoberto por scraping de termos de
 * descoberta de produto (product finds, achadinhos, tiktok shop brasil).
 *
 * @property int         $id
 * @property string      $external_video_id
 * @property string      $video_url
 * @property string|null $cover_url
 * @property string|null $play_url_hd
 * @property string|null $creator_handle
 * @property string|null $creator_name
 * @property string|null $creator_avatar_url
 * @property string|null $caption
 * @property array|null  $hashtags
 * @property int         $views
 * @property int         $comments
 * @property int         $likes
 * @property int         $shares
 * @property float       $viral_score
 * @property string|null $detected_product_title
 * @property string|null $detected_product_url
 * @property string|null $search_term
 * @property \Carbon\Carbon|null $published_at
 * @property \Carbon\Carbon      $scraped_at
 */
class TiktokViralVideo extends Model
{
    protected $table = 'tiktok_viral_videos';

    protected $fillable = [
        'external_video_id',
        'video_url',
        'cover_url',
        'play_url_hd',
        'creator_handle',
        'creator_name',
        'creator_avatar_url',
        'caption',
        'hashtags',
        'views',
        'comments',
        'likes',
        'shares',
        'viral_score',
        'detected_product_title',
        'detected_product_url',
        'search_term',
        'published_at',
        'scraped_at',
    ];

    protected $casts = [
        'hashtags'    => 'array',
        'published_at' => 'datetime',
        'scraped_at'   => 'datetime',
        'views'        => 'integer',
        'comments'     => 'integer',
        'likes'        => 'integer',
        'shares'       => 'integer',
        'viral_score'  => 'float',
    ];

    /**
     * SEL-218: Viral score = engajamento ponderado por impacto de descoberta.
     * Shares e comentários pesam mais (indicam produto genuinamente descoberto).
     * Formula: log10(views+1)*2 + log10(comments+1)*3 + log10(likes+1)*1 + log10(shares+1)*4
     */
    public static function calcViralScore(int $views, int $comments, int $likes, int $shares): float
    {
        return
            log10($views    + 1) * 2 +
            log10($comments + 1) * 3 +
            log10($likes    + 1) * 1 +
            log10($shares   + 1) * 4;
    }
}
