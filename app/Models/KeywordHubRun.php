<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordHubRun extends Model
{
    public const STATUSES = ['queued', 'running', 'completed', 'failed', 'cancelled'];

    protected $fillable = [
        'type',
        'status',
        'total_jobs',
        'finished_jobs',
        'failed_jobs',
        'seeds',
        'created_candidates',
        'updated_candidates',
        'filtered_candidates',
        'options',
        'note',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'options' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(KeywordHubRunItem::class, 'run_id');
    }

    public function refreshSummary(): void
    {
        $items = $this->items();
        $total = (clone $items)->count();
        $finished = (clone $items)->whereIn('status', ['completed', 'failed', 'cancelled'])->count();
        $failed = (clone $items)->where('status', 'failed')->count();
        $running = (clone $items)->where('status', 'running')->exists();

        $this->forceFill([
            'total_jobs' => $total,
            'finished_jobs' => $finished,
            'failed_jobs' => $failed,
            'seeds' => (int) (clone $items)->sum('seeds'),
            'created_candidates' => (int) (clone $items)->sum('created_candidates'),
            'updated_candidates' => (int) (clone $items)->sum('updated_candidates'),
            'filtered_candidates' => (int) (clone $items)->sum('filtered_candidates'),
            'status' => $this->nextStatus($total, $finished, $failed, $running),
            'finished_at' => $total > 0 && $finished >= $total ? ($this->finished_at ?? now()) : null,
        ])->save();
    }

    private function nextStatus(int $total, int $finished, int $failed, bool $running): string
    {
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }
        if ($total === 0) {
            return 'completed';
        }
        if ($finished >= $total) {
            return $failed > 0 ? 'failed' : 'completed';
        }

        return $running || $finished > 0 ? 'running' : 'queued';
    }
}
