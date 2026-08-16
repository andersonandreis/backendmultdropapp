<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioMessage extends Model
{
    protected $fillable = ["conversation_id", "role", "content", "attachments", "ui_widget", "tool_calls", "tts_url"];

    protected $casts = [
        "attachments" => "array",
        "ui_widget"   => "array",
        "tool_calls"  => "array",
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(StudioConversation::class, "conversation_id");
    }
}
