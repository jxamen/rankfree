<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExtToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

        if ($user === null || ! Hash::check($cred['password'], $user->password)) {
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
