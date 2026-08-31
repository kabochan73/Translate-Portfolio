<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude Messages API の薄い HTTP ラッパー。
 *
 * 公式 SDK ではなく Laravel の Http:: を使う。理由:
 * ・YouTubeService と実装スタイルを揃えられる
 * ・テストで Http::fake() を使ってモックできる（実 API を叩かない）
 *
 * 呼び出し元は SummaryGenerator。
 * 依存（キー・モデル・ワークスペース ID）は AppServiceProvider でバインドする。
 */
class AnthropicService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** Messages API のバージョンヘッダ（日付固定。現行）。 */
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly ?string $workspaceId = null,
    ) {}

    /**
     * 単発（1 ターン）のメッセージを送り、本文と使用トークン数を返す。
     *
     * system は map-reduce の各呼び出しで共通なので、prompt caching を
     * 効かせて 2 回目以降の入力コストを下げる（cache_control: ephemeral）。
     *
     * @return array{content: string, input_tokens: int, output_tokens: int}
     *
     * @throws RuntimeException API がテキストを返さなかった場合
     */
    public function complete(string $system, string $user, int $maxTokens = 4096): array
    {
        $response = $this->request()
            ->post(self::API_URL, [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                // 要約は素直なタスクなので拡張思考は使わない（コスト減・レスポンスも単純）。
                'thinking' => ['type' => 'disabled'],
                'system' => [[
                    'type' => 'text',
                    'text' => $system,
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
                'messages' => [
                    ['role' => 'user', 'content' => $user],
                ],
            ])
            ->throw();

        // content は複数ブロックになりうる（thinking 等）ので text ブロックを探す。
        $text = collect($response->json('content', []))->firstWhere('type', 'text')['text'] ?? null;

        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Anthropic API がテキストを返しませんでした。');
        }

        return [
            'content' => $text,
            // 概算コスト用に、キャッシュ読み書き分も足した入力トークン合算。
            'input_tokens' => (int) $response->json('usage.input_tokens', 0)
                + (int) $response->json('usage.cache_read_input_tokens', 0)
                + (int) $response->json('usage.cache_creation_input_tokens', 0),
            'output_tokens' => (int) $response->json('usage.output_tokens', 0),
        ];
    }

    /** 使用中のモデル ID。 */
    public function model(): string
    {
        return $this->model;
    }

    /** HTTP クライアント側でリトライする一時的エラーのステータスコード。 */
    private const RETRYABLE_STATUS = [
        429, // レート制限
        500, // Anthropic 側の一時的な内部エラー
        503, // ゲートウェイが一時的に応答不可（実測で稀に発生）
        529, // 過負荷（overloaded_error）
    ];

    /**
     * 共通のリクエスト設定。
     *
     * 一時的なエラー（RETRYABLE_STATUS）は HTTP クライアント側でも 3 回リトライする。
     * throw: false なので、リトライ対象外のエラーは complete() 側の ->throw() で拾う。
     */
    private function request(): PendingRequest
    {
        return Http::withHeaders(array_filter([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            // 通常キーなら null。array_filter で null のヘッダは落ちる。
            'anthropic-workspace-id' => $this->workspaceId,
        ]))
            ->timeout(120)
            ->retry(3, 1000, function ($exception) {
                return in_array($exception->response?->status(), self::RETRYABLE_STATUS, true);
            }, throw: false);
    }
}
