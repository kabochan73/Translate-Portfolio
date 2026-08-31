<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1 動画の字幕。字幕が取れた動画にだけ 1 行ある（video_id は unique）。
 */
class Transcript extends Model
{
    use HasFactory;

    protected $fillable = [
        'video_id',
        'language',
        'content',
        'segments',
    ];

    protected function casts(): array
    {
        return [
            // jsonb カラム ⇄ PHP 配列。$transcript->segments を配列として扱える。
            // 要約ジョブがこの配列を読んで [MM:SS] マーカーを組み立てる。
            'segments' => 'array',
        ];
    }

    /**
     * この字幕が属する動画。
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
