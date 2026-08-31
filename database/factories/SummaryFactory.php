<?php

namespace Database\Factories;

use App\Enums\SummaryStatus;
use App\Models\Summary;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の Summary。既定は「生成完了」。
 *
 * @extends Factory<Summary>
 */
class SummaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'status' => SummaryStatus::Completed,
            'language' => 'ja',
            'content' => "## TL;DR\n\nテスト用の要約本文。\n\n## キーポイント\n\n- ポイント1\n\n## チャプター別の要約\n\n### [0:15] はじめに\n\n導入部分。",
            'model' => 'claude-sonnet-5',
            'prompt_version' => 'v2',
            'input_tokens' => 1200,
            'output_tokens' => 300,
            'cost_usd' => 0.0054,
            'completed_at' => now(),
        ];
    }

    /** まだ生成中。 */
    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => SummaryStatus::Processing,
            'content' => null,
            'completed_at' => null,
        ]);
    }

    /** 生成に失敗。 */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => SummaryStatus::Failed,
            'content' => null,
            'error_message' => 'テスト用の生成エラー',
            'completed_at' => null,
        ]);
    }
}
