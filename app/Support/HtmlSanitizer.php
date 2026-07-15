<?php

namespace App\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * 화이트리스트 기반 HTML 새니타이저 (사용자 작성 리치 텍스트용 XSS 방어).
 * 자체 에디터(contenteditable+execCommand) 산출물을 저장 전에 정리한다.
 *  - 허용 태그/속성만 남기고 나머지는 태그 해제(unwrap) 또는 통째 제거
 *  - script/style/iframe 등 위험 태그 제거, on* 이벤트·style 등 비허용 속성 제거
 *  - a[href]·img[src]는 http(s)/상대경로/앵커만 허용(javascript:, data: 차단)
 */
class HtmlSanitizer
{
    /** 태그 => 허용 속성 목록. */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'h3' => [], 'h4' => [], 'div' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [], 'span' => [],
        'a' => ['href'], 'img' => ['src', 'alt'],
    ];

    /** 내용까지 통째로 버릴 위험 태그. */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'noscript', 'template'];

    /** style 속성에서 허용하는 CSS 속성(값도 형식 검증). */
    private const STYLE_PROPS = ['color', 'background-color', 'font-size', 'font-weight', 'text-align', 'text-decoration'];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // UTF-8 보존 + 단일 루트로 감싸기 (암시적 html/body 태그 생성 방지)
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="__rf_root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('__rf_root');
        if (! $root) {
            return '';
        }
        self::sanitizeChildren($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function sanitizeChildren(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue; // 텍스트 노드는 유지(saveHTML이 이스케이프)
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                // 비허용 태그: 내부 정리 후 자식만 부모로 올리고 태그 제거(unwrap)
                self::sanitizeChildren($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            // 허용 태그: 비허용 속성 제거 + href/src 프로토콜 검증
            $allowedAttrs = self::ALLOWED[$tag];
            foreach (iterator_to_array($child->attributes) as $attr) {
                $an = strtolower($attr->name);
                if ($an === 'style') {   // 인라인 스타일 — 허용 속성만 남기고 값 형식 검증
                    $filtered = self::filterStyle($attr->value);
                    if ($filtered === '') {
                        $child->removeAttribute('style');
                    } else {
                        $child->setAttribute('style', $filtered);
                    }

                    continue;
                }
                if (! in_array($an, $allowedAttrs, true)) {
                    $child->removeAttribute($attr->name);

                    continue;
                }
                if (($an === 'href' || $an === 'src') && ! self::safeUrl($attr->value)) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a' && $child->getAttribute('href') !== '') {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener nofollow ugc');
            }

            self::sanitizeChildren($child);
        }
    }

    /** 인라인 style — 허용 속성만, 값 형식 검증(url()/expression/javascript 등 차단). */
    private static function filterStyle(string $style): string
    {
        $safe = [];
        foreach (explode(';', $style) as $decl) {
            $decl = trim($decl);
            if ($decl === '' || ! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = array_map('trim', explode(':', $decl, 2));
            $prop = strtolower($prop);
            if (! in_array($prop, self::STYLE_PROPS, true)) {
                continue;
            }
            // 위험 패턴 차단
            if (preg_match('#url\(|expression|javascript:|/\*|[<>\\\\]#i', $val)) {
                continue;
            }
            $ok = match ($prop) {
                'color', 'background-color' => (bool) preg_match('/^(#[0-9a-f]{3,8}|rgba?\([\d\s,.%]+\)|[a-z]+)$/i', $val),
                'font-size' => (bool) preg_match('/^\d{1,3}(\.\d+)?(px|em|rem|pt|%)$/i', $val),
                'font-weight' => (bool) preg_match('/^(normal|bold|[1-9]00)$/i', $val),
                'text-align' => in_array(strtolower($val), ['left', 'center', 'right', 'justify'], true),
                'text-decoration' => (bool) preg_match('/^(none|underline|line-through)$/i', $val),
                default => false,
            };
            if ($ok) {
                $safe[] = $prop.': '.$val;
            }
        }

        return implode('; ', $safe);
    }

    /** http(s)://, 프로토콜상대(//), 루트상대(/), 앵커(#)만 허용. javascript:/data: 등 차단. */
    private static function safeUrl(string $url): bool
    {
        $url = trim($url);

        return $url !== '' && (
            (bool) preg_match('#^https?://#i', $url)
            || str_starts_with($url, '//')
            || str_starts_with($url, '/')
            || str_starts_with($url, '#')
        );
    }
}
