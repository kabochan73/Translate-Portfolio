<div>
    {{-- ============ 検索バー ============ --}}
    <div class="mb-6 flex flex-wrap items-center gap-3">
        <div class="relative min-w-0 flex-1">
            <input
                type="search"
                {{-- .live.debounce: 打鍵のたびに送るが 400ms 待ってまとめる。
                     検索は「入力しながら結果が絞れる」体験にしたいので .live。 --}}
                wire:model.live.debounce.400ms="query"
                placeholder="タイトル・チャンネル名・タグで検索"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm
                       focus:border-gray-900 focus:outline-none"
            >
            {{-- 検索中スピナー（Livewire 通信中だけ表示） --}}
            <span
                wire:loading wire:target="query"
                class="absolute right-3 top-2.5 text-xs text-gray-400"
            >検索中…</span>
        </div>

        {{-- タグで絞り込み中はチップを出す。× で解除。 --}}
        @if ($tag !== '')
            <button
                type="button"
                wire:click="filterByTag(@js($tag))"
                class="inline-flex items-center gap-1 rounded-full bg-gray-900 px-3 py-1 text-xs font-medium text-white"
            >
                タグ: {{ $tag }}
                <span aria-hidden="true">&times;</span>
            </button>
        @endif

        @if ($query !== '' || $tag !== '')
            <button
                type="button"
                wire:click="clearFilters"
                class="text-xs text-gray-500 hover:text-gray-900 hover:underline"
            >条件をクリア</button>
        @endif
    </div>

    {{-- ============ 一覧グリッド ============ --}}
    @if ($videos->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 py-16 text-center text-sm text-gray-500">
            @if ($query !== '' || $tag !== '')
                条件に一致する動画がありません。
            @else
                まだ動画がありません。ヘッダーの入力欄に YouTube の URL を貼って登録してください。
            @endif
        </div>
    @else
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($videos as $video)
                {{-- カードは relative なコンテナ。
                     カード全面をクリック可能にするため «透明な a を絶対配置»（stretched link）し、
                     タグボタンだけ z を上げてリンクより手前に置く。
                     こうすると <a> の中に <button> を入れる不正な入れ子を避けられる。 --}}
                <div
                    wire:key="video-{{ $video->id }}"
                    class="group relative overflow-hidden rounded-xl border border-gray-200 bg-white transition hover:shadow-md"
                >
                    <a
                        href="{{ route('videos.show', $video) }}"
                        wire:navigate
                        class="absolute inset-0 z-10"
                        aria-label="{{ $video->title ?? '動画詳細' }}"
                    ></a>

                    {{-- サムネイル（16:9）。未取得ならグレーの箱。 --}}
                    <div class="relative aspect-video bg-gray-100">
                        @if ($video->thumbnail_url)
                            <img
                                src="{{ $video->thumbnail_url }}"
                                alt=""
                                loading="lazy"
                                class="h-full w-full object-cover"
                            >
                        @endif

                        {{-- 再生時間バッジ（右下） --}}
                        @if ($video->duration_label)
                            <span class="absolute bottom-1.5 right-1.5 rounded bg-black/80 px-1.5 py-0.5 text-xs font-medium text-white">
                                {{ $video->duration_label }}
                            </span>
                        @endif

                        {{-- 状態バッジ（左上） --}}
                        <span class="absolute left-1.5 top-1.5">
                            <x-status-badge :status="$video->status" />
                        </span>
                    </div>

                    <div class="p-3">
                        <h2 class="line-clamp-2 text-sm font-semibold text-gray-900 group-hover:text-black">
                            {{ $video->title ?? '(タイトル取得中…)' }}
                        </h2>

                        <p class="mt-1 truncate text-xs text-gray-500">
                            {{ $video->channel_name ?? '—' }}
                            @if ($video->published_at)
                                ・{{ $video->published_at->diffForHumans() }}
                            @endif
                        </p>

                        {{-- タグ（最大3個 + 残数）。リンクより手前（z-20）に置き、
                             クリックでそのタグの絞り込みに切り替える。 --}}
                        @if ($video->tags->isNotEmpty())
                            <div class="relative z-20 mt-2 flex flex-wrap gap-1">
                                @foreach ($video->tags->take(3) as $t)
                                    <button
                                        type="button"
                                        wire:click="filterByTag(@js($t->name))"
                                        class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600 hover:bg-gray-200"
                                    >#{{ $t->name }}</button>
                                @endforeach
                                @if ($video->tags->count() > 3)
                                    <span class="px-1 py-0.5 text-[11px] text-gray-400">+{{ $video->tags->count() - 3 }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ページネーション。?page= は WithPagination が URL に載せる。 --}}
        <div class="mt-8">
            {{ $videos->links() }}
        </div>
    @endif
</div>
