<?php

namespace App\Services;

use MrMySQL\YoutubeTranscript\Exception\NoTranscriptAvailableException;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\Transcript;
use MrMySQL\YoutubeTranscript\TranscriptList;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;

/**
 * YouTube の字幕取得ライブラリ（mrmysql/youtube-transcript）のラッパー。
 *
 * このライブラリは YouTube の内部エンドポイントを叩いて字幕を取る。
 * 自動生成字幕（人手でない字幕）も取得できる。
 * 呼び出し元は取り込みジョブ FetchTranscript。
 *
 * TranscriptListFetcher の生成（Guzzle + PSR-17）は AppServiceProvider で行う。
 */
class TranscriptService
{
    public function __construct(private readonly TranscriptListFetcher $fetcher) {}

    /**
     * 字幕を取得する。
     *
     * @return array{
     *     language: string,
     *     content: string,
     *     segments: array<int, array{start: float, end: float, text: string}>,
     * }|null  字幕が無い動画は null（正常系。要約はスキップして no_transcript で終わる）
     *
     * 一時的な失敗（レート制限・IP ブロック・HTTP エラー等）は例外のまま投げる。
     * → ジョブがリトライし、3 回失敗したら failed。
     */
    public function fetch(string $videoId, ?string $preferredLanguage = null): ?array
    {
        try {
            $list = $this->fetcher->fetch($videoId);
            $transcript = $this->pickTranscript($list, $preferredLanguage);
            $entries = $transcript->fetch();
        } catch (NoTranscriptFoundException|NoTranscriptAvailableException|TranscriptsDisabledException) {
            // これらは「字幕が無い／無効化されている」＝異常ではない。null を返す。
            return null;
        }

        // ライブラリの返り値は [{text, start, duration}, ...]。
        // アプリで使う形（start/end/text）に整える。
        $segments = array_map(fn (array $entry): array => [
            'start' => (float) $entry['start'],
            'end' => (float) $entry['start'] + (float) $entry['duration'],
            // 返り値に &#39; &quot; などの HTML 実体参照が残るので元の文字へ戻す。
            'text' => html_entity_decode($entry['text'], ENT_QUOTES | ENT_HTML5),
        ], $entries);

        // 字幕トラックはあるが中身が空、というケースも null 扱い。
        if ($segments === []) {
            return null;
        }

        return [
            'language' => $transcript->language_code,
            // content は全 text を半角スペースで連結した素のテキスト（検索・保存用）。
            'content' => implode(' ', array_column($segments, 'text')),
            'segments' => $segments,
        ];
    }

    /**
     * 字幕トラックを選ぶ。
     *
     * 優先言語 → そのベース言語（en-US → en）→ 利用可能な任意、の順。
     * source_language（動画の音声言語）を優先言語として渡す想定。
     */
    private function pickTranscript(TranscriptList $list, ?string $preferredLanguage): Transcript
    {
        if ($preferredLanguage !== null && $preferredLanguage !== '') {
            $codes = array_values(array_unique([
                $preferredLanguage,
                explode('-', $preferredLanguage)[0],
            ]));

            try {
                return $list->findTranscript($codes);
            } catch (NoTranscriptFoundException) {
                // 優先言語の字幕が無ければ下のフォールバックへ。
            }
        }

        // 何でもいいので利用可能な字幕を1つ。
        return $list->findTranscript($list->getAvailableLanguageCodes());
    }
}
