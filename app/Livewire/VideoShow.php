<?php

namespace App\Livewire;

use App\Actions\StartVideoIngestion;
use App\Models\Video;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * 動画詳細ページ（/videos/{video}）。
 *
 * 役割:
 *   - 動画の埋め込み・メタ情報・タグの表示
 *   - 取り込みの進捗表示（終了状態になるまで wire:poll でポーリング）
 *   - 要約（Markdown）の表示
 *   - 「再試行」「削除」の操作
 *
 * ポーリングはビュー側の <div wire:poll.3s> で行い、
 * 終了状態（completed / no_transcript / failed）になったら
 * ビューの @if で wire:poll ごと消える（無駄な通信を止める）。
 */
#[Layout('layouts.app')]
class VideoShow extends Component
{
    /** URL の {video} から解決された動画（ルートモデルバインディング）。 */
    public Video $video;

    /**
     * 初回表示時に一度だけ関連を読み込む。
     */
    public function mount(): void
    {
        $this->video->load('tags', 'transcript', 'summary');
    }

    /**
     * 「再試行」ボタン。
     *
     * 終了状態のときだけ実行できる（処理中に押されても無視）。
     * status リセットとジョブ投入は Action に任せる（SubmitVideo と共通）。
     */
    public function retry(StartVideoIngestion $startIngestion): void
    {
        // 進行中に二重投入されないようガード。
        if (! $this->video->status->isTerminal()) {
            return;
        }

        $startIngestion($this->video);

        session()->flash('status', '再試行を開始しました。');
    }

    /**
     * 「削除」ボタン。
     *
     * transcripts / summaries / tag_video は videos への外部キーが
     * ON DELETE CASCADE なので、この 1 行の delete で子レコードも消える。
     */
    public function delete(): void
    {
        $this->video->delete();

        session()->flash('status', '動画を削除しました。');

        $this->redirectRoute('videos.index', navigate: true);
    }

    public function render()
    {
        // ポーリングのたびに最新値を読み直す。
        // refresh() で videos 本体、load() で関連（要約の進捗など）を更新。
        $this->video->refresh()->load('tags', 'transcript', 'summary');

        return view('livewire.video-show');
    }
}
