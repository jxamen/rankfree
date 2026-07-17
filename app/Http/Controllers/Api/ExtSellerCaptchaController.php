<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopSellerCaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExtSellerCaptchaController extends Controller
{
    private const MAX_IMAGE_BYTES = 2_000_000;

    public function store(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 3_000_000) {
            return response()->json(['ok' => false, 'message' => 'Payload is too large.'], 413);
        }

        $data = $request->validate([
            'store_id' => ['nullable', 'string', 'max:100'],
            'channel_uid' => ['required', 'string', 'max:120'],
            'channel_id' => ['nullable', 'string', 'max:120'],
            'captcha_key' => ['nullable', 'string', 'max:120'],
            'seller_info_type' => ['nullable', 'string', 'max:40'],
            'question' => ['nullable', 'string', 'max:500'],
            'image_data' => ['required', 'string'],
            'seller_info_url' => ['nullable', 'string', 'max:2000'],
            'prev_url' => ['nullable', 'string', 'max:2000'],
        ]);

        [$binary, $mime] = $this->decodeImage((string) $data['image_data']);
        if ($binary === null) {
            return response()->json(['ok' => false, 'message' => 'Invalid image data.'], 422);
        }

        if (strlen($binary) > self::MAX_IMAGE_BYTES) {
            return response()->json(['ok' => false, 'message' => 'Image is too large.'], 422);
        }

        $ext = $this->extensionFor($mime);
        if ($ext === null) {
            return response()->json(['ok' => false, 'message' => 'Unsupported image type.'], 422);
        }

        $channelUid = trim((string) $data['channel_uid']);
        $captchaKey = trim((string) ($data['captcha_key'] ?? ''));
        if ($captchaKey === '') {
            $captchaKey = 'img_'.substr(sha1($binary), 0, 20);
        }

        $dir = 'seller-captchas/'.$this->cleanPathPart($channelUid);
        $path = $dir.'/'.$this->cleanPathPart($captchaKey).'.'.$ext;

        Storage::disk('local')->put($path, $binary);

        $row = ShopSellerCaptcha::updateOrCreate(
            ['channel_uid' => $channelUid, 'captcha_key' => $captchaKey],
            [
                'user_id' => $request->user()?->id,
                'store_id' => $this->nullableTrim($data['store_id'] ?? null),
                'channel_id' => $this->nullableTrim($data['channel_id'] ?? null),
                'seller_info_type' => $this->nullableTrim($data['seller_info_type'] ?? null) ?: 'profile',
                'question' => $this->nullableTrim($data['question'] ?? null),
                'image_disk' => 'local',
                'image_path' => $path,
                'image_mime' => $mime,
                'image_bytes' => strlen($binary),
                'seller_info_url' => $this->nullableTrim($data['seller_info_url'] ?? null),
                'prev_url' => $this->nullableTrim($data['prev_url'] ?? null),
                'captured_at' => now(),
            ],
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'id' => $row->id,
                'store_id' => $row->store_id,
                'channel_uid' => $row->channel_uid,
                'captcha_key' => $row->captcha_key,
                'question' => $row->question,
                'path' => $row->image_path,
                'image_url' => route('admin.shop-products.seller-captchas.image', $row),
                'absolute_path' => Storage::disk('local')->path($row->image_path),
                'captured_at' => $row->captured_at?->toDateTimeString(),
            ],
        ]);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function decodeImage(string $imageData): array
    {
        $mime = null;
        $payload = $imageData;

        if (preg_match('/^data:(image\/[-+.a-zA-Z0-9]+);base64,(.+)$/s', $imageData, $match)) {
            $mime = strtolower($match[1]);
            $payload = $match[2];
        }

        $binary = base64_decode($payload, true);
        if ($binary === false || $binary === '') {
            return [null, null];
        }

        $detected = $this->detectMime($binary);
        if ($detected !== null) {
            $mime = $detected;
        }

        return [$binary, $mime];
    }

    private function detectMime(string $binary): ?string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($binary);
            if (is_string($mime) && str_starts_with($mime, 'image/')) {
                return strtolower($mime);
            }
        }

        return match (true) {
            str_starts_with($binary, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($binary, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($binary, 'GIF87a'), str_starts_with($binary, 'GIF89a') => 'image/gif',
            str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    private function extensionFor(?string $mime): ?string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg', 'image/pjpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    private function cleanPathPart(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value) ?: 'unknown';

        return trim($clean, '_') ?: 'unknown';
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
