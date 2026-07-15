@extends('console.layout')
@section('page-title', '셀러력 진단')
@section('crumb-title', $a->keyword)

@section('page-actions')
    <div class="flex items-center gap-2">
        <button type="button" class="btn btn-secondary btn-sm" onclick="spSaveImage(this)" title="리포트를 화면 그대로 PNG 이미지로 저장">🖼 이미지 저장</button>
        <button type="button" class="btn btn-secondary btn-sm" onclick="rfCopyShare(this, @js($shareUrl ?? ''))" title="비로그인 공개 공유 링크 복사">🔗 공유</button>
        <form method="POST" action="{{ route('console.seller-power.destroy', $a) }}" onsubmit="return confirm('이 분석 내역을 삭제할까요?')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-secondary btn-sm">삭제</button>
        </form>
    </div>
@endsection

@section('console-content')
    @include('partials._seller_power_body', ['a' => $a, 'r' => $r])
@endsection
