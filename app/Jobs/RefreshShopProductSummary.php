<?php

namespace App\Jobs;

use App\Domain\Shopping\ShopProductSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshShopProductSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public array $keys) {}

    public function handle(ShopProductSummary $summary): void
    {
        $summary->refresh($this->keys);
    }
}
