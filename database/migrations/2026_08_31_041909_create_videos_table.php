<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * videos テーブル。
 *
 * このアプリの中心となるテーブル。「1 行 = 登録された 1 動画」。
 * URL が登録されると status=pending の行がまず作られ、ジョブが順に
 * メタデータ → 字幕 → 要約 と処理しながら status を更新していく。
 * （状態遷移の詳細は docs/db_design.md §4.1）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();

            // YouTube 動画 ID（11 桁）。同じ動画を二重登録しないための一意キー。
            // URL そのものではなくこの ID で重複判定する（URL は末尾パラメータ等で揺れるため）。
            $table->string('youtube_id')->unique();

            // ユーザーが実際に貼り付けた元の URL。表示・デバッグ用に保持する。
            $table->string('url', 2048);

            // ここから下はメタデータ取得（FetchVideoMetadata ジョブ）が走るまで null。
            $table->string('title')->nullable();
            $table->string('channel_name')->nullable();
            $table->string('thumbnail_url', 2048)->nullable();

            // 再生時間（秒）。負の値はあり得ないので unsigned。
            $table->unsignedInteger('duration_seconds')->nullable();

            // 動画の公開日時。タイムゾーン付きで保存（timestamptz）。
            $table->timestampTz('published_at')->nullable();

            // 音声の言語コード（例 en / ja / en-US）。字幕を取るときの優先言語に使う。
            $table->string('source_language', 16)->nullable();

            // 取り込み全体の状態。App\Enums\ProcessingStatus の値が入る。
            // 文字列カラム + デフォルト 'pending'。enum への変換はモデルの cast で行う。
            $table->string('status', 32)->default('pending');

            // 失敗したときだけ埋まる。どの工程で・なぜ失敗したか。
            // failed_step: 'metadata' / 'transcript' / 'summary'
            $table->string('failed_step', 32)->nullable();
            $table->text('failed_reason')->nullable();

            // created_at / updated_at をタイムゾーン付きで作成。
            $table->timestampsTz();

            // 一覧画面のフィルタ（status）と並び替え・ページネーション（created_at）用。
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
