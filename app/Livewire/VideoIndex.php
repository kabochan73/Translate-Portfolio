<?php

namespace App\Livewire;

use App\Models\Video;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * 動画一覧ページ（/videos）。
 *
 * フルページ Livewire コンポーネント。やることは
 *   「検索語 / タグ絞り込み / ページ番号を状態として持ち、
 *    それを Video のクエリスコープに渡して一覧を描画する」
 * だけ。SQL 条件そのものは Model 側（scopeSearch / scopeWithTag）にある。
 */
#[Layout('layouts.app')]
#[Title('動画一覧')]
class VideoIndex extends Component
{
    // ページネーションのリンク・?page= の同期などを面倒みてくれるトレイト。
    use WithPagination;

    /**
     * フリーワード検索。
     *
     * #[Url] を付けると ?q=... としてブラウザの URL に載る。
     * → 検索結果を URL 共有できる / リロードしても消えない / 戻るボタンが効く。
     */
    #[Url(as: 'q', except: '')]
    public string $query = '';

    /**
     * タグ絞り込み（一覧カード内のタグバッジをクリックすると入る）。
     * こちらも ?tag=... として URL に載せる。
     */
    #[Url(as: 'tag', except: '')]
    public string $tag = '';

    /**
     * $query が変わる「直前」に呼ばれる Livewire のフック。
     *
     * 検索語を変えたのに 3 ページ目のまま、だと結果が 0 件に見えてしまう。
     * 条件が変わったら必ず 1 ページ目に戻す。
     */
    public function updatingQuery(): void
    {
        $this->resetPage();
    }

    public function updatingTag(): void
    {
        $this->resetPage();
    }

    /**
     * タグバッジから呼ぶ。同じタグをもう一度押したら解除（トグル）。
     */
    public function filterByTag(string $name): void
    {
        $this->tag = $this->tag === $name ? '' : $name;
        $this->resetPage();
    }

    /**
     * 検索語・タグをまとめてクリアする（「条件をクリア」リンク用）。
     */
    public function clearFilters(): void
    {
        $this->reset('query', 'tag');
        $this->resetPage();
    }

    public function render()
    {
        // when() の第1引数が truthy のときだけクロージャを適用する。
        // → 条件が空なら余計な WHERE を足さない。
        $videos = Video::query()
            ->with('tags') // N+1 回避：一覧で各カードの $video->tags を使うため先読み
            ->when($this->query !== '', fn ($q) => $q->search($this->query))
            ->when($this->tag !== '', fn ($q) => $q->withTag($this->tag))
            ->latest() // created_at 降順（新しい登録が上）
            ->paginate(18);

        return view('livewire.video-index', [
            'videos' => $videos,
        ]);
    }
}
