<?php

namespace Tests\Unit;

use App\Services\AnthropicService;
use App\Services\SummaryGenerator;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * SummaryGenerator の Unit テスト。
 *
 * AnthropicService はモックに差し替え、「何回・どんなプロンプトで呼ばれたか」と
 * 「usage の合算」を検証する。DB も HTTP も使わない純粋なロジックのテスト。
 */
class SummaryGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_short_transcript_uses_a_single_call_with_time_markers(): void
    {
        $captured = null;

        $anthropic = Mockery::mock(AnthropicService::class);
        $anthropic->shouldReceive('complete')
            ->once()
            ->andReturnUsing(function (string $system, string $user) use (&$captured) {
                $captured = $user;

                return ['content' => '要点です。', 'input_tokens' => 200, 'output_tokens' => 40];
            });

        $segments = [
            ['start' => 0.0, 'end' => 3.0, 'text' => 'intro part'],
            ['start' => 8.0, 'end' => 12.0, 'text' => 'still intro'],
            ['start' => 18.0, 'end' => 22.0, 'text' => 'second topic'], // 15 秒を越える
            ['start' => 40.0, 'end' => 44.0, 'text' => 'third topic'],
        ];

        $result = (new SummaryGenerator($anthropic))->generate($segments);

        // 時刻マーカーが本文に入っている（15 秒間隔で切り上げ）。
        $this->assertStringContainsString('[0:00]', $captured);
        $this->assertStringContainsString('[0:18]', $captured);
        $this->assertStringContainsString('[0:40]', $captured);

        // 1 チャンクなので usage はそのまま。
        $this->assertSame(200, $result['input_tokens']);
        $this->assertSame(40, $result['output_tokens']);
        $this->assertSame('v3', $result['prompt_version']);
    }

    public function test_long_transcript_is_split_into_map_then_reduce_and_sums_usage(): void
    {
        // 1 チャンク目安 6000 tok ≒ 18000 文字。それを十分に超える字幕を作る。
        $segments = [];
        for ($i = 0; $i < 400; $i++) {
            $segments[] = [
                'start' => $i * 5.0,
                'end' => $i * 5.0 + 4.0,
                'text' => "word{$i} filler filler filler filler filler filler filler filler",
            ];
        }

        $callCount = 0;

        $anthropic = Mockery::mock(AnthropicService::class);
        $anthropic->shouldReceive('complete')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;

                // map の呼び出しも reduce の呼び出しも一律 input 1000 / output 100 で返す。
                return ['content' => "partial {$callCount}", 'input_tokens' => 1000, 'output_tokens' => 100];
            });

        $result = (new SummaryGenerator($anthropic))->generate($segments);

        // 複数チャンク → map（チャンク数）+ reduce（1）で 2 回以上呼ばれる。
        $this->assertGreaterThanOrEqual(3, $callCount);

        // usage は全呼び出しの合算。
        $this->assertSame(1000 * $callCount, $result['input_tokens']);
        $this->assertSame(100 * $callCount, $result['output_tokens']);
        $this->assertSame('v3', $result['prompt_version']);
    }

    public function test_hour_long_timestamp_uses_h_mm_ss_format(): void
    {
        $captured = null;

        $anthropic = Mockery::mock(AnthropicService::class);
        $anthropic->shouldReceive('complete')
            ->once()
            ->andReturnUsing(function (string $system, string $user) use (&$captured) {
                $captured = $user;

                return ['content' => 'x', 'input_tokens' => 1, 'output_tokens' => 1];
            });

        (new SummaryGenerator($anthropic))->generate([
            ['start' => 3661.0, 'end' => 3664.0, 'text' => 'one hour in'], // 1:01:01
        ]);

        $this->assertStringContainsString('[1:01:01]', $captured);
    }
}
