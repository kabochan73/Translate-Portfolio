<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 動画一覧ページ（/videos）。
 *
 * フルページ Livewire コンポーネント（ルートに直接クラスを指定して使う）。
 * 検索・タグ絞り込み・ページネーションはフェーズ5で実装する。
 * 今はルーティングとレイアウトの確認用の最小スケルトン。
 */
#[Layout('layouts.app')]
class VideoIndex extends Component
{
    public function render()
    {
        return view('livewire.video-index');
    }
}
