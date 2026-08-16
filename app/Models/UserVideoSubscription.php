<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SEL-417: Assinatura de video IA de um usuario.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $plan_slug
 * @property string      $status               active|canceled|trialing
 * @property string|null $asaas_subscription_id
 * @property mixed       $cycle_started_at
 * @property mixed       $cycle_ends_at
 * @property int         $videos_used_this_cycle
 */
class UserVideoSubscription extends Model
{
    protected $table = "user_video_subscriptions";

    protected $fillable = [
        "user_id",
        "plan_slug",
        "status",
        "asaas_subscription_id",
        "cycle_started_at",
        "cycle_ends_at",
        "videos_used_this_cycle",
    ];

    protected $casts = [
        "cycle_started_at"       => "datetime",
        "cycle_ends_at"          => "datetime",
        "videos_used_this_cycle" => "integer",
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(VideoSubscriptionPlan::class, "plan_slug", "slug");
    }
}

