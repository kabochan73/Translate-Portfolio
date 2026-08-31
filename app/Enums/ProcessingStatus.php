<?php

namespace App\Enums;

/**
 * 動画の取り込み（ingestion）全体の状態。
 *
 * URL 登録 → メタデータ取得 → 字幕取得 → 要約生成 という
 * ジョブチェーンの「今どこにいるか」を 1 つの値で表す。
 * DB では videos.status カラム（varchar）に文字列で保存し、
 * Video モデルの $casts でこの enum に変換する。
 *
 * 状態遷移の詳細は docs/db_design.md §4.1 を参照。
 */
enum ProcessingStatus: string
{
    /** 登録直後 / 再試行直後。まだ何も処理していない。 */
    case Pending = 'pending';

    /** YouTube Data API からメタデータを取得中。 */
    case FetchingMetadata = 'fetching_metadata';

    /** 字幕を取得中。 */
    case FetchingTranscript = 'fetching_transcript';

    /** Claude で要約を生成中。 */
    case Summarizing = 'summarizing';

    /** 全工程が完了した（要約あり）。終了状態。 */
    case Completed = 'completed';

    /** 字幕が見つからず要約をスキップして終了した（正常終了）。終了状態。 */
    case NoTranscript = 'no_transcript';

    /** いずれかの工程が 3 回試行しても失敗した。終了状態。 */
    case Failed = 'failed';

    /**
     * 進捗ステッパー用の表示ステップ番号（1〜4）。
     *
     * 終了状態（completed / no_transcript / failed）はすべて 4 を返す
     * （＝「最後まで進んだ」ことを表す）。
     */
    public function step(): int
    {
        return match ($this) {
            self::Pending => 1,
            self::FetchingMetadata => 2,
            self::FetchingTranscript => 3,
            self::Summarizing,
            self::Completed,
            self::NoTranscript,
            self::Failed => 4,
        };
    }

    /**
     * これ以上自動では進まない「終了状態」かどうか。
     *
     * 終了状態のときだけ、詳細ページの「再試行」ボタンを出す。
     * また、詳細ページの wire:poll もこの値が true になったら止める。
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::NoTranscript,
            self::Failed => true,
            default => false,
        };
    }

    /**
     * 画面表示用の日本語ラベル。
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => '待機中',
            self::FetchingMetadata => '情報取得中',
            self::FetchingTranscript => '字幕取得中',
            self::Summarizing => '要約中',
            self::Completed => '完了',
            self::NoTranscript => '字幕なし',
            self::Failed => '失敗',
        };
    }
}
