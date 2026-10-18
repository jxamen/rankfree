# 선충전 테스트 대조

기준 `4f1db2d`와 구현 작업본을 같은 로컬 PHP/SQLite 환경에서 비교했다. 테스트 전용 APP_KEY 및 동일한 기존 빌드 자산을 사용했다. 비밀 설정이나 운영 DB를 사용하지 않았다.

| 범위 | 원본 | 구현 | 신규 실패 |
|---|---|---|---|
| 전체 (조회 간 대기 0ms) | 816개 중 784 통과, 32 실패 | 823개 중 791 통과, 동일 32 실패 | 0 |
| ShopKeywordExposureTest 제외 전체 | 789개 중 757 통과, 32 실패 | 796개 중 764 통과, 32 실패 | 0 |
| 주문·쿠폰·결제 관련 | 76개 중 75 통과, 기존 1 실패 | 83개 중 82 통과, 동일 1 실패 | 0 |
| 선충전 새 테스트 | — | 7/7 통과, 69 assertions | 0 |

전체 기본 설정 실행은 원본에서도 ShopKeywordExposureTest의 test_regenerate_hides_nonexposed_and_adds_new에서 300초 제한으로 중단됐다. 따라서 해당 클래스 27개를 양쪽에서 동일하게 제외한 대조를 먼저 수행했다. 같은 `SHOP_EXPOSURE_FETCH_DELAY_MS=0`을 양쪽에 적용한 전체 실행에서 기존 실패 32개가 정확히 일치했고, 신규 실패는 없었다. 실제 HTTP는 기존 테스트 fake를 사용하며 제품 코드는 변경하지 않았다. 전체 대조에서 새 테스트 7개와 assertion 69개가 증가했다.

재현: 테스트 전용 APP_KEY를 설정하고 `SHOP_EXPOSURE_FETCH_DELAY_MS=0 php -d memory_limit=1G vendor/bin/phpunit`. 기본 PHP 메모리 128MB로는 원본에서도 전체 테스트가 메모리 부족으로 중단되어 1GB로 동일하게 맞췄다.

## 동일하게 남은 기존 실패 32개

- `Tests\Feature\AdminMemberSubscriptionTest::test_member_search_filters`
- `Tests\Feature\CanonicalHostRedirectTest::test_preserves_path_and_query`
- `Tests\Feature\CmsFeatureTest::test_console_faq_search`
- `Tests\Feature\KeywordBrowseTest::test_invalid_region_selection_is_dropped`
- `Tests\Feature\KeywordBrowseTest::test_place_region_selects_are_three_level`
- `Tests\Feature\KeywordHubPhase3Test::test_share_page_shows_stored_ai_insight`
- `Tests\Feature\KeywordHubTest::test_published_category_cards_open_document_lists_with_analysis_links`
- `Tests\Feature\KeywordInsightTest::test_keyword_share_page_has_aeo_faq_breadcrumb_and_cta`
- `Tests\Feature\KeywordInsightTest::test_place_region_drilldown`
- `Tests\Feature\KeywordInsightTest::test_search_results_policy_and_privacy`
- `Tests\Feature\KeywordInsightTest::test_suggest_api`
- `Tests\Feature\OrderHiddenFieldAutofillTest::test_admin_internal_fields_card_save_and_refill_routes`
- `Tests\Feature\RankTrackFixesTest::test_store_accepts_long_tracking_url_with_multiple_keywords`
- `Tests\Feature\RankTrackTest::test_keyword_filter_narrows_slots`
- `Tests\Feature\RankTrackTest::test_shared_report_is_public`
- `Tests\Feature\RankTrackTest::test_store_accepts_long_naver_place_url`
- `Tests\Feature\RelatedDocsTest::test_same_type_section_falls_back_to_recent_docs`
- `Tests\Feature\RelatedDocsTest::test_store_share_page_recommends_related_docs_across_types`
- `Tests\Feature\RelatedDocsTest::test_tracking_slots_are_never_recommended`
- `Tests\Feature\ScheduleOverviewTest::test_lists_scheduled_jobs_with_korean_frequency`
- `Tests\Feature\ScheduledCommandsTest::test_schedule_registers_new_commands`
- `Tests\Feature\SellerInfoListTest::test_search_filters_by_company_or_representative`
- `Tests\Feature\SellerPowerShareTest::test_public_share_opens_without_login`
- `Tests\Feature\SeoPingTest::test_after_hub_publish_pings_doc_and_hub_urls_and_bumps_sitemap`
- `Tests\Feature\SeoPingTest::test_ping_indexnow_submits_encoded_urls`
- `Tests\Feature\SeoTest::test_share_report_is_noindex_with_og`
- `Tests\Feature\ShareButtonRenderTest::test_market_public_page_opens_without_login`
- `Tests\Feature\ShareButtonRenderTest::test_product_public_page_opens_without_login`
- `Tests\Feature\ShareButtonRenderTest::test_public_analysis_report_is_indexable_with_structured_data`
- `Tests\Feature\ShopProductListTest::test_shows_recent_seller_captcha_questions_with_image_links`
- `Tests\Feature\ShopRankTrackTest::test_store_creates_slots_and_runs_first_check`
- `Tests\Feature\ShopSerpTalkTest::test_talk_id_is_saved_and_rendered`


## PR #15 검수 보완 재검증 (2026-10-18)

동일 테스트 전용 APP_KEY, SQLite, memory_limit=1G, SHOP_EXPOSURE_FETCH_DELAY_MS=0으로 기준 4f1db2d 전체를 재실행했다: 816개 / 784 통과 / 32 실패. 수정본 전체는 833개 / 801 통과 / 같은 32 실패(12,956 assertions). 실패 테스트 이름 집합을 자동 비교하여 신규 실패 0, 기존 실패 해소 0을 확인했다.

외부 검수 원본 `/tmp/rk15rev-runtime/PrepaidReviewTest.php`도 수정 없이 7개 모두 통과(53 assertions). 저장소에 추가한 검수·회귀 10개는 전체 실행에서 모두 통과했으며 발주 직전 취소, 발주 중 취소/상태 초기화/환원/삭제 차단, 예약 서비스 직접 호출, API 조회·목록·필터를 검증한다. 업체 HTTP는 fake하고 운영 DB에 접근하지 않았다. 실제 MySQL 병렬 실행은 수행하지 않았다.

상세 비교: `docs/evidence/16rk-prepaid-f1/test-comparison.json`. 보호 대상 8개 파일 SHA-256 및 시작 커밋 대비 동일 확인: `docs/evidence/16rk-prepaid-f1/protected-files.json`.
