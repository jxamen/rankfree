<?php

namespace App\Domain\Shopping;

use PDO;
use RuntimeException;

class BoostingShopCampaignReader
{
    public function shopping(): array
    {
        // Load only constants; never bootstrap the other application or issue writes to it.
        $path = config('shop_keyword_autoend.boosting_db_config');
        if (! is_readable($path)) {
            throw new RuntimeException('부스팅샵 DB 설정을 읽을 수 없습니다. 종료 판정을 중단합니다.');
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
            return $pdo->query("SELECT no, keyword, link, link2, link3, catalog_product_url, status, fr_date, to_date FROM boosting_campaigns WHERE ad_category = 'shopping'")->fetchAll(PDO::FETCH_ASSOC);
        } finally {
            $pdo->rollBack();
        }
    }
}
