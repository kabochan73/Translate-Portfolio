<?php

namespace App\Livewire;

use App\Models\Video;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 動画詳細ページ（/videos/{video}）。
 *
 * ルートモデルバインディングで Video を受け取る。
 * 進捗ステッパー・要約表示・再試行・削除はフェーズ5で実装する。
 * 今はルーティング確認用の最小スケルトン。
 */
#[Layout('layouts.app')]
class VideoShow extends Component
{
    /** URL の {video} から解決された動画。 */
    public Video $video;

    public function render()
    {
        return view('livewire.video-show');
    }
}
