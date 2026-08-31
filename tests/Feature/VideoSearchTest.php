<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Video モデルのクエリスコープ（scopeSearch / scopeWithTag）のテスト。
 *
 * RefreshDatabase: 各テストの前に（インメモリ SQLite へ）マイグレーションを流し、
 * テスト間でデータを持ち越さない。
 */
class VideoSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_title_channel_and_tag_name(): void
    {
        $byTitle = Video::factory()->create(['title' => 'Laravel キューの解説', 'channel_name' => 'Foo']);
        $byChannel = Video::factory()->create(['title' => 'まったく別の話', 'channel_name' => 'Laravel News']);
        $byTag = Video::factory()->create(['title' => 'ML の基礎', 'channel_name' => 'Bar']);
        $byTag->tags()->attach(Tag::factory()->create(['name' => 'laravel']));

        $noise = Video::factory()->create(['title' => '料理動画', 'channel_name' => 'Kitchen']);

        // 大文字小文字を無視して 3 つの経路すべてにヒットする。
        $ids = Video::search('LARAVEL')->pluck('id');

        $this->assertEqualsCanonicalizing(
            [$byTitle->id, $byChannel->id, $byTag->id],
            $ids->all(),
        );
        $this->assertNotContains($noise->id, $ids->all());
    }

    public function test_with_tag_filters_by_exact_tag_name(): void
    {
        $ai = Tag::factory()->create(['name' => 'AI']);
        $php = Tag::factory()->create(['name' => 'PHP']);

        $tagged = Video::factory()->create();
        $tagged->tags()->attach($ai);

        $other = Video::factory()->create();
        $other->tags()->attach($php);

        $result = Video::withTag('AI')->pluck('id');

        $this->assertSame([$tagged->id], $result->all());
    }

    public function test_duration_label_formats_hours_and_minutes(): void
    {
        $short = Video::factory()->make(['duration_seconds' => 253]);   // 4:13
        $long = Video::factory()->make(['duration_seconds' => 3753]);   // 1:02:33
        $none = Video::factory()->make(['duration_seconds' => null]);

        $this->assertSame('4:13', $short->duration_label);
        $this->assertSame('1:02:33', $long->duration_label);
        $this->assertNull($none->duration_label);
    }
}
