<?php

namespace App\Enums;

/**
 * 要約 1 件そのものの状態。
 *
 * videos.status（取り込み全体の状態）とは別物。
 * 画面は基本 videos.status を見て、要約セクションの細かい出し分けだけ
 * この値を参照する（docs/db_design.md §2.3 / §4.2 の注記）。
 *
 * DB では summaries.status カラム（varchar）に文字列で保存し、
 * Summary モデルの $casts でこの enum に変換する。
 */
enum SummaryStatus: string
{
    /** 要約待ち（summaries 行を作った直後。まだ生成を始めていない）。 */
    case Pending = 'pending';

    /** Claude API で生成中。 */
    case Processing = 'processing';

    /** 生成完了。content が入っている。 */
    case Completed = 'completed';

    /** 生成に失敗した。error_message に理由が入る。 */
    case Failed = 'failed';

    /**
     * 画面表示用の日本語ラベル。
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => '要約待ち',
            self::Processing => '要約中',
            self::Completed => '完了',
            self::Failed => '失敗',
        };
    }
}
