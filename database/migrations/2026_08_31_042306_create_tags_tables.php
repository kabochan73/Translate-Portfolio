<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tags テーブルと、videos との中間テーブル tag_video を作る。
 *
 * タグは YouTube のメタデータから拾う（FetchVideoMetadata ジョブが
 * Tag::firstOrCreate → tags()->sync で紐付ける）。
 * 一覧ページの検索・絞り込みで使う。
 * （詳細は docs/db_design.md §2.4 / §2.5）
 *
 * 2 テーブルを 1 ファイルにまとめる理由: tag_video は tags に依存するので
 * 必ずセットで作成・削除したい。分けると順序管理が煩雑になるだけ。
 */
return new class extends Migration
{
    public function up(): void
    {
        // タグ本体。name は一意。同じ名前のタグは 1 つだけ。
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestampsTz();
        });

        // videos ⇔ tags の多対多をつなぐ中間テーブル。
        // Laravel 命名規約: 関連する 2 モデル名の単数形をアルファベット順に並べる → tag_video
        Schema::create('tag_video', function (Blueprint $table) {
            // どちらも FK + cascade。親（tag / video）が消えたら紐付けも消える。
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();

            // (tag_id, video_id) の複合主キー。
            // 同じ動画に同じタグを二重に付けられないようにする。
            $table->primary(['tag_id', 'video_id']);

            // 中間テーブルなので created_at / updated_at は持たない
            // （いつ紐付けたかを知る必要がない）。
        });
    }

    public function down(): void
    {
        // 依存の逆順で落とす（子 tag_video → 親 tags）。
        Schema::dropIfExists('tag_video');
        Schema::dropIfExists('tags');
    }
};
