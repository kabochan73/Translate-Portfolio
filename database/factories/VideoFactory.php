<?php

namespace Database\Factories;

use App\Enums\ProcessingStatus;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * テスト用の Video を組み立てるファクトリ。
 *
 * 既定は「取り込みが完了した動画」（メタデータが全部入っている状態）。
 * まだ処理前の状態が欲しいときは ->pending()、
 * 字幕が無い状態は ->noTranscript() のように状態メソッドで切り替える。
 *
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    public function definition(): array
    {
        // 11 桁の YouTube ID っぽいランダム文字列。
        $youtubeId = Str::substr(Str::random(16), 0, 11);

        return [
            'youtube_id' => $youtubeId,
            'url' => "https://www.youtube.com/watch?v={$youtubeId}",
            'title' => fake()->sentence(4),
            'channel_name' => fake()->name().' Channel',
            'thumbnail_url' => "https://i.ytimg.com/vi/{$youtubeId}/hqdefault.jpg",
            'duration_seconds' => fake()->numberBetween(60, 7200),
            'published_at' => fake()->dateTimeBetween('-2 years', '-1 day'),
            'source_language' => 'en',
            'status' => ProcessingStatus::Completed,
            'failed_step' => null,
            'failed_reason' => null,
        ];
    }

    /** 登録直後（まだ何も処理していない）。メタデータも空。 */
    public function pending(): static
    {
        return $this->state(fn () => [
            'title' => null,
            'channel_name' => null,
            'thumbnail_url' => null,
            'duration_seconds' => null,
            'published_at' => null,
            'source_language' => null,
            'status' => ProcessingStatus::Pending,
        ]);
    }

    /** 字幕が無くて正常終了した動画。 */
    public function noTranscript(): static
    {
        return $this->state(fn () => ['status' => ProcessingStatus::NoTranscript]);
    }

    /** どこかの工程で失敗した動画。 */
    public function failed(string $step = 'metadata'): static
    {
        return $this->state(fn () => [
            'status' => ProcessingStatus::Failed,
            'failed_step' => $step,
            'failed_reason' => 'テスト用の失敗理由',
        ]);
    }
}
