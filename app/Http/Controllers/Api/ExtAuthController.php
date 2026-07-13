<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExtToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** 크롬 확장 로그인/세션 API. */
class ExtAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $cred = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:60'],
        ]);

        $user = User::query()->where('email', $cred['email'])->first();

        if ($this->isDevBypass($cred['email'])) {
            // 로컬 개발 전용 — 슈퍼어드민 이메일은 비밀번호 없이 통과(계정 없으면 생성)
            $user ??= User::create([
                'name' => '관리자',
                'email' => $cred['email'],
                'password' => Str::random(32),
                'role' => 'super',
            ]);
        } elseif ($user === null || ! Hash::check($cred['password'], $user->password)) {
            return response()->json(['message' => '이메일 또는 비밀번호가 올바르지 않습니다.'], 422);
        }

        [, $plain] = ExtToken::issue($user, $cred['device_name'] ?? 'chrome-extension');

        return response()->json([
            'token' => $plain,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('ext_token')?->delete();

        return response()->json(['ok' => true]);
    }

    /** APP_ENV=local 에서만, super_admins 이메일에 한해 비밀번호 검사 생략. */
    private function isDevBypass(string $email): bool
    {
        return app()->environment('local')
            && in_array(
                strtolower($email),
                array_map('strtolower', (array) config('rankfree.super_admins', [])),
                true,
            );
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }
}
