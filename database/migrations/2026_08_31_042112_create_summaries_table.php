<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * summaries テーブル。
 *
 * 「字幕が取れた動画」につき 1 行。Claude での要約結果と、その実行メタ
 * （使ったモデル・トークン数・概算コスト）を保存する。
 * 字幕が無い動画（videos.status = no_transcript）には行を作らない。
 * （詳細は docs/db_design.md §2.3 / §4.2）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('summaries', function (Blueprint $table) {
            $table->id();

            // transcripts と同じく video_id に unique + cascade。1 動画 1 要約。
            $table->foreignId('video_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // 要約単体の状態。App\Enums\SummaryStatus の値。
            // videos.status とは別物（あちらは取り込み全体の状態）。
            $table->string('status', 16)->default('pending');

            // 要約の言語。今は日本語固定だが将来の拡張に備えてカラムは持つ。
            $table->string('language', 16)->default('ja');

            // 要約本文（Markdown）。「## TL;DR」「## キーポイント」
            // 「### [MM:SS] 見出し」を含む。完了するまでは null。
            $table->text('content')->nullable();

            // 実行メタ（デバッグ・表示用）。完了まで null。
            $table->string('model', 64)->nullable();          // 例: claude-sonnet-5
            $table->string('prompt_version', 16)->nullable(); // 時刻付け対応で 'v2'

            // API のトークン使用量。map-reduce（複数回呼び出し）のときは合算値。
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            // 概算コスト（USD）。config の単価 × トークン数で算出する目安値。
            // 課金の正確な額ではない。numeric(10,6) で 0.000001 ドル単位まで。
            $table->decimal('cost_usd', 10, 6)->nullable();

            // 失敗時のメッセージ（status = failed のとき埋まる）。
            $table->text('error_message')->nullable();

            // 要約が完了した時刻（status = completed のとき埋まる）。
            $table->timestampTz('completed_at')->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summaries');
    }
};
