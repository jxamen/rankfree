<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $__env->yieldContent('title', 'rankfree — 네이버 플레이스·쇼핑 순위 분석'); ?></title>
    <meta name="description" content="<?php echo $__env->yieldContent('description', '키워드만 입력하면 네이버 플레이스·쇼핑 순위와 경쟁사, 블로그 지수를 무료로 분석합니다. 가입 없이 바로 시작하세요.'); ?>">
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
    <?php echo $__env->yieldPushContent('head'); ?>
</head>
<body class="bg-canvas text-body font-sans antialiased min-h-screen flex flex-col">
    <?php echo $__env->make('partials.site-header', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <main class="flex-1">
        <?php echo $__env->yieldContent('content'); ?>
    </main>
    <?php echo $__env->make('partials.site-footer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php echo $__env->yieldPushContent('scripts'); ?>
</body>
</html>
<?php /**PATH C:\Users\jxame\Documents\project\rankfree\.claude\worktrees\rankfree-bootstrap\resources\views/layouts/site.blade.php ENDPATH**/ ?>