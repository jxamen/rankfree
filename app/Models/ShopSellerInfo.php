<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopSellerInfo extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'channel_uid',
        'channel_id',
        'biz_name',
        'representative',
        'customer_phone',
        'biz_reg_no',
        'mail_order_no',
        'email',
        'address',
        'raw',
        'seller_info_url',
        'captured_at',
    ];

    protected $casts = [
        'raw' => 'array',
        'captured_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
