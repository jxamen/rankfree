#!/usr/bin/env bash
#
# 운영 재배포 스크립트 — jcurve 유저로 실행 (root 금지: 파일 소유권 유지).
#   서버:  cd /www/jcurve/rankfree && bash deploy.sh
#   전제:  로컬에서 git push 완료. master 브랜치 배포.
#
set -euo pipefail
cd /www/jcurve/rankfree

echo "▶ 코드 동기화(origin/master로 강제 일치)"
git fetch origin master
git reset --hard origin/master

echo "▶ PHP 의존성"
php83 "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction

echo "▶ 프론트 자산 빌드(Vite)"
npm ci
npm run build

echo "▶ DB 마이그레이션"
php83 artisan migrate --force

echo "▶ 캐시 재생성"
php83 artisan config:cache
php83 artisan route:cache
php83 artisan view:cache

echo "✅ 배포 완료: $(git rev-parse --short HEAD)"
