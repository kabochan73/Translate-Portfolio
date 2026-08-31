<?php

namespace Database\Factories;

use App\Models\Transcript;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * テスト用の Transcript。既定で親 Video も一緒に作る。
 *
 * @extends Factory<Transcript>
 */
class TranscriptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'video_id' => Video::factory(),
            'language' => 'en',
            'content' => 'hello world this is a test transcript',
            'segments' => [
                ['start' => 0.0, 'end' => 2.0, 'text' => 'hello world'],
                ['start' => 2.0, 'end' => 5.0, 'text' => 'this is a test transcript'],
            ],
        ];
    }
}
