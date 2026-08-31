<?php

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Enums\SummaryStatus;
use App\Models\Video;
use App\Services\SummaryGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 取り込みチェーンの 3 本目：要約生成。
 *
 * チェーン（Bus::chain）には入っておらず、字幕が取れた時に
 * FetchTranscript から dispatch される。
 * 要約は時間がかかるので timeout を長めに取る（300 秒）。
 */
class GenerateSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** 要約は map-reduce で複数回 API を叩くので長め。 */
    public int $timeout = 300;

    public function __construct(public readonly Video $video) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(SummaryGenerator $generator): void
    {
        $transcript = $this->video->transcript;

        // 字幕が無い動画にこのジョブが来るのは想定外（バグ）。
        if ($transcript === null) {
            throw new RuntimeException('字幕が無い動画に GenerateSummary が実行されました。');
        }

        $this->video->update(['status' => ProcessingStatus::Summarizing]);

        // 再試行で既存の要約行を作り直せるよう updateOrCreate。
        $summary = $this->video->summary()->updateOrCreate([], [
            'status' => SummaryStatus::Processing,
            'language' => 'ja',
        ]);

        // ★ translate2 との違い: content ではなく segments（時刻付き）を渡す。
        $result = $generator->generate($transcript->segments ?? []);

        $summary->update([
            'status' => SummaryStatus::Completed,
            'content' => $result['content'],
            'model' => config('services.anthropic.model'),
            'prompt_version' => $result['prompt_version'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'cost_usd' => $this->estimateCost($result['input_tokens'], $result['output_tokens']),
            'error_message' => null,
            'completed_at' => now(),
        ]);

        $this->video->update(['status' => ProcessingStatus::Completed]);
    }

    public function failed(Throwable $e): void
    {
        $message = Str::limit($e->getMessage(), 500);

        // 要約行があれば失敗状態にする（無ければ何もしない）。
        $this->video->summary()->update([
            'status' => SummaryStatus::Failed,
            'error_message' => $message,
        ]);

        $this->video->update([
            'status' => ProcessingStatus::Failed,
            'failed_step' => 'summary',
            'failed_reason' => $message,
        ]);
    }

    /**
     * 概算コスト（USD）。単価は config/services.php の anthropic 節。
     * 正確な課金額ではなく「だいたいいくら」表示用。
     */
    private function estimateCost(int $inputTokens, int $outputTokens): float
    {
        return round(
            $inputTokens / 1_000_000 * (float) config('services.anthropic.input_cost_per_mtok')
            + $outputTokens / 1_000_000 * (float) config('services.anthropic.output_cost_per_mtok'),
            6,
        );
    }
}
