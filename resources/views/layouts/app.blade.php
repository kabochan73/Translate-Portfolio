<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Livewire のフォーム送信で CSRF トークンが要るので meta で埋め込む --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- フルページ Livewire コンポーネントが #[Title] を付ければ $title に入る。
         無ければアプリ名。 --}}
    <title>{{ $title ?? config('app.name') }}</title>

    {{-- Vite 経由で Tailwind(CSS) と app.js を読み込む。開発時は npm run dev、
         本番相当は npm run build が要る。 --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-white text-gray-900 antialiased">
    {{-- どのページでも共通のヘッダー。スクロールしても上に貼り付く。 --}}
    <header class="sticky top-0 z-10 border-b border-gray-200 bg-zinc-50 backdrop-blur">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
            <a href="{{ route('videos.index') }}" class="shrink-0 text-lg font-semibold">
                {{ config('app.name') }}
            </a>

            {{-- どのページからでも URL を貼れる登録フォーム（ヘッダー常駐）。 --}}
            <livewire:submit-video />
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        {{-- フラッシュメッセージ（「登録しました」「削除しました」など）。
             redirect()->with('status', ...) や session()->flash('status', ...) で出す。 --}}
        @if (session('status'))
            <div class="mb-6 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        {{-- 各ページ（VideoIndex / VideoShow）の render() 結果がここに入る。 --}}
        {{ $slot }}
    </main>
</body>

</html>
