<?php

return [
    // Private operational policy, shared with the legacy order importer.
    'path' => env('RANK_TRACKING_EXCLUSIONS_PATH', '/www/jcurve/boosting_shop/manager/data/rank_tracking_exclusions.json'),
    'boosting_db_config' => env('BOOSTING_DB_CONFIG', '/www/jcurve/boosting_shop/manager/data/dbconfig.php'),
];
