<?php

namespace App\Providers;

use App\Services\AnthropicService;
use App\Services\YouTubeService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\ServiceProvider;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;

class AppServiceProvider extends ServiceProvider
{
    /**
     * サービスのバインド。
     *
     * これらはコンストラクタに「スカラー値」や「Laravel が自動解決できない
     * 外部クラス」を要求するので、生成方法を明示する必要がある。
     *
     * TranscriptService（TranscriptListFetcher に依存）と
     * SummaryGenerator（AnthropicService に依存）は、依存先さえバインドすれば
     * Laravel が型ヒントから自動で解決できるのでここには書かない。
     */
    public function register(): void
    {
        // YouTube API キーを注入。singleton（1リクエスト内で使い回す）。
        $this->app->singleton(
            YouTubeService::class,
            fn () => new YouTubeService(config('services.youtube.key')),
        );

        // 字幕取得ライブラリ。PSR-18 HTTP クライアント + PSR-17 ファクトリを渡す。
        // Laravel が Guzzle を同梱しているのでそれを使う（HttpFactory は
        // RequestFactory と StreamFactory を兼ねるので同じインスタンスを2回渡す）。
        $this->app->singleton(TranscriptListFetcher::class, function () {
            $factory = new HttpFactory;

            return new TranscriptListFetcher(new GuzzleClient, $factory, $factory);
        });

        // Claude API のキー・モデル・ワークスペース ID を注入。
        $this->app->singleton(AnthropicService::class, fn () => new AnthropicService(
            config('services.anthropic.key'),
            config('services.anthropic.model'),
            config('services.anthropic.workspace_id'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
