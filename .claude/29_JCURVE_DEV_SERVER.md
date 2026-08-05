# 29. dev.j-curve.co.kr — 전용 계정·격리 배포 환경

> j-curve 홈페이지 개편(신규 Laravel)을 **같은 jcurve2 서버**에 올리되, 외부 개발자가 그 프로젝트에만
> 접근하도록 계정·DB·php-fpm 풀을 전부 분리한 구성. 2026-08-05 구축·검증 완료.
>
> 같은 서버의 rankfree 배포 기준은 [16_DEPLOYMENT.md](./16_DEPLOYMENT.md) 참조.

## 한눈에

| 항목 | 값 |
|---|---|
| 사이트 | `https://dev.j-curve.co.kr` |
| 앱 경로 | `/www/jcurve/j-curve-dev` (DocumentRoot `public/`) |
| 저장소 | `github.com/anarchy84/jcurve` (브랜치 `main`) |
| 계정 | `jcdev` (uid 1001) — sudo 없음 |
| DB | `jcurve_dev` / 계정 `jcurve_dev@localhost`·`@127.0.0.1` |
| php-fpm 풀 | `jcdev.sock` (user=jcdev) — rankfree 와 분리 |
| 인증서 | Let's Encrypt `dev.j-curve.co.kr` (HTTP-01, 자동갱신) |
| 프레임워크 | Laravel 13.19 / PHP 8.3 |

---

## 🔴 개발자가 반드시 알아야 할 두 가지

### 1) `jcdev` 로만 작업한다

```bash
ssh -i ~/.ssh/jcdev -p 9022 jcdev@49.247.13.187
```

`jcurve`(운영 계정)로 이 프로젝트를 만지면 **전부 권한 오류**다. 앱이 `jcdev` 소유이기 때문.

**`sudo` 로 우회하지 말 것.** root 소유 파일이 생겨 php-fpm(jcdev)이 못 쓰게 되고, 나중에
`config:cache`·로그 기록이 실패해 500 이 난다. 구축 당시 실제로 이 문제로 한참 헤맸다
(`bootstrap/cache/config.php` 가 root:root 로 남아 낡은 sqlite 설정을 계속 먹였다).

권한 오류가 나면 그건 "지금 jcurve 로 하고 있다"는 신호다. `whoami` 로 확인한다.
이미 jcurve 로 접속돼 있다면 재접속 없이 전환할 수 있다:

```bash
sudo su - jcdev
```

### 2) PHP 는 항상 `php83`

```
php    → 7.2.24   ← 절대 쓰지 말 것
php83  → 8.3.32   ← 앱·artisan·composer 전부 이걸로
```

이 서버는 crm 이 **mod_php 7.2** 로 서비스 중이라 기본 `php` 를 올릴 수 없다.

`composer` 는 `#!/usr/bin/env php` 라 그냥 치면 **7.2 로 돈다** — `fn` 화살표 함수에서
파스 에러가 나며 오토로드 덤프가 조용히 깨진다. 구축 당시 이것 때문에
`symfony/polyfill-php86` 의 `SortDirection` stub 이 오토로드에 안 잡혀 500 이 났다.

```bash
php83 /usr/local/bin/composer install --no-dev -o
```

`~/.bashrc` 에 넣어두면 편하다:

```bash
alias composer='php83 /usr/local/bin/composer'
```

node/npm 도 PATH 에 없다 — nvm 을 먼저 로드한다(node v22.14.0):

```bash
export NVM_DIR="$HOME/.nvm" && . "$NVM_DIR/nvm.sh"
```

---

## 배포 절차

```bash
cd /www/jcurve/j-curve-dev
git pull origin main
php83 /usr/local/bin/composer install --no-dev -o
export NVM_DIR="$HOME/.nvm" && . "$NVM_DIR/nvm.sh" && npm ci && npm run build
php83 artisan migrate --force
php83 artisan config:cache && php83 artisan route:cache && php83 artisan view:clear
```

Apache 리로드는 **불필요**하다(PHP 파일만 바뀌므로). vhost 를 고쳤을 때만 관리자에게 요청한다.

### 처음 세팅할 때

```bash
cp .env.example .env
# DB_* 는 아래 '전용 DB' 값으로 교체 (기본값이 sqlite 라 반드시 바꿔야 한다)
php83 artisan key:generate
# APP_URL=https://dev.j-curve.co.kr
chmod 600 .env
chmod -R 775 storage bootstrap/cache
```

---

## 격리 구조 — 무엇을 못 하나

| 대상 | jcdev |
|---|---|
| `/www/jcurve/j-curve-dev` | 읽기·쓰기 ✅ |
| `/www/jcurve/rankfree`·`crm`·`j-curve`·그 외 전부 | **차단**(ACL) |
| 다른 사용자 홈 | **차단**(ACL) |
| sudo | **없음** |
| DB `rankfree`·`crm` 등 | **목록에도 안 보임** |

### 왜 chmod 가 아니라 ACL 인가

Apache 는 `apache`(uid 48) 로 돌고 **jcurve 그룹에 없다.** 기존 사이트들은 디렉터리의
**others 권한**으로 파일을 읽는다. 따라서 `chmod o-rwx` 를 하면 crm·j-curve 가 전부 죽는다.

→ 권한 비트는 그대로 두고 `setfacl -m u:jcdev:---` 로 **그 계정만** 차단했다.

```bash
# 신규 프로젝트가 추가되면 그것도 막아야 한다
sudo setfacl -m u:jcdev:--- /www/jcurve/<새프로젝트>
```

### 왜 php-fpm 풀을 나눴나

풀을 공유하면 웹앱이 `jcurve` 로 실행돼 **계정을 나눈 의미가 없다**(앱 코드가 다른 프로젝트를
다 읽는다). 덤으로 dev 앱이 폭주해도 rankfree 워커를 잠식하지 않는다.

⚠️ `systemctl restart php83-php-fpm` 은 **rankfree 도 같이 끊는다.** 필요하면 `reload` 를 쓴다.

---

## 전용 DB

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=jcurve_dev
DB_USERNAME=jcurve_dev
DB_PASSWORD=(구축 시 무작위 생성 — /home/jcdev/db-credentials.txt 또는 앱 .env)
```

MySQL 클라이언트는 PATH 에 없다: `/usr/local/mysql/bin/mysql`

비밀번호를 잃어버렸으면 관리자가 `sudo bash ~jcurve/jcdev-db-root.sh` 를 재실행한다
(스키마는 유지되고 비밀번호만 재발급된다).

---

## HTTPS

- 인증서: Let's Encrypt, **HTTP-01(webroot)** 방식, acme.sh 크론이 하루 4회 자동 갱신
- 갱신 훅: `sudo /usr/local/apache/bin/httpd -k graceful`
- webroot: `/www/jcurve/acme-challenge`

### 와일드카드가 아닌 이유

rankfree.co.kr 은 Cloudflare DNS API(`dns_cf`)로 `*.rankfree.co.kr` 을 자동 발급하지만,
**j-curve.co.kr 은 네임서버가 hosting.kr** 이고 acme.sh 에 해당 DNS API 가 없다.
→ DNS-01 자동화가 불가능해 호스트 단위로 발급한다.

서브도메인이 늘면 SAN 에 얹고 vhost 를 추가해야 한다(무작업 아님):

```bash
sudo -u jcurve ~jcurve/.acme.sh/acme.sh --issue \
  -d dev.j-curve.co.kr -d www.j-curve.co.kr \
  -w /www/jcurve/acme-challenge --server letsencrypt
```

⚠️ :80 vhost 의 HTTPS 리다이렉트에서 `/.well-known/acme-challenge/` 를 **반드시 예외**로 둘 것.
안 그러면 갱신이 조용히 실패한다(구축 당시 이 함정을 피해 예외 규칙을 넣어두었다).

---

## 관리자용 스크립트 (`~jcurve/`, 모두 `sudo bash` 로 실행·재실행 안전)

| 스크립트 | 용도 |
|---|---|
| `jcurve-dev-ssl-root.sh` | vhost(:80/:443) + 인증서 발급·설치·자동갱신 훅 |
| `jcdev-account-root.sh` | 계정 생성·키 등록·소유권 이전·ACL 격리·전용 php-fpm 풀 |
| `jcdev-db-root.sh` | 전용 스키마·계정 생성(비밀번호 재발급 겸용) |

각 스크립트는 conf 백업 → `httpd -t` → 실패 시 자동 원복 → `graceful` 순으로 동작한다.

---

## 트러블슈팅

| 증상 | 원인 | 조치 |
|---|---|---|
| `Permission denied` 전반 | `jcurve` 로 작업 중 | `sudo su - jcdev` |
| `config:cache` 실패 | root 소유 캐시 잔재 | `rm -f bootstrap/cache/config.php` (jcdev 로) |
| `Class "…" not found` | composer 가 PHP 7.2 로 돌아 오토로드 깨짐 | `php83 /usr/local/bin/composer dump-autoload -o` |
| `syntax error, unexpected 'fn'` | 같은 원인 | 위와 동일 |
| `migrate` 가 "Nothing to migrate" | 낡은 config 캐시가 sqlite 를 먹임 | 캐시 삭제 후 `config:clear` |
| 500 인데 원인 불명 | — | `grep -a "production.ERROR" storage/logs/laravel.log \| tail -3` |

Apache 에러 로그(`/var/log/httpd/j-curve-dev-cokr-ssl-error_log`)는 root 전용이라 개발자는 못 본다.
대개 Laravel 로그로 충분하다.
