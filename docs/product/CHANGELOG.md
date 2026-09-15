# Changelog — insights-wp Bug-Fix & Methodology Session

**Base commit:** `fc46520e8585a34c03221bbba983e510dd77ba51` (branch `main`)
**Scope:** 8 files — `global-reference-data.php`, `blomstra-index-utilities.php`, `blomstra-index-alerts.php` (read only, no changes), `sivi-backend.php` (no functional changes), `sivi-shortcode.php`, `seri-backend.php`, `seri-shortcode.php`, `index-frontend-engine.js`, `index-frontend-styles.css`

This document exists so the current live code can be diffed against the original GitHub baseline and every change accounted for. Grouped by file, in the order changes were made. Each entry states what changed and why.

---

## `src/shared/global-reference-data.php`

1. **[Critical] HHI data corruption on total-failure runs.** A run where every fetchable country returned `permanent_failure` could overwrite good production HHI data with nulls. `array_merge()` let a null-valued failure placeholder overwrite an existing valid value; the promotion gate counted raw array-key count instead of valid-value count. Fixed: merge now excludes null-valued results before staging; gate counts non-null entries only.
2. **[High] HHI cron status misclassification.** A 100%-failure run was reported `'success'` — the completion check only looked for retryable/pending/quota states, never checking whether anything succeeded. Fixed: an explicit all-failed check now reports `'error'`.
3. **[High] EIA cron status misclassification.** Same pattern — "all fuels processed" was reported success regardless of whether any fuel actually fetched data. Fixed: checks how many fuels ended up permanently failed before reporting success.
4. **[High] World Bank indicators cron status misclassification.** Same pattern, plus it only checked the current tick's batch of 3, not the whole run. Fixed: pointer now tracks a cumulative `failed_total` across all ticks of a run (`blomstra_get_wb_pointer()` / `blomstra_update_wb_pointer()` signatures extended); completion reports error/partial/success based on the whole run.
5. **[Major root cause correction] Comtrade 403 misclassification.** UN Comtrade returns HTTP 403 for both genuine auth failure and for exceeding call-volume quota. Every 403 was being treated as a permanent, bad-key failure. Fixed: the 403 handler now inspects the response body for "quota" and reclassifies as `QUOTA_EXHAUSTED` (temporary) instead of `PERMANENT_FAILURE` when detected, extracting the "resets in HH:MM:SS" countdown into the log.
6. **[Critical] HHI infinite loop — three-layer fix:**
   - **Root cause:** the cron handler always called `blomstra_refresh_comtrade_hhi_data(null, null, true)` — `force=true` unconditionally resets all progress on *every* invocation, including the debounced 60-second self-reschedule whose entire purpose is to resume a quota-interrupted run. Resumption could never actually happen. Fixed: changed the call to `force=false`, letting the function's own correct internal reset-vs-resume logic take effect.
   - **Secondary:** a quota-interrupted chunk exited before ever incrementing `$attempts`, so a chunk unlucky enough to repeatedly hit the quota cutoff could make zero progress toward `BLOMSTRA_HHI_MAX_ATTEMPTS` forever. Fixed: quota interruption now credits an attempt and checks the threshold itself.
   - **Backstop:** added a `cycle_runs` counter to the HHI pointer (`blomstra_get_hhi_pointer()` / `blomstra_update_hhi_pointer()` signatures extended with a 5th parameter), incremented on every resumption; forces the cycle to terminate after 30 resumptions regardless of cause. Threaded through all 5 pointer-save call sites in the function.
7. **[Robustness] HHI time-budget safety net.** Added a self-imposed 480-second internal time budget checked at the top of each chunk iteration, so the function always reaches its final status-update code before any real PHP execution-time hard-kill, instead of risking a silent freeze at `'running'` forever.
8. **[Security + data-loss bug] API credential handling.** The real Comtrade/EIA key was being echoed into a `type="password"` field's `value` attribute — visible in page source despite appearing masked. This also meant a blank field on save was treated as "clear the credential," so simply re-saving the settings page without retyping a key wiped it. Fixed: fields now render blank with a `CONFIGURED ✓ (••••last4chars)` hint instead of the secret; a blank submission now means "leave unchanged"; an explicit checkbox is required to actually clear a key.
9. **[Bug, found during verification] Broken inline PHP tag inside a JavaScript string.** The historical-cache-manager admin screen embedded a literal `<?php wp_nonce_field(...); ?>` tag inside a `<script>` block's JS string — real, executable PHP that ran once server-side and corrupted the rendered script. Fixed: the nonce is now computed once, server-side, and passed into JS as a plain variable.
10. **[Structural, reverted] Missing opening `<?php` tag.** Initially added `<?php` to the top of this file (and the other 6) believing it was a build-pipeline artifact. This was wrong: the plugin runs as WPCode snippets executed via `eval()`, which assumes its input is already in PHP mode — adding the tag broke the live site (WPCode auto-deactivated the snippet, taking down `admin_menu` registration). **Reverted** — the file correctly has no leading `<?php`, as originally committed.

## `src/shared/blomstra-index-utilities.php`

11. **[Critical, affects every index] `blomstra_build_partial_rank_display()` mislabeled its own low/high range.** It assumed injection point 10 always produces the smaller (better) rank number and point 90 always the bigger one, without checking. That assumption is backwards for every index on this engine — a lower injected value always pushes the composite further from rank #1, so point 10 actually produces the *bigger* rank number. Result: partial-coverage countries could display ranges like `#37–9*` (first number bigger than the second). Fixed with `min()`/`max()` so the range is correctly ordered regardless of which direction either point's rank comes out — a universal fix, not specific to either index's orientation.

## `src/indices/sivi/sivi-shortcode.php`

12. **Band labels updated** from `"Low,Medium,High,Extreme"` to `"Very Good,Good,Poor,Very Poor"` — see the label/color scheme redesign below. No other changes to this file (the earlier `<?php` addition was reverted, net no change).

## `src/indices/seri/seri-backend.php`

13. **[Live-crash bug] Wrong field name.** Displayed `$seri_status['last_run']` and `$last_cron['seri_daily']['last_run']`, a field name `blomstra_update_cron_status()` never writes (it writes `last_attempt`). Caused a fatal error that got the live WPCode snippet auto-deactivated. Fixed all 7 occurrences.
14. **[High] Daily and weekly cron status misclassification** — both reported `'success'` unconditionally regardless of whether `seri_build_composite()` returned an error. Fixed with the same error-check pattern as the reference-data layer.
15. **[Major, Version 5.0.0] Full methodology polarity flip.** Every indicator across all four pillars (governance, macro, external, fiscal) was built so a HIGH value meant more at-risk — internally 100% consistent, but the wrong direction for a resilience index, and contradicting its own name and public methodology text. Flipped every indicator's sign at the source (governance: removed the `100 - raw` inversion; macro/external/fiscal: swapped which indicators get negated). `SERI_VERSION` bumped `4.3.0` → `5.0.0`; `standard_version` bumped `BMS-1.1.0` → `BMS-1.2.0` (Major, since it changes the meaning of every historical score).
16. **[Critical, my own mistake from the flip above] Stale pre-flip rank-sort direction.** A block computing `rank_display` for both full- and partial-coverage countries still had `asort()` (ascending) with a comment saying "lowest score = most resilient" — correct before the flip, backwards after it. This corrupted the backend's stored rank for every SERI country (full-coverage countries only looked right because the frontend's client-side rank recompute overrides the backend value for them). Fixed: `arsort()` (descending) plus the matching comparison-direction flip in the partial-rank counting loop.

## `src/indices/seri/seri-shortcode.php`

17. Pillar labels updated to positive/strength framing matching the methodology flip: "Governance Risk" → "Governance Strength", "Macro Vulnerability" → "Macro Stability" (already matched the internal pillar name), "External Vulnerability" → "External Resilience", "Fiscal Stress" → "Fiscal Strength".
18. Methodology text and subtitle rewritten for the new polarity ("higher score = more resilient" instead of "lower score = higher resilience").
19. Score label changed from "Structural Score" to "Resilience Score".
20. Added `data-biw-orientation="higher_is_better"` and `data-biw-metric-name="Resilience"` — the two new engine config attributes (see below).
21. Removed two dead, never-implemented config attributes discovered along the way: `data-biw-sort-direction="asc"` and `data-biw-band-select-label` — confirmed via grep that the engine never read either one.
22. Band labels set to `"Very Good,Good,Poor,Very Poor"` (see label/color scheme redesign below) — went through one incorrect intermediate state (reversed label order, `"Extreme,High,Medium,Low"`) which was reverted after user testing showed it was wrong; final state uses the same label string as SIVI, with the engine's orientation flag handling the direction automatically.

## `src/frontend/index-frontend-engine.js`

### New shared engine capability (added to support SERI sharing this engine with SIVI)
23. Added `data-biw-orientation` config attribute (`"higher_is_worse"` default / `"higher_is_better"`), read once at widget construction.
24. Added `data-biw-metric-name` config attribute (default `"Vulnerability"`), used to genericize card titles, dropdown text, and extremes-panel headers instead of hardcoding "Vulnerability".
25. Added shared helper `orientedBandColors()` — the single source of truth for the 4-color band array, reversed when `orientation === 'higher_is_better'`.
26. Added shared helper `orientedBandIndex(b)` — same reversal logic, applied to band **labels** (see label/color redesign below).
27. Added shared helper `deltaColorClass(type)` — orientation-aware color-class selection for rank-movement arrows, used by both the table and the drawer.

### Consolidated duplicate band-color/label logic (12 separate hardcoded copies found and fixed across several rounds)
Each of the following independently hardcoded the same band-to-color or band-to-label mapping, none aware of orientation. All now route through the shared helpers above:
28. `getCountryColor()` — map choropleth fill.
29. `getColor()` — feeds the drawer's score display, movers list, scatter plot dots, and block-comparison badges.
30. The donut (band-distribution) chart's slice fill.
31. The histogram bars.
32. The map's own legend (also previously hardcoded the label text, ignoring the configured `bandLabels`).
33. The "Resilience/Vulnerability Levels" summary panel — this one used fixed CSS classes (`dist-dot extreme/high/medium/low`) tied to fixed stylesheet colors, not JS logic at all; converted to inline oriented colors.
34. **`badgeHtml()`** — the function rendering the actual score badge **in the table** — had its own direct `bandClasses[b]` lookup bypassing `getRiskClass()` entirely. This was the single most consequential of the twelve, since it's why the table itself kept showing backwards colors after the first "consolidation" pass.
35. The country drawer's delta-arrow rendering — its own hardcoded `biw-delta-up`/`biw-delta-down` assignment, independent of the table's version.
36. The "Top movers" summary-card widget — hardcoded `mover-up`/`mover-down` classes the same unoriented way.
37. The map's own country-hover tooltip — used `bandLabels[band(score)]` directly while its color already correctly used `getColor(score)`, producing a tooltip whose label and color actively contradicted each other (reported live: Norway showing "Very Poor", Venezuela showing "Very Good").
38. The donut chart's hover tooltip — same label/color mismatch pattern as the map tooltip.

### Rank-movement and delta fixes
39. `deltaHtml()` (table) and the drawer's delta rendering: wording changed from "Rank worsened (more vulnerable)" / "Rank improved (less vulnerable)" — backwards for a higher-is-better index — to fully orientation-neutral "Rank moved up, closer to #1" / "Rank moved down, further from #1", which needs no configuration at all.
40. Delta arrow **color** made orientation-aware via `deltaColorClass()` — moving toward rank #1 is bad (red) for SIVI, good (green) for SERI. The arrow direction itself always reflects the real numeric movement regardless of orientation.

### Partial-coverage rank display (shared bug, affects SIVI and SERI equally, not introduced this session)
41. `rankHtml()` (table) and the drawer's rank display both used to prefer the client-side recomputed rank over the backend's partial-coverage range display — `getRecomputedRank()` always returns a number for any country with a score, so it silently overrode the honest-uncertainty range (`#38–52*`) for *every* partial-coverage country, making that feature effectively dead in the live view. Fixed: partial coverage now always shows its backend-provided range; full coverage still uses the recomputed rank as before.

### Map marker fixes
42. Marker selection (best/worst/biggest-mover) now excludes whichever countries are already used for best/worst when picking the mover, preventing a coincidental tie (very likely right after a full ranking inversion) from silently collapsing 3 markers to 2.
43. **Root-caused and fixed the "still only 2 of 3 visible" follow-up report:** not a ranking-logic bug — the marker-placement code silently drops a marker if that specific country isn't present as its own polygon in the simplified world-map data (common for small territories; Macao SAR was the reported case). Fixed: selection now tries the best candidate first and falls back to the next-best by score/rank-change if the top choice has no placeable map feature, guaranteeing 3 actually-visible markers whenever the map data allows it.
44. Map marker/pulse color changed from purple (`#9B59B6`) to cyan (`#22D3EE`) for better visibility against the red/yellow/green band-color choropleth.

### Other fixes
45. **Dark-mode-by-default bug:** `state.isDark` defaulted to `false` (light), but the CSS class needed to actually render light mode was only added when `localStorage` explicitly said `'light'` — a first-time visitor got dark mode from the base CSS despite the JS state saying otherwise. Fixed: light now applies unless `'dark'` was explicitly saved.
46. Default `bandLabels` fallback changed from `"Low,Medium,High,Extreme"` to `"Very Good,Good,Poor,Very Poor"`.

## `src/frontend/index-frontend-styles.css`

### Label/color scheme redesign (final, user-confirmed design)
Band labels changed from intensity-based ("Low"..."Extreme", which requires already knowing an index's direction to read as good or bad) to quality-based ("Very Good, Good, Poor, Very Poor" — always means the same thing regardless of index). This meant the label must now reverse together with the color for a higher-is-better index, unlike the old design where only color reversed.

47. Renamed CSS custom properties: `--biw-low/medium/high/extreme` → `--biw-verygood/good/poor/verypoor` (all usages, including ones unrelated to score bands like DQI badges and comparison-series colors, which simply reference the same variables under their new names).
48. Renamed the two class families structurally tied to the engine's JS output: `.biw-badge-low/medium/high/extreme` → `.biw-badge-verygood/good/poor/verypoor`; `.biw-risk-badge.low/medium/high/extreme` → `.biw-risk-badge.verygood/good/poor/verypoor`.
49. Applied a corrected, genuinely monotonic color palette while renaming (the old `--biw-medium` at `#fac678` was measurably *brighter* than `--biw-high` at `#eb674e`, breaking the intended severity gradient — a pre-existing issue independent of orientation, affecting SIVI too): `verygood=#22c55e`, `good=#eab308`, `poor=#f97316`, `verypoor=#dc2626`, with matching `rgba()` background-tint values updated to match.
50. Left deliberately unchanged: purely positional class names not tied to display text (histogram's `axis-band low/med/high/ext`), DQI/provenance quality dots, and comparison-series accent colors (these distinguish one series from another, not a score band).

---

## Not changed this session (flagged, not fixed)

- **SIVI/SERI cron-staleness mixing** — sources refresh on different days; SIVI (and now SERI) rebuild daily and mix different-day-freshness pillars across countries in the same composite. DQI disclosure is the only current mitigation. This is a methodology/product decision, not a code bug — left for the user/research side to decide.
- **EIA and WB indicators** were not given the same time-budget safety net as HHI's stuck-running fix — worth doing if the same symptom is ever observed there.
- **SERI historical backfill infrastructure** (per-year status table, year-specific fetchers, backfill cron, admin range UI matching SIVI's) does not exist yet — flagged as the next piece of work, not started.
- **`blomstra-index-alerts.php`** was read and referenced but never modified.
- **`sivi-backend.php`** had no net functional changes (only the reverted `<?php` addition).

## Consequence for existing data

SERI's pre-existing snapshots (August and September 2026) were built under the old, wrong-for-resilience polarity and read backwards relative to the new methodology. A fresh SERI rebuild after deployment is required for the composite/rank values to be consistent with the new methodology going forward; the historical snapshots from before the flip should be treated as using a different, incompatible methodology version (`< 5.0.0`) rather than being directly comparable to anything built under `5.0.0`.
