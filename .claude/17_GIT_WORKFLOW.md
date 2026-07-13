# 17. Git 워크플로 (여러 CLI 동시 작업 · 안전 배포)

> 여러 Claude CLI 세션이 같은 저장소를 동시에 만질 때 서로의 작업을 덮어쓰지 않고,
> **master를 항상 배포 가능한 상태**로 유지하기 위한 규칙. 배포는 [16_DEPLOYMENT.md](./16_DEPLOYMENT.md)의 `deploy.sh`.

## 원칙

1. **master = 운영(배포 전용)** — 항상 완성·동작하는 상태만. `deploy.sh`가 `git reset --hard origin/master`로 통째 반영하므로, **master에 올라간 건 곧 운영에 나갈 수 있는 것**.
2. **작업은 브랜치/워크트리에서** — 진행 중 기능은 feature 브랜치. 완성돼야 master로 병합.
3. **커밋은 선택적으로** — `git add -A` 금지(다른 세션의 미완성 파일까지 섞임). **`git add <내 파일>`** 로 내 것만.

## 여러 CLI 동시 작업 = git worktree (세션별 별도 폴더)

한 폴더엔 브랜치 하나만 체크아웃되므로, **병렬 작업은 폴더를 분리**한다. 각 CLI가 자기 폴더·자기 브랜치.

```bash
# 메인 폴더는 그대로 두고, 기능별 작업 폴더 생성(+ 브랜치)
git worktree add ../rankfree-settings -b feature/settings     # CLI A
git worktree add ../rankfree-keyword  -b feature/keyword      # CLI B
# 각 CLI는 자기 폴더에서 작업 → 서로 영향 없음(git 이력은 공유)

git worktree list        # 현재 워크트리 확인
git worktree remove ../rankfree-settings   # 병합 끝나면 정리
```

## 기능 완성 → master 병합 → 배포

```bash
# (기능 폴더에서) 완성분 커밋
git add <파일들> && git commit -m "feat: ..."

# master에 병합
git switch master
git merge feature/settings          # 충돌 나면 해결 후 커밋
git push origin master

# 서버에서 배포 (jcurve)
#   cd /www/jcurve/rankfree && bash deploy.sh
```

## 배포 전 체크
- `git status`가 **깨끗**(미완성 미커밋 없음)한가
- master가 **동작하는 상태**인가(다른 CLI 진행분이 미완성으로 섞이지 않았는가)
- 급한 것만 나가야 하면, 그 커밋만 master에 올리고 나머지는 브랜치에 둔다

## 롤백
```bash
# 잘못 배포 시 이전 커밋으로 되돌려 재배포
git switch master && git reset --hard <이전-정상-커밋>
git push --force-with-lease origin master
#   서버에서 bash deploy.sh
```

## 요약 (지금 상황용)
- 급한 건 **커밋 단위로만** master에 올린다(전체 `-A` 금지).
- 다른 CLI의 큰 기능은 **feature 브랜치/워크트리**에서 → 완성 후 병합 → 배포.
- `deploy.sh`는 origin/master를 그대로 반영하므로, **master에 미완성 두지 않는다.**
