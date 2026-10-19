# 배송비 수집 0.6.9.0

기존 근거 경로는 `product.productDeliveryInfo.deliveryFeeType`,
`deliveryFee.deliveryFeeType`, `baseFee`, 숫자형 `deliveryFee`, `deliveryFee.baseFee`다.
FREE는 0, 명시된 기본비는 정수, 조건부 무료의 기준액은 배송비로 사용하지 않는다.
추가 JSON 금액 경로를 추정해 도입하지 않았다.

새 폴백은 화면에 명시된 `배송비 3,000원`, `배송비 3,000원 (30,000원 이상 무료)`,
`배송비 4,000원 착불 묶음배송`, `무료배송` 문구다. 전자는 기본비만 저장한다.
착불만 있거나 무료 기준액만 있으면 null이다. 무료 기준액·묶음 단위·지역 추가비는
기존 단일 정수 delivery_fee로 표현할 수 없으므로 최종 결제 배송비로 해석하지 않는다.
이 문구들은 합성 fixture로 검증했다. 실제 누락 상품의 새 JSON 경로/문구는
네이버 보안 확인 화면 때문에 확인하지 못했다.

null일 때 delivery_diagnostic 문자열 JSON을 기존 saveProductInfo payload에 추가한다.
productDeliveryInfo 및 배송 관련 이름의 주변 키를 깊이 4까지 순회한다.
키 경로와 숫자/불리언/허용 배송 유형만 보존하고 임의 문자열은 null로 치환한다.
화면 문구도 배송 어휘와 금액 토큰만 남긴다. 전화번호 패턴은 제거한다.
주소·연락처·전체 HTML·상품 원본 전체는 전송하지 않는다. UTF-8 2048바이트 이내다.

서버는 두 상품 저장 API에서 재정제한 후 Laravel 로그에
`product_delivery_diagnostic`으로 user_id/product_id/evidence를 기록한다.
공통 저장과 분석별 저장으로 같은 수집의 로그가 중복될 수 있다.
API 번들 적용 및 새 확장 사용 후 실제 로그를 보고 원본 키 유무·기본비·조건 유형을
비교해야 해당 상품의 null 원인을 확정할 수 있다. 보안문자 풀이/차단 우회는 없다.
