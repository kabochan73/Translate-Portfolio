<?php

namespace App\Services;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * YouTube Data API v3 との通信をまとめた薄いラッパー。
 *
 * 外部 API を叩くのはこの手の Service クラスだけ、という方針
 * （Livewire コンポーネントやジョブから直接 Http:: を呼ばない）。
 * 呼び出し元は取り込みジョブ FetchVideoMetadata。
 *
 * API キーはコンストラクタで受け取る（バインドは AppServiceProvider）。
 */
class YouTubeService
{
    public function __construct(private readonly ?string $apiKey) {}

    /**
     * 各種 YouTube URL から 11 桁の動画 ID を取り出す。
     *
     * 対応形式: watch?v= / youtu.be/ / shorts/ / embed/
     * 取り出せなければ null（呼び出し元がバリデーションエラーにする）。
     */
    public function extractVideoId(string $url): ?string
    {
        // 11 桁の ID 部分だけキャプチャ。ID に使われる文字は英数字と _ - のみ。
        $pattern = '#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#';

        return preg_match($pattern, $url, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * 動画のメタデータを取得する。
     *
     * @return array{
     *     title: string,
     *     channel_name: string,
     *     thumbnail_url: ?string,
     *     duration_seconds: ?int,
     *     published_at: string,
     *     source_language: ?string,
     *     tags: array<int, string>,
     * }
     *
     * @throws RuntimeException 動画が見つからない場合（削除済み・非公開・ID 誤りなど）
     */
    public function fetchVideoData(string $videoId): array
    {
        // 一時的な失敗（ネットワーク・5xx）は Http::retry で 3 回まで粘る。
        // それでもダメなら throw() で例外 → ジョブがリトライ or failed。
        $response = Http::retry(3, 200)
            ->timeout(15)
            ->get('https://www.googleapis.com/youtube/v3/videos', [
                'id' => $videoId,
                // snippet: タイトル・チャンネル名・サムネ・タグ・言語
                // contentDetails: 再生時間（ISO 8601 の duration）
                'part' => 'snippet,contentDetails',
                'key' => $this->apiKey,
            ])
            ->throw();

        // 存在しない ID を渡すと items が空配列で返る（HTTP は 200）。
        $item = $response->json('items.0');

        if ($item === null) {
            throw new RuntimeException("YouTube 動画が見つかりません: {$videoId}");
        }

        $snippet = $item['snippet'];
        $duration = $item['contentDetails']['duration'] ?? null;

        return [
            'title' => $snippet['title'],
            'channel_name' => $snippet['channelTitle'],
            // サムネは high → default の順で拾う。どちらも無ければ null。
            'thumbnail_url' => $snippet['thumbnails']['high']['url']
                ?? $snippet['thumbnails']['default']['url']
                ?? null,
            'duration_seconds' => $this->parseDurationSeconds($duration),
            'published_at' => $snippet['publishedAt'],
            // 字幕取得の優先言語に使う。音声言語 → 表記言語 の順で拾う。
            'source_language' => $snippet['defaultAudioLanguage']
                ?? $snippet['defaultLanguage']
                ?? null,
            // タグが無い動画も多い。その場合は空配列。
            'tags' => $snippet['tags'] ?? [],
        ];
    }

    /**
     * ISO 8601 の期間文字列（例 "PT4M13S" = 4分13秒）を秒に直す。
     */
    private function parseDurationSeconds(?string $isoDuration): ?int
    {
        if ($isoDuration === null) {
            return null;
        }

        return (int) CarbonInterval::make($isoDuration)?->totalSeconds;
    }
}
