<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** API 키 관리 — 콘솔 셀프서비스 (발급·허용기간·일일 한도·허용 IP·활성 토글·삭제). */
class ApiKeyController extends Controller
{
    public function index(Request $request)
    {
        return view('console.api-keys', [
            'keys' => $request->user()->apiKeys()->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['in:'.implode(',', array_keys(ApiKey::SCOPES))],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'allowed_ips' => ['nullable', 'string', 'max:2000'],
        ]);

        [$key, $plain] = ApiKey::issue(
            $request->user(),
            $data['name'],
            $data['scopes'],
            isset($data['expires_at']) && $data['expires_at'] ? Carbon::parse($data['expires_at'])->endOfDay() : null,
            $data['daily_limit'] ?? null,
            $data['allowed_ips'] ?? null,
        );

        return back()
            ->with('newApiKey', $plain)
            ->with('status', "API 키 '{$key->name}' 발급 완료. 아래 키는 지금 한 번만 표시되니 안전한 곳에 보관하세요.");
    }

    public function toggle(Request $request, ApiKey $key)
    {
        abort_unless($key->user_id === $request->user()->id, 403);
        $key->update(['is_active' => ! $key->is_active]);

        return back()->with('status', "'{$key->name}' 키를 ".($key->is_active ? '활성화' : '비활성화')."했습니다.");
    }

    /** 키 원문 재발급(회전) — 구 키(원문 미보관)를 복사 가능하게 만들 때 사용. 기존 키는 즉시 무효화. */
    public function regenerate(Request $request, ApiKey $key)
    {
        abort_unless($key->user_id === $request->user()->id, 403);
        $plain = $key->rotate();

        return back()
            ->with('newApiKey', $plain)
            ->with('status', "'{$key->name}' 키를 재발급했습니다. 이전 키는 무효화되며, 아래 새 키를 복사해 교체하세요.");
    }

    public function destroy(Request $request, ApiKey $key)
    {
        abort_unless($key->user_id === $request->user()->id, 403);
        $key->delete();

        return back()->with('status', "'{$key->name}' 키를 삭제했습니다.");
    }
}
