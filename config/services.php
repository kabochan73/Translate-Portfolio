<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // YouTube Data API v3。動画のメタデータ（タイトル・チャンネル名・再生時間・
    // 公開日・タグ）取得に使う。字幕そのものは youtube-transcript パッケージ側で取る。
    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

    // Claude Messages API。字幕テキストの日本語要約に使う。
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),

        // 組織で「ワークスペース」に紐付いた API キーを使う場合のみ必要。
        // 通常のキーなら未設定（null）でよい。
        'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),

        // 使用モデル。要約は claude-sonnet-5（速度・コストのバランス重視）。
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

        // コスト概算用の単価（USD / 100万トークン）。
        // summaries.cost_usd を「だいたいいくらかかったか」表示するためだけに使う。
        // 既定値は claude-sonnet-5 の第一者 API レート（input $2 / output $10）。
        // モデルを変えたら .env で上書きすること。
        'input_cost_per_mtok' => (float) env('ANTHROPIC_INPUT_COST_PER_MTOK', 2.0),
        'output_cost_per_mtok' => (float) env('ANTHROPIC_OUTPUT_COST_PER_MTOK', 10.0),
    ],

];
