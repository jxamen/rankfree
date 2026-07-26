<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 회원별 API 기능 권한(2026-07-26) — 회원이 키를 발급할 때 아무 기능이나 쓰지 못하게,
        // 관리자가 허용한 scope 만 발급·사용할 수 있다. null/[] = 허용 없음(슈퍼관리자는 예외로 전체 허용).
        Schema::table('users', function (Blueprint $table) {
            $table->text('api_scopes')->nullable()->after('role');
        });

        // 무중단 이관 — 이미 키를 쓰고 있는 회원에게는 그 키들의 scope 합집합을 그대로 허용으로 넣어준다.
        // (이 단계가 없으면 배포 순간 기존 외부 연동이 전부 403 이 된다)
        $rows = DB::table('api_keys')->select('user_id', 'scopes')->get();
        $byUser = [];
        foreach ($rows as $row) {
            $scopes = json_decode((string) $row->scopes, true);
            if (! is_array($scopes)) {
                continue;
            }
            $byUser[$row->user_id] = array_values(array_unique(array_merge($byUser[$row->user_id] ?? [], $scopes)));
        }
        foreach ($byUser as $userId => $scopes) {
            DB::table('users')->where('id', $userId)->update(['api_scopes' => json_encode(array_values($scopes))]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('api_scopes');
        });
    }
};
