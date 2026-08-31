# Translate-Portfolio 設計書（アーキテクチャ）

- 版: 1.0
- 作成日: 2026-08-31
- 対象: `~/Desktop/Translate-Portfolio`
- 関連: [db_design.md](./db_design.md)

この文書は「どう作るか（HOW）」をまとめる。前作 `~/Desktop/translate2` の
設計をベースにしつつ、今回の要件に合わせて次の点を変えている。

| 変更点 | 前作 | 本作 |
|---|---|---|
| 認証 | あり（後回し扱い） | **なし**（ローカル専用シングルユーザー） |
| デプロイ | Railway（web + worker + Postgres + Redis） | **なし**（ローカル動作 + README で見せる） |
| 画面 | Blade + Alpine + `/status` ポーリング API | **Livewire 3**（`wire:poll` / `#[Url]`） |
| 検索 | あり | あり（最初から） |
| 要約の見出し | `## チャプター` のみ | `### [MM:SS] 見出し`（**時刻表記**） |

> **認証を入れない判断（2026-08-31）**
> デプロイしない＝ローカルの 1 人しか触らないので、ログインで守る対象が無い。
> `.env` 固定 1 アカウントの Breeze はポートフォリオとしても見栄えがしない。
> 将来デプロイするならその時に Breeze を足す（YAGNI）。
> Laravel 標準の `users` テーブル / モデルは標準テストを壊さないためそのまま残す。

> **デプロイを外す判断（2026-08-31）**
> 規模が小さくポートフォリオ用であること、そして字幕取得に使う
> `mrmysql/youtube-transcript` がデータセンター IP（Railway 含む）から
> YouTube にブロックされやすく本番で字幕が取れない致命リスクがあること。
> worker 常時稼働コストや migrate の二重実行懸念もあり、「ローカルで確実に
> 動くもの + 充実した README」で見せる方針にした。

---

## 1. 全体像

YouTube の URL を登録すると、バックグラウンドで
「メタデータ取得 → 字幕取得 → 要約生成」を順に実行し、
詳細画面で動画（埋め込み）と日本語要約を表示する Web アプリ。
（字幕テキストは取得・保存するが画面には出さない。要約の時刻付けに使うだけ）

```
[ブラウザ]
   │ URL 登録（SubmitVideo::submit / ヘッダー常駐フォーム）
   ▼
[SubmitVideo]  (app/Livewire)
   │ ・URL 検証 / youtube_id 抽出（YouTubeService）
   │ ・Video::firstOrCreate（重複は作らない）
   │ ・新規なら StartVideoIngestion を実行
   ▼ 即リダイレクト
[VideoShow]  (/videos/{video})  ←── 非終了状態の間だけ wire:poll.3s で再描画
   ▲
   │ status 更新
[キューワーカー]  (php artisan queue:work)
   FetchVideoMetadata → FetchTranscript → GenerateSummary
        │                    │                  │
   [YouTube Data API]  [youtube-transcript]  [Claude Messages API]
```

ポイント:

- **Web リクエスト（Livewire 含む）は外部 API を叩かない**。全部キュー経由。
  例外は「URL から動画 ID を正規表現で取り出す」処理だけ（ネットワーク不要）。
- 取り込みの状態は `videos.status` の 1 本に集約。画面はそれを見るだけ。
- 失敗してもリトライで最終的に成功する設計（`$tries = 3` + `backoff`）。

---

## 2. レイヤー構成

| レイヤー | 置き場所 | 役割 |
|---|---|---|
| Livewire | `app/Livewire` | 画面。入力を受け、下の層を呼び、結果を描画するだけ（薄く保つ） |
| Action | `app/Actions` | 複数の操作元で共有する手続きを 1 クラスに集約 |
| Job | `app/Jobs` | 取り込みパイプラインの各工程。キューで実行 |
| Service | `app/Services` | 外部 API との通信をカプセル化。純粋な PHP クラス |
| Model | `app/Models` | Eloquent。リレーション・キャスト・クエリスコープ |
| Enum | `app/Enums` | `ProcessingStatus` / `SummaryStatus` |

**方針（2026-08-31 決定）: Livewire コンポーネントは薄く。**
クエリの条件はモデルのクエリスコープへ、取り込み開始のディスパッチは
専用 Action へ切り出す。コンポーネントは「入力を受ける・下の層を呼ぶ・結果を返す」だけ。

- SQL 条件 → `Video::scopeSearch()` / `Video::scopeWithTag()`
- 「状態リセット + ジョブ投入」 → `App\Actions\StartVideoIngestion`
  （新規登録の `SubmitVideo` と、再試行の `VideoShow::retry()` の両方から使う）

---

## 3. 取り込みパイプライン

### 3.1 ジョブチェーン

```php
// App\Actions\StartVideoIngestion::__invoke()
$video->update([
    'status' => ProcessingStatus::Pending,
    'failed_step' => null,
    'failed_reason' => null,
]);

Bus::chain([
    new FetchVideoMetadata($video),
    new FetchTranscript($video),
])->dispatch();
```

- **Redis キュー 1 本（`default`）**。用途別キュー・優先度分けはしない。
- **Horizon は入れない**。worker は
  `php artisan queue:work redis --tries=3 --sleep=1 --max-time=3600`。
- チェーンは「前のジョブが成功したら次」。途中で例外が投げられると
  以降のジョブは実行されず、そのジョブの `failed()` が呼ばれる。

### 3.2 各ジョブの責務

| ジョブ | やること | 開始時の status | 正常終了後 |
|---|---|---|---|
| `FetchVideoMetadata` | `YouTubeService` でメタデータ取得 → `videos` 更新 + タグ紐付け（`Tag::firstOrCreate` → `tags()->sync`） | `fetching_metadata` | 次へ |
| `FetchTranscript` | `TranscriptService` で字幕取得 → `transcripts` を `updateOrCreate` | `fetching_transcript` | 字幕あり → `GenerateSummary::dispatch` / 字幕なし → `no_transcript` で終了 |
| `GenerateSummary` | `SummaryGenerator::generate($transcript->segments)` → `summaries` 更新 → `videos.status = completed`（チェーン外。`FetchTranscript` が投げる） | `summarizing` | `completed` |

### 3.3 共通の設定（各ジョブに書く）

```php
class FetchVideoMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;               // GenerateSummary だけ 300

    /** 再試行の待ち時間（秒）: 1回目失敗→10s, 2回目→30s, 3回目→60s */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function __construct(public readonly Video $video) {}

    /** 3回試行しても失敗した時に1度だけ呼ばれる */
    public function failed(\Throwable $e): void
    {
        $this->video->update([
            'status' => ProcessingStatus::Failed,
            'failed_step' => 'metadata',      // ジョブごとに変える
            'failed_reason' => \Str::limit($e->getMessage(), 500),
        ]);
    }
}
```

> `GenerateSummary` の `timeout = 300` に合わせ、`.env` に
> `REDIS_QUEUE_RETRY_AFTER=360` を入れている（`retry_after` < `timeout` だと
> 実行中のジョブが二重取得される）。

使わないもの（最小構成の方針）:

- ジョブミドルウェア（`WithoutOverlapping` / `ThrottlesExceptions` / `RateLimited`）
- `ShouldBeUnique`（同一動画の重複投入は `firstOrCreate` +
  「終了状態からのみ再試行」で実用上防げる）
- `retryUntil`（回数制限だけで足りる）

### 3.4 字幕なしの分岐

チェーンは `[FetchVideoMetadata, FetchTranscript]` の 2 本だけ。
`GenerateSummary` は `FetchTranscript` が字幕を取れた時だけ投げる。
字幕なしは `status = no_transcript`（**例外ではなく正常終了**）にして return するだけ
＝チェーンがそこで自然に終わる（無駄なジョブが走らない）。

```php
// FetchTranscript::handle()
$data = $transcripts->fetch($this->video->youtube_id, $this->video->source_language);

if ($data === null) {
    $this->video->update(['status' => ProcessingStatus::NoTranscript]);
    return;                                   // チェーンはここで終わり
}

$this->video->transcript()->updateOrCreate([], [...]);
GenerateSummary::dispatch($this->video);      // 字幕がある時だけ後続を投げる
```

一時的な取得失敗（レート制限等）は `TranscriptService` が例外を投げ、
ジョブがリトライ → 3 回失敗で `failed`（`failed_step = transcript`）。

### 3.5 再試行（ユーザー操作）

詳細ページの「再試行」ボタン → `VideoShow::retry()`:

```php
public function retry(StartVideoIngestion $startIngestion): void
{
    if (! $this->video->status->isTerminal()) {
        return;                               // 進行中は無視（二重投入防止）
    }
    $startIngestion($this->video);            // status リセット + チェーン投入
}
```

各ジョブは `updateOrCreate` を使うので、既にある `transcript` / `summary` は
上書き更新される（冪等）。

---

## 4. Service 設計

### 4.1 YouTubeService

- `extractVideoId(string $url): ?string` — 正規表現で 11 桁 ID を抽出
  （`watch?v=` / `youtu.be/` / `shorts/` / `embed/`）。取り出せなければ `null`。
- `fetchVideoData(string $videoId): array` — Data API v3
  `videos?part=snippet,contentDetails`。`Http::retry(3, 200)->timeout(15)->throw()`。
- 再生時間は `CarbonInterval::make($iso)?->totalSeconds`。

### 4.2 TranscriptService

- `mrmysql/youtube-transcript` の `TranscriptListFetcher` をラップ。
- 優先言語: `source_language` → ベース言語（`en-US` → `en`）→ 利用可能な任意。
- 返り値: `{ language, content, segments: [{start, end, text}] }` または `null`。
  - `content` = 全セグメントをスペース連結した素の本文（保存・検索用）。
  - `segments` = 要約の時刻付けに使う（画面表示はしない）。
- `html_entity_decode` で `&amp;` 等を戻す。

### 4.3 AnthropicService

Claude Messages API の薄い HTTP ラッパー。**公式 SDK ではなく `Http::` を使う**
（`YouTubeService` と揃える／`Http::fake()` でテストする方針）。

- `complete(string $system, string $user, int $maxTokens = 4096): array`
  → `{ content, input_tokens, output_tokens }`。`input_tokens` はキャッシュ読み書き分も合算。
- `timeout(120)` + `Http::retry(3, 1000, ..., throw: false)` で 429 / 529 に対応。
- prompt caching: system プロンプトを
  `[{ type: 'text', text: '...', cache_control: { type: 'ephemeral' } }]` 形式で送る
  （`anthropic-version: 2023-06-01` のみ。`anthropic-beta` ヘッダは不要）。
- モデルは `config('services.anthropic.model')`（`.env` の `ANTHROPIC_MODEL`、
  既定 `claude-sonnet-5`）。

### 4.4 SummaryGenerator（本作の目玉）

要約の組み立てロジック。`AnthropicService` を使う。前作との違いは **時刻付け**。

```
入力: array $segments（Transcript::segments。[{start, end, text}]）
 1. segments を「[M:SS] マーカー入りの 1 本のテキスト」にする
    （MARKER_INTERVAL_SECONDS = 15 秒ごとに [M:SS] / [H:MM:SS] を挿入）
 2. トークン概算分割（1 チャンク ≒ 6000 tok。mb_strlen / 3 で概算、単語境界で詰める）
 3. チャンクが 1 個 → そのまま最終プロンプトへ
    チャンクが複数 →
      a. 各チャンクを「部分要点」プロンプトで抽出（map。時刻を残すよう指示）
      b. 部分要点を連結して「統合要約」プロンプトへ（reduce）
 4. usage を合算して返す
出力: { content: string(markdown), input_tokens, output_tokens, prompt_version }
```

出力フォーマット（プロンプトで指定）:

```
## TL;DR
（3〜4 文）

## キーポイント
- （箇条書き 5〜8）

## チャプター別の要約
### [M:SS] 見出し
（短い段落）
```

- `PROMPT_VERSION = 'v2'`（時刻対応で `v1` から上げた）。変えたら版を上げる。
- `GenerateSummary` ジョブが結果を `summaries` に書き、
  `cost_usd` を `config('services.anthropic.*_cost_per_mtok')` × トークンで概算する
  （既定単価: input $2 / output $10 per Mtok、`claude-sonnet-5` の第一者 API レート）。

### 4.5 バインド（AppServiceProvider::register）

コンストラクタにスカラー値や Laravel が自動解決できない外部クラスを要求するものだけ
`singleton` で登録:

- `YouTubeService`（API キー）
- `TranscriptListFetcher`（Guzzle + PSR-17 `HttpFactory`）
- `AnthropicService`（キー・モデル・ワークスペース ID）

`TranscriptService` / `SummaryGenerator` は依存先さえバインドすれば型ヒントから
自動解決されるので書かない。

---

## 5. 画面（Livewire 3）

ルートは 2 つだけ（+ ルートの `/` は `/videos` へリダイレクト）。認証ミドルウェアなし。

```php
Route::redirect('/', '/videos');
Route::get('/videos', VideoIndex::class)->name('videos.index');
Route::get('/videos/{video}', VideoShow::class)->name('videos.show');
```

いずれもフルページ Livewire コンポーネント（`#[Layout('layouts.app')]`）。
レイアウト `resources/views/layouts/app.blade.php` は自前で 1 枚書く
（ヘッダーに `<livewire:submit-video />`、`session('status')` のフラッシュ枠、`{{ $slot }}`）。

### 5.1 SubmitVideo（ヘッダー常駐の URL 登録フォーム）

`submit()`:

1. `required` / `string` / `max:2048` をバリデーション。
2. `YouTubeService::extractVideoId()` が `null` なら `url` フィールドにエラーを付けて中断。
3. `Video::firstOrCreate(['youtube_id' => $id], ['url' => $url])`。
4. 既存（`! wasRecentlyCreated`）→ フラッシュ + 詳細ページへリダイレクト（取り込みはしない）。
   新規 → `StartVideoIngestion` 実行 → 詳細ページへリダイレクト（`navigate: true`）。

### 5.2 VideoIndex（一覧・検索）

- `use WithPagination`。1 ページ 18 件（`paginate(18)`）。
- `#[Url(as: 'q', except: '')] public string $query`（フリーワード）
- `#[Url(as: 'tag', except: '')] public string $tag`（タグ絞り込み）
  - `#[Url]` で `?q=` / `?tag=` としてブラウザ URL に載る → 検索結果を共有・
    リロード・戻るボタンが効く。
- `updatingQuery()` / `updatingTag()` で `resetPage()`（条件変更時は 1 ページ目へ）。
- `filterByTag($name)` は同じタグ再クリックで解除（トグル）。

```php
$videos = Video::query()
    ->with('tags')                                  // N+1 回避
    ->when($this->query !== '', fn ($q) => $q->search($this->query))
    ->when($this->tag !== '', fn ($q) => $q->withTag($this->tag))
    ->latest()
    ->paginate(18);
```

- `Video::scopeSearch()` — `title` / `channel_name` / タグ名の部分一致 OR
  （PostgreSQL の `ILIKE`。3 条件は `where(Closure)` で括弧にくくる）。
- `Video::scopeWithTag()` — `whereHas('tags', name = ?)`（完全一致）。
- ビュー: `wire:model.live.debounce.400ms="query"` の検索欄、3 列グリッドのカード
  （サムネ・再生時間バッジ・状態バッジ・タイトル・チャンネル・相対公開日・タグ）、
  カードは「stretched link」パターン（透明な `<a>` を全面に重ね、タグボタンだけ手前）。

### 5.3 VideoShow（詳細）

- `public Video $video`（ルートモデルバインディング）。`mount()` で
  `load('tags', 'transcript', 'summary')`、`render()` で毎回
  `refresh()->load(...)`。
- **進捗ポーリング**: ビュー側で
  ```blade
  <div @unless ($video->status->isTerminal()) wire:poll.3s @endunless>
  ```
  終了状態（`completed` / `no_transcript` / `failed`）になると属性ごと
  DOM から消え、ポーリングが止まる。
- `retry()` — `isTerminal()` の時だけ `StartVideoIngestion` を呼ぶ。
- `delete()` — `$video->delete()` の 1 行。`transcripts` / `summaries` / `tag_video`
  は FK の `ON DELETE CASCADE` で一緒に消える。ビューのボタンに
  `wire:confirm="..."` を付ける。
- ビュー（2 カラム: 動画側 `lg:sticky` + 要約側）:
  - 左: 戻るリンク、`youtube-nocookie.com/embed/{id}` の iframe、タイトル/チャンネル、
    状態バッジ + 再試行 + 削除、失敗理由、タグ、メタ（再生時間/公開日/元 URL）。
  - 右: 「要約」見出し。
    - 非終了 → 進捗ステッパー（`待機中 / 情報取得 / 字幕取得 / 要約` の 4 段。
      現在ステップは `ProcessingStatus::step()`）。
    - `summary.status === completed` →
      `str($summary->content)->markdown(['html_input' => 'strip'])` を
      `.summary-body`（Markdown 表示スタイル）で。生成メタ（モデル/トークン/概算 $）も表示。
    - `no_transcript` → 「字幕なし」。`failed` → 「再試行して」。

### 5.4 共有パーツ

- `resources/views/components/status-badge.blade.php`（匿名コンポーネント）—
  `ProcessingStatus` を色付きバッジで表示。一覧カードと詳細で共用。

> WebSocket（Reverb）や独自の JSON ステータス API は使わない。
> `wire:poll` で十分。前作の `/videos/{id}/status` + Alpine の
> `ingestProgress()` はまるごと廃止した。

---

## 6. 詳細ページの動画表示

- 動画は `https://www.youtube-nocookie.com/embed/{youtube_id}` を
  そのまま `<iframe>` で貼るだけ。IFrame Player API は読み込まない。
- インタラクティブ字幕プレイヤー（click-to-seek 等）は作らない。
  字幕は元言語のままで日本語話者には実用性が薄いと判断。
  `transcripts.segments` は保存を続けるが画面には出さない
  （要約のチャプター見出しの時刻付けに使う）。

---

## 7. エラーハンドリング方針

| 事象 | 扱い |
|---|---|
| URL パース失敗 | `SubmitVideo` で即バリデーションエラー（キューに乗せない） |
| YouTube API 失敗 | ジョブをリトライ → 3 回ダメなら `failed`（`failed_step = metadata`） |
| 字幕が存在しない | 正常系。`no_transcript` |
| 字幕ライブラリの例外 | リトライ → ダメなら `failed`（`failed_step = transcript`） |
| Claude 429 / 529 | `Http::retry` で吸収 → ダメならジョブリトライ → `failed`（`failed_step = summary`） |
| Claude その他 4xx/5xx | ジョブリトライ → `failed` |

- `failed_reason` はユーザーに見せる前提で、生スタックトレースではなく
  要約したメッセージを `Str::limit(…, 500)` で入れる。
- 失敗ジョブは Laravel 標準の `failed_jobs` テーブルに残る。

---

## 8. ローカル実行

```
docker compose up -d postgres redis      # postgres:16-alpine + redis:7-alpine のみ
php artisan migrate
npm run dev            &                  # または npm run build
php artisan queue:work &
php artisan serve
```

- ホスト PHP は `intl` 拡張が読み込めない警告が出るが動作はする。
- キュー / キャッシュ / セッションはすべて Redis。
- 本番用の Dockerfile / nginx / supervisord は作らない（デプロイしないため）。

---

## 9. テスト方針

| 対象 | 種別 | ポイント |
|---|---|---|
| `/` → `/videos` リダイレクト | Feature | 認証なしで `/videos` が 200 |
| `SubmitVideo` | Livewire | `Http::fake` + `Bus::fake`。チェーン投入、重複 URL は作らずリダイレクト |
| `FetchVideoMetadata` | Feature | `Http::fake`。`videos` 更新 + タグ紐付け |
| `FetchTranscript` | Feature | `TranscriptService` モック。字幕あり（→ `GenerateSummary`）/ なし（→ `no_transcript`） |
| `GenerateSummary` | Feature | `Http::fake`（Anthropic）。completed / failed |
| `SummaryGenerator` | Unit | 複数チャンク分割、時刻マーカー挿入、usage 合算 |
| `VideoIndex` 検索 | Livewire | title / channel / tag ヒット、0 件、ページネーション + クエリ保持 |
| `VideoShow` | Livewire | 非終了で `wire:poll`、`retry()` は終了状態のみ、`delete()` で行が消えて一覧へ（子テーブルも消える） |

- 外部 API は**絶対に本物を呼ばない**（`Http::fake` / サービスをモック / `Bus::fake`）。
