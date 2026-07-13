@extends('admin.layout')
@section('page-title', $title ?? '관리자')

@section('admin-content')
<div class="card text-center" style="padding:64px 24px;max-width:600px;">
    <div style="font-size:var(--fs-2xl);opacity:.4;">🚧</div>
    <h2 class="mt-3 text-ink font-semibold" style="font-size:var(--fs-md);">{{ $title ?? '관리자' }}</h2>
    <p class="mt-2 text-muted" style="font-size:var(--fs-xs);">준비 중인 화면입니다. 곧 제공됩니다.</p>
</div>
@endsection
