<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** 커뮤니티 글 첨부파일 — 파일 실체는 비공개 디스크, 다운로드는 라우트로만. */
class CommunityPostAttachment extends Model
{
    /** 첨부 실물이 놓이는 디스크 — 공개 URL 이 없는 곳(storage/app/private). */
    public const DISK = 'local';

    /** 글당 최대 개수 · 개별 최대 용량(KB) · 허용 확장자 — 컨트롤러 검증과 폼 안내가 같이 쓴다. */
    public const MAX_COUNT = 5;

    public const MAX_KB = 20480;   // 20MB

    public const EXTENSIONS = 'jpg,jpeg,png,gif,webp,pdf,zip,txt,csv,xls,xlsx,doc,docx,ppt,pptx,hwp,hwpx';

    /** file input 의 accept 속성값 — `.jpg,.png,…` */
    public static function acceptAttribute(): string
    {
        return '.'.implode(',.', explode(',', self::EXTENSIONS));
    }

    protected $fillable = ['post_id', 'original_name', 'path', 'mime', 'size'];

    protected $casts = ['size' => 'integer', 'download_count' => 'integer'];

    /** 레코드가 지워지면 실제 파일도 함께 지운다(고아 파일 방지). */
    protected static function booted(): void
    {
        static::deleting(function (self $attachment) {
            Storage::disk(self::DISK)->delete($attachment->path);
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(CommunityPost::class, 'post_id');
    }

    /** 목록에 표시할 크기 — 1KB 미만은 그대로 B. */
    public function sizeLabel(): string
    {
        $bytes = (int) $this->size;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024).'KB';
        }

        return $bytes.'B';
    }
}
