<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 動画撮影用の配信ファイルの出力先
    |--------------------------------------------------------------------------
    |
    | RecordingDeploySeeder が書く SQLite の置き場。クライアントは運営ツール（tool-web, :8091）から
    | 取得するので、tool の public/masterdata を api-php にマウントしたパスを指す
    | （docker-compose.yml の api-php.volumes）。ディレクトリが無いとシーダーは失敗する。
    |
    */
    'masterdata_dir' => env('RECORDING_MASTERDATA_DIR', '/var/www/tool-masterdata'),

];
