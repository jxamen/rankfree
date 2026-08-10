<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;

/**
 * Chrome 웹스토어 게시 — 관리자 화면 [웹스토어에 게시] 버튼이 호출한다.
 * 항목 ID(extension.chrome_id)와 구글 OAuth 연동(GoogleToken, chromewebstore scope)을 써서
 * extension/ 을 zip 으로 묶어 업로드하고 심사에 제출한다.
 * 자격증명은 관리자 환경설정(AppSetting, 암호화)에서만 조달 — .env 별도 CWS_* 불필요.
 */
class CwsPublisher
{
    public const SCOPE = 'https://www.googleapis.com/auth/chromewebstore';

    private const API = 'https://www.googleapis.com';

    private const ID_KEY = 'extension.chrome_id';

    /** 확장 항목 ID(32자). */
    public static function extensionId(): string
    {
        return trim((string) AppSetting::read(self::ID_KEY));
    }

    private const PUBLIC_DIR = 'extension';

    /**
     * 게시 패키지에 다시 들어오면 안 되는 심볼 — **캡차 자동 풀이**(2026-08-10 제거).
     * 캡차 이미지를 서버로 보내 정답을 받아 자동 제출하는 동작은 봇 방지 우회라
     * 심사에서 항목 삭제까지 갈 수 있다. 실수로 되살아나면 게시를 막는다.
     */
    private const FORBIDDEN = ['solveQuiz', 'sellerCaptcha'];

    /** manifest 버전. */
    public static function version(): string
    {
        $manifest = json_decode((string) @file_get_contents(base_path(self::PUBLIC_DIR.'/manifest.json')), true);

        return (string) ($manifest['version'] ?? '0');
    }

    /** UI 상태 — 게시 준비가 됐는지와 부족한 항목. */
    public static function status(): array
    {
        $id = self::extensionId();
        $connected = GoogleToken::oauthConnected();
        $scoped = GoogleToken::hasScope(self::SCOPE);

        return [
            'extension_id' => $id,
            'has_id' => $id !== '',
            'connected' => $connected,
            'scoped' => $scoped,                     // 웹스토어 게시 권한까지 연동됨
            'ready' => $id !== '' && $scoped,
            'version' => self::version(),
            'zip_ok' => class_exists(\ZipArchive::class),
        ];
    }

    /**
     * 게시 실행 — zip 빌드 → 업로드 → (심사 제출). 결과: [ok, message, version].
     *
     * @param  bool  $submitReview  false 면 업로드까지만(대시보드에서 수동 제출)
     * @param  string  $target  'default'(전체) | 'trustedTesters'
     */
    public static function publish(bool $submitReview = true, string $target = 'default'): array
    {
        $extId = self::extensionId();
        if ($extId === '') {
            return ['ok' => false, 'message' => '크롬 확장 항목 ID를 먼저 환경설정에 입력하세요.'];
        }
        if (! preg_match('/^[a-p]{32}$/', $extId)) {
            return ['ok' => false, 'message' => '항목 ID 형식이 올바르지 않습니다(32자 a–p). 웹스토어 대시보드의 항목 ID를 확인하세요.'];
        }

        $token = GoogleToken::token(self::SCOPE);
        if (! $token) {
            return ['ok' => false, 'message' => '구글 재연동이 필요합니다 — 아래 "구글 계정으로 연동"을 다시 눌러 웹스토어 게시 권한까지 동의하세요.'];
        }

        [$zipPath, $version, $zipErr] = self::buildZip();
        if ($zipErr) {
            return ['ok' => false, 'message' => $zipErr, 'version' => $version];
        }

        try {
            // 1) 업로드(신 버전 패키지) — PUT 바이너리
            $up = Http::withToken($token)
                ->withHeaders(['x-goog-api-version' => '2'])
                ->withBody(file_get_contents($zipPath), 'application/zip')
                ->timeout(180)
                ->put(self::API.'/upload/chromewebstore/v1.1/items/'.$extId);
            $upj = is_array($up->json()) ? $up->json() : [];

            if (($upj['uploadState'] ?? '') !== 'SUCCESS') {
                $errs = collect($upj['itemError'] ?? [])
                    ->map(fn ($e) => $e['error_detail'] ?? $e['error_code'] ?? '')->filter()->implode('; ');
                return ['ok' => false, 'version' => $version, 'message' => '업로드 실패('.$up->status().'): '
                    .($errs ?: json_encode($upj, JSON_UNESCAPED_UNICODE))
                    .' — 항목 ID·게시 계정 권한, manifest 버전(v'.$version.')이 스토어 최신본보다 높은지 확인하세요.'];
            }

            if (! $submitReview) {
                return ['ok' => true, 'version' => $version, 'message' => 'v'.$version.' 업로드 완료(심사 미제출) — 웹스토어 대시보드에서 검토 후 제출하세요.'];
            }

            // 2) 게시(심사 제출) — POST publish.
            // 본문은 반드시 빈 JSON 객체 '{}' 로 보낸다. Content-Length:0 만 주면 구글이 빈 문자열을
            // JSON 으로 파싱하려다 400 "Root element must be a message" 를 돌려주고, 그 응답엔 status /
            // statusDetail 이 없어 아래 실패 메시지가 통째로 비어 나온다(진짜 원인이 가려짐 — 2026-07-29).
            $pub = Http::withToken($token)
                ->withHeaders(['x-goog-api-version' => '2'])
                ->withBody('{}', 'application/json')
                ->timeout(60)
                ->post(self::API.'/chromewebstore/v1.1/items/'.$extId.'/publish?publishTarget='.$target);
            $pj = is_array($pub->json()) ? $pub->json() : [];
            $st = (array) ($pj['status'] ?? []);

            if ($pub->successful() && (in_array('OK', $st, true) || in_array('ITEM_PENDING_REVIEW', $st, true))) {
                return ['ok' => true, 'version' => $version, 'message' => 'v'.$version.' 게시 요청 접수: '.implode(', ', $st)
                    .($target === 'trustedTesters' ? ' (테스터 대상)' : '').' — 심사 대기(보통 수 시간~며칠) 후 반영됩니다.'];
            }

            // 오류 응답에는 status/statusDetail 이 없고 error.message 에 사유가 온다
            // (예: "Publish condition not met: … Privacy practices tab") — 그대로 노출해야 조치할 수 있다.
            $detail = trim(implode(', ', $st).' '.implode('; ', (array) ($pj['statusDetail'] ?? [])));
            if ($detail === '') {
                $detail = (string) ($pj['error']['message'] ?? mb_substr((string) $pub->body(), 0, 300));
            }

            return ['ok' => false, 'version' => $version, 'message' => '게시 실패('.$pub->status().'): '.$detail];
        } catch (\Throwable $e) {
            return ['ok' => false, 'version' => $version, 'message' => '게시 중 오류: '.$e->getMessage()];
        } finally {
            @unlink($zipPath);
        }
    }

    /** extension/ 을 zip 으로 묶는다(문서 파일 제외). 반환: [zipPath, version, error]. */
    private static function buildZip(): array
    {
        $version = self::version();
        if (! class_exists(\ZipArchive::class)) {
            return [null, $version, '서버에 PHP zip 확장(ext-zip)이 없어 패키지를 만들 수 없습니다.'];
        }
        $extDir = base_path(self::PUBLIC_DIR);
        if (! is_dir($extDir)) {
            return [null, $version, 'extension 디렉터리를 찾을 수 없습니다.'];
        }

        $zipPath = storage_path('app/rankfree-extension-v'.$version.'.zip');
        @unlink($zipPath);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return [null, $version, 'zip 파일 생성에 실패했습니다.'];
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $local = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($extDir))), '/');
            // 확장 패키지에 불필요한 개발 문서 제외(웹스토어 심사 잡음 방지)
            if (preg_match('#(^|/)\w+\.md$#i', $local)) {
                continue;
            }
            // 마지막 방어선 — 캡차 자동 풀이가 되살아나면 게시하지 않는다(2026-08-10 제거).
            if (str_ends_with($local, '.js')) {
                foreach (self::FORBIDDEN as $needle) {
                    if (str_contains((string) @file_get_contents($file->getPathname()), $needle)) {
                        $zip->close();
                        @unlink($zipPath);

                        return [null, $version, "캡차 자동 풀이 코드가 패키지에 남아 있어 게시를 중단했습니다({$local} → {$needle}). "
                            .'봇 방지 우회는 심사에서 항목 삭제 사유입니다.'];
                    }
                }
            }
            $zip->addFile($file->getPathname(), $local);
        }
        $zip->close();

        if (! is_file($zipPath) || filesize($zipPath) < 1000) {
            return [null, $version, 'zip 패키지가 비정상입니다(내용 없음).'];
        }

        return [$zipPath, $version, null];
    }
}
