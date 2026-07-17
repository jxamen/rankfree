<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopSellerCaptcha extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'channel_uid',
        'channel_id',
        'captcha_key',
        'seller_info_type',
        'question',
        'image_disk',
        'image_path',
        'image_mime',
        'image_bytes',
        'seller_info_url',
        'prev_url',
        'captured_at',
    ];

    protected $casts = [
        'image_bytes' => 'integer',
        'captured_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
