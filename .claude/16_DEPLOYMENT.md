# 16. 배포 (Deployment) — jcurve2(crm) 서버

> rankfree(Laravel 13 / PHP 8.3)를 **기존 crm 서버(jcurve2)에 crm 무변경 원칙**으로 올리는 배포 기준 문서.
> crm은 **mod_php 7.2**로 서비스 중 — 절대 건드리지 않고 rankfree만 **PHP 8.3(php-fpm)**로 공존 구동한다.

## 서버 환경 (2026-07 확인)

| 항목 | 값 | 비고 |
|------|-----|------|
| OS | Rocky Linux 8.10 (kernel 4.18) | |
| 웹서버 | **Apache 2.4.61 (Unix, 수동 소스빌드)** | `/usr/local/apache`, 소스 `/usr/local/src/httpd-2.4.61` |
| PHP(crm) | **7.2.24, mod_php** | `php_module (shared)` — crm 전용, 무변경 |
| Apache 모듈 | `proxy_module`, `proxy_http_module` 있음 / **`proxy_fcgi` 없음** | apxs로 추가(아래) |
| DB | **MariaDB 11.4.2** | utf8mb4 완전 지원 |
| Node | v22.14.0 | Vite 빌드는 로컬 권장 |
| 컨트롤패널 | **없음(수동 빌드)** | vhost 직접 편집 가능 |
| vhost 정의 | `conf/extra/httpd-vhosts.conf`(HTTP) · `conf/extra/httpd-ssl.conf`(HTTPS) | crm vhost 존재 |
| mod_php ini | `/usr/local/apache/conf/php.ini` | crm 7.2 전용, 무변경 |
| SELinux | **Disabled** | Apache→fpm 소켓 정책 불필요 |

### 확정 파라미터 / 진행 상태 (2026-07-13)
- **배포 완료(0~6단계) — rankfree.kr 공개 라이브** 🎉. 외부에서 `HTTP 200 OK`, `X-Powered-By: PHP/8.3.32`(php83-fpm), HTTP/2, Laravel 세션 정상. crm·실사용 도메인 전부 무영향. DNS `rankfree.kr`·`www` → 서버 IP `49.247.13.187`. 프로덕션 캐시(config/route/view) 적용.
- 서버 IP: `49.247.13.187`. **서버에서 `curl https://rankfree.kr`가 빈 응답인 건 NAT 헤어핀(자기 공인IP 루프백 미지원)일 뿐 정상** — 외부/로컬 `--resolve 127.0.0.1` 테스트는 200. `.env` 변경 시 `php83 artisan config:cache` 재실행 필요.
- 스케줄러/큐: 앱에 예약작업·`ShouldQueue` 잡 없음 → 크론/워커 불필요. 확장 기본 서버 = `https://rankfree.kr`(코드 기본값, 로그인 폼에 프리필).

### 기능 활성화 — 운영 .env·Playwright·세션 (필수)
> `.env`는 시크릿이라 git으로 안 옮김. 아래를 운영 `.env`에 채워야 각 기능이 동작. **`.env` 변경 시 반드시 `php83 artisan config:cache`**(config 캐시 상태라 안 하면 반영 안 됨).

**API 키만으로 되는 기능** — 로컬 `.env` 값 그대로 복사:
- 키워드 검색량·경쟁: `NAVER_SEARCHAD_API_KEY` `NAVER_SEARCHAD_SECRET` `NAVER_SEARCHAD_CUSTOMER_ID`
- 쇼핑 순위·셀러력·시장분석: `NAVER_SHOPPING_API_KEYS`

**Playwright(chromium) 필요한 기능** — 키워드 성별·연령·트렌드(검색광고 웹세션), 스마트플레이스, 경쟁 SERP:
- `.env`: `NAVER_ADS_LOGIN_ID` `NAVER_ADS_LOGIN_PW` `NAVER_ADS_CUSTOMER_ID` `NAVER_ADS_ACCOUNT_NO`, `RANKFREE_NODE=/home/jcurve/.nvm/versions/node/v22.14.0/bin/node`
- playwright npm 패키지는 `npm ci`로 이미 설치. **chromium 브라우저는 별도**: `node_modules/.bin/playwright install chromium`(웹 실행 유저 **jcurve**로) + 시스템 라이브러리(`playwright install-deps chromium` 또는 dnf 수동).
- 구조: `artisan searchadweb:login`(Playwright)이 세션 쿠키를 DB(암호화)에 저장 → 이후 성별/연령/트렌드는 **저장 쿠키로 curl** 호출(요청마다 브라우저 안 띄움).
- **세션 지속성 크론(jcurve)** — 만료 전 재로그인으로 크롤링 끊김 방지:
  `0 */4 * * * cd /www/jcurve/rankfree && php83 artisan searchadweb:login >> storage/logs/searchad-login.log 2>&1`

**게차**: DB 계정은 `@localhost`뿐 아니라 **`@127.0.0.1`**도 필요(Laravel TCP 접속). chromium은 **웹 실행 유저(jcurve) 캐시**에 있어야(root `~/.cache`만으론 php-fpm 재로그인 실패).

### 재배포 워크플로
로컬 개발 → `git push` → 서버(jcurve) `bash deploy.sh`(git reset→composer→npm build→migrate→config/route/view:cache). **FTP 직접 편집 금지**(git과 어긋나고 다음 배포에 덮어써짐).
- 4단계: `apxs`로 `mod_proxy_fcgi.so` 빌드+`LoadModule proxy_fcgi_module`(httpd.conf, proxy_module 다음). 5단계: `httpd-vhosts.conf`(:80 리다이렉트) + `httpd-ssl.conf`(:443) rankfree vhost를 DocumentRoot→`public` + `<FilesMatch \.php$> SetHandler "proxy:unix:/var/opt/remi/php83/run/php-fpm/rankfree.sock|fcgi://localhost/"`. 기존 GoGetSSL 인증서 유지. 검증: `curl -skI --resolve rankfree.kr:443:127.0.0.1 https://rankfree.kr` → HTTP/2 200.
- 코드 전송: GitHub `github.com/jxamen/rankfree`(public) → 서버에서 **jcurve 유저로** clone/composer/artisan(root 파일 금지). 검증: `find /www/jcurve/rankfree ! -user jcurve` 빈 출력.

**실전 게차(3단계에서 실제로 걸린 것)**
- **DB 계정은 `@127.0.0.1`도 필요**: Laravel이 `DB_HOST=127.0.0.1`(TCP)로 붙어서 `'rankfree'@'localhost'`만으론 `[1045] Access denied`. → `CREATE USER 'rankfree'@'127.0.0.1' IDENTIFIED BY '동일암호'; GRANT ALL ON rankfree.* ...` 추가.
- **Vite 자산 빌드 필수**: `public/build`는 gitignore라 배포에 없음 → `@vite`가 `manifest not found`로 500. → 서버에서 `npm ci && npm run build`. 재배포 시에도 항상 수행.
- php83 = **8.3.32**(Remi, `/opt/remi/php83`), 시스템 php = **7.2.24 유지**(crm mod_php 무변경)
- fpm 풀: `/etc/opt/remi/php83/php-fpm.d/rankfree.conf` → 소켓 **`/var/opt/remi/php83/run/php-fpm/rankfree.sock`** (`apache:apache`, `0660`), 워커 유저 **`jcurve`**
- 배포 경로: **`/www/jcurve/rankfree`** (`jcurve:jcurve`, 현재 `.well-known`만) → 5단계에서 DocumentRoot를 **`/www/jcurve/rankfree/public`** 으로 변경
- SSL: 기존 **GoGetSSL** 인증서 `/usr/local/apache/ssl/rankfree.kr/*` (발급 불필요)
- 보호 도메인(끝까지 200 유지): adtox.biz · ai-agent.kr · bizforeshop.com · boostings.shop · crmpro.kr · digitalnomad.kr · j-curve.co.kr · jp-shopping.shop · myinfluencer.co.kr · mymy.place · peakreward.shop · placeads.co.kr · placemanager.shop

## 라우팅 결정 — Option ①: apxs `mod_proxy_fcgi` + php83-fpm (단일 Apache)

- crm의 `.php`는 mod_php 7.2가 처리. rankfree는 **vhost 범위에서만** `.php`를 php83-fpm(FastCGI)으로 넘긴다.
- `proxy_fcgi`가 없지만 **소스 트리가 있어** apxs로 shared 모듈만 빌드·로드(재컴파일 불필요).
- LoadModule 순서: `proxy_module` → `proxy_fcgi_module`(proxy 뒤). 런타임에 심볼 해석.
- **폴백(②)**: apxs 실패 시 `proxy_http`로 nginx+php83-fpm(로컬 :8080) 리버스 프록시 + `TrustProxies`에 `127.0.0.1` 신뢰.

---

## 단계별 안전 배포 (공용 서버 — 게이트 + 롤백)

> jcurve2에는 crm 외 여러 서비스가 있음. **Apache는 4단계 전까지 절대 건드리지 않는다.**
> 1~3단계는 실행 중 서비스에 **영향 0**(패키지/서비스/DB/파일 추가만) → 각 단계 뒤 게이트 통과 시에만 다음으로.

| 단계 | 작업 | 실행 서비스 영향 | 롤백 |
|------|------|------------------|------|
| 0 | 베이스라인 기록 + 설정 백업 | 없음(읽기) | — |
| 1 | php83 + php83-fpm 설치(Remi) | **없음**(7.2·Apache 무관) | `dnf remove php83*` |
| 2 | php83-fpm 풀 기동(자기 소켓) | **없음** | 소켓/서비스 중지 |
| 3 | DB 생성·코드·composer·migrate + **격리 테스트** | **없음**(Apache 미접촉) | DB drop·디렉터리 삭제 |
| 4 | apxs `mod_proxy_fcgi` + LoadModule | **Apache 재적용(주의)** | LoadModule 제거 후 graceful |
| 5 | rankfree vhost 추가 | **Apache 재적용(주의)** | vhost 제거 후 graceful |
| 6 | SSL·크론·큐·확장 연결·최종 검증 | 낮음 | 개별 롤백 |

**Apache 변경 불변 규칙(4·5단계)**
1. 변경 전 `cp -a /usr/local/apache/conf /root/apache-conf.bak.$(date +%F)`
2. 편집 후 **반드시 `/usr/local/apache/bin/httpd -t`** — Syntax OK 아니면 재시작 금지(모듈까지 로드 검사됨)
3. `-t` 통과 시에만 **`httpd -k graceful`**(무중단, 설정 오류 시 구 설정 유지). **`stop/start`·`restart` 금지**
4. graceful 직후 **crm + 다른 도메인 전부 200 확인** → 하나라도 이상이면 즉시 편집 되돌리고 `-t`→`graceful`
5. 가능하면 저트래픽 시간대에 진행

**격리 테스트(3단계 게이트, Apache 안 건드리고 앱 검증)**
```bash
php83 artisan serve --host=127.0.0.1 --port=8899 &
curl -sI http://127.0.0.1:8899        # 200 + Laravel 응답이면 8.3+DB 정상
kill %1
```

---

## 배포 순서

### 1) PHP 8.3 공존 설치 (Remi, 7.2와 독립)
```bash
dnf install epel-release
dnf install https://rpms.remirepo.net/enterprise/remi-release-8.rpm
dnf install php83 php83-php-fpm \
  php83-php-{mysqlnd,mbstring,xml,curl,gd,bcmath,intl,opcache,zip,fileinfo,openssl,tokenizer}
# php83 패키지는 /opt/remi/php83 에 설치되어 시스템 php72와 완전 분리
```

### 2) `mod_proxy_fcgi` 빌드·로드 (Option ①)
```bash
cd /usr/local/src/httpd-2.4.61/modules/proxy
/usr/local/apache/bin/apxs -c -i mod_proxy_fcgi.c
# → /usr/local/apache/modules/mod_proxy_fcgi.so 생성
# httpd.conf 의 `LoadModule proxy_module ...` 바로 아래에 추가:
#   LoadModule proxy_fcgi_module modules/mod_proxy_fcgi.so
/usr/local/apache/bin/httpd -M 2>/dev/null | grep fcgi   # 확인
```

### 3) php83-fpm 전용 풀
`/etc/opt/remi/php83/php-fpm.d/rankfree.conf`:
```ini
[rankfree]
user = <사이트유저>
group = <사이트유저>
listen = /run/php-fpm/php83-rankfree.sock
listen.owner = apache      ; Apache가 소켓에 쓸 수 있어야 함(mod_php가 apache 유저로 구동)
listen.group = apache
listen.mode = 0660
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 24M
```
```bash
systemctl enable --now php83-php-fpm
```

### 4) MariaDB — rankfree 전용 DB (crm DB 무변경)
```sql
CREATE DATABASE rankfree CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rankfree'@'localhost' IDENTIFIED BY '<강한암호>';
GRANT ALL PRIVILEGES ON rankfree.* TO 'rankfree'@'localhost';
FLUSH PRIVILEGES;
```

### 5) 코드 배포 + 앱 초기화 (php83 사용)
```bash
# 코드 업로드: git clone/pull 또는 rsync → /home/.../rankfree
# 자산은 로컬에서 `npm run build` 후 public/build 포함해 올리면 서버 빌드 불필요

php83 /usr/local/bin/composer install --no-dev -o
cp .env.example .env         # 아래 .env 값 반영
php83 artisan key:generate   # APP_KEY 없을 때
php83 artisan migrate --force
php83 artisan db:seed --force            # PermissionSeeder 등
php83 artisan storage:link
php83 artisan config:cache route:cache view:cache
```

`.env` (운영):
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://rankfree.kr
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rankfree
DB_USERNAME=rankfree
DB_PASSWORD=<강한암호>
# SESSION/QUEUE/CACHE=database 는 MariaDB에서 그대로 사용 가능
```

### 6) Apache vhost (rankfree만 추가 — crm vhost 무변경)
`conf/extra/httpd-vhosts.conf` (HTTP → HTTPS 리다이렉트) 및 `conf/extra/httpd-ssl.conf`(실서비스):
```apache
<VirtualHost *:443>
    ServerName rankfree.kr
    DocumentRoot /home/.../rankfree/public

    # rankfree의 .php 만 php83-fpm으로 (crm mod_php 7.2 무영향)
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php-fpm/php83-rankfree.sock|fcgi://localhost/"
    </FilesMatch>

    <Directory /home/.../rankfree/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All          # Laravel public/.htaccess(mod_rewrite) 사용
        Require all granted
        DirectoryIndex index.php
    </Directory>

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/rankfree.kr/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/rankfree.kr/privkey.pem
</VirtualHost>
```
```bash
/usr/local/apache/bin/httpd -t         # 문법 검사
# graceful 재시작 (crm 세션 유지)
/usr/local/apache/bin/httpd -k graceful
```
> `mod_rewrite`이 로드돼 있어야 pretty URL 동작(`httpd -M | grep rewrite`). 없으면 apxs로 추가.

### 7) 권한
```bash
chown -R <사이트유저>:apache /home/.../rankfree/storage /home/.../rankfree/bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 8) HTTPS 인증서 (Let's Encrypt, 수동 Apache)
```bash
# webroot 방식 — DocumentRoot(public) 기준
certbot certonly --webroot -w /home/.../rankfree/public -d rankfree.kr
# 갱신 후 httpd -k graceful (deploy-hook 등록 권장)
```

### 9) 스케줄러 / 큐 (사용 시)
```bash
# 크론(스케줄러)
* * * * * cd /home/.../rankfree && php83 artisan schedule:run >> /dev/null 2>&1
# 큐 워커(QUEUE_CONNECTION=database 사용 시) — systemd 서비스로:
#   ExecStart=/opt/remi/php83/root/usr/bin/php artisan queue:work --sleep=3 --tries=3
```

---

## 검증 체크리스트
- [ ] `httpd -M | grep proxy_fcgi` → 모듈 로드됨
- [ ] `curl -I https://rankfree.kr` → 200, `X-Powered-By`/응답이 PHP 8.3 스택
- [ ] `php83 artisan about` → env=production, DB=mysql 연결 OK
- [ ] crm 사이트 정상(무영향) — mod_php 7.2 그대로
- [ ] 확장 고급설정 서버주소를 `https://rankfree.kr`로 → 저장/키워드 분석 왕복 확인
- [ ] storage 쓰기 OK(로그·캐시), `storage:link` 심볼릭 정상

## 주의 / 게차
- **crm 무변경**: httpd.conf 전역 핸들러/AddType(.php→mod_php)은 유지. rankfree vhost의 `<FilesMatch> SetHandler`가 그 vhost 범위에서만 우선한다.
- 소켓 권한: php83-fpm `listen.owner=apache` 필수(Apache가 apache 유저로 구동 → 소켓 접근).
- 배포 자산: `public/build`(Vite 산출물)를 반드시 포함. 서버에 Node 있지만 로컬 빌드 권장.
- 롤백: rankfree vhost 제거 + `httpd -k graceful` 하면 crm은 원상. php83-fpm 중지해도 crm 무관.
- APP_KEY·.env는 배포에서 관리(리포 커밋 금지).
