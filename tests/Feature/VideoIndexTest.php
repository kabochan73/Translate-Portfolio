<?php

namespace Tests\Feature;

use App\Livewire\VideoIndex;
use App\Models\Tag;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 一覧ページ（VideoIndex）の Livewire テスト。
 *
 * 検索・タグ絞り込み・ページネーション・クエリ文字列の保持を見る。
 */
class VideoIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_redirects_root_to_videos_and_lists_without_auth(): void
    {
        $this->get('/')->assertRedirect('/videos');

        Video::factory()->create(['title' => 'はじめての動画']);

        // 認証なしで 200。
        $this->get('/videos')
            ->assertOk()
            ->assertSee('はじめての動画');
    }

    public function test_search_filters_by_query(): void
    {
        Video::factory()->create(['title' => 'Laravel の話', 'channel_name' => 'A']);
        Video::factory()->create(['title' => '料理の話', 'channel_name' => 'B']);

        Livewire::test(VideoIndex::class)
            ->set('query', 'laravel')
            ->assertSee('Laravel の話')
            ->assertDontSee('料理の話');
    }

    public function test_updating_query_resets_to_first_page(): void
    {
        Video::factory()->count(25)->create();

        // Livewire 3 の WithPagination はページ番号を $paginators['page'] に持つ。
        Livewire::test(VideoIndex::class)
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->set('query', 'zzz-no-match')
            ->assertSet('paginators.page', 1);
    }

    public function test_filter_by_tag_is_a_toggle(): void
    {
        $tagged = Video::factory()->create(['title' => 'タグ付き動画']);
        $tagged->tags()->attach(Tag::factory()->create(['name' => 'AI']));
        Video::factory()->create(['title' => 'タグ無し動画']);

        Livewire::test(VideoIndex::class)
            ->call('filterByTag', 'AI')
            ->assertSet('tag', 'AI')
            ->assertSee('タグ付き動画')
            ->assertDontSee('タグ無し動画')
            // 同じタグをもう一度 → 解除
            ->call('filterByTag', 'AI')
            ->assertSet('tag', '')
            ->assertSee('タグ無し動画');
    }

    public function test_pagination_shows_18_per_page(): void
    {
        Video::factory()->count(20)->create();

        Livewire::test(VideoIndex::class)
            ->assertViewHas('videos', fn ($videos) => $videos->count() === 18 && $videos->total() === 20);
    }

    public function test_query_and_tag_are_bound_to_the_url(): void
    {
        // #[Url] が付いたプロパティは ?q= / ?tag= から復元される。
        Video::factory()->create(['title' => 'Laravel の話']);
        Video::factory()->create(['title' => '料理の話']);

        Livewire::withQueryParams(['q' => 'laravel'])
            ->test(VideoIndex::class)
            ->assertSet('query', 'laravel')
            ->assertSee('Laravel の話')
            ->assertDontSee('料理の話');
    }
}
