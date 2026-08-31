<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * transcripts テーブル。
 *
 * 「1 動画につき字幕は 0 個か 1 個」。字幕が取れた動画にだけ 1 行作る
 * （FetchTranscript ジョブが取得できたときだけ insert する）。
 * video_id に unique を張って 1 対 1 を DB レベルで保証する。
 * （詳細は docs/db_design.md §2.2）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcripts', function (Blueprint $table) {
            $table->id();

            // 親の videos への外部キー。
            // ・unique  … 1 動画 1 字幕を保証
            // ・cascadeOnDelete … videos の行を消したら、この字幕も自動で消える
            $table->foreignId('video_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // 実際に取得できた字幕の言語コード（希望言語と違うこともある）。
            $table->string('language', 16);

            // 全セグメントを半角スペースで連結した「素のテキスト」。
            // 字幕プレイヤーは作らないので画面には出さないが、
            // デバッグや将来の全文検索の余地として保持する。
            $table->text('content');

            // [{ "start": 0.0, "end": 4.2, "text": "..." }, ...] の配列。
            // 要約のチャプター見出しに [MM:SS] を付けるために使う。
            // json ではなく jsonb（将来の部分参照に備える）。表示はしない。
            $table->jsonb('segments')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcripts');
    }
};
