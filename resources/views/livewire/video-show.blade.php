@php
    use App\Enums\ProcessingStatus;

    // 進捗ステッパーの 4 段。ラベルと「この段に対応する step 番号」。
    $steps = [
        1 => '待機中',
        2 => '情報取得',
        3 => '字幕取得',
        4 => '要約',
    ];
    $currentStep = $video->status->step();
    $isTerminal = $video->status->isTerminal();
@endphp

{{-- 取り込みが進行中の間だけ 3 秒ごとにサーバーへ問い合わせて再描画する。
     終了状態になると @if が false になり wire:poll ごと DOM から消える。 --}}
<div @unless ($isTerminal) wire:poll.3s @endunless>

    <div class="mt-2 grid grid-cols-1 gap-4 lg:grid-cols-2">

        {{-- ============ 左カラム：動画とメタ情報 ============ --}}
        <div class="lg:sticky lg:top-20 lg:self-start">
            <h1 class="text-lg font-bold text-gray-900">
                {{ $video->title ?? '(タイトル取得中…)' }}
            </h1>
            <p class="mt-1 text-sm text-gray-500">{{ $video->channel_name ?? '—' }}</p>

            {{-- 状態バッジ + 操作ボタン --}}
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-status-badge :status="$video->status" />

                @if ($isTerminal)
                    <button type="button" wire:click="retry" wire:loading.attr="disabled" wire:target="retry"
                        class="rounded-lg border border-gray-300 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">再試行</button>
                @endif

                <button type="button" wire:click="delete" {{-- ブラウザ標準の確認ダイアログ。OK を押さないと delete() は呼ばれない。 --}}
                    wire:confirm="この動画を削除しますか？ 字幕・要約もまとめて削除されます。"
                    class="rounded-lg border border-red-200 px-3 py-1 text-xs font-medium text-red-600 hover:bg-red-50">削除</button>
            </div>

            {{-- 失敗理由（status=failed のときだけ） --}}
            @if ($video->status === ProcessingStatus::Failed && $video->failed_reason)
                <div class="mt-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800">
                    <span class="font-semibold">失敗（{{ $video->failed_step }}）:</span>
                    {{ $video->failed_reason }}
                </div>
            @endif

            {{-- タグ --}}
            @if ($video->tags->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-1">
                    @foreach ($video->tags as $t)
                        <a href="{{ route('videos.index', ['tag' => $t->name]) }}" wire:navigate
                            class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600 hover:bg-gray-200">#{{ $t->name }}</a>
                    @endforeach
                </div>
            @endif

            {{-- メタ情報 --}}
            <dl class="mt-4 space-y-1 text-xs text-gray-500">
                @if ($video->duration_label)
                    <div class="flex gap-2">
                        <dt class="w-16 shrink-0">再生時間</dt>
                        <dd>{{ $video->duration_label }}</dd>
                    </div>
                @endif
                @if ($video->published_at)
                    <div class="flex gap-2">
                        <dt class="w-16 shrink-0">公開日</dt>
                        <dd>{{ $video->published_at->isoFormat('LL') }}</dd>
                    </div>
                @endif
                <div class="flex gap-2">
                    <dt class="w-16 shrink-0">元 URL</dt>
                    <dd class="min-w-0 truncate">
                        <a href="{{ $video->url }}" target="_blank" rel="noopener"
                            class="text-blue-600 hover:underline">{{ $video->url }}</a>
                    </dd>
                </div>
            </dl>

            {{-- YouTube 埋め込み。nocookie ドメインでトラッキングを抑える。
                 動画情報の «下» に置く（この順で見せたいという指定）。 --}}
            <div class="mt-4 aspect-video overflow-hidden rounded-xl bg-black">
                <iframe class="h-full w-full" src="https://www.youtube-nocookie.com/embed/{{ $video->youtube_id }}"
                    title="{{ $video->title }}" frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen></iframe>
            </div>
        </div>

        {{-- ============ 右カラム：進捗 or 要約 ============ --}}
        <div>

            @if (!$isTerminal)
                {{-- --- 進行中：進捗ステッパー --- --}}
                <ol class="mt-2 space-y-3">
                    @foreach ($steps as $num => $label)
                        <li class="flex items-center gap-3">
                            <span @class([
                                'flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                'bg-emerald-600 text-white' => $num < $currentStep,
                                'bg-blue-600 text-white animate-pulse' => $num === $currentStep,
                                'bg-gray-200 text-gray-400' => $num > $currentStep,
                            ])>
                                {{ $num < $currentStep ? '✓' : $num }}
                            </span>
                            <span @class([
                                'text-sm',
                                'text-gray-900 font-medium' => $num === $currentStep,
                                'text-gray-500' => $num !== $currentStep,
                            ])>{{ $label }}</span>
                        </li>
                    @endforeach
                </ol>
                <p class="mt-4 text-xs text-gray-400">
                    処理が進むとこの画面は自動で更新されます（{{ $video->status->label() }}）。
                </p>
            @elseif ($video->status === ProcessingStatus::NoTranscript)
                {{-- --- 字幕なしで正常終了 --- --}}
                <div class="mt-4 rounded-lg bg-gray-50 px-4 py-6 text-sm text-gray-600">
                    この動画には字幕が見つからなかったため、要約は生成していません。
                </div>
            @elseif ($video->status === ProcessingStatus::Failed)
                {{-- --- 失敗 --- --}}
                <div class="mt-4 rounded-lg bg-red-50 px-4 py-6 text-sm text-red-800">
                    取り込みに失敗しました。「再試行」ボタンからやり直せます。
                </div>
            @elseif ($video->summary?->status === \App\Enums\SummaryStatus::Completed)
                {{-- --- 要約完成 --- --}}
                <div class="summary-body">
                    {!! str($video->summary->content)->markdown([
                        'html_input' => 'strip',
                        'allow_unsafe_links' => false,
                    ]) !!}
                </div>

                {{-- 生成メタ（モデル・トークン・概算コスト） --}}
                <p class="mt-2 pt-2 text-[11px] text-gray-600">
                    {{ $video->summary->model }}
                    ／ 入力 {{ number_format($video->summary->input_tokens) }} tok
                    ・出力 {{ number_format($video->summary->output_tokens) }} tok
                    @if ($video->summary->cost_usd !== null)
                        ／ 概算 ${{ $video->summary->cost_usd }}
                    @endif
                    ／ プロンプト {{ $video->summary->prompt_version }}
                </p>
            @else
                {{-- completed だが要約が無い等の想定外。念のため。 --}}
                <p class="mt-4 text-sm text-gray-500">要約は利用できません。</p>
            @endif
        </div>
    </div>
</div>
