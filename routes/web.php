<?php

use App\Livewire\VideoIndex;
use App\Livewire\VideoShow;
use Illuminate\Support\Facades\Route;

// このアプリは認証なし・画面は一覧と詳細の2つだけ。
// ルートには Livewire コンポーネントのクラスを直接指定する（フルページコンポーネント）。

// ルートは一覧へ飛ばす。
Route::redirect('/', '/videos');

// 動画一覧。検索・タグ絞り込みはこのコンポーネント内で行う。
Route::get('/videos', VideoIndex::class)->name('videos.index');

// 動画詳細。{video} は Video モデルに解決される（ルートモデルバインディング）。
Route::get('/videos/{video}', VideoShow::class)->name('videos.show');
