<?php $__env->startSection('content'); ?>


<section class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="grid gap-12 lg:grid-cols-[1.05fr_0.95fr] lg:items-center">
            
            <div>
                <div class="badge mb-5">가입 없이 무료 · 30초 완료</div>
                <h1 class="font-display text-ink" style="font-size:clamp(38px,5vw,60px);line-height:1.05;">
                    내 가게, 지금<br>네이버에서 몇 위일까?
                </h1>
                <p class="mt-5 text-body" style="font-size:18px;line-height:1.6;max-width:520px;">
                    키워드만 넣으면 <b class="text-ink">플레이스 순위</b>부터 상위 경쟁사, 블로그 지수까지 한눈에.
                    회원가입 없이 지금 바로 확인하세요.
                </p>

                <form id="hero-form" action="/rank-check" method="GET" class="mt-8 card p-3 flex flex-col sm:flex-row gap-2" style="max-width:560px;">
                    <input name="keyword" class="input" style="border:0;flex:1;" placeholder="검색 키워드 (예: 강남 미용실)" required>
                    <input name="place" class="input" style="border:0;flex:1;" placeholder="내 업체명 또는 플레이스 URL" required>
                    <button type="submit" class="btn btn-primary">무료 순위 조회</button>
                </form>
                <p class="mt-3 text-muted-soft" style="font-size:13px;">
                    이미 <b class="text-muted">1,200+</b> 사장님이 순위를 확인했어요.
                </p>
            </div>

            
            <div class="card overflow-hidden" style="box-shadow:var(--shadow-card);">
                <div class="flex items-center justify-between px-5 border-b border-hairline" style="height:52px;">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full" style="background:var(--color-badge-orange);"></span>
                        <span class="text-ink font-semibold" style="font-size:14px;">순위 결과</span>
                    </div>
                    <span class="text-muted-soft" style="font-size:12px;">키워드 · 강남 미용실</span>
                </div>
                <div class="p-5">
                    
                    <div class="card-soft p-4 flex items-center justify-between mb-4">
                        <div>
                            <div class="text-muted" style="font-size:12px;">내 매장</div>
                            <div class="text-ink font-semibold" style="font-size:15px;">라온헤어 강남점</div>
                        </div>
                        <div class="text-right">
                            <div class="font-display text-ink" style="font-size:30px;line-height:1;">7<span style="font-size:15px;" class="text-muted">위</span></div>
                            <div class="mt-1 inline-flex items-center gap-1" style="font-size:12px;color:var(--color-success);font-weight:700;">▲ 2</div>
                        </div>
                    </div>
                    
                    <div class="flex flex-col">
                        <?php $__currentLoopData = [['1','수앤수 헤어','리뷰 1,284'],['2','블로우 강남','리뷰 980'],['3','제로그램','리뷰 872'],['4','살롱드메이','리뷰 641']]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="flex items-center gap-3 py-2.5 border-b border-hairline-soft">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md bg-surface-card text-ink font-semibold" style="font-size:12px;"><?php echo e($row[0]); ?></span>
                            <span class="text-ink flex-1" style="font-size:14px;"><?php echo e($row[1]); ?></span>
                            <span class="text-muted-soft" style="font-size:12px;"><?php echo e($row[2]); ?></span>
                        </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<section id="features" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="text-center mb-12">
            <h2 class="font-display text-ink" style="font-size:clamp(28px,3.5vw,40px);line-height:1.1;">순위 확인만? 분석까지 한 번에.</h2>
            <p class="mt-4 text-muted" style="font-size:17px;">네이버 마케팅에 필요한 데이터를 무료로 제공합니다.</p>
        </div>
        <div class="grid gap-6 md:grid-cols-3">
            <?php $__currentLoopData = [
                ['📍','플레이스 순위 추적','키워드별 순위를 매일 자동 기록하고, 오르내림을 그래프와 알림으로 알려드려요.'],
                ['📊','경쟁사 분석','상위 30개 업체의 리뷰·저장·예약·평점을 비교해, 내가 밀리는 지점을 콕 집어드려요.'],
                ['✍️','블로그 지수 분석','블로그 방문·이웃·포스팅 활동을 점수화해 체험단·리뷰어의 영향력을 판단해요.'],
            ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $f): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="card-soft p-8">
                <div style="font-size:28px;"><?php echo e($f[0]); ?></div>
                <h3 class="mt-4 text-ink font-semibold" style="font-size:18px;"><?php echo e($f[1]); ?></h3>
                <p class="mt-2 text-muted" style="font-size:15px;line-height:1.6;"><?php echo e($f[2]); ?></p>
            </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </div>
</section>


<section id="place" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="grid gap-12 lg:grid-cols-2 lg:items-center">
            <div>
                <div class="badge mb-4">플레이스 순위 추적</div>
                <h2 class="font-display text-ink" style="font-size:clamp(26px,3vw,36px);line-height:1.15;">순위가 왜 떨어졌는지<br>데이터로 답합니다.</h2>
                <p class="mt-4 text-body" style="font-size:16px;line-height:1.7;max-width:480px;">
                    매일 순위를 기록하고 경쟁사 지표와 함께 보여줍니다. 리뷰가 부족한지, 저장수가 밀리는지, 어떤 키워드에서 빠지는지 한눈에 파악하세요.
                </p>
                <ul class="mt-6 flex flex-col gap-3">
                    <?php $__currentLoopData = ['키워드별 일간 순위 추이 그래프','상위 30위 경쟁사 리뷰·저장·예약 비교','공유 링크로 리포트 전달 (로그인 불필요)']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $li): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="flex items-center gap-2 text-ink" style="font-size:15px;">
                        <span style="color:var(--color-success);">✓</span> <?php echo e($li); ?>

                    </li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            </div>
            
            <div class="card p-6" style="box-shadow:var(--shadow-soft);">
                <div class="flex items-center justify-between mb-4">
                    <span class="text-ink font-semibold" style="font-size:14px;">순위 추이 · 최근 14일</span>
                    <span class="badge" style="font-size:12px;">강남 미용실</span>
                </div>
                <svg viewBox="0 0 400 160" class="w-full" style="height:auto;">
                    <polyline fill="none" stroke="var(--color-primary)" stroke-width="2.5"
                        points="0,120 33,110 66,116 99,90 132,96 165,70 198,74 231,52 264,60 297,40 330,44 363,28 400,30" />
                    <?php $__currentLoopData = ['0,120','99,90','198,74','297,40','363,28']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pt): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <circle cx="<?php echo e(explode(',', $pt)[0]); ?>" cy="<?php echo e(explode(',', $pt)[1]); ?>" r="3" fill="var(--color-primary)" />
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </svg>
                <div class="flex justify-between mt-2 text-muted-soft" style="font-size:11px;">
                    <span>7/1</span><span>7/7</span><span>7/14</span>
                </div>
            </div>
        </div>
    </div>
</section>


<section id="marketing" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="text-center mb-12">
            <h2 class="font-display text-ink" style="font-size:clamp(28px,3.5vw,40px);line-height:1.1;">분석에서 끝나지 않습니다.</h2>
            <p class="mt-4 text-muted" style="font-size:17px;max-width:560px;margin-inline:auto;">약점을 찾았다면, 개선할 마케팅까지 rankfree가 연결해드립니다.</p>
        </div>
        <div class="grid gap-6 md:grid-cols-3">
            <?php $__currentLoopData = [
                ['플레이스 최적화','정보 충실도·키워드·사진을 진단하고 상위 노출에 맞게 정비합니다.'],
                ['블로그·체험단','분석한 블로그 지수를 바탕으로 영향력 있는 리뷰어를 매칭합니다.'],
                ['광고 대행','플레이스·파워링크·쇼핑 광고를 데이터 기반으로 운영합니다.'],
            ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $m): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <div class="card p-8 flex flex-col">
                <h3 class="text-ink font-semibold" style="font-size:18px;"><?php echo e($m[0]); ?></h3>
                <p class="mt-2 text-muted flex-1" style="font-size:15px;line-height:1.6;"><?php echo e($m[1]); ?></p>
                <a href="/support" class="btn btn-secondary btn-sm mt-5" style="align-self:flex-start;">상담 문의</a>
            </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </div>
</section>


<section id="pricing" class="border-b border-hairline-soft">
    <div class="container-page py-20 lg:py-24">
        <div class="text-center mb-12">
            <h2 class="font-display text-ink" style="font-size:clamp(28px,3.5vw,40px);line-height:1.1;">합리적인 요금</h2>
            <p class="mt-4 text-muted" style="font-size:17px;">무료로 시작하고, 필요할 때만 올리세요.</p>
        </div>
        <div class="grid gap-6 md:grid-cols-3" style="max-width:960px;margin-inline:auto;">
            
            <div class="card p-8 flex flex-col">
                <div class="text-ink font-semibold" style="font-size:20px;">무료</div>
                <div class="mt-3 font-display text-ink" style="font-size:36px;line-height:1;">0<span style="font-size:16px;" class="text-muted">원</span></div>
                <p class="mt-2 text-muted" style="font-size:14px;">지금 바로 순위 확인</p>
                <ul class="mt-6 flex flex-col gap-2 flex-1" style="font-size:14px;">
                    <?php $__currentLoopData = ['1회성 순위 조회','키워드 1개 추적','블로그 지수 조회']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $li): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="flex gap-2 text-body"><span style="color:var(--color-success);">✓</span><?php echo e($li); ?></li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
                <a href="/#hero-form" class="btn btn-secondary mt-6">무료로 시작</a>
            </div>
            
            <div class="p-8 flex flex-col rounded-lg bg-surface-dark text-on-dark">
                <div class="flex items-center gap-2">
                    <span class="font-semibold" style="font-size:20px;">프로</span>
                    <span class="badge" style="background:var(--color-surface-dark-elevated);color:var(--color-on-dark);font-size:11px;">인기</span>
                </div>
                <div class="mt-3 font-display" style="font-size:36px;line-height:1;">29,000<span style="font-size:16px;" class="text-on-dark-soft">원/월</span></div>
                <p class="mt-2 text-on-dark-soft" style="font-size:14px;">본격 순위 관리</p>
                <ul class="mt-6 flex flex-col gap-2 flex-1" style="font-size:14px;">
                    <?php $__currentLoopData = ['키워드 무제한 추적','매일 자동 순위 기록·알림','경쟁사 30위 분석','공유 리포트']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $li): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="flex gap-2 text-on-dark-soft"><span style="color:var(--color-badge-emerald);">✓</span><?php echo e($li); ?></li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
                <a href="/#hero-form" class="btn mt-6" style="background:var(--color-canvas);color:var(--color-ink);">프로 시작</a>
            </div>
            
            <div class="card p-8 flex flex-col">
                <div class="text-ink font-semibold" style="font-size:20px;">마케팅 대행</div>
                <div class="mt-3 font-display text-ink" style="font-size:36px;line-height:1;">맞춤<span style="font-size:16px;" class="text-muted"> 견적</span></div>
                <p class="mt-2 text-muted" style="font-size:14px;">분석 + 실행까지</p>
                <ul class="mt-6 flex flex-col gap-2 flex-1" style="font-size:14px;">
                    <?php $__currentLoopData = ['플레이스 최적화','블로그·체험단 매칭','광고 운영 대행','전담 매니저']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $li): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="flex gap-2 text-body"><span style="color:var(--color-success);">✓</span><?php echo e($li); ?></li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
                <a href="/support" class="btn btn-secondary mt-6">상담 문의</a>
            </div>
        </div>
    </div>
</section>


<section class="container-page py-20 lg:py-24">
    <div class="card-soft text-center" style="padding:48px 24px;">
        <h2 class="font-display text-ink" style="font-size:clamp(26px,3vw,32px);line-height:1.2;">30초면 내 순위를 알 수 있어요.</h2>
        <p class="mt-3 text-muted" style="font-size:16px;">가입도, 카드도 필요 없습니다. 지금 키워드만 넣어보세요.</p>
        <a href="/#hero-form" class="btn btn-primary btn-lg mt-6">무료로 순위 확인하기</a>
    </div>
</section>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.site', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\jxame\Documents\project\rankfree\.claude\worktrees\rankfree-bootstrap\resources\views/welcome.blade.php ENDPATH**/ ?>