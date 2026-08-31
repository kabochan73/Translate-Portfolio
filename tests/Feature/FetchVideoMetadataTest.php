<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchVideoMetadata;
use App\Models\Video;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * FetchVideoMetadata ジョブのテスト。
 *
 * YouTube Data API の HTTP 呼び出しは Http::fake() で差し替える
 * （本物には絶対に触れない）。ジョブは handle() を直接呼んで実行する。
 */
class FetchVideoMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_metadata_and_syncs_tags(): void
    {
        // YouTube Data API v3 の videos エンドポイントの返り値を模擬。
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'snippet' => [
                        'title' => 'Understanding Laravel Queues',
                        'channelTitle' => 'Laravel Daily',
                        'publishedAt' => '2024-05-01T10:00:00Z',
                        'defaultAudioLanguage' => 'en',
                        'thumbnails' => [
                            'high' => ['url' => 'https://i.ytimg.com/vi/abc/hqdefault.jpg'],
                        ],
                        'tags' => ['laravel', 'queue', 'laravel'], // 重複はまとめられる
                    ],
                    'contentDetails' => ['duration' => 'PT4M13S'], // 253 秒
                ]],
            ]),
        ]);

        $video = Video::factory()->pending()->create(['youtube_id' => 'abc']);

        (new FetchVideoMetadata($video))->handle(app(YouTubeService::class));

        $video->refresh();
        $this->assertSame('Understanding Laravel Queues', $video->title);
        $this->assertSame('Laravel Daily', $video->channel_name);
        $this->assertSame(253, $video->duration_seconds);
        $this->assertSame('en', $video->source_language);
        // このジョブは status を「取得中」にするだけ（完了にするのは後続）。
        $this->assertSame(ProcessingStatus::FetchingMetadata, $video->status);

        // タグは正規化されて 2 件だけ紐付く。
        $this->assertEqualsCanonicalizing(
            ['laravel', 'queue'],
            $video->tags()->pluck('name')->all(),
        );
    }

    public function test_missing_video_throws(): void
    {
        // items が空 = 削除済み / 非公開 / ID 誤り。
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => []]),
        ]);

        $video = Video::factory()->pending()->create();

        $this->expectException(RuntimeException::class);

        (new FetchVideoMetadata($video))->handle(app(YouTubeService::class));
    }

    public function test_failed_hook_marks_video_failed_at_metadata_step(): void
    {
        $video = Video::factory()->pending()->create();

        (new FetchVideoMetadata($video))->failed(new RuntimeException('YouTube API 障害'));

        $video->refresh();
        $this->assertSame(ProcessingStatus::Failed, $video->status);
        $this->assertSame('metadata', $video->failed_step);
        $this->assertStringContainsString('YouTube API 障害', $video->failed_reason);
    }
}
