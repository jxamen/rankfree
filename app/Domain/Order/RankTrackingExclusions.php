<?php

namespace App\Domain\Order;

use App\Models\MarketingOrder;
use PDO;
use RuntimeException;

/** Order auto-registration only: never changes an order or its financial state. */
class RankTrackingExclusions
{
    public function policy(): array
    {
        $path = config('rank_tracking_exclusions.path');
        if (! $path || ! file_exists($path)) {
            return [];
        }
        $policy = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($policy)) {
            throw new RuntimeException('순위추적 제외 설정을 확인하세요.');
        }

        return $policy;
    }

    public function excludesOrder(MarketingOrder $order): bool
    {
        $policy = $this->policy();
        if (in_array((int) $order->user_id, $policy['rankfree_user_ids'] ?? [], true)) {
            return true;
        }
        $user = $order->user;
        if (! $user) {
            return false;
        }
        $normalize = fn ($value) => mb_strtolower(preg_replace('/[\s()㈜]+/u', '', trim((string) $value)));
        $names = array_map($normalize, $policy['business_names'] ?? []);
        $accounts = array_map('strtolower', $policy['boost_member_ids'] ?? []);

        return in_array($normalize($user->name), $names, true)
            || in_array(strtolower(explode('@', (string) $user->email)[0]), $accounts, true);
    }

    public function excludesBoostingLabel(?string $label): bool
    {
        $policy = $this->policy();
        $ids = $policy['boost_member_ids'] ?? [];
        if (! $ids || ! preg_match('/^부스팅샵 ([1-9][0-9]*)$/u', (string) $label, $match)) {
            return false;
        }

        return in_array($this->boostingOrderMember((int) $match[1]), $ids, true);
    }

    protected function boostingOrderMember(int $orderId): ?string
    {
        $path = config('rank_tracking_exclusions.boosting_db_config');
        if (! is_readable($path)) {
            throw new RuntimeException('주문자 확인이 불가능해 순위추적 자동 등록을 보류합니다.');
        }
        if (! defined('_GNUBOARD_')) {
            define('_GNUBOARD_', true);
        }
        require_once $path;
        $pdo = new PDO('mysql:host='.G5_MYSQL_HOST.';dbname='.G5_MYSQL_DB.';charset=utf8mb4', G5_MYSQL_USER, G5_MYSQL_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare('SELECT mb_id FROM boosting_campaigns WHERE no = ?');
            $query->execute([$orderId]);
            $member = $query->fetchColumn();

            return $member === false ? null : (string) $member;
        } finally {
            $pdo->rollBack();
        }
    }
}
