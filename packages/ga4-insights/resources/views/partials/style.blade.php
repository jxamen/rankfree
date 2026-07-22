{{-- GA4 Insights 자체 스코프 CSS.
     폰트는 호스트 디자인 시스템 토큰(--fs-*, --color-*, --font-mono)을 그대로 따르고, 없으면 폴백값(px)을 쓴다(이식성).
     rankfree 기준 --fs-xs=14px 가 시스템 최소 — 이 대시보드도 그 이하로는 쓰지 않는다. --}}
<style>
.ga4-dash{
    --ga4-fg:var(--color-ink,#0a0b0d); --ga4-muted:var(--color-muted,#5b616e); --ga4-soft:var(--color-muted-soft,#8b909c);
    --ga4-line:var(--color-hairline,#e6e8ec); --ga4-line2:var(--color-hairline-soft,#eef0f3);
    --ga4-card:var(--color-canvas,#fff); --ga4-surface:var(--color-surface-strong,#f1f3f7);
    --ga4-accent:var(--color-primary,#0052ff); --ga4-up:var(--color-success,#05b169); --ga4-down:var(--color-error,#cf202f);
    --ga4-mono:var(--font-mono,ui-monospace,'JetBrains Mono',SFMono-Regular,Menlo,monospace);
    /* 폰트 스케일 — 호스트 --fs-* 토큰 우선, 폴백은 rankfree 값 */
    --ga4-fs-xs:var(--fs-xs,14px); --ga4-fs-sm:var(--fs-sm,15px); --ga4-fs-base:var(--fs-base,16px);
    --ga4-fs-md:var(--fs-md,18px); --ga4-fs-lg:var(--fs-lg,21px); --ga4-fs-xl:var(--fs-xl,25px);
    --ga4-fs-2xl:var(--fs-2xl,31px);
    color:var(--ga4-fg); font-size:var(--ga4-fs-sm); line-height:1.6;
}
.ga4-dash *{box-sizing:border-box}
.ga4-title{font-size:var(--ga4-fs-xl);font-weight:700;letter-spacing:-.01em}
.ga4-title small{color:var(--ga4-soft);font-weight:500;font-size:var(--ga4-fs-xs);letter-spacing:0}
.ga4-sub{color:var(--ga4-muted);font-size:var(--ga4-fs-xs)}

.ga4-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:18px 0 4px}
.ga4-toolbar .sp{flex:1}
.ga4-btn{display:inline-flex;align-items:center;gap:5px;padding:9px 18px;border-radius:99px;border:1px solid var(--ga4-line);background:var(--ga4-card);color:var(--ga4-fg);font-size:var(--ga4-fs-xs);font-weight:600;text-decoration:none;cursor:pointer;font-family:inherit;transition:background .12s,border-color .12s,color .12s}
.ga4-btn:hover{border-color:var(--ga4-accent);color:var(--ga4-accent)}
.ga4-btn.on{background:var(--ga4-accent);color:#fff;border-color:var(--ga4-accent)}
.ga4-btn.on:hover{color:#fff}

.ga4-section{margin-top:38px}
.ga4-section>.head{margin-bottom:16px}
.ga4-section>.head h2{font-size:var(--ga4-fs-md);font-weight:700;margin:0;letter-spacing:-.01em}
.ga4-section>.head .d{display:block;color:var(--ga4-muted);font-size:var(--ga4-fs-xs);margin-top:4px;line-height:1.55}
.ga4-section>.head .d b{color:var(--ga4-fg)}

.ga4-card{background:var(--ga4-card);border:1px solid var(--ga4-line);border-radius:16px;padding:20px;box-shadow:0 1px 2px rgba(16,24,40,.03)}
.ga4-card+.ga4-card{margin-top:14px}
.ga4-card-h{font-size:var(--ga4-fs-sm);font-weight:650;margin-bottom:14px;display:flex;align-items:center;gap:6px}
.ga4-grid{display:grid;gap:14px}
.ga4-kpis{grid-template-columns:repeat(2,1fr)}
@media(min-width:680px){.ga4-kpis{grid-template-columns:repeat(3,1fr)}}
@media(min-width:1120px){.ga4-kpis{grid-template-columns:repeat(5,1fr)}}
.ga4-cols2{grid-template-columns:1fr}
@media(min-width:1024px){.ga4-cols2{grid-template-columns:1fr 1fr}}

.ga4-kpi{padding:18px 20px}
.ga4-kpi .lab{color:var(--ga4-muted);font-size:var(--ga4-fs-xs);font-weight:500;display:flex;align-items:center;gap:5px}
.ga4-kpi .val{font-family:var(--ga4-mono);font-size:var(--ga4-fs-2xl);font-weight:650;margin-top:10px;line-height:1.05;letter-spacing:-.02em}
.ga4-kpi .dlt{display:inline-flex;align-items:center;gap:4px;font-size:var(--ga4-fs-xs);font-family:var(--ga4-mono);font-weight:600;margin-top:12px;padding:4px 11px;border-radius:99px}
.ga4-kpi .dlt.ga4-up{color:var(--ga4-up);background:color-mix(in srgb,var(--ga4-up) 12%,transparent)}
.ga4-kpi .dlt.ga4-down{color:var(--ga4-down);background:color-mix(in srgb,var(--ga4-down) 12%,transparent)}
.ga4-kpi .dlt.ga4-flat{color:var(--ga4-soft);background:var(--ga4-surface)}
.ga4-up{color:var(--ga4-up)} .ga4-down{color:var(--ga4-down)} .ga4-flat{color:var(--ga4-soft)}

.ga4-help{display:inline-flex;width:18px;height:18px;border-radius:50%;background:var(--ga4-surface);color:var(--ga4-muted);font-size:var(--ga4-fs-xs);align-items:center;justify-content:center;cursor:help;font-weight:700;font-style:normal;flex:none;line-height:1}

.ga4-bars{display:flex;flex-direction:column;gap:12px}
.ga4-bar{display:flex;align-items:center;gap:10px;font-size:var(--ga4-fs-xs)}
.ga4-bar .n{width:170px;flex:none;color:var(--ga4-fg);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ga4-bar .t{flex:1;background:var(--ga4-surface);border-radius:99px;height:11px;overflow:hidden;min-width:40px}
.ga4-bar .t>i{display:block;height:100%;background:var(--ga4-accent);border-radius:99px;transition:width .3s}
.ga4-bar .v{width:140px;text-align:right;font-family:var(--ga4-mono);color:var(--ga4-fg);font-weight:600}
.ga4-bar .v small{color:var(--ga4-soft);font-weight:400;font-size:var(--ga4-fs-xs)}

.ga4-tbl{width:100%;border-collapse:collapse;font-size:var(--ga4-fs-xs)}
.ga4-tbl th{text-align:left;color:var(--ga4-muted);font-weight:600;font-size:var(--ga4-fs-xs);padding:12px;border-bottom:1px solid var(--ga4-line);white-space:nowrap;position:sticky;top:0;background:var(--ga4-card)}
.ga4-tbl td{padding:13px 12px;border-top:1px solid var(--ga4-line2);vertical-align:middle}
.ga4-tbl tbody tr:hover td{background:color-mix(in srgb,var(--ga4-accent) 4%,transparent)}
.ga4-tbl td.num,.ga4-tbl th.num{text-align:right;font-family:var(--ga4-mono);white-space:nowrap}
.ga4-tbl td.p{max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ga4-tbl td.warn,.ga4-tbl .warn{color:var(--ga4-down);font-weight:600}
.ga4-tbl a{color:var(--ga4-accent);text-decoration:none}
.ga4-tbl a:hover{text-decoration:underline}
.ga4-scroll{overflow:auto;max-height:500px;border-radius:10px}
.ga4-empty{color:var(--ga4-soft);font-size:var(--ga4-fs-xs);text-align:center;padding:28px}

.ga4-chart{display:flex;align-items:flex-end;gap:3px;height:190px}
.ga4-chart .c{flex:1;min-width:3px;height:100%;display:flex;flex-direction:column;justify-content:flex-end}
.ga4-chart .c .n{display:none;font-size:var(--ga4-fs-xs);color:var(--ga4-soft);text-align:center;line-height:1;margin-bottom:3px;white-space:nowrap}
.ga4-chart .c .b{display:block;background:linear-gradient(var(--ga4-accent),color-mix(in srgb,var(--ga4-accent) 55%,var(--ga4-card)));border-radius:4px 4px 0 0;transition:opacity .12s}
.ga4-chart .c:hover .b{opacity:.75}
.ga4-chart.nums .c .n{display:block}
.ga4-chart.nums .c .b{max-height:calc(100% - 16px)}
.ga4-axis{display:flex;justify-content:space-between;color:var(--ga4-soft);font-size:var(--ga4-fs-xs);margin-top:8px}
/* ── 드래그앤드롭 12그리드 배치(#ga4-layout) ─────────────────────────
   한 줄에 N개 = 균등 분할(2=6+6, 3=4+4+4, 4=3+3+3+3). 섹션은 컨테이너 쿼리로 내부 그리드 적응. */
#ga4-layout .ga4-row{display:grid;gap:14px;grid-template-columns:1fr;align-items:stretch}
@media(min-width:1024px){
  #ga4-layout .ga4-row.cols2{grid-template-columns:repeat(2,1fr)}
  #ga4-layout .ga4-row.cols3{grid-template-columns:repeat(3,1fr)}
  #ga4-layout .ga4-row.cols4{grid-template-columns:repeat(4,1fr)}
}
#ga4-layout .ga4-section{margin-bottom:0;container-type:inline-size;display:flex;flex-direction:column;min-width:0}
#ga4-layout .ga4-section>.ga4-card:last-child{flex:1}
/* ⚠️ 드래그 하이라이트는 지오메트리를 절대 바꾸지 않는다(배경·아웃라인만) —
   높이가 변하면 커서 아래 요소가 요동쳐 drop 이 무효화된다(실측: enter/leave 폭주 후 dragend) */
.ga4-rowgap{height:14px;border-radius:8px;transition:background .12s}
#ga4-layout.dragging .ga4-rowgap{background:color-mix(in srgb,var(--ga4-accent) 7%,transparent)}
#ga4-layout .ga4-rowgap.over{background:color-mix(in srgb,var(--ga4-accent) 32%,transparent)}
#ga4-layout [data-sec].over{outline:2px dashed var(--ga4-accent);outline-offset:3px;border-radius:12px}
#ga4-layout [data-sec].drag-src{opacity:.45}
.ga4-drag{cursor:grab;user-select:none;touch-action:none;color:var(--ga4-soft);margin-right:8px;font-size:14px;line-height:1}
.ga4-drag:hover{color:var(--ga4-accent)}
.ga4-drag:active{cursor:grabbing}
#ga4-layout.dragging{user-select:none;cursor:grabbing}
.ga4-ghost{position:fixed;z-index:9999;pointer-events:none;background:var(--ga4-card);border:1px solid var(--ga4-accent);border-radius:8px;padding:6px 12px;font-size:var(--ga4-fs-xs);color:var(--ga4-ink,#0a0b0d);box-shadow:0 8px 24px rgba(0,0,0,.14);white-space:nowrap}
/* 드래그 중 줄 틈의 히트 영역을 위아래로 넓힌다 — 보이는 높이는 그대로(지오메트리 불변 원칙) */
#ga4-layout.dragging .ga4-rowgap{position:relative;z-index:5}
#ga4-layout.dragging .ga4-rowgap::after{content:'';position:absolute;left:0;right:0;top:-12px;bottom:-12px}
/* 지표 숨기기(✕) — 섹션에 올렸을 때만 보임 */
.ga4-section>.head{position:relative;padding-right:28px}
.ga4-hide{position:absolute;right:0;top:0;border:0;background:none;color:var(--ga4-soft);cursor:pointer;font-size:13px;line-height:1;padding:4px 6px;border-radius:6px;opacity:0;transition:opacity .12s}
.ga4-section:hover .ga4-hide{opacity:1}
.ga4-hide:hover{color:var(--ga4-down);background:color-mix(in srgb,var(--ga4-down) 8%,transparent)}
/* 지표 표시 패널 */
.ga4-secmenu{position:relative;display:inline-block}
.ga4-secmenu-panel{position:absolute;right:0;top:calc(100% + 6px);z-index:60;background:var(--ga4-card);border:1px solid var(--ga4-line);border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.12);padding:10px 12px;min-width:250px;max-height:420px;overflow:auto;display:flex;flex-direction:column;gap:2px}
.ga4-secmenu-panel label{display:flex;align-items:center;gap:8px;font-size:var(--ga4-fs-xs);color:var(--ga4-fg);padding:5px 6px;border-radius:8px;cursor:pointer;white-space:nowrap}
.ga4-secmenu-panel label:hover{background:color-mix(in srgb,var(--ga4-accent) 7%,transparent)}
.ga4-secmenu-panel input{accent-color:var(--ga4-accent)}
/* 좁은 컬럼에선 KPI·2열 카드가 컨테이너 폭에 맞춰 줄어들게(뷰포트 미디어쿼리 무시) */
#ga4-layout .ga4-kpis{grid-template-columns:repeat(2,1fr)}
@container(min-width:640px){#ga4-layout .ga4-kpis{grid-template-columns:repeat(3,1fr)}}
@container(min-width:1080px){#ga4-layout .ga4-kpis{grid-template-columns:repeat(5,1fr)}}
#ga4-layout .ga4-cols2{grid-template-columns:1fr}
@container(min-width:640px){#ga4-layout .ga4-cols2{grid-template-columns:1fr 1fr}}

.ga4-hours{display:flex;align-items:flex-end;gap:4px;height:120px}
.ga4-hours>div{flex:1;background:linear-gradient(var(--ga4-accent),color-mix(in srgb,var(--ga4-accent) 55%,var(--ga4-card)));border-radius:3px 3px 0 0;min-height:3px}
.ga4-hours-axis{display:flex;justify-content:space-between;color:var(--ga4-soft);font-size:var(--ga4-fs-xs);margin-top:8px}

.ga4-rt{display:inline-flex;align-items:center;gap:7px;font-size:var(--ga4-fs-xs);font-weight:600}
.ga4-dot{width:9px;height:9px;border-radius:50%;background:var(--ga4-up);animation:ga4pulse 1.6s infinite}
@keyframes ga4pulse{0%,100%{opacity:1}50%{opacity:.25}}

.ga4-banner{padding:15px 17px;border-radius:12px;font-size:var(--ga4-fs-xs);margin-bottom:14px}
.ga4-banner.err{background:color-mix(in srgb,var(--ga4-down) 9%,var(--ga4-card));color:var(--ga4-down);border:1px solid color-mix(in srgb,var(--ga4-down) 25%,var(--ga4-card))}
.ga4-banner.info{background:var(--ga4-surface);color:var(--ga4-muted)}
.ga4-note{color:var(--ga4-soft);font-size:var(--ga4-fs-xs);margin-top:12px;line-height:1.55}
</style>
