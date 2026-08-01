# design-05. 미션 타입 체계 (설계만 — 미구현)

> 2026-08-01 지시: "미션 내용은 플레이스 방문, 저장, 쇼핑 등등 **미션 타입에 따라 다 다름**.
> **동일 미션이라도 로직이 다른 경우**도 있음." → 타입을 확정하기 전에 **구조만 먼저 잡아둔다.**
> 이 문서는 설계 기준이고, 구현 착수 시점은 미정이다. 현재 구현은 **쇼핑·해시태그 한 종**뿐이다.

관련: [design-01-schema.md](./design-01-schema.md) · [design-04-vendor-api.md](./design-04-vendor-api.md) §2-0 ·
[API-miniapp-integration.md](./API-miniapp-integration.md)

---

## 1. 왜 타입이 필요한가

지금은 미션이 한 종류라 안내 문구와 채점이 코드 한 갈래로 흐른다. 그런데 실제 운영되는 미션은
**수행 절차 · 정답 소스 · 화면에 필요한 정보**가 전부 다르다. 기존 서비스(boosting_shop `quiz`)를
조사한 결과가 근거다 — "쇼핑 미션" 하나 안에도 서로 다른 로직이 공존한다:

| 계열 | 타입(예시) | 참여자가 하는 일 | 정답 소스 |
|---|---|---|---|
| 쇼핑 | 해시태그 | 검색 유입 → 상품 상세 → 관련 태그 확인 | **태그 N번째**(참여자별 랜덤) |
| 쇼핑 | 상품번호 | 검색 유입 → 상세정보 → 구매 추가정보 | 상품번호 **앞 5자리** |
| 쇼핑 | 가격비교 | 가격비교 → 판매처에서 스토어·판매가 일치 상품 | 상품번호 앞 5자리 |
| 쇼핑 | 찜하기 | 상품 찜 → 화면 캡처 업로드 | **이미지 판정**(AI) |
| 자사몰 | 유입 | 검색 유입 → 상품/고객센터 | 상품 URL 또는 전화번호 |
| 플레이스 | 방문·저장 | 플레이스 유입 → 저장·길찾기·리뷰 등 | 캡처 또는 별도 확인 |

**"동일 미션 다른 로직"의 정체**: 같은 쇼핑 유입이어도 검색 진입 방식(통합검색 / 가격비교 / 플러스스토어),
키워드 종류(메인 / 조합), 정답 소스(태그 / 상품번호)가 조합으로 갈린다. 타입 하나에 분기를 넣으면
금세 감당이 안 된다 → **타입 + variant** 2단으로 나눈다.

---

## 2. 구조 — 타입 · variant · 능력(capability)

```
kind          = 대분류      shopping | place | mall | web
variant       = 세부 로직    shopping.tag | shopping.product_no5 | place.save | …
capability    = 그 variant 가 요구하는 것들(정답 소스·필요 데이터·응답 필드·검증 방식)
```

**capability 정의(코드 상수 또는 config)**

| 항목 | 뜻 | 예 |
|---|---|---|
| `answer_source` | 무엇으로 채점하나 | `tag` · `product_no5` · `price` · `phone` · `url` · `image` |
| `requires` | 미션이 성립하려면 있어야 하는 데이터 | 태그형=`tags`, 상품번호형=`channel_product_id`, 플레이스=`place_id` |
| `copy_kind` | 안내 문구 세트 키(MissionCopy) | `shopping_tag` · `shopping_product_no5` … |
| `payload` | 응답에 실을 추가 필드 | 쇼핑=상품 사진·상품명·판매가, 플레이스=업체명·주소 |
| `verify` | 채점 방식 | `exact`(정규화 비교) · `numeric`(오차 허용) · `async`(이미지 판정) |

> `answer_source` 는 **이미 config 로 구현돼 있다**(`reward.answer_sources`, 어드민 리워드 설정).
> 타입 도입 시 "전역 기본값"에서 "variant 의 기본값"으로 승격하면 된다 — 미션별 `answer_type` 우선순위는 그대로.

---

## 3. 스키마 영향

**기존 컬럼 재사용**
- `reward_missions.kind` — 지금 `external` 고정. 여기에 **대분류**를 넣는다.
- `reward_missions.answer_type` — variant 기본값이 채워지고, 운영자가 미션별로 덮을 수 있다.
- `reward_missions.tags` / `answer` / `tolerance_percent` — 정답 소스별로 쓰이는 칸이 갈린다.

**추가가 필요한 것**
| 컬럼 | 이유 |
|---|---|
| `variant` (string 32) | 세부 로직 식별자. `kind` 만으로는 부족 |
| `channel_product_id` (string 40) | **상품번호형의 전제** — 지금 미션에 채널 상품번호가 없어 구현 불가 |
| `place_id` 등 플레이스 식별자 | 플레이스 계열 도입 시 |
| `proof_*` (이미지 판정용) | 찜하기처럼 캡처를 받는 타입. 업로드 저장·판정 상태·재시도가 딸린다 |

> ⚠️ **이미지 판정형(찜하기)은 비용이 다르다.** 참여 확정이 동기 응답이 아니라 **비동기 판정**이 되고,
> 업로드 저장소·AI 호출 비용·재판정 큐가 붙는다. 다른 타입과 같은 트랜잭션 모델을 쓸 수 없으므로
> **별도 Phase 로 다룬다**(현재 참여 확정은 제출 시점 원자 트랜잭션 전제 — design-01 §2).

---

## 4. 안내 문구와의 관계

문구 세트(`MissionCopy`)는 이미 **kind 별로 나뉘어 있고 어드민에서 편집**된다(`reward.copy.{kind}.*`).
타입이 늘면 세트가 늘 뿐이라 구조 변경은 없다:

```
reward.copy.shopping_tag.*          ← 구현됨
reward.copy.shopping_product_no5.*  ← 타입 추가 시
reward.copy.place_save.*            ← 〃
```

어드민 [리워드 설정](../../app/Http/Controllers/Admin/RewardSettingsController.php) 의 `KINDS` 상수에
한 줄 추가하면 화면에 탭이 늘어난다. **문구 변경에 배포가 필요 없다는 원칙은 유지**된다.

치환 변수도 타입별로 달라진다 — 쇼핑은 `{shop_name}{product_title}{price}`, 플레이스는 `{place_name}{address}`.
`MissionCopy::vars()` 를 variant 별로 분기하되, **없는 변수는 빈 문자열로 지우는 현재 동작**이 있어
문구가 깨지지는 않는다.

---

## 5. 배분(§design-04 2-1)과의 연결

2026-08-01 지시 — "벤더1에 **어떤 미션을 어떤 비율로** 지정할지" 설정 가능해야 한다.
타입 체계가 여기에 직접 걸린다: 배분 단위가 **미션 개별**이 아니라 보통 **타입/variant 단위**이기 때문이다
(예: "벤더A 는 쇼핑 태그형만 60%, 플레이스는 안 줌").

그래서 **타입 확정이 배분 구현보다 먼저**다. 배분 테이블은 타입을 참조하게 설계한다:

```
reward_media_allocations(media_id, target_type[mission|kind|variant], target_key, ratio, max_per_day, min_per_day)
```

---

## 6. 이행 계획 (구현 착수 시)

1. `variant` 컬럼 추가 + 기존 미션 전부 `shopping.tag` 로 채움(현재 동작과 동일)
2. capability 표를 config 로 정의 → `MissionGrader`·`MissionCopy`·응답 조립이 그 표를 읽게 변경
3. 타입 하나 추가(`shopping.product_no5`)로 구조 검증 — `channel_product_id` 컬럼·동기화 매핑 포함
4. 어드민 리워드 설정에 타입 탭 자동 확장 · 미션 편집에서 variant 선택
5. 배분 테이블(§5) 구현
6. 이미지 판정형은 별도 Phase(비동기 판정·업로드·비용)

**현재 상태 요약**: 1~6 전부 미구현. 지금은 `kind='external'` · 태그 채점 · 문구 세트 2종
(`shopping_tag` / `fallback`)으로 동작하며, 이 문서의 구조로 확장할 수 있게 문구·정답 소스는
이미 설정으로 분리돼 있다.
