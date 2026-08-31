@props(['status'])

{{--
    取り込み状態（ProcessingStatus）を色付きの小さなバッジで表示する。
    一覧カードと詳細ページの両方で使うので匿名コンポーネントに切り出した。

    使い方: <x-status-badge :status="$video->status" />
--}}

@php
    // enum の値ごとに Tailwind の配色クラスを決める。
    // 進行中＝青系、完了＝緑系、字幕なし＝グレー、失敗＝赤。
    $classes = match ($status) {
        \App\Enums\ProcessingStatus::Completed => 'bg-emerald-100 text-emerald-800',
        \App\Enums\ProcessingStatus::Failed => 'bg-red-100 text-red-800',
        \App\Enums\ProcessingStatus::NoTranscript => 'bg-gray-200 text-gray-700',
        default => 'bg-blue-100 text-blue-800', // pending / fetching_* / summarizing
    };
@endphp

<span {{ $attributes->class(["inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium", $classes]) }}>
    {{ $status->label() }}
</span>
