<?php

namespace App\Domain\Keyword;

use App\Support\GeminiCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 키워드 AI 인사이트(22 Phase 3) — 발행 시점 실측 데이터(vm)를 근거로
 * 검색의도 해석·콘텐츠 방향 한 단락을 생성해 snapshot 에 저장한다(열람 시 재호출 없음).
 * 원칙: **사실(수치)은 데이터가 말하고 AI 는 문장만 만든다** — 새 수치·과장 생성 금지(프롬프트 강제).
 * 키(services.gemini/anthropic)가 없으면 null — 문서는 AI 없이도 완결(선택 보강).
 */
class KeywordAiInsight
{
    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const SYSTEM = '너는 네이버 검색 마케팅 데이터 분석 도우미다. 제공된 실측 데이터만 근거로 말하고, '
        .'데이터에 없는 수치·사실을 만들지 않는다. 과장·확정 표현(반드시, 보장, 무조건)을 쓰지 않는다.';

    public function enabled(): bool
    {
        return $this->providers() !== [];
    }

    /**
     * vm(검색량·경쟁·시즌·타겟)을 근거로 2~4문장 인사이트 생성.
     * 실패·미설정·데이터 부족 시 null.
     *
     * @return array{text:string,provider:string,generated_at:string}|null
     */
    public function write(array $vm): ?array
    {
        if (empty($vm['keyword']) || empty($vm['has_volume'])) {
            return null;
        }
        $providers = $this->providers();
        if (! $providers) {
            return null;
        }

        $aeo = KeywordAnalysisPresenter::aeo($vm);
        $facts = collect([$aeo['summary']])
            ->concat(collect($aeo['faq'])->map(fn ($f) => '- '.$f['q'].' → '.$f['a']))
            ->implode("\n");

        $prompt = "아래는 네이버 키워드 '{$vm['keyword']}'의 실측 분석 데이터입니다.\n\n{$facts}\n\n"
            ."이 데이터만 근거로, 이 키워드를 공략하려는 마케터·판매자에게 도움이 되는 검색의도 해석과 콘텐츠·마케팅 방향을 "
            ."한국어 2~4문장 한 단락으로 써 주세요. 불릿·제목 없이 문장만, 존댓말로. 데이터에 없는 수치를 만들지 마세요.";

        foreach ($providers as $p) {
            $text = $p['name'] === 'gemini'
                ? $this->callGemini($p['key'], $p['model'], $prompt)
                : $this->callAnthropic($p['key'], $p['model'], $prompt);
            if ($text !== null && trim($text) !== '') {
                return ['text' => trim($text), 'provider' => $p['name'], 'generated_at' => now()->toIso8601String()];
            }
        }

        return null;
    }

    /** 공급자 우선순위 — Gemini(키 있으면) → Claude. 커뮤니티 재작성과 동일한 서비스 키 재사용. */
    private function providers(): array
    {
        $defs = [
            'gemini' => ['key' => GeminiCredentials::apiKey(), 'model' => GeminiCredentials::model()],
            'anthropic' => ['key' => (string) config('services.anthropic.key', ''), 'model' => (string) config('services.anthropic.model', 'claude-opus-4-8')],
        ];

        return collect($defs)
            ->filter(fn ($d) => $d['key'] !== '')
            ->map(fn ($d, $name) => ['name' => $name] + $d)
            ->values()->all();
    }

    private function callGemini(string $key, string $model, string $prompt): ?string
    {
        $gen = ['maxOutputTokens' => 1024];
        if (str_contains($model, '2.5')) {
            $gen['thinkingConfig'] = ['thinkingBudget' => 0]; // 짧은 요약엔 사고 불필요 — 속도·비용 절감
        }

        try {
            $resp = Http::withHeaders(['x-goog-api-key' => $key, 'content-type' => 'application/json'])
                ->timeout(60)->post(self::GEMINI_ENDPOINT."/{$model}:generateContent", [
                    'system_instruction' => ['parts' => [['text' => self::SYSTEM]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'generationConfig' => $gen,
                ]);
            if (! $resp->successful()) {
                Log::warning('hub: gemini api error', ['status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 300)]);

                return null;
            }
            $out = '';
            foreach ($resp->json('candidates.0.content.parts') ?? [] as $part) {
                $out .= $part['text'] ?? '';
            }

            return $out !== '' ? $out : null;
        } catch (\Throwable $e) {
            Log::warning('hub: gemini call failed', ['msg' => $e->getMessage()]);
        }

        return null;
    }

    private function callAnthropic(string $key, string $model, string $prompt): ?string
    {
        try {
            $resp = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post(self::ANTHROPIC_ENDPOINT, [
                'model' => $model,
                'max_tokens' => 1024,
                'system' => self::SYSTEM,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
            if (! $resp->successful()) {
                Log::warning('hub: anthropic api error', ['status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 300)]);

                return null;
            }
            $data = $resp->json();
            if (($data['stop_reason'] ?? '') === 'refusal') {
                return null;
            }
            foreach ($data['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    return $block['text'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('hub: anthropic call failed', ['msg' => $e->getMessage()]);
        }

        return null;
    }
}
