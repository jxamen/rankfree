<?php

namespace App\Http\Controllers;

use App\Domain\Seo\RelatedDocsService;
use App\Models\SellerPowerAnalysis;
use Illuminate\Http\Request;

/** 셀러력 — 쇼핑 상품 SEO·지수 경쟁 비교 내역(콘솔) + 공개 공유 리포트. */
class SellerPowerController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        return view('console.seller-power.index', [
            'analyses' => $request->user()->sellerPowerAnalyses()
                ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('keyword', 'like', "%{$q}%")->orWhere('product_name', 'like', "%{$q}%")))
                ->latest('updated_at')->paginate(24)->withQueryString(),
            'q' => $q,
        ]);
    }

    public function show(Request $request, SellerPowerAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return view('console.seller-power.show', [
            'a' => $analysis,
            'r' => $this->remapRx((array) $analysis->snapshot),
            'shareUrl' => $analysis->shareUrl(),
            'public' => false,
        ]);
    }

    /** 공개 공유 리포트 — 공유 토큰으로 비로그인 열람. */
    public function shared(string $slug, RelatedDocsService $related)
    {
        $a = SellerPowerAnalysis::findByShareKey($slug);
        abort_if(! $a, 404);

        return view('seller-power.share', [
            'a' => $a,
            'r' => $this->remapRx((array) $a->snapshot),
            'related' => $related->sectionsFor($a, array_filter([$a->keyword])),
        ]);
    }

    public function destroy(Request $request, SellerPowerAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('console.seller-power')->with('status', '셀러력 분석 내역을 삭제했습니다.');
    }

    /**
     * 처방(rx) 표시 보정 — 포인트 지급/지급액을 '마케팅·판매자' 그룹으로 이동(기존 저장분 호환).
     * 스코어러는 이미 마케팅으로 분류하지만, 그 이전 스냅샷은 '기본·배송'에 있어 표시 시점에 재배치한다.
     */
    private function remapRx(array $r): array
    {
        $rx = (array) ($r['rx'] ?? []);
        if (! $rx) {
            return $r;
        }
        $moveNames = ['포인트 지급', '포인트 지급액'];
        $moved = [];

        foreach ($rx as &$g) {
            if (($g['axis'] ?? '') === '마케팅·판매자') {
                continue;
            }
            $keep = [];
            foreach ((array) ($g['items'] ?? []) as $it) {
                if (in_array($it['name'] ?? '', $moveNames, true)) {
                    $moved[] = $it;
                } else {
                    $keep[] = $it;
                }
            }
            $g['items'] = $keep;
        }
        unset($g);

        if ($moved) {
            $placed = false;
            foreach ($rx as &$g) {
                if (($g['axis'] ?? '') === '마케팅·판매자') {
                    $g['items'] = array_merge((array) ($g['items'] ?? []), $moved);
                    $placed = true;
                    break;
                }
            }
            unset($g);
            if (! $placed) {
                $rx[] = ['axis' => '마케팅·판매자', 'items' => $moved];
            }
        }

        $r['rx'] = array_values(array_filter($rx, fn ($g) => ! empty($g['items'])));

        return $r;
    }
}
