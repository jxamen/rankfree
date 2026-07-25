<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\CwsPublisher;
use Illuminate\Http\Request;

/**
 * 크롬 확장 웹스토어 게시(admin) — 환경설정 integ 탭 [웹스토어에 게시] 버튼.
 * 항목 ID(환경설정) + 구글 OAuth(chromewebstore scope)로 서버에서 업로드·심사제출한다.
 */
class ExtensionPublishController extends Controller
{
    public function publish(Request $request)
    {
        $target = $request->input('target') === 'testers' ? 'trustedTesters' : 'default';
        $uploadOnly = $request->boolean('upload_only');

        $res = CwsPublisher::publish(! $uploadOnly, $target);

        $back = redirect()->route('admin.settings', ['tab' => 'integ']);

        return $res['ok']
            ? $back->with('status', '확장 게시 — '.$res['message'])
            : $back->withErrors(['extension' => $res['message']]);
    }
}
