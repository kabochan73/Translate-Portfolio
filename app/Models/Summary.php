<?php

namespace App\Models;

use App\Enums\SummaryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1 動画の要約。字幕が取れた動画にだけ作る（no_transcript には作らない）。
 * 要約本文（Markdown）と、その生成メタ（モデル・トークン数・概算コスト）を持つ。
 */
class Summary extends Model
{
    protected $fillable = [
        'video_id',
        'status',
        'language',
        'content',
        'model',
        'prompt_version',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SummaryStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            // 小数第 6 位まで保持する文字列として扱う（浮動小数の誤差を避ける）。
            'cost_usd' => 'decimal:6',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * この要約が属する動画。
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
