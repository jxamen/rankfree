<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\GoogleToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 구글 계정 OAuth 연동(admin) — 서치 콘솔·GA4 데이터 조회용 refresh token 발급·저장.
 * 소셜 로그인과 같은 OAuth 클라이언트를 재사용한다(리디렉션 URI 만 추가 등록 필요).
 */
class GoogleConnectController extends Controller
{
    private const SCOPES = [
        'openid', 'email',
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/analytics.readonly',
    ];

    /** 구글 동의 화면으로 리디렉션. */
    public function redirect(Request $request)
    {
        $clientId = (string) config('services.google.client_id');
        if ($clientId === '') {
            return redirect()->route('admin.settings', ['tab' => 'integ'])->withErrors(['google' => '구글 클라이언트 ID가 없습니다 — 외부 연동 탭에서 소셜 로그인 키를 먼저 등록하세요.']);
        }

        $state = Str::random(32);
        session(['google_connect_state' => $state]);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => route('admin.google-connect.callback'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]));
    }

    /** 콜백 — code → refresh token 교환·저장. */
    public function callback(Request $request)
    {
        if ($request->query('error')) {
            return redirect()->route('admin.settings', ['tab' => 'integ'])->withErrors(['google' => '구글 연동이 취소되었습니다: '.$request->query('error')]);
        }
        if ((string) $request->query('state') !== (string) session('google_connect_state')) {
            return redirect()->route('admin.settings', ['tab' => 'integ'])->withErrors(['google' => '연동 요청 검증에 실패했습니다. 다시 시도하세요.']);
        }
        session()->forget('google_connect_state');

        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => (string) config('services.google.client_id'),
            'client_secret' => (string) config('services.google.client_secret'),
            'code' => (string) $request->query('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => route('admin.google-connect.callback'),
        ]);
        if (! $res->successful() || ! $res->json('refresh_token')) {
            $hint = $res->json('refresh_token') === null && $res->successful()
                ? 'refresh token 이 발급되지 않았습니다 — 구글 계정 보안 페이지에서 이 앱 액세스 권한을 삭제 후 다시 연동하세요.'
                : mb_substr((string) $res->body(), 0, 200);

            return redirect()->route('admin.settings', ['tab' => 'integ'])->withErrors(['google' => '토큰 교환 실패: '.$hint]);
        }

        // 연동 계정 이메일 — id_token 페이로드에서 추출(구글이 TLS 로 직접 발급한 값)
        $email = '';
        if ($idToken = (string) $res->json('id_token')) {
            $payload = json_decode(base64_decode(strtr(explode('.', $idToken)[1] ?? '', '-_', '+/')), true);
            $email = (string) ($payload['email'] ?? '');
        }

        AppSetting::write(GoogleToken::KEY_REFRESH, (string) $res->json('refresh_token'));
        AppSetting::write(GoogleToken::KEY_EMAIL, $email);
        AppSetting::write(GoogleToken::KEY_SCOPES, (string) $res->json('scope', implode(' ', self::SCOPES)));
        \Illuminate\Support\Facades\Cache::forget('google-oauth-access');

        return redirect()->route('admin.settings', ['tab' => 'integ'])->with('status', '구글 계정 연동 완료'.($email ? " — {$email}" : '').'. 서치 콘솔·GA4 수집이 이 계정 권한으로 동작합니다.');
    }

    /** 연동 해제. */
    public function disconnect()
    {
        GoogleToken::disconnect();

        return back()->with('status', '구글 계정 연동을 해제했습니다.');
    }
}
