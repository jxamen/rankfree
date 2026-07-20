<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopFilterHtmlParser;
use Tests\TestCase;

class ShopFilterHtmlParserTest extends TestCase
{
    private function parser(): ShopFilterHtmlParser
    {
        return new ShopFilterHtmlParser();
    }

    public function test_parses_brand_filter(): void
    {
        $html = '<ul class="basicTypeFilter_finder_tit_list__Ufmtp">'
            .'<li data-shp-contents-id="닥터린" data-shp-contents-type="브랜드" data-shp-contents-rank="1"><button><span>닥터린</span></button></li>'
            .'<li data-shp-contents-id="종근당" data-shp-contents-type="브랜드" data-shp-contents-rank="3"><button><span>종근당</span></button></li>'
            .'</ul>';
        $r = $this->parser()->parse($html);
        $this->assertContains('닥터린', $r['brands']);
        $this->assertContains('종근당', $r['brands']);
    }

    public function test_keyword_rec_uses_filter_value_id_not_contents_id(): void
    {
        // contents-id 는 "3000" 조각이지만 실제 검색어는 filter_value_id "비타민c3000"
        $html = '<ul class="basicTypeFilter_finder_list__u4KSV">'
            .'<li data-shp-contents-id="3000" data-shp-contents-type="키워드추천" '
            .'data-shp-contents-dtl="[{&quot;key&quot;:&quot;filter_value_id&quot;,&quot;value&quot;:&quot;비타민c3000&quot;}]">'
            .'<button><span>3000</span></button></li></ul>';
        $r = $this->parser()->parse($html);
        $this->assertContains('비타민c3000', $r['keyword_recs']);
        $this->assertNotContains('3000', $r['keyword_recs']);
    }

    public function test_parses_attribute_filters(): void
    {
        $html = '<div class="product_detail_box__hGnDU">'
            .'<a data-shp-contents-id="1개월분" data-shp-contents-type="제품용량_M(속성)">제품용량 : 1개월분</a>'
            .'<a data-shp-contents-id="항산화" data-shp-contents-type="주요 기능성_M(속성)">주요 기능성 : 항산화</a>'
            .'</div>';
        $r = $this->parser()->parse($html);
        $this->assertContains('1개월분', $r['attributes']);
        $this->assertContains('항산화', $r['attributes']);
    }

    public function test_empty_and_garbage_html_is_safe(): void
    {
        $this->assertSame(['brands' => [], 'keyword_recs' => [], 'attributes' => []], $this->parser()->parse(null));
        $this->assertSame(['brands' => [], 'keyword_recs' => [], 'attributes' => []], $this->parser()->parse('<div>그냥 텍스트'));
    }
}
