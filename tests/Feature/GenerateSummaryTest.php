<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Enums\SummaryStatus;
use App\Jobs\GenerateSummary;
use App\Models\Summary;
use App\Models\Video;
use App\Services\SummaryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * GenerateSummary ジョブのテスト。
 *
 * Claude Messages API は Http::fake() で差し替える。
 * 短い字幕なので SummaryGenerator は 1 チャンク → API 呼び出し 1 回。
 */
class GenerateSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_a_completed_summary_and_marks_video_completed(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => "全体の要点です。\n\n## キーポイント\n\n- ポイント1\n\n### [0:00] 冒頭\n\n導入。"],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ]),
        ]);

        $video = Video::factory()->create(['status' => ProcessingStatus::FetchingTranscript]);
        $video->transcript()->create([
            'language' => 'en',
            'content' => 'hello world',
            'segments' => [['start' => 0.0, 'end' => 2.0, 'text' => 'hello world']],
        ]);

        (new GenerateSummary($video))->handle(app(SummaryGenerator::class));

        $video->refresh();
        $this->assertSame(ProcessingStatus::Completed, $video->status);

        $summary = $video->summary;
        $this->assertSame(SummaryStatus::Completed, $summary->status);
        $this->assertStringContainsString('## キーポイント', $summary->content);
        $this->assertSame('claude-sonnet-5', $summary->model);
        $this->assertSame('v3', $summary->prompt_version);
        $this->assertSame(100, $summary->input_tokens);
        $this->assertSame(50, $summary->output_tokens);
        // 100/1e6*2 + 50/1e6*10 = 0.0007
        $this->assertSame(0.0007, (float) $summary->cost_usd);
        $this->assertNotNull($summary->completed_at);
    }

    public function test_it_throws_when_transcript_is_missing(): void
    {
        $video = Video::factory()->create();

        $this->expectException(RuntimeException::class);

        (new GenerateSummary($video))->handle(app(SummaryGenerator::class));
    }

    public function test_failed_hook_marks_summary_and_video_failed(): void
    {
        $video = Video::factory()->create(['status' => ProcessingStatus::Summarizing]);
        Summary::factory()->processing()->for($video)->create();

        (new GenerateSummary($video))->failed(new RuntimeException('Claude 500'));

        $video->refresh();
        $this->assertSame(ProcessingStatus::Failed, $video->status);
        $this->assertSame('summary', $video->failed_step);
        $this->assertSame(SummaryStatus::Failed, $video->summary->status);
        $this->assertStringContainsString('Claude 500', $video->summary->error_message);
    }
}
