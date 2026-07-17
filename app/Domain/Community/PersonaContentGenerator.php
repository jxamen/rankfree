<?php

namespace App\Domain\Community;

use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\CommunitySeed;
use App\Models\Persona;
use App\Support\GeminiCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 페르소나 콘텐츠 생성 — 글밥(수집 소재)을 AI 로 재작성해 글/댓글 텍스트를 만든다.
 * 공급자: Gemini(기본, 무료 티어) / Claude — 어드민 환경 설정 > AI API 에서 선택·모델 지정.
 * API 키가 없거나 호출 실패 시: 폴백 허용 설정이면 원문 가벼운 변형, 아니면 문장풀 생성.
 * 반환에 seed_id·provider 가 포함되어 호출측(시뮬레이터)이 사용 이력을 기록한다.
 */
class PersonaContentGenerator
{
    private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function apiEnabled(): bool
    {
        return $this->providers() !== [];
    }

    /**
     * 재작성에 사용할 공급자 우선순위 — 설정(rankfree.community.rewrite.provider) 기준.
     * auto: Gemini(키 있으면) → Claude 순으로 시도. 반환: [['name'=>'gemini','key'=>..,'model'=>..], …]
     */
    private function providers(): array
    {
        $setting = (string) config('rankfree.community.rewrite.provider', 'auto');
        if ($setting === 'off') {
            return [];
        }
        $customModel = trim((string) config('rankfree.community.rewrite.model', ''));

        $defs = [
            'gemini' => ['key' => GeminiCredentials::apiKey(), 'model' => GeminiCredentials::model()],
            'anthropic' => ['key' => (string) config('services.anthropic.key', ''), 'model' => (string) config('services.anthropic.model', 'claude-opus-4-8')],
        ];

        $order = $setting === 'auto' ? ['gemini', 'anthropic'] : [$setting];
        $out = [];
        foreach ($order as $name) {
            if (! isset($defs[$name]) || $defs[$name]['key'] === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'key' => $defs[$name]['key'],
                'model' => $customModel !== '' ? $customModel : $defs[$name]['model'],
            ];
        }

        return $out;
    }

    /** AI 실패/미설정 시 글밥 원문을 가볍게 변형해 쓰는 폴백 허용 여부(환경 설정). */
    private function fallbackAllowed(): bool
    {
        return (bool) config('rankfree.community.rewrite.fallback', true);
    }

    /**
     * 게시글 생성 → ['title','body','seed_id','provider'] | null.
     * 글밥(수집 소재)이 있으면 그걸 소재로 페르소나 말투로 재작성한다. 없으면 성향 기반 생성.
     */
    public function generatePost(Persona $persona, CommunityCategory $category): ?array
    {
        $seed = CommunitySeed::pick('post', $category->id);

        $schema = [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'body' => ['type' => 'string']],
            'required' => ['title', 'body'],
            'additionalProperties' => false,
        ];
        if ($seed) {
            // 수집 소재를 소재로 새로 쓰기(원문 복붙 금지 · 표현 변형)
            $prompt = "아래는 다른 커뮤니티에서 수집한 '글감'이야. 이걸 소재·아이디어로 삼아 네 말투로 완전히 새로 써줘. "
                .'문장·표현은 원문과 다르게 바꾸되(그대로 복사 금지), 핵심 소재/맥락은 유지해. '
                ."제목 20자 내외, 본문은 {$this->lengthGuide($persona)} 커뮤니티 '{$category->name}'에 어울리게. JSON(title, body)으로만 답해.\n\n"
                .'[글감 제목] '.($seed->title ?: '(없음)')."\n[글감 본문] ".mb_substr($seed->body, 0, 1200);
        } else {
            $prompt = "커뮤니티 게시판 '{$category->name}'({$category->description})에 올릴 짧은 글을 하나 써줘. "
                ."제목은 20자 내외로 자연스럽게, 본문은 {$this->lengthGuide($persona)} "
                .'실제 커뮤니티 이용자가 쓴 것처럼 구어체로. 광고·홍보 톤 금지. JSON(title, body)으로만 답해.';
        }

        $res = $this->call($persona, $prompt, 700, $schema);
        if ($res !== null) {
            $json = json_decode($this->stripFences($res['text']), true);
            if (is_array($json) && ! empty($json['title']) && ! empty($json['body'])) {
                $seed?->increment('used_count');

                return [
                    'title' => mb_substr(trim($json['title']), 0, 150),
                    'body' => trim($json['body']),
                    'seed_id' => $seed?->id,
                    'provider' => $res['provider'],
                ];
            }
        }

        // 폴백 — 허용 설정일 때만 글밥 원문 변형. 아니면 문장풀 생성(글밥 미사용).
        if ($seed && $this->fallbackAllowed()) {
            $seed->increment('used_count');

            return $this->varySeedPost($persona, $seed, $category) + ['seed_id' => $seed->id, 'provider' => 'fallback'];
        }

        return $this->fallbackPost($persona, $category) + ['seed_id' => null, 'provider' => 'fallback'];
    }

    /** 댓글 생성 → ['text','seed_id','provider'] | null. 댓글 글밥이 있으면 그걸 재작성해 사용. */
    public function generateComment(Persona $persona, CommunityPost $post): ?array
    {
        $seed = CommunitySeed::pick('comment', $post->category_id);

        if ($seed) {
            $prompt = "아래 '댓글 소재'를 참고해서, 이 글에 어울리는 짧은 댓글 하나를 네 말투로 새로 써줘(1~2문장, 40자 내외). "
                ."표현은 소재와 다르게 바꿔. 댓글 텍스트만 답하고 따옴표·설명은 붙이지 마.\n\n"
                ."[댓글 소재] {$seed->body}\n[원글 제목] {$post->title}";
        } else {
            $prompt = "아래 커뮤니티 글에 어울리는 짧은 댓글 하나만 구어체로 써줘(1~2문장, 40자 내외). 댓글 텍스트만 답하고 따옴표·설명은 붙이지 마.\n\n"
                ."[제목] {$post->title}\n[본문] ".mb_substr($post->body, 0, 400);
        }

        $res = $this->call($persona, $prompt, 200, null);
        if ($res !== null && trim($res['text']) !== '') {
            $seed?->increment('used_count');

            return [
                'text' => mb_substr(trim($this->stripFences($res['text'])), 0, 300),
                'seed_id' => $seed?->id,
                'provider' => $res['provider'],
            ];
        }

        if ($seed && $this->fallbackAllowed()) {
            $seed->increment('used_count');
            $text = trim($seed->body);
            if ($persona->emoji_level >= 2) {
                $text .= ' 😊';
            }

            return ['text' => mb_substr($text, 0, 300), 'seed_id' => $seed->id, 'provider' => 'fallback'];
        }

        return ['text' => $this->fallbackComment($persona), 'seed_id' => null, 'provider' => 'fallback'];
    }

    /** 폴백 — 수집 글감을 가볍게 변형(제목 접두/본문 축약). */
    private function varySeedPost(Persona $persona, CommunitySeed $seed, CommunityCategory $category): array
    {
        $prefixes = ['', '', '(공유) ', '요즘 관련 글: ', ''];
        $title = ($seed->title ?: mb_substr($seed->body, 0, 24)).'';
        $title = $prefixes[array_rand($prefixes)].$title;
        $body = trim($seed->body);
        if ($persona->post_length === 'short') {
            $body = Str::limit($body, 120);
        }

        return ['title' => mb_substr($title, 0, 150), 'body' => $body];
    }

    /**
     * AI 재작성 호출 — 설정된 공급자 순서로 시도, 첫 성공을 반환.
     * 반환: ['text'=>string, 'provider'=>'gemini'|'anthropic'] | null.
     */
    private function call(Persona $persona, string $userPrompt, int $maxTokens, ?array $schema): ?array
    {
        foreach ($this->providers() as $p) {
            $text = $p['name'] === 'gemini'
                ? $this->callGemini($p['key'], $p['model'], $persona, $userPrompt, $maxTokens, $schema !== null)
                : $this->callAnthropic($p['key'], $p['model'], $persona, $userPrompt, $maxTokens, $schema);
            if ($text !== null) {
                return ['text' => $text, 'provider' => $p['name']];
            }
        }

        return null;
    }

    /** Google Gemini generateContent 호출. 실패 시 null. */
    private function callGemini(string $key, string $model, Persona $persona, string $userPrompt, int $maxTokens, bool $json): ?string
    {
        $gen = [
            // 2.5 계열은 사고(thinking) 토큰이 출력 한도에 포함 — 여유 확보
            'maxOutputTokens' => max(1024, $maxTokens * 2),
        ];
        if ($json) {
            $gen['responseMimeType'] = 'application/json';
        }
        if (str_contains($model, '2.5')) {
            $gen['thinkingConfig'] = ['thinkingBudget' => 0]; // 짧은 재작성엔 사고 불필요 — 속도·비용 절감
        }
        $body = [
            'system_instruction' => ['parts' => [['text' => $this->systemPrompt($persona)]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig' => $gen,
        ];

        try {
            $resp = Http::withHeaders(['x-goog-api-key' => $key, 'content-type' => 'application/json'])
                ->timeout(60)->post(self::GEMINI_ENDPOINT."/{$model}:generateContent", $body);

            if (! $resp->successful()) {
                Log::warning('community: gemini api error', ['status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 300)]);

                return null;
            }
            $out = '';
            foreach ($resp->json('candidates.0.content.parts') ?? [] as $part) {
                $out .= $part['text'] ?? '';
            }

            return $out !== '' ? $out : null;
        } catch (\Throwable $e) {
            Log::warning('community: gemini call failed', ['msg' => $e->getMessage()]);
        }

        return null;
    }

    /** Anthropic Messages API 호출. 실패 시 null. */
    private function callAnthropic(string $key, string $model, Persona $persona, string $userPrompt, int $maxTokens, ?array $schema): ?string
    {
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $this->systemPrompt($persona),
            'messages' => [['role' => 'user', 'content' => $userPrompt]],
        ];
        if ($schema !== null) {
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
        }

        try {
            $resp = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post(self::ANTHROPIC_ENDPOINT, $body);

            if (! $resp->successful()) {
                Log::warning('community: anthropic api error', ['status' => $resp->status(), 'body' => mb_substr($resp->body(), 0, 300)]);

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
            Log::warning('community: anthropic call failed', ['msg' => $e->getMessage()]);
        }

        return null;
    }

    /** ```json … ``` 펜스 제거(모델이 마크다운으로 감싸는 경우 대비). */
    private function stripFences(string $s): string
    {
        $s = trim($s);
        if (str_starts_with($s, '```')) {
            $s = preg_replace('/^```[a-z]*\s*/i', '', $s);
            $s = preg_replace('/\s*```$/', '', $s);
        }

        return trim((string) $s);
    }

    /** 페르소나 성향 → 시스템 프롬프트. */
    private function systemPrompt(Persona $persona): string
    {
        $tone = $persona->toneLabel();
        $interests = implode('·', (array) ($persona->interests ?? []));
        $age = $persona->age ? "{$persona->age}세" : '';
        $gender = Persona::GENDERS[$persona->gender] ?? '';
        $emoji = match ($persona->emoji_level) {
            0 => '이모지는 쓰지 않는다',
            2 => '이모지를 자주 섞어 쓴다',
            default => '이모지를 가끔 쓴다',
        };

        return "너는 한국 네이버 마케팅 관심 커뮤니티의 이용자 '{$persona->nickname}'다. "
            .trim("{$age} {$gender} · 관심사 {$interests} · 말투는 {$tone}")
            .". {$emoji}. 실제 사람처럼 자연스러운 한국어 구어체로 쓰고, AI라는 사실을 드러내지 않는다. "
            .'너무 정제되지 않게, 커뮤니티 특유의 편안한 톤으로 쓴다.';
    }

    private function lengthGuide(Persona $persona): string
    {
        return match ($persona->post_length) {
            'short' => '2~3문장으로 짧게.',
            'long' => '5~7문장으로 경험담을 곁들여.',
            default => '3~4문장 정도로.',
        };
    }

    // ---- 폴백(문장풀) — API 미사용/실패 시 ----

    private function fallbackPost(Persona $persona, CommunityCategory $category): array
    {
        $interest = collect($persona->interests ?? ['마케팅'])->random();
        $titles = [
            "{$interest} 순위 다들 어떻게 관리하세요?",
            "요즘 {$interest} 쪽 반응이 예전 같지 않네요",
            "{$category->name}에 처음 글 남겨봐요",
            "{$interest} 관련 팁 공유합니다",
            "{$interest} 하시는 분들 계신가요?",
        ];
        $bodies = [
            "요즘 {$interest} 관련해서 이것저것 해보고 있는데 생각보다 쉽지 않네요. 다들 어떻게 하시는지 궁금해서 글 남겨봅니다.",
            '얼마 전부터 순위가 조금씩 오르는 것 같은데 확실하진 않아요. 경험 있으신 분들 조언 부탁드려요!',
            '무료로 순위 확인하는 방법 찾다가 여기까지 왔네요. 도움 많이 받고 갑니다.',
            "{$interest} 쪽은 리뷰 관리가 진짜 중요한 것 같아요. 꾸준함이 답인 듯.",
        ];

        return [
            'title' => mb_substr($titles[array_rand($titles)], 0, 150),
            'body' => $bodies[array_rand($bodies)],
        ];
    }

    private function fallbackComment(Persona $persona): string
    {
        $pool = [
            '오 이거 저도 궁금했어요!',
            '공감합니다ㅎㅎ 저도 비슷한 고민이었어요',
            '정보 감사해요~ 도움 많이 됐습니다',
            '저는 리뷰 늘리니까 좀 나아지더라구요',
            '헐 이런 방법이 있었네요',
            '꾸준히 하는 게 답인 것 같아요',
            '좋은 글 잘 보고 갑니다!',
            '저도 한번 해봐야겠네요',
        ];
        if ($persona->emoji_level >= 2) {
            $pool = array_map(fn ($c) => $c.' 😊', $pool);
        }

        return $pool[array_rand($pool)];
    }
}
