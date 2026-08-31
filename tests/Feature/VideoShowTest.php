<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchTranscript;
use App\Jobs\FetchVideoMetadata;
use App\Livewire\VideoShow;
use App\Models\Summary;
use App\Models\Transcript;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 詳細ページ（VideoShow）の Livewire テスト。
 *
 * 進捗ポーリングの有無・再試行のガード・削除の連鎖を見る。
 */
class VideoShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_progress_video_polls_for_updates(): void
    {
        $video = Video::factory()->pending()->create();

        Livewire::test(VideoShow::class, ['video' => $video])
            // 第 2 引数 false = HTML エスケープせず生の属性文字列で検索。
            ->assertSee('wire:poll', false);
    }

    public function test_terminal_video_stops_polling_and_shows_retry(): void
    {
        $video = Video::factory()->create(); // 既定 = completed

        Livewire::test(VideoShow::class, ['video' => $video])
            ->assertDontSee('wire:poll', false)
            ->assertSee('再試行');
    }

    public function test_completed_video_renders_the_summary_markdown(): void
    {
        $video = Video::factory()->create();
        Summary::factory()->for($video)->create([
            'content' => "## TL;DR\n\nこれは要約です。",
        ]);

        Livewire::test(VideoShow::class, ['video' => $video])
            ->assertSee('これは要約です。')
            ->assertSee('claude-sonnet-5'); // 生成メタ
    }

    public function test_retry_restarts_ingestion_only_from_a_terminal_state(): void
    {
        Bus::fake();

        $failed = Video::factory()->failed('transcript')->create();

        Livewire::test(VideoShow::class, ['video' => $failed])
            ->call('retry');

        $failed->refresh();
        $this->assertSame(ProcessingStatus::Pending, $failed->status);
        $this->assertNull($failed->failed_step);
        Bus::assertChained([FetchVideoMetadata::class, FetchTranscript::class]);
    }

    public function test_retry_is_ignored_while_still_processing(): void
    {
        Bus::fake();

        $running = Video::factory()->create(['status' => ProcessingStatus::Summarizing]);

        Livewire::test(VideoShow::class, ['video' => $running])
            ->call('retry');

        $running->refresh();
        $this->assertSame(ProcessingStatus::Summarizing, $running->status);
        Bus::assertNothingDispatched();
    }

    public function test_delete_removes_the_video_with_children_and_redirects(): void
    {
        $video = Video::factory()->create();
        Transcript::factory()->for($video)->create();
        Summary::factory()->for($video)->create();

        Livewire::test(VideoShow::class, ['video' => $video])
            ->call('delete')
            ->assertRedirect(route('videos.index'));

        $this->assertDatabaseMissing('videos', ['id' => $video->id]);
        // FK の ON DELETE CASCADE で子テーブルも消える。
        $this->assertDatabaseMissing('transcripts', ['video_id' => $video->id]);
        $this->assertDatabaseMissing('summaries', ['video_id' => $video->id]);
    }
}
