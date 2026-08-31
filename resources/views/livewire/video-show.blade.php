{{-- 動画詳細ページの中身。フェーズ5で動画埋め込み・進捗ステッパー・要約表示を作る。 --}}
<div>
    <a href="{{ route('videos.index') }}" class="text-sm text-blue-600 hover:underline">&larr; 一覧に戻る</a>

    <h1 class="mt-2 text-xl font-bold">
        動画詳細 #{{ $video->id }}
    </h1>
    <p class="mt-1 text-gray-500">youtube_id: {{ $video->youtube_id }}</p>
    <p class="text-gray-500">status: {{ $video->status->label() }}</p>
    <p class="mt-2 text-gray-500">（フェーズ5で実装予定）</p>
</div>
