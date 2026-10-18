추정 — 보안문자의 직접 원인은 미확정. 코드로는 이어서가 탭·검색 간격 기록을 초기화하는 점과 새 분석이 복수 lane으로 출발할 수 있는 점을 확인했다. #84~#86의 요청별 시각·브라우저 상태가 없어 운영 증상과의 인과는 확정하지 않는다.

## 범위와 기준
- origin/master `37bf724`에서 새 가지 `rf-first-block` 생성. 확장 버전 `0.6.8.6`.
- 운영 조회·배포·push·게시 및 실제 네이버 검색은 수행하지 않았다. 아래 재현은 VM/가상 브라우저와 시계 기반이다.

## 수정 전 호출 추적
1. 주문 → 분석: `app/Http/Controllers/Admin/MarketingOrderController.php::createShopKeyword`가 분석을 준비하고 show 경로로 HTTP redirect한다. 이 경로가 수집용 새 창을 만들지는 않는다.
2. `resources/views/admin/shop-keyword/show.blade.php::runExtLoop`가 pending 항목을 받아 병렬 설정이 2 이상이면 `Promise.all(d.items.map(processItem))`로 브릿지 `fetchShopSerp`를 동시에 호출한다. background의 `drainShopKeywordCheckQueue`도 병렬 batch를 처리한다. 화면 호출은 `lastScreenSerpAt`을 갱신해 background 큐에 양보를 요청한다.
3. `extension/background.js::fetchShopSerp`는 브라우저 저장 설정을 적용하고 `ShopSerpPool.run` → lane별 `ShopSerpPolicy.run` → `ShopSerpTab.read`를 호출한다. 평범한 A→B 화면 진입은 `restart/resume`을 호출하지 않는다. `parallel_revision` 변경은 reduction만 해제한다.
4. `ShopSerpTab.read`: 메모리 `tabId`, 없으면 session `rfSerpTabId[:lane:N]`를 읽고 `tabs.get`으로 존재 확인한다. 살아 있으면 `tabs.update({url,active:false})`; 없을 때만 `tabs.create({url,active:false,pinned:false})`. 따라서 **매 분석마다 새 탭을 만든다는 가설은 일반 A→B 경로에는 해당하지 않는다**. 수집 종료 시 탭을 자동으로 닫거나 ID를 지우는 경로도 없다.
5. 첫 실제 검색 URL은 `https://m.search.naver.com/search.naver?where=m&query=<encodeURIComponent(keyword)>`. 통합검색 상품 블록을 읽고, 블록이 없으면 `content/shop-navigation.js`가 같은 탭의 쇼핑 메뉴로 이동한다. 첫 URL은 평소 검색과 같다. 당일 캐시 적중이면 검색 자체를 하지 않는다.
6. 탭은 처음에는 inactive이며 보안문자 감지 시 기존 recovery/focus가 active로 전환한다. 보안문자 뒤 `&ct=1` URL과 대체어 시도는 기존 복구 경로이며 이번 변경 대상이 아니다. 자동 보안문자 풀이·가짜 입력·UA/IP 변경은 추가하지 않았다.

## lane, 간격, 휴식의 차이
| 경로 | 수정 전 탭/정책 | 시작 병렬과 간격 |
|---|---|---|
| 같은 분석의 평소 수집 | lane 탭 재사용, `s.last` 유지 | lane별 완료 후 gap(1~30초). 이미 시작한 lane에는 stagger 재적용 안 함 |
| A 완료 → B | 기존 lane 재사용, `s.last` 유지. A의 reduction은 분석별이므로 B에 자동 승계 안 됨 | B의 첫 batch도 최대 설정 4개 lane. 새 analysis:slot마다 stagger 적용. 기본 전달값 3초지만 저장 설정은 0도 허용 |
| 중단 → 이어서 버튼 | show의 `resumeShopSerp` → `restartShopSerp` → pool.restart. 모든 lane의 ID/키워드/분석/recovery 삭제, 메모리 탭 해제, policy.resume이 `s.last=0`, `until=0` | `rfSerpLastOpened=0`, `rfSerpLastLaneStarted=0`, started/affinity 초기화. 옛 탭은 닫지 않은 채 새 탭을 만들 수 있음. 첫 요청은 이전 간격을 기다리지 않음 |
| 모든 탭 닫은 뒤 서버 checking 재개 | fetchShopSerp가 closed 4개 + close ack + collector-state 확인 후 restart | 닫힌 탭 대체가 필요. 이어서와 같은 초기화 경로 |
| 쉬었다가 그대로 진행 / SW 재기동 | 휴식 길이 전용 분기 없음. local 정책과 session 탭 ID로 복원 | 이미 지난 gap만큼 추가 대기하지 않음. started는 메모리라 SW 재기동 시 다시 stagger하되 저장 lastLaneStarted 참조 |

- 신규 탭 생성은 공유 openLane 큐가 직렬화하고 마지막 생성 시각+설정 gap을 지킨다. gap=0이면 API 호출은 직렬이어도 여러 탭이 거의 연달아 열린다. 재사용 탭의 최초 lane 시작도 startLane 큐가 직렬화하지만 gap=0이면 검색은 겹칠 수 있다. lane별 policy라 서로 다른 lane의 마지막 시각은 공유하지 않는다.
- 쿠키 삭제·변경, 새 창 생성, discard/frozen 강제 조작은 해당 수집 코드에 없다. `tabs.get` 성공 시 discarded/frozen 분기 없이 같은 탭 update를 한다. 실제 휴식 중 Chrome이 탭을 discard/freeze했는지는 제공 자료로 알 수 없다. 일반 브라우저 쿠키 사용은 그대로다.
- 제공된 분석 시작: #84 22:36:59, #85 23:00:03, #86 23:13:52. 시작끼리 차이는 23분 04초 / 13분 49초이며 **A 마지막 요청→B 첫 요청의 실제 공백이 아니다**. 요청별 타임스탬프, 설정값, lane 번호, 탭 상태가 없어 실제 공백과 동시 lane 수를 수치로 확정할 수 없다. policy의 lastIntervalMs 역시 lane별 이전 완료→다음 완료이며 네트워크 요청 시작 간격과 다르다.
- 코드상 같은 lane의 다음 요청은 `max(0,last+gap-now)` 대기한다. 이어서는 last를 지워 0초도 가능했고, 다른 lane은 자체 last가 오래됐으면 바로 출발할 수 있었다. 긴 휴식 뒤의 차단을 간격 부족 하나로 설명할 근거는 없다.

## 수정
- `shop-serp-pool.js::restart`: 살아 있는 탭 ID/문서/recovery를 유지. 닫힌 slot만 초기화하므로 실제 닫힌 탭은 대체한다. `rfSerpLastOpened`, `rfSerpLastLaneStarted` 유지.
- `shop-serp-policy.js::resume`: cooldown 해제는 유지하되 `s.last`를 보존해 정해진 검색 간격을 지킨다. 보안문자 탭 recovery는 보존되므로 이어서를 눌러도 기존 사람 확인 경로를 유지한다.
- pool admission: 새 분석/명시적 이어서의 첫 작업은 한 lane만 실행. 첫 결과(차단 포함) 처리 후 기존 병렬 수를 적용하며 차단이면 기존 reduction을 따른다. 완료 여부는 local에 저장해 worker 재기동으로 반복 초기화하지 않는다. 당일 캐시 적중이면 첫 작업은 네트워크 없이 끝날 수 있다.
- deferred/설정 축소 재진입 전에 slot을 반환해 첫 작업 제한으로 대기 교착이 생기지 않도록 했다. 정상 병렬 수집의 lane별 간격, 설정된 탭 열기 간격은 유지한다.

## 재현·검증
- `node --test tests/extension/*.test.cjs`: 110개 통과, 실패 0.
- 추가 재현: 새 분석에서 parallel=4/stagger=0이어도 첫 결과 전 추가 lane 진입 없음, 이후 4개 병렬 복원.
- 추가 재현: resume 후 이전 완료+500ms에서 gap 4000ms의 나머지를 대기하고, 60초 휴식 후에는 불필요한 추가 대기 없음.
- 추가 재현: live slot 0과 worker에 로드되지 않은 slot 3의 session ID 및 recovery/open/stagger 시각 보존.
- 기존 close→pause→resume 테스트는 닫힌 탭 대체를 유지하면서 첫 검색 뒤 새로 만든 탭 재사용도 허용하도록 수정. 기존 병렬 동작 테스트에는 첫 검색 완료 상태를 명시하여 새 분석 최초 작업과 평소 병렬 수집을 구분했다.
- 실운영 보안문자 재현·해소 여부는 검증하지 않았으므로 해결 확정으로 보고하지 않는다.

## 전달
- Git bundle: `/tmp/rf-first-block.bundle` (`37bf724..rf-first-block`, 기반 커밋 필요).
- 운영 반영 없음.
