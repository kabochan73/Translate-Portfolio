<?php

namespace App\Actions;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchTranscript;
use App\Jobs\FetchVideoMetadata;
use App\Models\Video;
use Illuminate\Support\Facades\Bus;

/**
 * 1 本の動画について「取り込みパイプラインを（再）スタートする」処理。
 *
 * やることは 2 つだけ:
 *   1. videos の状態を pending に戻し、前回の失敗情報を消す
 *   2. ジョブチェーン（メタデータ取得 → 字幕取得）をキューに投入する
 *
 * この 2 つは «新規登録直後» と «詳細ページの「再試行」ボタン» の
 * 両方で必要になる。同じロジックを 2 か所に書くと片方だけ直し忘れる事故が
 * 起きるので、1 つの呼び出し可能クラス（__invoke）に集約している。
 *
 * 使い方:
 *   app(StartVideoIngestion::class)($video);
 *   // または DI で受け取って $this->startIngestion($video);
 */
class StartVideoIngestion
{
    /**
     * @param  Video  $video  取り込み対象。すでに DB に保存済みであること
     */
    public function __invoke(Video $video): void
    {
        // --- 1. 状態をリセット ---------------------------------------------
        // 再試行のときは status=failed / no_transcript や failed_step が
        // 残っているので、まっさらな pending に戻す。
        // 新規登録直後は既に pending だが、二度書いても実害はない。
        $video->update([
            'status' => ProcessingStatus::Pending,
            'failed_step' => null,
            'failed_reason' => null,
        ]);

        // --- 2. ジョブチェーンを投入 -------------------------------------
        // GenerateSummary はここに入れない。字幕が取れたときだけ
        // FetchTranscript が自分で投げる（字幕なしはチェーンがそこで終わる）。
        Bus::chain([
            new FetchVideoMetadata($video),
            new FetchTranscript($video),
        ])->dispatch();
    }
}
