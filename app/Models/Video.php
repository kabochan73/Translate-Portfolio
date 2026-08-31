<?php

namespace App\Models;

use App\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 登録された 1 動画を表すモデル。このアプリの中心。
 *
 * URL が登録されると status=pending でこの行が作られ、
 * ジョブチェーン（FetchVideoMetadata → FetchTranscript → GenerateSummary）が
 * メタデータ・字幕・要約を埋めながら status を進めていく。
 */
class Video extends Model
{
    /**
     * 一括代入（create / update に配列で渡す）を許可するカラム。
     *
     * id と timestamps は Laravel が管理するので入れない。
     */
    protected $fillable = [
        'youtube_id',
        'url',
        'title',
        'channel_name',
        'thumbnail_url',
        'duration_seconds',
        'published_at',
        'source_language',
        'status',
        'failed_step',
        'failed_reason',
    ];

    /**
     * 新規インスタンスの初期値。
     *
     * DB カラムにも default 'pending' があるが、それは INSERT 後に効くもの。
     * Video::create() 直後の（まだ refresh していない）インスタンスでも
     * $video->status を non-null にするためここでも既定値を持たせる。
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * DB の値 ⇄ PHP の型 の変換ルール。
     *
     * - status は文字列カラムだが、読み書きは ProcessingStatus enum で行える。
     * - published_at は Carbon 日時オブジェクトになる。
     */
    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'published_at' => 'datetime',
            'status' => ProcessingStatus::class,
        ];
    }

    /**
     * サムネイルに重ねる再生時間表示（"4:13" / "1:02:33"）。未取得なら null。
     *
     * $video->duration_label でアクセスできる（アクセサ）。
     */
    protected function durationLabel(): Attribute
    {
        return Attribute::get(function (): ?string {
            $seconds = $this->duration_seconds;

            if ($seconds === null) {
                return null;
            }

            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            $s = $seconds % 60;

            return $h > 0
                ? sprintf('%d:%02d:%02d', $h, $m, $s)
                : sprintf('%d:%02d', $m, $s);
        });
    }

    /**
     * この動画の字幕（0 or 1 件）。video_id が unique なので hasOne。
     */
    public function transcript(): HasOne
    {
        return $this->hasOne(Transcript::class);
    }

    /**
     * この動画の要約（0 or 1 件）。
     */
    public function summary(): HasOne
    {
        return $this->hasOne(Summary::class);
    }

    /**
     * この動画に付いたタグ（0 件以上）。中間テーブルは tag_video。
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
