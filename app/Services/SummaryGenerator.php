<?php

namespace App\Services;

/**
 * 字幕から日本語要約を組み立てる。
 *
 * translate2 版との違い（今回の目玉機能）:
 * ・入力を「連結済みテキスト」ではなく segments（時刻付き）で受け取る
 * ・本文に [M:SS] の時刻マーカーを埋め込んでから要約させる
 * ・出力のチャプター見出しに時刻を付けさせる（### [M:SS] 見出し）
 *
 * 長い字幕は分割して部分要約 → 統合する（map-reduce）。
 * AnthropicService を使い、usage（トークン数）を合算して返す。
 */
class SummaryGenerator
{
    /** プロンプトを変えたら上げる。時刻付け対応で v1 → v2、TL;DR 見出し廃止で v2 → v3。 */
    public const PROMPT_VERSION = 'v3';

    /** 何秒ごとに [M:SS] マーカーを本文へ挿入するか。 */
    private const MARKER_INTERVAL_SECONDS = 15;

    /** 1 チャンクの目安トークン数。 */
    private const CHUNK_TARGET_TOKENS = 6000;

    private const SYSTEM = <<<'TXT'
        あなたは動画の字幕から日本語の要約を作る編集者です。
        字幕の言語が何であっても、出力は必ず日本語にします。
        事実に忠実に、憶測を避け、簡潔にまとめます。
        字幕本文には [M:SS] または [H:MM:SS] 形式の時刻マーカーが埋め込まれています。
        これはその箇所がおよそ何分何秒の発話かを表します。
        TXT;

    private const FORMAT = <<<'TXT'

        次の Markdown 構成で出力してください:

        （最初に見出しを付けず、3〜4 文で全体の要点を書く）

        ## キーポイント
        - （箇条書きで 5〜8 項目）

        ## チャプター別の要約
        話の流れに沿って 3〜6 個のチャプターに分けます。
        各チャプターの見出しは、その話題が始まる時刻を使って
        「### [M:SS] 見出し」の形式にします（時刻は本文中の [M:SS] マーカーを参考に）。
        見出しの下に、その区間の内容を短い段落で書きます。
        TXT;

    public function __construct(private readonly AnthropicService $anthropic) {}

    /**
     * @param  array<int, array{start: float|int, end?: float|int, text: string}>  $segments
     * @return array{content: string, input_tokens: int, output_tokens: int, prompt_version: string}
     */
    public function generate(array $segments): array
    {
        // 1. segments を「[M:SS] 付きの 1 本のテキスト」にする
        $marked = $this->buildMarkedText($segments);

        // 2. 長ければトークン数で分割
        $chunks = $this->chunk($marked);

        $inputTokens = 0;
        $outputTokens = 0;

        // 3-a. 1 チャンクで収まる → そのまま最終フォーマットで要約
        if (count($chunks) === 1) {
            $final = $this->anthropic->complete(
                self::SYSTEM.self::FORMAT,
                "次の字幕を要約してください:\n\n".$chunks[0],
            );

            return $this->result(
                $final['content'],
                $inputTokens + $final['input_tokens'],
                $outputTokens + $final['output_tokens'],
            );
        }

        // 3-b. 複数チャンク → map（部分要点。時刻を残す）→ reduce（統合）

        // map: 各チャンクの要点を、時刻付きの箇条書きで抽出
        $partials = [];
        foreach ($chunks as $i => $chunk) {
            $part = $this->anthropic->complete(
                self::SYSTEM,
                sprintf(
                    "次は長い動画の字幕の一部（%d/%d）です。".
                    "この部分の要点を、各項目の先頭に対応する時刻 [M:SS] を付けた".
                    "日本語の箇条書きで簡潔に抽出してください:\n\n%s",
                    $i + 1,
                    count($chunks),
                    $chunk,
                ),
                maxTokens: 1500,
            );
            $partials[] = $part['content'];
            $inputTokens += $part['input_tokens'];
            $outputTokens += $part['output_tokens'];
        }

        // reduce: 時刻付きの部分要点をまとめて最終フォーマットへ
        $final = $this->anthropic->complete(
            self::SYSTEM.self::FORMAT,
            "次は 1 本の動画を分割して抽出した各部分の要点（時刻付き）です。".
            "全体を通した要約にまとめてください:\n\n".implode("\n\n---\n\n", $partials),
        );

        return $this->result(
            $final['content'],
            $inputTokens + $final['input_tokens'],
            $outputTokens + $final['output_tokens'],
        );
    }

    /**
     * segments を、約 15 秒ごとに [M:SS] マーカーを挟んだ 1 本のテキストにする。
     *
     * 自動生成字幕は文の区切りが無いことが多いので、マーカーが
     * 「だいたいこの辺の発話」という時刻の手がかりになる。
     *
     * @param  array<int, array{start: float|int, end?: float|int, text: string}>  $segments
     */
    private function buildMarkedText(array $segments): string
    {
        $parts = [];
        $nextMarkerAt = 0.0; // この秒数を超えたら次のマーカーを打つ

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $start = (float) ($segment['start'] ?? 0);

            if ($start >= $nextMarkerAt) {
                $parts[] = '['.$this->formatTimestamp($start).']';
                // 次は start を 15 秒単位で切り上げた位置
                $nextMarkerAt = (floor($start / self::MARKER_INTERVAL_SECONDS) + 1)
                    * self::MARKER_INTERVAL_SECONDS;
            }

            $parts[] = $text;
        }

        return implode(' ', $parts);
    }

    /**
     * 秒数を [M:SS]（1 時間以上なら [H:MM:SS]）の文字列にする。
     */
    private function formatTimestamp(float $seconds): string
    {
        $total = (int) round($seconds);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    /**
     * @return array{content: string, input_tokens: int, output_tokens: int, prompt_version: string}
     */
    private function result(string $content, int $inputTokens, int $outputTokens): array
    {
        return [
            'content' => $content,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'prompt_version' => self::PROMPT_VERSION,
        ];
    }

    /**
     * 本文をおおよそ CHUNK_TARGET_TOKENS 以下のチャンクに分ける。
     *
     * 文の区切り記号に頼れないので単語境界で詰めていく。
     * [M:SS] マーカーも 1 単語として扱われ、直後の本文にくっついて残る。
     * トークン数は「1 トークン ≒ 3 文字」で概算（小さめに見積もって安全側）。
     *
     * @return list<string>
     */
    private function chunk(string $text): array
    {
        $text = trim($text);

        if ($this->estimateTokens($text) <= self::CHUNK_TARGET_TOKENS) {
            return [$text];
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $chunks = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($current !== '' && $this->estimateTokens($candidate) > self::CHUNK_TARGET_TOKENS) {
                $chunks[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3);
    }
}
