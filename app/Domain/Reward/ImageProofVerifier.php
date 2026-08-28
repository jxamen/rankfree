<?php

namespace App\Domain\Reward;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * 스크린샷 증빙 검증(2026-08-28) — 제출한 화면에 **표식**(플레이스 저장 별표·쇼핑 찜 하트)이 있는지 본다.
 *
 * 저장·찜 미션은 참여자가 실제로 눌렀는지를 텍스트 정답으로 물을 수 없다. 그래서 누른 뒤 화면을 올리게 하고
 * OpenCV 템플릿 매칭으로 표식을 찾는다(boosting_shop `quiz/check_template_save.py` 이식).
 * 판정 자체는 [scripts/check-template.py](../../../scripts/check-template.py) 가 하고 여기서는 실행·해석만 한다.
 *
 * 원본에서 가져온 규칙:
 *  · 임계값 **0.8** 이상이어야 통과(여러 배율로 훑어 가장 높은 값).
 *  · 통과 여부와 무관하게 **원본 이미지는 남기지 않는다** — 참여자 화면이라 보관 자체가 부담이다.
 *  · 같은 이미지를 여러 번 올려 통과하는 것을 막으려 **해시**를 돌려준다(호출부가 중복을 판정).
 */
class ImageProofVerifier
{
    /**
     * @return array{ok: bool, probability: float, hash: string, reason: ?string, template: ?string}
     */
    public function verify(UploadedFile $file, string $kind): array
    {
        $fail = fn (string $reason, float $p = 0.0, string $hash = '') => [
            'ok' => false, 'probability' => $p, 'hash' => $hash, 'reason' => $reason, 'template' => null,
        ];

        $templates = $this->templatesFor($kind);
        if ($templates === []) {
            return $fail('no_template');   // 이 유형은 이미지 증빙을 쓰지 않는다(설정 누락 포함)
        }
        if ($file->getSize() > (int) config('reward.image_proof.max_bytes', 8388608)) {
            return $fail('too_large');
        }

        // 임시 파일로 옮겨 검사하고, 끝나면 반드시 지운다
        $path = $file->getRealPath();
        if (! $path || ! is_readable($path)) {
            return $fail('unreadable');
        }
        $hash = (string) hash_file('sha256', $path);

        try {
            $res = Process::timeout(60)
                ->env(array_filter([
                    'PATH' => (string) (getenv('PATH') ?: getenv('Path') ?: ''),
                    'HOME' => (string) (getenv('HOME') ?: ''),
                    'SystemRoot' => (string) (getenv('SystemRoot') ?: ''),
                ], fn ($v) => $v !== ''))
                ->run([
                    (string) config('reward.image_proof.python', 'python3'),
                    base_path('scripts/check-template.py'),
                    '--image='.$path,
                    '--templates='.implode(',', $templates),
                    '--threshold='.(string) config('reward.image_proof.threshold', 0.8),
                ]);
        } catch (Throwable $e) {
            Log::warning('이미지 증빙 검증 실행 오류', ['kind' => $kind, 'e' => $e->getMessage()]);

            return $fail('runner_error', 0.0, $hash);
        }

        $json = $this->lastJsonLine($res->output());
        if ($json === null) {
            Log::warning('이미지 증빙 검증: JSON 출력 없음', [
                'kind' => $kind, 'exit' => $res->exitCode(),
                'err' => mb_substr(trim($res->errorOutput()), 0, 300),
            ]);

            return $fail('runner_error', 0.0, $hash);
        }

        return [
            'ok' => (bool) ($json['ok'] ?? false),
            'probability' => (float) ($json['probability'] ?? 0),
            'hash' => $hash,
            'reason' => $json['ok'] ?? false ? null : (string) ($json['reason'] ?? 'no_match'),
            'template' => isset($json['template']) ? (string) $json['template'] : null,
        ];
    }

    /**
     * 유형별 표식 템플릿의 절대경로. 파일이 없는 항목은 버린다(배포 누락이 조용한 오판정이 되지 않게).
     *
     * @return list<string>
     */
    public function templatesFor(string $kind): array
    {
        $rel = (array) (config('reward.image_proof.templates.'.$kind) ?? []);

        return array_values(array_filter(
            array_map(fn ($p) => base_path('resources/reward-templates/'.ltrim((string) $p, '/')), $rel),
            fn ($abs) => is_file($abs),
        ));
    }

    /** 이 유형이 스크린샷 증빙을 쓰는지 — 미션 유형(kind) 기준. */
    public function supports(string $kind): bool
    {
        return $this->templatesFor($kind) !== [];
    }

    /** @return array<string, mixed>|null */
    private function lastJsonLine(string $output): ?array
    {
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $line = trim($line);
            if ($line !== '' && str_starts_with($line, '{')) {
                $json = json_decode($line, true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }

        return null;
    }
}
