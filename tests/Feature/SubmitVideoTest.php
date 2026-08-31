<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchTranscript;
use App\Jobs\FetchVideoMetadata;
use App\Livewire\SubmitVideo;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ヘッダーの URL 登録フォーム（SubmitVideo）のテスト。
 *
 * 外部にも一切触らない: ジョブは Bus::fake() で投入だけ検証する。
 */
class SubmitVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_url_creates_video_and_dispatches_the_ingestion_chain(): void
    {
        Bus::fake();

        Livewire::test(SubmitVideo::class)
            ->set('url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('videos.show', Video::first()));

        // 動画が 1 件だけ作られ、pending で始まっている。
        $this->assertDatabaseCount('videos', 1);
        $video = Video::first();
        $this->assertSame('dQw4w9WgXcQ', $video->youtube_id);
        $this->assertSame(ProcessingStatus::Pending, $video->status);

        // メタデータ → 字幕 の順のチェーンが投入された。
        Bus::assertChained([
            FetchVideoMetadata::class,
            FetchTranscript::class,
        ]);
    }

    public function test_duplicate_url_does_not_create_a_second_video_or_dispatch(): void
    {
        Bus::fake();

        $existing = Video::factory()->create(['youtube_id' => 'dQw4w9WgXcQ']);

        Livewire::test(SubmitVideo::class)
            ->set('url', 'https://youtu.be/dQw4w9WgXcQ')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('videos.show', $existing));

        $this->assertDatabaseCount('videos', 1);
        Bus::assertNothingDispatched();
    }

    public function test_non_youtube_url_is_rejected(): void
    {
        Bus::fake();

        Livewire::test(SubmitVideo::class)
            ->set('url', 'https://example.com/not-a-video')
            ->call('submit')
            ->assertHasErrors('url');

        $this->assertDatabaseCount('videos', 0);
        Bus::assertNothingDispatched();
    }

    public function test_url_is_required(): void
    {
        Livewire::test(SubmitVideo::class)
            ->set('url', '')
            ->call('submit')
            ->assertHasErrors(['url' => 'required']);
    }
}
