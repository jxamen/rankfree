# Exposure request pacing

Screen and background exposure checks share one serialized ShopSerpPolicy lane, a daily raw HTML cache, and a persistent request interval with a 1600ms floor. No count-based rests, proactive cooldowns, success-based interval changes, or queue time budgets apply. The background check queue drains continuously while work exists; alarms recover an idle or restarted worker. Empty queues, screen ownership, authentication failures, storage failures, transport retries and user/target stops retain their existing behavior.

Only an actual 403, 429 or captcha starts the existing fixed 3-minute cooldown without increasing the request gap. After expiry the next request may run; another block immediately starts another cooldown. Browser state records lastBlockedAt and blockedOnResume along with existing counters. Server shop_serp_request logs include blocked_on_resume. Successful requests keep the current gap.

The server exposure path retains its shared cache lock, daily raw HTML cache and the same block policy. SHOP_EXPOSURE_MIN_INTERVAL_MS retains a minimum of 1600ms. Screen and background loops rely on that policy without extra success sleeps. Server batch polling resumes immediately; per-request configured server batch intervals remain.

Browser caches are per profile; server caches are shared between workers. Separate profiles and server requests are not coordinated. No live Naver probes are used in tests.
