<div class="min-w-0 flex-1">
    {{-- wire:submit で通常のフォーム送信をジャックし、submit() を呼ぶ。
         .prevent は Livewire 3 では自動なので不要。 --}}
    <form wire:submit="submit" class="flex items-center gap-2">
        <input
            type="url"
            wire:model="url"
            placeholder="YouTube の動画 URL を貼り付け"
            class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm
                   focus:border-gray-900 focus:outline-none"
            {{-- 送信中は二重送信防止のため無効化 --}}
            wire:dirty.class="border-gray-900"
        >

        <button
            type="submit"
            class="shrink-0 rounded-lg bg-gray-900 px-4 py-1.5 text-sm font-medium text-white
                   hover:bg-gray-700 disabled:opacity-50"
            {{-- submit アクション実行中はボタンを無効化してスピナー的な文言に --}}
            wire:loading.attr="disabled"
            wire:target="submit"
        >
            <span wire:loading.remove wire:target="submit">登録</span>
            <span wire:loading wire:target="submit">送信中…</span>
        </button>
    </form>

    {{-- バリデーションエラー（url フィールド）だけ小さく表示。 --}}
    @error('url')
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
