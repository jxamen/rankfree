# Exposure pacing proposal (not deployed)

Historical Naver response telemetry is absent: successful HTML submissions in the access log and DB saved results cannot supply a request denominator or a 403 rate. The 1600 ms floor is provisional, based on the previous slower setting's upper end, not a measured low-block rate. Keep this PR draft until a measured interval can replace it.

The extension serializes screen and background exposure fetches through one worker lane. IndexedDB keeps successful raw script fragments by KST day + normalized keyword, so each target is parsed independently. Storage failures stop collection. Chrome local storage preserves cooldown and pacing across worker restarts, counts 403/429/captcha/slow entries and keeps 72 hourly buckets in `rfSerpPolicy`. These browser counters are local, not centrally uploaded. Captcha and HTTP counters can overlap. Blocks double the gap up to 15s and enforce 30 minutes without another network request. Twenty consecutive successes lower the gap by 100ms to 1600ms. Existing longer screen rests remain.

The server exposure path uses a shared cache lock, a day/keyword raw HTML cache, and a persistent cooldown with the same adaptation. `SHOP_EXPOSURE_MIN_INTERVAL_MS` configures its provisional floor (minimum 1600ms). Structured `shop_serp_request` logs include status, captcha, preceding interval and slow-mode entry. Network exceptions are not successful samples.

Cache scope: each browser profile has its own cache; server workers share the Laravel cache. Caches are not shared between browsers and server. The patch does not coordinate separate browsers behind one public IP beyond the existing server run lock. Other m.search features such as keywordSignals remain outside this exposure-only policy. This is not an IP/proxy/identity change.

PR #17 is merged and is an ancestor of the production commit checked on 2026-10-18. Its server target guard and screen stop remain; the extension background queue now also stops immediately after a `target_reached` save response.

Validation: Node policy tests cover serialization, same-day reuse, next-day fetch, 403/429/captcha cooldown, progressive recovery and storage failure. PHP tests cover server cooldown, captcha exclusion, raw HTML reuse for different targets, plus existing target/IP/lock behavior. Browser runtime/IndexedDB visual checks and production traffic checks have not been performed. No deployment, merge, production cache/DB write or live Naver probe was performed.
