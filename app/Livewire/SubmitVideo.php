<?php

namespace App\Livewire;

use App\Actions\StartVideoIngestion;
use App\Models\Video;
use App\Services\YouTubeService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * ヘッダーに常駐する「YouTube URL 登録フォーム」。
 *
 * レイアウト（layouts/app.blade.php）のヘッダー内に <livewire:submit-video />
 * として置く。どのページからでも URL を貼って取り込みを開始できる。
 *
 * このコンポーネントの責務は 3 つだけ:
 *   1. 入力（URL）を受け取ってバリデーションする
 *   2. videos 行を用意する（重複は作らない）
 *   3. 新規なら取り込みを開始し、詳細ページへ飛ばす
 *
 * 「状態リセット + ジョブ投入」の中身は Action に切り出してあるので、
 * ここには書かない（VideoShow の再試行と共通化するため）。
 */
class SubmitVideo extends Component
{
    /** フォームの入力値。wire:model で双方向バインドする。 */
    public string $url = '';

    /**
     * 登録ボタン（またはフォーム submit）で呼ばれる。
     *
     * YouTubeService はメソッドインジェクションで受け取る
     * （Livewire のアクションは型ヒントから DI コンテナ経由で解決してくれる）。
     */
    public function submit(YouTubeService $youtube): void
    {
        // --- 1. バリデーション -------------------------------------------
        // まず「入っているか・長すぎないか」の基本ルール。
        $this->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        // 次に「YouTube の URL として ID を取り出せるか」。
        // extractVideoId は対応形式（watch?v= / youtu.be / shorts / embed）以外だと null。
        $youtubeId = $youtube->extractVideoId($this->url);

        if ($youtubeId === null) {
            // 特定フィールドにエラーを付けて中断する。
            throw ValidationException::withMessages([
                'url' => 'YouTube の動画 URL を入力してください。',
            ]);
        }

        // --- 2. videos 行を用意（重複登録を防ぐ） -----------------------
        // youtube_id は unique。すでに登録済みなら既存行が返り、
        // wasRecentlyCreated が false になる。
        $video = Video::firstOrCreate(
            ['youtube_id' => $youtubeId],
            ['url' => $this->url],
        );

        // 入力欄はどちらのルートでも空に戻す。
        $this->reset('url');

        // --- 3a. すでに登録済みだった場合 ------------------------------
        // 取り込みはやり直さず（再試行は詳細ページのボタンから）、
        // その動画の詳細ページへ案内するだけ。
        if (! $video->wasRecentlyCreated) {
            session()->flash('status', 'この動画はすでに登録されています。');
            $this->redirectRoute('videos.show', $video, navigate: true);

            return;
        }

        // --- 3b. 新規登録 --------------------------------------------------
        // status=pending にして FetchVideoMetadata → FetchTranscript を投入。
        app(StartVideoIngestion::class)($video);

        session()->flash('status', '取り込みを開始しました。進捗はこのページで確認できます。');
        $this->redirectRoute('videos.show', $video, navigate: true);
    }

    public function render()
    {
        return view('livewire.submit-video');
    }
}
