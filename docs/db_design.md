# Translate-Portfolio データベース設計書

- 版: 1.0
- 作成日: 2026-08-31
- DBMS: PostgreSQL 16
- 関連: [design.md](./design.md)（※フェーズ6で作成）

> **認証について（2026-08-31 決定）**
> ログイン機能は実装しない。ローカル専用・シングルユーザーのため守るものが無く、
> デプロイもしないため。Laravel Breeze も入れない。
> `users` / `cache` / `jobs` の Laravel 標準マイグレーションはそのまま残す
> （消すと標準テストが壊れるだけで、実際には使わない）。
> 将来デプロイして認証が必要になったら、その時 Breeze を足す。

---

## 1. ER 概要

```
videos 1 ──── 0..1 transcripts
       1 ──── 0..1 summaries
       * ──── *    tags        （中間テーブル tag_video）
```

- `videos` が中心。1 動画につき字幕 0..1・要約 0..1・タグ 0..*。
- `transcripts` / `summaries` は `video_id` に **UNIQUE** を張り「1対1」を DB レベルで保証する。
- 削除は `videos` を消したら子（transcripts / summaries / tag_video）も消える（`ON DELETE CASCADE`）。
  アプリ側で個別に子を delete しない。

---

## 2. テーブル定義

### 2.1 `videos`

| カラム | 型 | 制約 / 既定 | 説明 |
|---|---|---|---|
| `id` | bigint | PK, auto increment | |
| `youtube_id` | varchar(255) | NOT NULL, **UNIQUE** | 11 桁の YouTube 動画 ID。重複登録の判定キー |
| `url` | varchar(2048) | NOT NULL | 登録された元 URL |
| `title` | varchar(255) | NULL | メタデータ取得前は NULL |
| `channel_name` | varchar(255) | NULL | 同上 |
| `thumbnail_url` | varchar(2048) | NULL | サムネイル画像 URL |
| `duration_seconds` | integer | NULL（CHECK >= 0 相当。unsignedInteger で作る） | 再生時間（秒） |
| `published_at` | timestamptz | NULL | 動画の公開日時 |
| `source_language` | varchar(16) | NULL | 音声言語コード（例 `en`, `ja`, `en-US`）。字幕取得時の優先言語に使う |
| `status` | varchar(32) | NOT NULL, DEFAULT `'pending'` | 取り込み状態（§4） |
| `failed_step` | varchar(32) | NULL | 失敗した工程 `metadata` / `transcript` / `summary` |
| `failed_reason` | text | NULL | 失敗理由（ユーザー表示向けに要約済み、最大 500 字目安） |
| `created_at` | timestamptz | NOT NULL | |
| `updated_at` | timestamptz | NOT NULL | |

**インデックス**

| 名前 | 対象 | 用途 |
|---|---|---|
| `videos_youtube_id_unique` | `youtube_id` | 重複チェック（自動） |
| `videos_status_index` | `status` | 一覧の状態フィルタ |
| `videos_created_at_index` | `created_at` | `latest()` 並び替え + ページネーション |

> `title` / `channel_name` の `LIKE '%...%'` 部分一致検索は前方一致でないため通常インデックスは効かない。
> 個人利用の件数（数百〜数千）ならフルスキャンで問題なし。全文検索インデックスは張らない（§6）。

---

### 2.2 `transcripts`

| カラム | 型 | 制約 / 既定 | 説明 |
|---|---|---|---|
| `id` | bigint | PK | |
| `video_id` | bigint | NOT NULL, **UNIQUE**, FK → `videos.id` ON DELETE CASCADE | 1 動画 1 字幕 |
| `language` | varchar(16) | NOT NULL | 取得できた字幕の言語コード |
| `content` | text | NOT NULL | 全セグメントを半角スペースで連結した素の本文。検索・保存用 |
| `segments` | jsonb | NULL | 下記 JSON 構造。**保存はするが画面表示はしない**。要約の時刻付け（`[MM:SS]`）に使う |
| `created_at` / `updated_at` | timestamptz | NOT NULL | |

**`segments` の構造**

```json
[
  { "start": 0.0, "end": 4.2, "text": "字幕1行目" },
  { "start": 4.2, "end": 9.1, "text": "字幕2行目" }
]
```

- `start` / `end` は秒（float）。`end = start + duration`。配列順 = 再生順。
- 型は `jsonb`（`json` ではなく）。将来の部分参照に備える。現状インデックスは不要。
- `FetchTranscript` ジョブが字幕ライブラリの返り値をそのまま入れる。
- `content` は `segments` の `text` を半角スペース連結したもの。字幕プレイヤーは作らないので
  画面には出さないが、素のテキストとして保持しておく（デバッグ・将来の全文検索余地）。

**インデックス**: `transcripts_video_id_unique`（自動）のみ。

---

### 2.3 `summaries`

| カラム | 型 | 制約 / 既定 | 説明 |
|---|---|---|---|
| `id` | bigint | PK | |
| `video_id` | bigint | NOT NULL, **UNIQUE**, FK → `videos.id` ON DELETE CASCADE | 1 動画 1 要約 |
| `status` | varchar(16) | NOT NULL, DEFAULT `'pending'` | `pending` / `processing` / `completed` / `failed`（§4.2） |
| `language` | varchar(16) | NOT NULL, DEFAULT `'ja'` | 要約の言語 |
| `content` | text | NULL | 要約本文（Markdown）。`## TL;DR` / `## キーポイント` / `### [MM:SS] 見出し` を含む。完了まで NULL |
| `model` | varchar(64) | NULL | 使用した Claude モデル ID（例 `claude-sonnet-5`） |
| `prompt_version` | varchar(16) | NULL | プロンプトの版。時刻付け対応で `v2` |
| `input_tokens` | integer | NULL | API 入力トークン数（map-reduce は合算） |
| `output_tokens` | integer | NULL | API 出力トークン数（合算） |
| `cost_usd` | numeric(10,6) | NULL | 概算コスト（USD）。`config('services.anthropic.*_cost_per_mtok')` で算出 |
| `error_message` | text | NULL | 失敗時のメッセージ |
| `completed_at` | timestamptz | NULL | 要約完了時刻 |
| `created_at` / `updated_at` | timestamptz | NOT NULL | |

**インデックス**: `summaries_video_id_unique`（自動）のみ。

> `summaries.status` は `videos.status` とは別物。
> `videos.status` が取り込み全体の状態、`summaries.status` が要約単体の状態。
> 画面表示は基本 `videos.status` を見て、要約セクションの細かい出し分けだけ `summaries.status` を参照する。

---

### 2.4 `tags`

| カラム | 型 | 制約 | 説明 |
|---|---|---|---|
| `id` | bigint | PK | |
| `name` | varchar(255) | NOT NULL, **UNIQUE** | タグ名。`firstOrCreate(['name' => ...])` で作る。出所は YouTube のタグ |
| `created_at` / `updated_at` | timestamptz | NOT NULL | |

**インデックス**: `tags_name_unique`（自動）。

---

### 2.5 `tag_video`（中間テーブル）

| カラム | 型 | 制約 |
|---|---|---|
| `tag_id` | bigint | NOT NULL, FK → `tags.id` ON DELETE CASCADE |
| `video_id` | bigint | NOT NULL, FK → `videos.id` ON DELETE CASCADE |

- **複合主キー** `PRIMARY KEY (tag_id, video_id)` — 同じ組み合わせの重複を防ぐ。
- `timestamps` は持たない。
- Laravel 命名規約どおり単数形アルファベット順で `tag_video`。

---

### 2.6 `users` / `cache` / `jobs`（Laravel 標準・未使用）

- `users` … 認証を実装しないのでレコード 0 件で運用。どの画面も認証を要求しない。
  マイグレーションは標準のまま残す（消すと標準テストが壊れるため）。
- `cache` … `CACHE_STORE=redis` なので未使用。
- `jobs` / `job_batches` / `failed_jobs` … キュー本体は Redis だが、
  **失敗ジョブの記録 `failed_jobs` だけは DB に残す**（Laravel 既定。フェーズ4 で確定）。
- `sessions` テーブルは作らない（`SESSION_DRIVER=redis`）。

---

## 3. マイグレーション順序

FK があるので順番が重要。

```
0001_..._create_users_table          （Laravel 標準。未使用だが残す）
0001_..._create_cache_table          （Laravel 標準。未使用）
0001_..._create_jobs_table           （Laravel 標準。failed_jobs のみ使う）
2026_..._create_videos_table
2026_..._create_transcripts_table    （videos に依存）
2026_..._create_summaries_table      （videos に依存）
2026_..._create_tags_table
2026_..._create_tag_video_table      （tags, videos に依存）
```

---

## 4. 状態機械

### 4.1 `videos.status`

値は `App\Enums\ProcessingStatus`（string backed enum）。

| 値 | 意味 | 遷移元 | 遷移先 |
|---|---|---|---|
| `pending` | 登録直後 / 再試行直後。まだ何も処理していない | （新規）, 再試行 | `fetching_metadata` |
| `fetching_metadata` | YouTube メタデータ取得中 | `pending` | `fetching_transcript`, `failed` |
| `fetching_transcript` | 字幕取得中 | `fetching_metadata` | `summarizing`, `no_transcript`, `failed` |
| `summarizing` | Claude で要約中 | `fetching_transcript` | `completed`, `failed` |
| `completed` | 全工程完了（要約あり） | `summarizing` | 再試行で `pending` |
| `no_transcript` | 字幕が無く要約はスキップ（正常終了） | `fetching_transcript` | 再試行で `pending` |
| `failed` | いずれかの工程で 3 回試行しても失敗 | 各処理中状態 | 再試行で `pending` |

```
pending
  → fetching_metadata
      → fetching_transcript
          → summarizing → completed
          → no_transcript
      （どの矢印でも失敗しうる）→ failed

completed / no_transcript / failed → (再試行) → pending
```

**表示用ステップ番号**（進捗ステッパー）

| status | step |
|---|---|
| `pending` | 1 |
| `fetching_metadata` | 2 |
| `fetching_transcript` | 3 |
| `summarizing` | 4 |
| `completed` / `no_transcript` / `failed` | 4（終了） |

**終了状態**（`isTerminal()` が true）: `completed` / `no_transcript` / `failed`。
これらのときだけ詳細画面の「再試行」ボタンを押せる。また `wire:poll` はこの状態で止まる。

### 4.2 `summaries.status`

値は `App\Enums\SummaryStatus`（string backed enum）。`ProcessingStatus` と扱いを揃えるため専用 enum にする。

| 値 | 意味 |
|---|---|
| `pending` | `summaries` 行を作った直後 |
| `processing` | Claude 呼び出し中 |
| `completed` | 要約完了。`content` / `completed_at` などが埋まる |
| `failed` | 要約失敗。`error_message` が埋まる |

---

## 5. Eloquent リレーション / キャスト

```php
// Video
public function transcript(): HasOne { return $this->hasOne(Transcript::class); }
public function summary(): HasOne     { return $this->hasOne(Summary::class); }
public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class); }

// Transcript / Summary
public function video(): BelongsTo    { return $this->belongsTo(Video::class); }

// Tag
public function videos(): BelongsToMany { return $this->belongsToMany(Video::class); }
```

**キャスト**

```php
// Video
'duration_seconds' => 'integer',
'published_at'     => 'datetime',
'status'           => ProcessingStatus::class,

// Transcript
'segments'         => 'array',       // jsonb ⇄ PHP array

// Summary
'status'           => SummaryStatus::class,
'input_tokens'     => 'integer',
'output_tokens'    => 'integer',
'cost_usd'         => 'decimal:6',
'completed_at'     => 'datetime',
```

`Video` には `durationLabel` アクセサ（`H:MM:SS` / `M:SS` 表記）を持たせる。

---

## 6. 検索・一覧クエリと N+1 対策

一覧ページ `/videos` の検索は **Livewire コンポーネント `VideoIndex`** が担当する。

| 入力 | 対象 | 実装 |
|---|---|---|
| フリーワード `q` | `title` / `channel_name` / タグ名 | `whereLike('title', "%q%")` OR `whereLike('channel_name', "%q%")` OR `whereHas('tags', name LIKE "%q%")` |
| タグ絞り込み `tag` | 完全一致のタグ名 | `whereHas('tags', fn ($t) => $t->where('name', $tag))` |

```php
$videos = Video::query()
    ->with('tags')                       // 一覧でタグを出すので必須 eager load（無いと N+1）
    ->when($this->query !== '', fn ($q) => $q->where(fn ($w) =>
        $w->whereLike('title', "%{$this->query}%")
          ->orWhereLike('channel_name', "%{$this->query}%")
          ->orWhereHas('tags', fn ($t) => $t->whereLike('name', "%{$this->query}%")),
    ))
    ->when($this->tag !== '', fn ($q) =>
        $q->whereHas('tags', fn ($t) => $t->where('name', $this->tag)))
    ->latest()
    ->paginate(18);
```

| 画面 | eager load |
|---|---|
| 一覧 `/videos` | `tags` |
| 詳細 `/videos/{video}` | `tags`, `transcript`, `summary`（`$video->load(...)`） |

- `q` と `tag` は `#[Url]` でクエリパラメータに同期し、ページネーションでも保持する。
- 検索インデックスは張らない（個人利用の件数ならフルスキャンで十分）。

---

## 7. データ整合性ルール

1. `videos.youtube_id` は常に 11 文字。`YouTubeService::extractVideoId()` で抽出したものだけ保存する。
2. `transcripts` は「字幕が実際に取得できた時だけ」作る。空の字幕は作らない。
3. `summaries` は取り込みで字幕があった動画にだけ作る（`no_transcript` の動画には作らない）。
4. 再試行時は `Transcript` / `Summary` を `updateOrCreate` で更新（重複行を作らない）。
5. `videos` を削除すると子テーブルは CASCADE で自動削除。アプリ側で個別 delete しない。

---

## 8. 想定データ量（キャパシティ）

| テーブル | 想定行数 | 1 行サイズ目安 |
|---|---|---|
| `videos` | 〜数千 | 小 |
| `transcripts` | `videos` と同程度 | `content` 数 KB〜数十 KB、`segments` も同程度 |
| `summaries` | `videos` と同程度 | `content` 数 KB |
| `tags` | 〜数千 | 小 |
| `tag_video` | `videos` × 平均タグ数 | 極小 |

個人利用の範囲。パーティショニングやアーカイブは考慮不要。

---

## 9. 決定事項ログ

- 認証は実装しない（2026-08-31）。Breeze も入れない
- `summaries.status` は専用 enum `App\Enums\SummaryStatus`（pending/processing/completed/failed）
- `failed_jobs` は DB に残す（Redis / Horizon 方式は採らない）
- 要約プロンプトは時刻付け対応で `v2`。`### [MM:SS] 見出し` を含む Markdown
- `cost_usd` は `numeric(10,6)`。概算表示用途なのでこの桁で十分
