<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordHubRunItem extends Model
{
    public const STATUSES = ['queued', 'running', 'completed', 'failed', 'cancelled'];

    protected $fillable = [
        'run_id',
        'type',
        'target_type',
        'target_id',
        'label',
        'status',
        'seeds',
        'created_candidates',
        'updated_candidates',
        'filtered_candidates',
        'note',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(KeywordHubRun::class, 'run_id');
    }
}
