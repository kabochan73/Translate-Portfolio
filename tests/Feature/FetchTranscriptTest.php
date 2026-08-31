<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchTranscript;
use App\Jobs\GenerateSummary;
use App\Models\Video;
use App\Services\TranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

/**
 * FetchTranscript ジョブのテスト。
 *
 * 字幕取得は「字幕あり」「字幕なし」で分岐が大きく違うので両方見る。
 * TranscriptService はモックに差し替える（youtube-transcript の本物を呼ばない）。
 * GenerateSummary の投入は Bus::fake() で検証。
 */
class FetchTranscriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_transcript_it_saves_and_dispatches_summary(): void
    {
        Bus::fake();

        // fetch() が字幕データを返すケース。
        $service = $this->mock(TranscriptService::class);
        $service->shouldReceive('fetch')
            ->once()
            ->andReturn([
                'language' => 'en',
                'content' => 'hello world foo bar',
                'segments' => [
                    ['start' => 0.0, 'end' => 2.0, 'text' => 'hello world'],
                    ['start' => 2.0, 'end' => 4.0, 'text' => 'foo bar'],
                ],
            ]);

        $video = Video::factory()->pending()->create();

        (new FetchTranscript($video))->handle($service);

        $video->refresh();
        $this->assertSame(ProcessingStatus::FetchingTranscript, $video->status);

        // 字幕が保存された。
        $this->assertNotNull($video->transcript);
        $this->assertSame('en', $video->transcript->language);
        $this->assertCount(2, $video->transcript->segments);

        // 要約ジョブが投入された。
        Bus::assertDispatched(GenerateSummary::class);
    }

    public function test_without_transcript_it_ends_as_no_transcript(): void
    {
        Bus::fake();

        // fetch() が null を返す = 字幕なし（正常系）。
        $service = $this->mock(TranscriptService::class);
        $service->shouldReceive('fetch')->once()->andReturnNull();

        $video = Video::factory()->pending()->create();

        (new FetchTranscript($video))->handle($service);

        $video->refresh();
        $this->assertSame(ProcessingStatus::NoTranscript, $video->status);
        $this->assertNull($video->transcript);

        // 要約は投げない。
        Bus::assertNotDispatched(GenerateSummary::class);
    }

    public function test_failed_hook_marks_video_failed_at_transcript_step(): void
    {
        $video = Video::factory()->pending()->create();

        (new FetchTranscript($video))->failed(new RuntimeException('字幕サービス障害'));

        $video->refresh();
        $this->assertSame(ProcessingStatus::Failed, $video->status);
        $this->assertSame('transcript', $video->failed_step);
    }
}
