<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 動画撮影用の成果物の置き場
    |--------------------------------------------------------------------------
    |
    | tool の build（MASTER_DEPLOY_FIXED_KEY=7777777777）が置いた成果物（7777777777_{hash16}/）の置き場。
    | RecordingDeploySeeder は読むだけ（書かない）。クライアントは運営ツール（tool-web, :8091）から
    | 取得するので、tool の public/masterdata を api-php にマウントしたパスを指す
    | （docker-compose.yml の api-php.volumes）。ディレクトリや成果物が無いとシーダーは何も書かずに中止する。
    |
    */
    'masterdata_dir' => env('RECORDING_MASTERDATA_DIR', '/var/www/tool-masterdata'),

];
