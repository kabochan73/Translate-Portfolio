<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * タグ。YouTube のメタデータから拾い、一覧ページの検索・絞り込みに使う。
 * name は unique（同名タグは 1 つだけ）。
 */
class Tag extends Model
{
    protected $fillable = [
        'name',
    ];

    /**
     * このタグが付いている動画。中間テーブルは tag_video。
     */
    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class);
    }
}
