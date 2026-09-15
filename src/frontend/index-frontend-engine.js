/* =====================================================================
   Blomstra Index Frontend Engine — v4.1.8
   - Countries Covered: total uses default text color
   - Composite Analytics: all stats use default text color
   - "Compare Blocks" → "Compare Groups"
   - Block share buttons: "CSV" and "Share" with icons
   - Map tooltip now uses pillars (generic)
   - Added data-biw-block-groups filter
   - Fixed hover contrasts for tabs and map zoom
   ===================================================================== */

// ─── Check for shared utilities ──────────────────────────────────
if (typeof window.BIW_GET_ISO3 !== 'function') {
    console.warn('Blomstra: BIW_GET_ISO3 not found – country codes will fail.');
}
if (!window.BIW_COUNTRY_GROUPS) {
    console.warn('Blomstra: BIW_COUNTRY_GROUPS not found – block comparisons disabled.');
}

(function () {
    'use strict';

    var getIso3 = window.BIW_GET_ISO3 || function() { return null; };
    var allCountryGroups = window.BIW_COUNTRY_GROUPS || {};

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = function () { reject(new Error('Failed to load: ' + src)); };
            document.head.appendChild(script);
        });
    }

    var d3Loaded = false;
    var topojsonLoaded = false;

    function ensureD3() {
        if (typeof d3 !== 'undefined') {
            d3Loaded = true;
            return Promise.resolve();
        }
        return loadScript('https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js')
            .then(function () {
                d3Loaded = true;
                if (typeof d3 === 'undefined') throw new Error('D3 global missing');
            });
    }

    function ensureTopojson() {
        if (typeof topojson !== 'undefined') {
            topojsonLoaded = true;
            return Promise.resolve();
        }
        return loadScript('https://cdn.jsdelivr.net/npm/topojson-client@3')
            .then(function () {
                topojsonLoaded = true;
                if (typeof topojson === 'undefined') throw new Error('Topojson global missing');
            });
    }

    function boot() {
        var containers = document.querySelectorAll('.biw[data-biw-slug]:not([data-biw-initialized])');
        containers.forEach(function (el) {
            el.setAttribute('data-biw-initialized', '1');
            try {
                new BlomstraIndexWidget(el);
            } catch (e) {
                el.innerHTML = '<div class="biw-error">Widget failed: ' + (e && e.message ? e.message : e) + '</div>';
                if (window.console) console.error('BlomstraIndexWidget init error', e);
            }
        });
    }

    function BlomstraIndexWidget(root) {
        // ─── Per-index block group filter ─────────────────────────────
        var blockGroupsAttr = root.getAttribute('data-biw-block-groups');
        var blockGroups = [];
        if (blockGroupsAttr) {
            try {
                blockGroups = JSON.parse(blockGroupsAttr);
                if (typeof blockGroups === 'string') blockGroups = [blockGroups];
                if (!Array.isArray(blockGroups)) blockGroups = [];
            } catch (e) {
                blockGroups = [];
            }
        }
        var countryGroups = blockGroups.length
            ? Object.keys(allCountryGroups)
                .filter(function(name) { return blockGroups.indexOf(name) !== -1; })
                .reduce(function(acc, name) {
                    acc[name] = allCountryGroups[name];
                    return acc;
                }, {})
            : allCountryGroups;

        var slug = root.getAttribute('data-biw-slug');
        var endpoint = root.getAttribute('data-biw-endpoint');
        var namesEndpoint = root.getAttribute('data-biw-names-endpoint') || '/wp-json/blomstra/v1/country-names';
        var historyEndpoint = root.getAttribute('data-biw-history-endpoint') || ('/wp-json/blomstra/v1/index-history/' + slug);
        var scoreKey = root.getAttribute('data-biw-score-key') || 'sivi_structural';
        var coverageKey = root.getAttribute('data-biw-coverage-key') || 'coverage';
        var view = root.getAttribute('data-biw-view') || 'dashboard';
        var pillars = [];
        try { pillars = JSON.parse(root.getAttribute('data-biw-pillars') || '[]'); } catch (e) { pillars = []; }
        var bandThresholds = (root.getAttribute('data-biw-band-thresholds') || '25,50,75').split(',').map(Number);
        var bandLabels = (root.getAttribute('data-biw-band-labels') || 'Very Good,Good,Poor,Very Poor').split(',');
        var bandClasses = ['biw-badge-verygood', 'biw-badge-good', 'biw-badge-poor', 'biw-badge-verypoor'];
        var scoreLabel = root.getAttribute('data-biw-score-label') || 'Vulnerability Score';
        // NEW (2026-09): generic support for indices where a HIGH score is
        // GOOD rather than bad (e.g. a resilience index vs. a vulnerability
        // index), sharing this same engine. Defaults preserve exactly the
        // existing behavior for any index that doesn't set these.
        var orientation = root.getAttribute('data-biw-orientation') || 'higher_is_worse'; // or 'higher_is_better'
        var metricName = root.getAttribute('data-biw-metric-name') || 'Vulnerability';
        var methodology = root.getAttribute('data-biw-methodology') || '';

        var minYear = parseInt(root.getAttribute('data-biw-year-min')) || 2004;
        var maxYear = parseInt(root.getAttribute('data-biw-year-max')) || (new Date().getFullYear());

        var watchlistKey = 'biw_watchlist_' + slug;
        var watchlist = [];
        try { watchlist = JSON.parse(localStorage.getItem(watchlistKey) || '[]'); } catch (e) { watchlist = []; }

        var state = {
            all: [],
            filtered: [],
            names: {},
            history: {},
            indexMeta: null,
            view: view,
            sortKey: 'rank',
            sortAsc: false,
            showWatchlistOnly: false,
            selectedCountry: null,
            d3Ready: false,
            d3Error: null,
            compareList: [],
            isDark: false,
            hasRegionData: false,
            selectedYear: maxYear,
            mapLayer: 'score',
            mapProjection: null,
            mapPath: null,
            mapFeatures: null,
            mapMarkers: null,
            mapHintObserver: null,
            mapHintTimeout: null,
            blockCompareA: null,
            blockCompareB: null,
        };

        root.innerHTML = buildShell();
        var q = function (sel) { return root.querySelector(sel); };
        var qa = function (sel) { return root.querySelectorAll(sel); };

        var drawerOverlay = document.getElementById('biw-drawer-overlay');
        var drawer = document.getElementById('biw-drawer');

        var compareDock = document.getElementById('biw-compare-dock');
        var compareItems = document.getElementById('biw-compare-items');
        var compareCount = document.getElementById('biw-compare-count');

        bindControls();
        loadData();

        // ── Helpers ──────────────────────────────────────────────
        function getHistoricalEntry(iso3, year) {
            var hist = state.history[iso3] || [];
            if (hist.length === 0) return null;
            var best = null;
            var bestPeriod = '';
            hist.forEach(function (entry) {
                var period = entry.period || '';
                var entryYear = parseInt(period.substring(0, 4), 10);
                if (!isNaN(entryYear) && entryYear === year) {
                    if (period > bestPeriod) {
                        bestPeriod = period;
                        best = entry;
                    }
                }
            });
            if (best) return best;
            var bestDiff = Infinity;
            hist.forEach(function (entry) {
                var period = entry.period || '';
                var entryYear = parseInt(period.substring(0, 4), 10);
                if (!isNaN(entryYear)) {
                    var diff = Math.abs(entryYear - year);
                    if (diff < bestDiff) {
                        bestDiff = diff;
                        best = entry;
                    }
                }
            });
            return best;
        }

        function getHistoricalPillar(iso3, year, pillarKey) {
            var entry = getHistoricalEntry(iso3, year);
            if (entry && entry.pillars && entry.pillars[pillarKey] !== undefined) {
                return entry.pillars[pillarKey];
            }
            if (entry && entry[pillarKey] !== undefined) {
                return entry[pillarKey];
            }
            return null;
        }

        function getHistoricalScore(iso3, year) {
            var entry = getHistoricalEntry(iso3, year);
            if (entry && entry.composite_score !== undefined) {
                return entry.composite_score;
            }
            return null;
        }

        function getScoreForYear(iso3, year) {
            return getHistoricalScore(iso3, year);
        }

        // ─── getRecomputedRank with fallback ──────────────────────
        function getRecomputedRank(iso3, year) {
            var scores = {};
            var liveCountry = state.all.find(function(c) { return c.iso3 === iso3; });
            var hasHistory = false;
            state.all.forEach(function (country) {
                var score = getScoreForYear(country.iso3, year);
                if (score !== null && score !== undefined) {
                    scores[country.iso3] = score;
                    hasHistory = true;
                }
            });
            if (!hasHistory || Object.keys(scores).length < 2) {
                if (liveCountry && liveCountry.rank !== undefined && liveCountry.rank !== null) {
                    return liveCountry.rank;
                }
                if (liveCountry && liveCountry.rank_display && liveCountry.rank_display.best_estimate !== undefined) {
                    return liveCountry.rank_display.best_estimate;
                }
                return null;
            }
            var sorted = Object.keys(scores).sort(function (a, b) { return scores[b] - scores[a]; });
            var rank = sorted.indexOf(iso3) + 1;
            return rank > 0 ? rank : null;
        }

        // ─── Build shell ──────────────────────────────────────────
        function buildShell() {
            var title = root.getAttribute('data-biw-title') || '';
            var subtitle = root.getAttribute('data-biw-subtitle') || '';
            var eyebrow = root.getAttribute('data-biw-eyebrow') || 'Strategic Intelligence';

            var shell = '<div class="biw-header">' +
                '  <div class="biw-header-left">' +
                '    <span class="biw-eyebrow">' + esc(eyebrow) + '</span>' +
                '    <h1>' + esc(title) + '</h1>' +
                '    <div class="biw-sub">' + esc(subtitle) + '</div>' +
                '    <div class="biw-meta biw-last-updated">Loading data…</div>' +
                '  </div>' +
                '  <button class="biw-header-method" id="biw-header-method">📖 Methodology</button>' +
                '</div>';

            if (view === 'dashboard') {
                shell += renderDashboardShell();
            } else {
                shell += renderTableShell();
            }

            if (methodology) {
                shell += '<div class="biw-methodology-bottom">' + methodology + '</div>';
            }

            shell += '<div class="biw-drawer-overlay" id="biw-drawer-overlay"></div>' +
                '<div class="biw-drawer" id="biw-drawer">' +
                '  <button class="biw-drawer-close" id="biw-drawer-close">✕</button>' +
                '  <div class="drawer-head"><div class="drawer-country" id="drawer-country">—</div></div>' +
                '  <div class="drawer-score-rank">' +
                '    <div><span class="score" id="drawer-score">—</span><br><span style="font-size:0.75rem;color:var(--biw-slate-dim);">' + esc(scoreLabel) + '</span></div>' +
                '    <div class="rank-wrap">' +
                '      <span class="rank" id="drawer-rank">—</span>' +
                '      <span id="drawer-risk-badge"></span>' +
                '      <span class="rank-delta" id="drawer-delta"></span>' +
                '      <div id="drawer-dqi-badge" style="margin-top:4px;"></div>' +
                '    </div>' +
                '  </div>' +
                '  <div class="drawer-radar"><div class="drawer-radar-label">Pillar radar</div><div id="drawer-radar"></div></div>' +
                '  <div class="drawer-sensitivity" id="drawer-sensitivity" style="display:none;"></div>' +
                '  <div class="drawer-history"><div class="drawer-history-label">Score trend</div><div id="drawer-history-chart"></div></div>' +
                '  <div class="drawer-provenance" id="drawer-provenance" style="display:none;"><div class="drawer-provenance-label">Data provenance</div><div id="drawer-provenance-items"></div></div>' +
                '  <div class="drawer-pillars" id="drawer-pillars"></div>' +
                '  <div class="drawer-why" id="drawer-why">Select a country to see insight.</div>' +
                '  <div><span class="drawer-coverage" id="drawer-coverage">—</span></div>' +
                '  <div class="drawer-actions-bottom">' +
                '    <button id="drawer-watchlist">☆ Watchlist</button>' +
                '    <button id="drawer-compare">◫ Compare</button>' +
                '    <button id="drawer-share" class="primary">🔗 Share</button>' +
                '  </div>' +
                '</div>';

            shell += '<div class="biw-compare-dock" id="biw-compare-dock">' +
                '  <div class="dock-header"><span>Compare (<span id="biw-compare-count">0</span>/4)</span><button class="close-dock" id="biw-compare-dock-close">✕</button></div>' +
                '  <div class="dock-items" id="biw-compare-items"></div>' +
                '  <div class="dock-actions"><button id="biw-compare-view-btn">View comparison</button><button class="secondary" id="biw-compare-clear-btn">Clear</button></div>' +
                '</div>';

            shell += '<div class="biw-compare-modal-overlay" id="biw-compare-overlay"></div>' +
                '<div class="biw-compare-modal" id="biw-compare-modal">' +
                '  <button class="modal-close" id="biw-compare-modal-close">✕</button>' +
                '  <h2>Country comparison</h2>' +
                '  <div class="compare-grid">' +
                '    <div class="radar-box" id="compare-radar-box"></div>' +
                '    <div class="table-wrap" id="compare-table-wrap"></div>' +
                '  </div>' +
                '</div>';

            // ─── Block Comparison Modal ──────────────────────────────
            shell += '<div class="biw-block-compare-overlay" id="biw-block-overlay"></div>' +
                '<div class="biw-block-compare-modal" id="biw-block-modal">' +
                '  <button class="modal-close" id="biw-block-close">✕</button>' +
                '  <div class="block-header">' +
                '    <h2><span id="biw-block-title">🏛️ Compare Groups</span></h2>' +
                '    <div class="block-share">' +
                '      <button id="biw-block-share" title="Share this comparison">🔗 Share</button>' +
                '      <button id="biw-block-download" title="Download CSV">⬇ CSV</button>' +
                '    </div>' +
                '  </div>' +
                '  <div class="block-selector">' +
                '    <div class="block-select-group">' +
                '      <label>Group A:</label>' +
                '      <select id="biw-block-a"><option value="">— Select —</option></select>' +
                '    </div>' +
                '    <div class="block-select-group">' +
                '      <label>Group B (optional):</label>' +
                '      <select id="biw-block-b"><option value="">— None —</option></select>' +
                '    </div>' +
                '    <button id="biw-block-compare-btn" class="button">Compare</button>' +
                '  </div>' +
                '  <div class="block-tabs" id="block-tabs-wrapper" style="display:none;">' +
                '    <button class="block-tab active" data-tab="overview">Overview</button>' +
                '    <button class="block-tab" data-tab="detailed">Detailed Comparison</button>' +
                '  </div>' +
                '  <div class="block-results" id="biw-block-results" style="display:none;">' +
                '    <div class="block-tab-content active" id="block-tab-overview"></div>' +
                '    <div class="block-tab-content" id="block-tab-detailed"></div>' +
                '  </div>' +
                '</div>';

            shell += '<div class="biw-method-overlay" id="biw-method-overlay"></div>' +
                '<div class="biw-method-modal" id="biw-method-modal">' +
                '  <button class="modal-close" id="biw-method-close">✕</button>' +
                '  <h2>Methodology</h2>' +
                '  <div class="content">' + methodology + '</div>' +
                '</div>';

            return shell;
        }

        // ─── Render shells ────────────────────────────────────────
        function renderTableShell() {
            return '<div class="biw-table-toolbar">' +
                '  <input type="text" class="biw-search" placeholder="Search countries…">' +
                '  <div class="biw-table-controls">' +
                '    <select class="biw-select biw-region-filter" id="biw-region-filter"><option value="all">All regions</option><option value="Africa">Africa</option><option value="Americas">Americas</option><option value="Asia">Asia</option><option value="Europe">Europe</option><option value="Oceania">Oceania</option></select>' +
                '    <select class="biw-select biw-band-filter"><option value="all">All ' + metricName.toLowerCase() + ' levels</option>' +
                '      <option value="0">' + esc(bandLabels[orientedBandIndex(0)]) + '</option>' +
                '      <option value="1">' + esc(bandLabels[orientedBandIndex(1)]) + '</option>' +
                '      <option value="2">' + esc(bandLabels[orientedBandIndex(2)]) + '</option>' +
                '      <option value="3">' + esc(bandLabels[orientedBandIndex(3)]) + '</option>' +
                '    </select>' +
                '    <select class="biw-select biw-sort-select">' +
                '      <option value="rank">Sort: Rank</option>' +
                '      <option value="name">Sort: Country</option>' +
                '      <option value="' + esc(scoreKey) + '">Sort: Score</option>' +
                '      <option value="coverage">Sort: Coverage</option>' +
                pillars.map(function (p) { return '<option value="' + esc(p.key) + '">Sort: ' + esc(p.label) + '</option>'; }).join('') +
                '    </select>' +
                '    <select class="biw-select biw-block-dropdown" id="biw-block-dropdown">' +
                '      <option value="">🏛️ Compare Groups</option>' +
                Object.keys(countryGroups).map(function(name) {
                    return '<option value="' + name + '">' + name + '</option>';
                }).join('') +
                '      <option value="__all__">View All Groups →</option>' +
                '    </select>' +
                '    <button class="biw-watchlist-toggle"><span>★</span> Watchlist</button>' +
                '    <button class="biw-btn-print">Print</button>' +
                '    <button class="biw-btn-export" id="biw-btn-export">⬇ CSV</button>' +
                '    <div class="biw-share-group">' +
                '      <button class="biw-btn-share" data-share="x">𝕏</button>' +
                '      <button class="biw-btn-share" data-share="linkedin">in</button>' +
                '      <button class="biw-btn-share" data-share="copy">🔗</button>' +
                '    </div>' +
                '  </div>' +
                '</div>' +
                '<div class="biw-table-wrap"><table class="biw-table"><thead><tr class="biw-head-row"></tr></thead><tbody class="biw-tbody"></tbody></table></div>' +
                '<div class="biw-no-results" style="display:none;">No countries found.</div>';
        }

        function renderDashboardShell() {
            return '' +
                '<div class="biw-summary-grid">' +
                '  <div class="biw-summary-card" id="biw-countries-card">' +
                '    <b class="card-title">Countries Covered</b>' +
                '    <div id="biw-countries-detail"></div>' +
                '  </div>' +
                '  <div class="biw-summary-card" id="biw-score-card">' +
                '    <b class="card-title">Composite Analytics</b>' +
                '    <div id="biw-score-detail"></div>' +
                '  </div>' +
                '  <div class="biw-summary-card" id="biw-dist-card">' +
                '    <b class="card-title">' + esc(metricName) + ' Levels</b>' +
                '    <div class="biw-distribution-grid" id="biw-dist-inline"></div>' +
                '  </div>' +
                '  <div class="biw-summary-card" id="biw-mover-card">' +
                '    <b class="card-title">Top movers</b>' +
                '    <div id="biw-mover-list" style="font-size:0.8rem;margin-top:2px;"></div>' +
                '  </div>' +
                '  <div class="biw-summary-card biw-block-card" id="biw-block-card" style="cursor:pointer;border-left:3px solid var(--biw-champagne);">' +
                '    <b class="card-title">Compare Groups</b>' +
                '    <div class="block-card-buttons" id="biw-block-card-buttons"></div>' +
                '    <a href="#" class="block-card-view-all" id="biw-block-card-view-all">View all groups →</a>' +
                '  </div>' +
                '</div>' +

                '<div class="biw-toolbar">' +
                '  <div class="biw-controls">' +
                '    <span style="font-size:12px;font-weight:700;color:var(--biw-slate-dim)">Map layer:</span>' +
                '    <div class="biw-seg" data-layer-group="map">' +
                '      <button class="biw-seg-btn active" data-layer="score">Composite</button>' +
                pillars.map(function (p) { return '<button class="biw-seg-btn" data-layer="' + esc(p.key) + '">' + esc(p.label) + '</button>'; }).join('') +
                '    </div>' +
                '    <button class="biw-dark-toggle" id="biw-dark-toggle"><span class="icon">🌙</span> Dark</button>' +
                '  </div>' +
                '</div>' +

                '<div class="biw-map-hero">' +
                '  <div class="biw-map-viewport" id="biw-map">' +
                '    <svg></svg>' +
                '    <div class="biw-map-zoom"><button class="biw-zoom-in">+</button><button class="biw-zoom-out">−</button><button class="biw-zoom-reset">↺</button></div>' +
                '    <div class="biw-map-tooltip" id="biw-map-tooltip"></div>' +
                '    <div class="biw-year-slider-container">' +
                '      <label>Year</label>' +
                '      <input type="range" id="biw-year-slider" min="' + minYear + '" max="' + maxYear + '" step="1" value="' + maxYear + '">' +
                '      <span class="year-label" id="biw-year-label">' + maxYear + '</span>' +
                '      <button class="play-btn" id="biw-play-btn">▶</button>' +
                '    </div>' +
                '    <div class="biw-legend-horizontal">' +
                '      <span class="legend-item"><span class="legend-dot" style="background:' + orientedBandColors()[0] + '"></span> ' + esc(bandLabels[orientedBandIndex(0)] || 'Very Good') + '</span>' +
                '      <span class="legend-item"><span class="legend-dot" style="background:' + orientedBandColors()[1] + '"></span> ' + esc(bandLabels[orientedBandIndex(1)] || 'Good') + '</span>' +
                '      <span class="legend-item"><span class="legend-dot" style="background:' + orientedBandColors()[2] + '"></span> ' + esc(bandLabels[orientedBandIndex(2)] || 'Poor') + '</span>' +
                '      <span class="legend-item"><span class="legend-dot" style="background:' + orientedBandColors()[3] + '"></span> ' + esc(bandLabels[orientedBandIndex(3)] || 'Very Poor') + '</span>' +
                '      <span class="legend-item"><span class="legend-line"></span> No data</span>' +
                '    </div>' +
                '  </div>' +
                '  <div class="biw-map-side">' +
                '    <div class="biw-widget"><h4>Risk distribution</h4><div class="biw-donut" id="biw-donut"></div></div>' +
                '    <div class="biw-widget"><h4>' + esc(metricName) + ' extremes</h4>' +
                '      <div style="font-size:11px;color:var(--biw-slate-dim);margin-bottom:8px;">Highest ' + esc(metricName) + '</div>' +
                '      <div id="biw-most-vulnerable" class="biw-mover-list"></div>' +
                '      <div style="font-size:11px;color:var(--biw-slate-dim);margin:8px 0;">Lowest ' + esc(metricName) + '</div>' +
                '      <div id="biw-least-vulnerable" class="biw-mover-list"></div>' +
                '    </div>' +
                '  </div>' +
                '</div>' +

                '<div class="biw-chart-grid">' +
                '  <div class="biw-chart-panel"><h2>Pillar scatter</h2>' +
                '    <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">' +
                '      <select class="biw-select biw-scatter-x" style="min-width:100px;font-size:0.7rem;padding:4px 8px;">' +
                '        <option value="' + esc(pillars[0] ? pillars[0].key : '') + '">X: ' + esc(pillars[0] ? pillars[0].label : '') + '</option>' +
                '        <option value="' + esc(pillars[1] ? pillars[1].key : '') + '" selected>X: ' + esc(pillars[1] ? pillars[1].label : '') + '</option>' +
                '        <option value="' + esc(pillars[2] ? pillars[2].key : '') + '">X: ' + esc(pillars[2] ? pillars[2].label : '') + '</option>' +
                '      </select>' +
                '      <select class="biw-select biw-scatter-y" style="min-width:100px;font-size:0.7rem;padding:4px 8px;">' +
                '        <option value="' + esc(pillars[0] ? pillars[0].key : '') + '">Y: ' + esc(pillars[0] ? pillars[0].label : '') + '</option>' +
                '        <option value="' + esc(pillars[1] ? pillars[1].key : '') + '" selected>Y: ' + esc(pillars[1] ? pillars[1].label : '') + '</option>' +
                '        <option value="' + esc(pillars[2] ? pillars[2].key : '') + '">Y: ' + esc(pillars[2] ? pillars[2].label : '') + '</option>' +
                '      </select>' +
                '      <div class="biw-share-group">' +
                '        <button class="biw-btn-share" data-share="x" data-chart="scatter">𝕏</button>' +
                '        <button class="biw-btn-share" data-share="linkedin" data-chart="scatter">in</button>' +
                '        <button class="biw-btn-share" data-share="copy" data-chart="scatter">🔗</button>' +
                '      </div>' +
                '    </div>' +
                '    <div class="biw-scatter-container" id="biw-scatter"></div>' +
                '    <div class="biw-scatter-axis"><span>0</span><span>25</span><span>50</span><span>75</span><span>100</span></div>' +
                '  </div>' +
                '  <div class="biw-chart-panel"><h2>Score distribution</h2>' +
                '    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">' +
                '      <div style="flex:1;"></div>' +
                '      <div class="biw-share-group">' +
                '        <button class="biw-btn-share" data-share="x" data-chart="histogram">𝕏</button>' +
                '        <button class="biw-btn-share" data-share="linkedin" data-chart="histogram">in</button>' +
                '        <button class="biw-btn-share" data-share="copy" data-chart="histogram">🔗</button>' +
                '      </div>' +
                '    </div>' +
                '    <div class="biw-histogram-container">' +
                '      <div class="biw-histogram-bars" id="biw-histogram"></div>' +
                '    </div>' +
                '    <div class="biw-histogram-axis">' +
                '      <div class="axis-band low">' + esc(bandLabels[orientedBandIndex(0)]) + ' 0–' + bandThresholds[0] + '</div>' +
                '      <div class="axis-band med">' + esc(bandLabels[orientedBandIndex(1)]) + ' ' + bandThresholds[0] + '–' + bandThresholds[1] + '</div>' +
                '      <div class="axis-band high">' + esc(bandLabels[orientedBandIndex(2)]) + ' ' + bandThresholds[1] + '–' + bandThresholds[2] + '</div>' +
                '      <div class="axis-band ext">' + esc(bandLabels[orientedBandIndex(3)]) + ' ' + bandThresholds[2] + '+</div>' +
                '    </div>' +
                '  </div>' +
                '</div>' +

                // Table toolbar
                '<div class="biw-table-toolbar">' +
                '  <input type="text" class="biw-search" placeholder="Search countries…">' +
                '  <div class="biw-table-controls">' +
                '    <select class="biw-select biw-region-filter" id="biw-region-filter"><option value="all">All regions</option><option value="Africa">Africa</option><option value="Americas">Americas</option><option value="Asia">Asia</option><option value="Europe">Europe</option><option value="Oceania">Oceania</option></select>' +
                '    <select class="biw-select biw-band-filter"><option value="all">All ' + metricName.toLowerCase() + ' levels</option>' +
                '      <option value="0">' + esc(bandLabels[orientedBandIndex(0)]) + '</option>' +
                '      <option value="1">' + esc(bandLabels[orientedBandIndex(1)]) + '</option>' +
                '      <option value="2">' + esc(bandLabels[orientedBandIndex(2)]) + '</option>' +
                '      <option value="3">' + esc(bandLabels[orientedBandIndex(3)]) + '</option>' +
                '    </select>' +
                '    <select class="biw-select biw-sort-select">' +
                '      <option value="rank">Sort: Rank</option>' +
                '      <option value="name">Sort: Country</option>' +
                '      <option value="' + esc(scoreKey) + '">Sort: Score</option>' +
                '      <option value="coverage">Sort: Coverage</option>' +
                pillars.map(function (p) { return '<option value="' + esc(p.key) + '">Sort: ' + esc(p.label) + '</option>'; }).join('') +
                '    </select>' +
                '    <select class="biw-select biw-block-dropdown" id="biw-block-dropdown">' +
                '      <option value="">🏛️ Compare Groups</option>' +
                Object.keys(countryGroups).map(function(name) {
                    return '<option value="' + name + '">' + name + '</option>';
                }).join('') +
                '      <option value="__all__">View All Groups →</option>' +
                '    </select>' +
                '    <button class="biw-watchlist-toggle"><span>★</span> Watchlist</button>' +
                '    <button class="biw-btn-print">Print</button>' +
                '    <button class="biw-btn-export" id="biw-btn-export">⬇ CSV</button>' +
                '    <div class="biw-share-group">' +
                '      <button class="biw-btn-share" data-share="x">𝕏</button>' +
                '      <button class="biw-btn-share" data-share="linkedin">in</button>' +
                '      <button class="biw-btn-share" data-share="copy">🔗</button>' +
                '    </div>' +
                '  </div>' +
                '</div>' +
                '<div class="biw-table-wrap"><table class="biw-table"><thead><tr class="biw-head-row"></tr></thead><tbody class="biw-tbody"></tbody></table></div>' +
                '<div class="biw-no-results" style="display:none;">No countries found.</div>';
        }

        // ─── Data loading ──────────────────────────────────────────
        function loadData() {
            var tbody = q('.biw-tbody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="' + (pillars.length + 5) + '" class="biw-loading">⏳ Loading data…</td></tr>';
            }
            Promise.all([
                fetchJSON(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now()),
                fetchJSON(namesEndpoint).catch(function () { return {}; }),
                fetchJSON(historyEndpoint).catch(function (err) {
                    console.warn('History endpoint unavailable:', err.message || err);
                    return {};
                })
            ]).then(function (results) {
                var data = results[0];
                state.names = results[1] || {};
                state.history = results[2] || {};
                state.indexMeta = data;

                var meta = q('.biw-last-updated');
                if (meta) {
                    meta.textContent = 'Last updated: ' + (data.last_updated || '—') +
                        (data.version ? ' · v' + data.version : '') +
                        (data.total_countries ? ' · ' + data.total_countries + ' countries' : '');
                }

                var countries = data.countries || {};
                state.all = Object.keys(countries).map(function (iso3) {
                    var row = countries[iso3];
                    row.iso3 = iso3;
                    row.name = state.names[iso3] || iso3;
                    if (row.region) state.hasRegionData = true;
                    return row;
                });

                state.all.forEach(function (c) {
                    if (!c.rank && c.rank_display && c.rank_display.best_estimate !== undefined) {
                        c.rank = c.rank_display.best_estimate;
                    }
                    if (!c.region) c.region = 'Other';
                });

                var regionFilter = document.getElementById('biw-region-filter');
                if (regionFilter && !state.hasRegionData) {
                    regionFilter.style.display = 'none';
                }

                render();

                if (view === 'dashboard') {
                    ensureD3()
                        .then(ensureTopojson)
                        .then(function () {
                            state.d3Ready = true;
                            state.d3Error = null;
                            renderDashboard();
                            var errEl = document.getElementById('biw-d3-error');
                            if (errEl) errEl.remove();
                        })
                        .catch(function (err) {
                            state.d3Error = err.message || 'D3 or topojson failed to load';
                            console.error('D3 loading error:', err);
                            var container = document.getElementById('biw-map');
                            if (container) {
                                var errDiv = document.createElement('div');
                                errDiv.id = 'biw-d3-error';
                                errDiv.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:var(--biw-poor);text-align:center;font-family:var(--biw-sans);z-index:20;background:rgba(0,0,0,0.8);padding:20px;border-radius:8px;';
                                errDiv.innerHTML = '<strong>⚠️ Visualisation engine failed to load</strong><br><span style="font-size:12px;">' + esc(state.d3Error) + '</span><br><span style="font-size:10px;color:var(--biw-slate-dim);">Check console for details.</span>';
                                container.style.position = 'relative';
                                container.appendChild(errDiv);
                            }
                        });
                }

                populateBlockSelectors();

            }).catch(function (err) {
                var tbody2 = q('.biw-tbody');
                if (tbody2) {
                    tbody2.innerHTML = '<tr><td colspan="' + (pillars.length + 5) + '" class="biw-error">⚠ ' + esc(err.message || String(err)) + '</td></tr>';
                }
            });
        }

        function fetchJSON(url) {
            return fetch(url).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status + ' from ' + url);
                return r.json();
            });
        }

        function band(score) {
            for (var i = 0; i < bandThresholds.length; i++) {
                if (score <= bandThresholds[i]) return i;
            }
            return bandThresholds.length;
        }

        // BUGFIX (2026-09): band labels changed from intensity-based
        // ("Low"..."Extreme", which needed the reader to already know an
        // index's direction to interpret as good or bad) to quality-based
        // ("Very Good"..."Very Poor", which always means the same thing
        // regardless of index). That means the label must now reverse
        // together with the color for a higher-is-better index — unlike
        // the old intensity-based labels, which stayed in direct score
        // order on purpose. One shared index calculation for both.
        function orientedBandIndex(b) {
            return (orientation === 'higher_is_better') ? (bandLabels.length - 1 - b) : b;
        }

        function getRiskLabel(score) {
            var b = band(score);
            return bandLabels[orientedBandIndex(b)] || 'Unknown';
        }

        function getRiskClass(score) {
            var b = band(score);
            var idx = (orientation === 'higher_is_better') ? (bandClasses.length - 1 - b) : b;
            return bandClasses[idx] || 'biw-badge-verygood';
        }

        // BUGFIX (2026-09): getCountryColor(), getColor(), the donut chart,
        // the histogram, and the map legend each hardcoded their own copy
        // of this same band-to-color array, independently of getRiskClass()
        // above and of each other. None of the copies applied the
        // orientation reversal — so for a higher-is-better index, some
        // parts of the widget (whichever used getRiskClass) showed correct
        // colors while every other part (map, drawer, movers list, scatter,
        // donut, histogram, legend) still showed the un-reversed, backwards
        // colors. One shared source of truth now; every call site below
        // uses this instead of its own copy.
        function orientedBandColors() {
            var colors = ['var(--biw-verygood)', 'var(--biw-good)', 'var(--biw-poor)', 'var(--biw-verypoor)'];
            return (orientation === 'higher_is_better') ? colors.slice().reverse() : colors;
        }

        // ─── Apply filters ──────────────────────────────────────────
        function applyFilters() {
            var term = q('.biw-search') ? q('.biw-search').value.toLowerCase().trim() : '';
            var bandFilter = q('.biw-band-filter') ? q('.biw-band-filter').value : 'all';
            var regionFilterEl = document.getElementById('biw-region-filter');
            var regionFilter = (regionFilterEl && regionFilterEl.style.display !== 'none') ? regionFilterEl.value : 'all';
            var sortKey = q('.biw-sort-select') ? q('.biw-sort-select').value : 'rank';

            var filtered = state.all.filter(function (c) {
                var match = c.name.toLowerCase().indexOf(term) !== -1;
                if (regionFilter !== 'all' && c.region !== regionFilter) return false;
                if (bandFilter !== 'all') {
                    var bf = parseInt(bandFilter, 10);
                    var score = getScoreForYear(c.iso3, state.selectedYear) ?? c[scoreKey];
                    if (band(score) !== bf) return false;
                }
                if (state.showWatchlistOnly && watchlist.indexOf(c.iso3) === -1) return false;
                return match;
            });

            filtered.sort(function (a, b) {
                var valA, valB;
                if (sortKey === 'name') {
                    valA = a.name.toLowerCase();
                    valB = b.name.toLowerCase();
                    return valA.localeCompare(valB);
                }
                if (sortKey === 'rank') {
                    var rankA = getRecomputedRank(a.iso3, state.selectedYear) || 9999;
                    var rankB = getRecomputedRank(b.iso3, state.selectedYear) || 9999;
                    return rankA - rankB;
                }
                if (sortKey === 'coverage') {
                    valA = a[coverageKey] === 'full' ? 0 : 1;
                    valB = b[coverageKey] === 'full' ? 0 : 1;
                    if (valA === valB) {
                        var rankA2 = getRecomputedRank(a.iso3, state.selectedYear) || 9999;
                        var rankB2 = getRecomputedRank(b.iso3, state.selectedYear) || 9999;
                        return rankA2 - rankB2;
                    }
                    return valA - valB;
                }
                var pillarKey = sortKey;
                var valA = getHistoricalPillar(a.iso3, state.selectedYear, pillarKey) ?? a[pillarKey];
                var valB = getHistoricalPillar(b.iso3, state.selectedYear, pillarKey) ?? b[pillarKey];
                valA = valA !== undefined && valA !== null ? valA : -1;
                valB = valB !== undefined && valB !== null ? valB : -1;
                return valB - valA;
            });

            state.filtered = filtered;
        }

        function render() {
            applyFilters();
            renderHead();
            renderTable();
            if (view === 'dashboard') {
                updateSummary();
                if (state.d3Ready) {
                    renderDonut();
                    renderExtremes();
                    renderHistogram();
                    renderScatter();
                    updateMapMarkers();
                }
            }
            var noResults = q('.biw-no-results');
            if (noResults) {
                noResults.style.display = state.filtered.length ? 'none' : 'block';
            }
            updateCompareDock();
        }

        // ─── Render head ────────────────────────────────────────────
        function renderHead() {
            var head = q('.biw-head-row');
            if (!head) return;
            var cells = '<th style="width:36px;text-align:center;">★</th>' +
                '<th style="width:70px;text-align:center;">Rank</th>' +
                '<th style="width:60px;text-align:center;">Δ</th>' +
                '<th>Country</th>' +
                '<th style="width:110px;text-align:center;">' + esc(scoreLabel) + '</th>' +
                pillars.map(function (p) {
                    return '<th style="width:120px;text-align:center;">' + esc(p.label) + '</th>';
                }).join('') +
                '<th style="width:80px;text-align:center;">Compare</th>' +
                '<th style="width:80px;"></th>';
            head.innerHTML = cells;
        }

        function badgeHtml(score, coverage) {
            var cov = coverage === 'partial'
                ? '<span class="biw-coverage biw-coverage-partial" title="Partial Index — one pillar estimated">PARTIAL</span>'
                : (coverage ? '<span class="biw-coverage biw-coverage-full">FULL</span>' : '');
            // BUGFIX (2026-09): this was the SEVENTH hardcoded copy of the
            // band-to-color logic (`bandClasses[band(score)]` directly) —
            // and the most consequential one, since it's what renders the
            // actual score badge in the table. It bypassed getRiskClass()
            // entirely, so the table itself never got the orientation fix
            // despite getRiskClass being correctly written all along.
            var cls = getRiskClass(score);
            return '<span class="biw-badge-cell"><span class="biw-badge ' + cls + '">' + fmtNum(score) + '</span>' + cov + '</span>';
        }

        function rankHtml(c) {
            var rd = c.rank_display;
            // BUGFIX (2026-09): getRecomputedRank() always returns a
            // definitive-looking number for ANY country with a score,
            // regardless of coverage — it was running first and silently
            // overriding the partial-coverage range (#38–52*) the backend
            // computes specifically because that country's rank isn't
            // fully certain. Pre-existing bug, not introduced by any of
            // today's changes — affects SIVI and SERI equally since this
            // is shared engine code neither index customizes.
            if (c.coverage === 'partial' && rd && !rd.is_definitive) {
                return '<span class="biw-rank-partial" title="80% range #' + rd.range_80_low + '–#' + rd.range_80_high +
                    ' · theoretical #' + rd.theoretical_low + '–#' + rd.theoretical_high + '">' + rd.string_format + '</span>';
            }
            var rank = getRecomputedRank(c.iso3, state.selectedYear);
            if (rank) return '#' + rank;
            if (rd && rd.is_definitive) {
                return '<span>' + rd.string_format + '</span>';
            }
            if (rd) {
                return '<span class="biw-rank-partial" title="80% range #' + rd.range_80_low + '–#' + rd.range_80_high +
                    ' · theoretical #' + rd.theoretical_low + '–#' + rd.theoretical_high + '">' + rd.string_format + '</span>';
            }
            return c.rank !== undefined && c.rank !== null ? '#' + c.rank : '—';
        }

        // ─── Delta ──────────────────────────────────────────────────
        function deltaInfo(c) {
            var currentYear = state.selectedYear;
            var prevYear = currentYear - 1;

            var scoresCurrent = {};
            var scoresPrev = {};
            state.all.forEach(function (country) {
                var scoreCur = getScoreForYear(country.iso3, currentYear);
                var scorePrev = getScoreForYear(country.iso3, prevYear);
                if (scoreCur !== null && scoreCur !== undefined) scoresCurrent[country.iso3] = scoreCur;
                if (scorePrev !== null && scorePrev !== undefined) scoresPrev[country.iso3] = scorePrev;
            });

            var common = Object.keys(scoresCurrent).filter(function (iso3) {
                return scoresPrev[iso3] !== undefined;
            });

            if (common.length < 2) {
                return { type: 'new' };
            }

            var rankCurrent = {};
            var rankPrev = {};
            var sortedCur = common.slice().sort(function (a, b) { return scoresCurrent[b] - scoresCurrent[a]; });
            var sortedPrev = common.slice().sort(function (a, b) { return scoresPrev[b] - scoresPrev[a]; });
            sortedCur.forEach(function (iso3, idx) { rankCurrent[iso3] = idx + 1; });
            sortedPrev.forEach(function (iso3, idx) { rankPrev[iso3] = idx + 1; });

            var currentRank = rankCurrent[c.iso3];
            var prevRank = rankPrev[c.iso3];
            if (prevRank === undefined || currentRank === undefined) {
                return { type: 'new' };
            }
            var delta = prevRank - currentRank;
            if (delta === 0) return { type: 'flat' };
            return { type: delta > 0 ? 'up' : 'down', value: Math.abs(delta) };
        }

        // BUGFIX (2026-09): the drawer had its own separate, hardcoded
        // delta-color rendering (biw-delta-up/-down applied directly by
        // arrow direction, no orientation check) — same bug class as the
        // band-color duplicates above, just for delta arrows instead.
        // Shared here so the table and the drawer can't drift apart again.
        function deltaColorClass(deltaType) {
            var movingTowardRank1 = (deltaType === 'up');
            var isGoodMove = (orientation === 'higher_is_better') ? movingTowardRank1 : !movingTowardRank1;
            return isGoodMove ? 'biw-delta-down' : 'biw-delta-up';
        }

        function deltaHtml(c) {
            var info = deltaInfo(c);
            if (info.type === 'new') return '<span class="biw-delta biw-delta-new" title="No previous year data">NEW</span>';
            if (info.type === 'flat') return '<span class="biw-delta biw-delta-flat" title="No change in rank (compared among countries with data in both years)">—</span>';
            var arrow = info.type === 'up' ? '↑' : '↓';
            var cls = deltaColorClass(info.type);
            // BUGFIX (2026-09): this used to say "Rank worsened (more
            // vulnerable)" / "Rank improved (less vulnerable)" — wording
            // that's backwards for a higher-is-better index sharing this
            // same engine (e.g. resilience, where moving toward #1 is
            // good, not bad). Describing the movement itself, rather than
            // judging it as better/worse, is correct for any index.
            var label = info.type === 'up' ? 'Rank moved up, closer to #1' : 'Rank moved down, further from #1';
            return '<span class="biw-delta ' + cls + '" title="' + label + ' (compared among countries with data in both years)">' + arrow + info.value + '</span>';
        }

        function pillarCellHtml(c, p) {
            var v = getHistoricalPillar(c.iso3, state.selectedYear, p.key) ?? c[p.key];
            if (v === null || v === undefined) {
                return '<span style="color:var(--biw-slate-dim);font-family:var(--biw-mono);font-size:0.7rem;">—</span>';
            }
            return '<div class="biw-bar-cell">' +
                '<div class="biw-bar-track"><div class="biw-bar-fill" style="width:' + v + '%;background:' + esc(p.color || '#60a5fa') + '"></div></div>' +
                '<span>' + fmtNum(v) + '</span>' +
                '</div>';
        }

        function renderTable() {
            var body = q('.biw-tbody');
            if (!body) return;
            body.innerHTML = state.filtered.map(function (c, i) {
                var starred = watchlist.indexOf(c.iso3) !== -1;
                var inCompare = state.compareList.some(function (item) { return item.iso3 === c.iso3; });
                var score = getScoreForYear(c.iso3, state.selectedYear) ?? c[scoreKey];
                return '<tr data-idx="' + i + '">' +
                    '<td style="text-align:center;"><button class="biw-star-btn ' + (starred ? 'active' : '') + '" data-action="star" data-idx="' + i + '">★</button></td>' +
                    '<td class="biw-rank">' + rankHtml(c) + '</td>' +
                    '<td class="biw-num">' + deltaHtml(c) + '</td>' +
                    '<td class="biw-country">' + esc(c.name) + '</td>' +
                    '<td class="biw-num">' + badgeHtml(score, c[coverageKey]) + '</td>' +
                    pillars.map(function (p) { return '<td class="biw-num">' + pillarCellHtml(c, p) + '</td>'; }).join('') +
                    '<td style="text-align:center;"><input type="checkbox" class="biw-compare-check" data-action="compare-check" data-idx="' + i + '" ' + (inCompare ? 'checked' : '') + '></td>' +
                    '<td class="biw-action"><button class="biw-btn-detail" data-action="detail" data-idx="' + i + '">Details</button></td>' +
                    '</tr>';
            }).join('');
        }

        // ─── Update summary ──────────────────────────────────────────
        function updateSummary() {
            var total = state.all.length;

            // ─── Countries card ────────────────────────────────────────
            var fullCount = 0, partialCount = 0;
            state.all.forEach(function(c) {
                var cov = c[coverageKey];
                if (cov === 'full') fullCount++;
                else if (cov === 'partial') partialCount++;
            });
            var countriesDetail = document.getElementById('biw-countries-detail');
            if (countriesDetail) {
                countriesDetail.innerHTML =
                    '<div style="font-size:1rem;font-weight:700;">' + total + ' total</div>' +
                    '<div style="font-size:0.8rem;display:flex;gap:12px;margin-top:2px;">' +
                    '<span><span style="display:inline-block;width:10px;height:10px;background:var(--biw-verygood);border-radius:50%;margin-right:4px;"></span> ' + fullCount + ' full</span>' +
                    '<span><span style="display:inline-block;width:10px;height:10px;background:var(--biw-good);border-radius:50%;margin-right:4px;"></span> ' + partialCount + ' partial</span>' +
                    '</div>';
            }

            // ─── Composite Analytics card ─────────────────────────────
            var scores = state.all.map(function (d) { return getScoreForYear(d.iso3, state.selectedYear) ?? d[scoreKey]; });
            var validScores = scores.filter(function (s) { return s !== null && s !== undefined; });
            var mean = validScores.length ? (validScores.reduce(function (a, b) { return a + b; }, 0) / validScores.length) : 0;
            var sortedScores = validScores.slice().sort(function(a,b){return a-b;});
            var median = 0;
            if (sortedScores.length) {
                var mid = Math.floor(sortedScores.length / 2);
                median = sortedScores.length % 2 ? sortedScores[mid] : (sortedScores[mid-1] + sortedScores[mid]) / 2;
            }
            var minScore = sortedScores.length ? sortedScores[0] : 0;
            var maxScore = sortedScores.length ? sortedScores[sortedScores.length-1] : 0;

            var scoreDetail = document.getElementById('biw-score-detail');
            if (scoreDetail) {
                scoreDetail.innerHTML =
                    '<div style="font-size:0.9rem;display:flex;gap:14px;flex-wrap:wrap;margin-top:2px;align-items:center;">' +
                    '<span style="font-weight:600;">📊 Range: ' + fmtNum(minScore) + ' – ' + fmtNum(maxScore) + '</span>' +
                    '</div>' +
                    '<div style="font-size:0.85rem;display:flex;gap:20px;flex-wrap:wrap;margin-top:2px;">' +
                    '<span style="font-weight:500;">📈 Median: ' + fmtNum(median) + '</span>' +
                    '<span style="font-weight:500;">📉 Mean: ' + fmtNum(mean) + '</span>' +
                    '</div>';
            }

            // ─── Vulnerability distribution ───────────────────────────
            var ext = validScores.filter(function (s) { return s >= bandThresholds[2]; }).length;
            var high = validScores.filter(function (s) { return s >= bandThresholds[1] && s < bandThresholds[2]; }).length;
            var med = validScores.filter(function (s) { return s >= bandThresholds[0] && s < bandThresholds[1]; }).length;
            var low = validScores.filter(function (s) { return s < bandThresholds[0]; }).length;

            var distInline = document.getElementById('biw-dist-inline');
            if (distInline) {
                // BUGFIX (2026-09): this used fixed CSS classes
                // (dist-dot extreme/high/medium/low) tied to fixed colors
                // in the stylesheet, bypassing the same orientation
                // reversal as everywhere else — sixth place this same bug
                // showed up. Text stays in direct order (Extreme always
                // means the highest-score band); only the dot color reverses.
                var distColors = orientedBandColors();
                distInline.innerHTML =
                    '<span class="dist-item"><span class="dist-dot" style="background:' + distColors[3] + '"></span><span class="dist-num">' + ext + '</span> Extreme</span>' +
                    '<span class="dist-item"><span class="dist-dot" style="background:' + distColors[2] + '"></span><span class="dist-num">' + high + '</span> High</span>' +
                    '<span class="dist-item"><span class="dist-dot" style="background:' + distColors[1] + '"></span><span class="dist-num">' + med + '</span> Medium</span>' +
                    '<span class="dist-item"><span class="dist-dot" style="background:' + distColors[0] + '"></span><span class="dist-num">' + low + '</span> Low</span>';
            }

            // ─── Top movers ────────────────────────────────────────
            var moverListEl = document.getElementById('biw-mover-list');
            if (moverListEl) {
                var movers = state.all.map(function (c) {
                    var info = deltaInfo(c);
                    return { country: c, delta: info.value || 0, type: info.type };
                });

                var upMover = null;
                var downMover = null;
                var upDelta = -1;
                var downDelta = -1;

                movers.forEach(function (m) {
                    if (m.type === 'up' && m.delta > upDelta) {
                        upDelta = m.delta;
                        upMover = m;
                    }
                    if (m.type === 'down' && m.delta > downDelta) {
                        downDelta = m.delta;
                        downMover = m;
                    }
                });

                var html = '';
                // BUGFIX (2026-09): tenth instance of the same bug class —
                // mover-up/mover-down were hardcoded (up=red, down=green)
                // regardless of orientation. Swap which existing class
                // applies, same pattern as deltaColorClass().
                var upMoverClass = (orientation === 'higher_is_better') ? 'mover-down' : 'mover-up';
                var downMoverClass = (orientation === 'higher_is_better') ? 'mover-up' : 'mover-down';
                if (upMover) {
                    html += '<div class="' + upMoverClass + '">🔺 ' + esc(upMover.country.name) + ' (▲' + upMover.delta + ')</div>';
                }
                if (downMover) {
                    html += '<div class="' + downMoverClass + '">🔻 ' + esc(downMover.country.name) + ' (▼' + downMover.delta + ')</div>';
                }
                if (!upMover && !downMover) {
                    html = '<div style="color:var(--biw-slate-dim);font-size:0.8rem;">No significant moves</div>';
                }
                moverListEl.innerHTML = html;
            }

            // ─── Block card buttons ──────────────────────────────
            var cardButtons = document.getElementById('biw-block-card-buttons');
            if (cardButtons) {
                var quickGroups = ['G7', 'BRICS', 'EU', 'ASEAN'];
                cardButtons.innerHTML = quickGroups.map(function(name) {
                    return '<button class="block-card-btn" data-group="' + name + '">' + name + '</button>';
                }).join('');
                cardButtons.querySelectorAll('.block-card-btn').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        openBlockSelector(this.dataset.group, null);
                    });
                });
            }

            var viewAll = document.getElementById('biw-block-card-view-all');
            if (viewAll) {
                viewAll.addEventListener('click', function(e) {
                    e.preventDefault();
                    openBlockSelector(null, null);
                });
            }
        }

        // ─── Donut ──────────────────────────────────────────────────
        function renderDonut() {
            var container = document.getElementById('biw-donut');
            if (!container || !state.d3Ready || typeof d3 === 'undefined') return;

            var colors = orientedBandColors();
            var scores = state.all.map(function (d) { return getScoreForYear(d.iso3, state.selectedYear) ?? d[scoreKey]; });
            var validScores = scores.filter(function (s) { return s !== null && s !== undefined; });
            var counts = [0, 0, 0, 0];
            validScores.forEach(function (s) {
                var b = band(s);
                counts[b]++;
            });
            var total = validScores.length;

            var svg = d3.select(container).html('')
                .append('svg')
                .attr('width', 170)
                .attr('height', 170)
                .append('g')
                .attr('transform', 'translate(85,85)');

            var pie = d3.pie().value(function (d) { return d; });
            var arc = d3.arc().innerRadius(42).outerRadius(72);
            var tooltip = createContainerTooltip(container, 'biw-donut-tooltip');

            svg.selectAll('path')
                .data(pie(counts))
                .enter()
                .append('path')
                .attr('d', arc)
                .attr('fill', function (d, i) { return colors[i]; })
                .attr('stroke', 'var(--biw-obsidian-card)')
                .attr('stroke-width', 2)
                .on('mouseenter', function (event, d) {
                    var idx = d.index;
                    var label = bandLabels[orientedBandIndex(idx)] || 'Unknown';
                    var count = counts[idx] || 0;
                    var pct = total > 0 ? ((count / total) * 100).toFixed(1) : 0;
                    tooltip.innerHTML = '<strong>' + esc(label) + '</strong><br>' + count + ' countries (' + pct + '%)';
                    showTooltip(container, tooltip, event);
                })
                .on('mouseleave', function () {
                    tooltip.classList.remove('visible');
                });

            svg.append('text')
                .attr('text-anchor', 'middle')
                .attr('dy', '0.35em')
                .style('fill', 'var(--biw-text)')
                .style('font-weight', '800')
                .style('font-size', '18px')
                .text(total);

            // Tooltip hiding handled by delegated listener
        }

        // ─── Extremes ───────────────────────────────────────────────
        function renderExtremes() {
            var sorted = state.all.slice().map(function (c) {
                var score = getScoreForYear(c.iso3, state.selectedYear) ?? c[scoreKey];
                return { country: c, score: score };
            }).filter(function (d) { return d.score !== null && d.score !== undefined; })
            .sort(function (a, b) { return a.score - b.score; });

            var most = sorted.slice(-3).reverse();
            var least = sorted.slice(0, 3);

            var mostEl = document.getElementById('biw-most-vulnerable');
            if (mostEl) {
                mostEl.innerHTML = most.map(function (d) {
                    return '<div class="biw-mover-item" data-iso3="' + esc(d.country.iso3) + '"><span class="name">' + esc(d.country.name) + '</span><span class="score" style="color:' + getColor(d.score) + '">' + fmtNum(d.score) + '</span></div>';
                }).join('');
            }
            var leastEl = document.getElementById('biw-least-vulnerable');
            if (leastEl) {
                leastEl.innerHTML = least.map(function (d) {
                    return '<div class="biw-mover-item" data-iso3="' + esc(d.country.iso3) + '"><span class="name">' + esc(d.country.name) + '</span><span class="score" style="color:' + getColor(d.score) + '">' + fmtNum(d.score) + '</span></div>';
                }).join('');
            }
        }

        // ─── Histogram ──────────────────────────────────────────────
        function renderHistogram() {
            var container = document.getElementById('biw-histogram');
            if (!container || !state.d3Ready || typeof d3 === 'undefined') return;
            container.innerHTML = '';
            var binSize = 5;
            var numBins = 20;
            var buckets = Array.from({ length: numBins }, function () { return []; });
            state.all.forEach(function (c) {
                var score = getScoreForYear(c.iso3, state.selectedYear) ?? c[scoreKey];
                if (score !== null && score !== undefined) {
                    var idx = Math.min(numBins - 1, Math.floor(score / binSize));
                    buckets[idx].push({ name: c.name, score: score, iso3: c.iso3 });
                }
            });
            var maxCount = Math.max.apply(null, buckets.map(function (b) { return b.length; })) || 1;
            var tooltip = createContainerTooltip(container, 'biw-histogram-tooltip');

            buckets.forEach(function (countriesInBin, i) {
                var start = i * binSize;
                var end = start + binSize;
                var count = countriesInBin.length;
                var bar = document.createElement('div');
                bar.className = 'biw-histogram-bar' + (count === 0 ? ' empty' : '');
                bar.style.height = count > 0 ? (count / maxCount) * 100 + '%' : '0%';
                var histColors = orientedBandColors();
                if (start < bandThresholds[0]) bar.style.backgroundColor = histColors[0];
                else if (start < bandThresholds[1]) bar.style.backgroundColor = histColors[1];
                else if (start < bandThresholds[2]) bar.style.backgroundColor = histColors[2];
                else bar.style.backgroundColor = histColors[3];

                var countryNames = countriesInBin.map(function (d) { return d.name + ' (' + fmtNum(d.score) + ')'; }).slice(0, 12).join(', ');
                var extraCount = count > 12 ? '\n...and ' + (count - 12) + ' more' : '';
                var content = count > 0
                    ? '<div><strong>Score Band: ' + start + ' – ' + end + '</strong></div><div class="count">' + count + ' countr' + (count === 1 ? 'y' : 'ies') + '</div><div class="country-list">' + countryNames + extraCount + '</div>'
                    : '<div><strong>Score Band: ' + start + ' – ' + end + '</strong></div><div class="count">No countries</div>';

                bar.addEventListener('mouseenter', function (e) {
                    tooltip.innerHTML = content;
                    showTooltip(container, tooltip, e);
                });
                bar.addEventListener('mouseleave', function () {
                    if (!tooltip.classList.contains('pinned')) {
                        tooltip.classList.remove('visible');
                    }
                });
                bar.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (tooltip.classList.contains('pinned')) {
                        tooltip.classList.remove('pinned', 'visible');
                    } else {
                        tooltip.innerHTML = content;
                        tooltip.classList.add('visible', 'pinned');
                        showTooltip(container, tooltip, e);
                    }
                });
                container.appendChild(bar);
            });

            // Tooltip hiding handled by delegated listener
        }

        // ─── Scatter ────────────────────────────────────────────────
        function renderScatter() {
            var container = document.getElementById('biw-scatter');
            if (!container || !state.d3Ready || typeof d3 === 'undefined') return;
            container.innerHTML = '';
            var xKey = q('.biw-scatter-x') ? q('.biw-scatter-x').value : (pillars[0] ? pillars[0].key : '');
            var yKey = q('.biw-scatter-y') ? q('.biw-scatter-y').value : (pillars[1] ? pillars[1].key : '');
            var tooltip = createContainerTooltip(container, 'biw-scatter-tooltip');

            state.all.forEach(function (d) {
                var x = getHistoricalPillar(d.iso3, state.selectedYear, xKey) ?? d[xKey];
                var y = getHistoricalPillar(d.iso3, state.selectedYear, yKey) ?? d[yKey];
                if (x === null || x === undefined) x = 50;
                if (y === null || y === undefined) y = 50;
                var dot = document.createElement('div');
                dot.className = 'biw-scatter-dot';
                dot.style.left = Math.max(3, Math.min(97, x)) + '%';
                dot.style.bottom = Math.max(3, Math.min(97, y)) + '%';
                var score = getScoreForYear(d.iso3, state.selectedYear) ?? d[scoreKey];
                dot.style.background = getColor(score);

                var content = '<strong>' + esc(d.name) + '</strong><br>Rank: ' + rankHtml(d) + '<br>' + esc(scoreLabel) + ': ' + fmtNum(score) +
                    '<br>' + esc(xKey) + ': ' + fmtNum(x) + '<br>' + esc(yKey) + ': ' + fmtNum(y);

                dot.addEventListener('mouseenter', function (e) {
                    tooltip.innerHTML = content;
                    showTooltip(container, tooltip, e);
                });
                dot.addEventListener('mouseleave', function () {
                    if (!tooltip.classList.contains('pinned')) {
                        tooltip.classList.remove('visible');
                    }
                });
                dot.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (tooltip.classList.contains('pinned')) {
                        tooltip.classList.remove('pinned', 'visible');
                    } else {
                        tooltip.innerHTML = content;
                        tooltip.classList.add('visible', 'pinned');
                        showTooltip(container, tooltip, e);
                    }
                    selectCountry(d.iso3);
                });
                container.appendChild(dot);
            });

            // Tooltip hiding handled by delegated listener
        }

        // ─── Helpers ────────────────────────────────────────────────
        function createContainerTooltip(container, id) {
            var el = container.querySelector('#' + id);
            if (el) return el;
            el = document.createElement('div');
            el.id = id;
            el.className = 'biw-custom-tooltip';
            container.style.position = 'relative';
            container.appendChild(el);
            return el;
        }

        function showTooltip(container, tooltip, e) {
            var rect = container.getBoundingClientRect();
            var left = e.clientX - rect.left + 12;
            var top = e.clientY - rect.top + 12;
            var tw = tooltip.offsetWidth || 280;
            var th = tooltip.offsetHeight || 150;
            if (left + tw > rect.width) left = rect.width - tw - 10;
            if (left < 10) left = 10;
            if (top + th > rect.height) top = rect.height - th - 10;
            if (top < 10) top = 10;
            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
            tooltip.classList.add('visible');
        }

        function getColor(score) {
            var b = band(score);
            return orientedBandColors()[b] || 'var(--biw-slate-dim)';
        }

        // ─── Map ──────────────────────────────────────────────────────
        function renderMap(layer, year) {
            var container = document.getElementById('biw-map');
            if (!container || !state.d3Ready || typeof d3 === 'undefined' || typeof topojson === 'undefined') return;
            var rect = container.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) {
                setTimeout(function () { renderMap(layer, year); }, 200);
                return;
            }
            var svg = d3.select(container).select('svg');
            svg.style('pointer-events', 'all');
            var width = container.clientWidth || 800;
            var height = container.clientHeight || 450;
            svg.attr('viewBox', '0 0 ' + width + ' ' + height);
            var g = svg.select('g');
            if (g.empty()) g = svg.append('g');

            var projection = d3.geoNaturalEarth1()
                .scale(150)
                .translate([width / 2, height / 2 + 20])
                .center([0, 10]);

            var path = d3.geoPath().projection(projection);
            state.mapProjection = projection;
            state.mapPath = path;

            var ZOOM_STEP_IN = 1.12;
            var ZOOM_STEP_OUT = 0.89;
            var WHEEL_SENSITIVITY = 0.0018;

            var zoom = d3.zoom()
                .scaleExtent([1, 10])
                .filter(function (event) {
                    if (event.type === 'wheel') {
                        if (event.ctrlKey) {
                            event.preventDefault();
                            return true;
                        }
                        return false;
                    }
                    if (event.type === 'dblclick') {
                        event.preventDefault();
                        return false;
                    }
                    return true;
                })
                .wheelDelta(function (event) {
                    return -event.deltaY * WHEEL_SENSITIVITY * ZOOM_STEP_IN;
                })
                .on('zoom', function (e) {
                    g.attr('transform', e.transform);
                    updateMapMarkers();
                });

            svg.call(zoom);

            svg.on('dblclick', function (event) {
                svg.transition().duration(300).call(zoom.scaleBy, ZOOM_STEP_IN);
            });

            var hint = container.querySelector('.biw-zoom-hint');
            if (!hint) {
                hint = document.createElement('div');
                hint.className = 'biw-zoom-hint';
                hint.textContent = 'Ctrl+scroll to zoom · Double-click to zoom in · Drag to pan';
                container.appendChild(hint);
            }

            function showHint() {
                if (!hint) return;
                if (state.mapHintTimeout) {
                    clearTimeout(state.mapHintTimeout);
                    state.mapHintTimeout = null;
                }
                hint.style.opacity = '1';
                hint.style.display = 'block';
                state.mapHintTimeout = setTimeout(function () {
                    hint.style.opacity = '0';
                    setTimeout(function () {
                        hint.style.display = 'none';
                    }, 300);
                }, 3000);
            }

            setTimeout(function () {
                var rect2 = container.getBoundingClientRect();
                if (rect2.width > 0 && rect2.height > 0 &&
                    rect2.top < window.innerHeight && rect2.bottom > 0) {
                    showHint();
                }
            }, 100);

            if (window.IntersectionObserver) {
                if (state.mapHintObserver) {
                    state.mapHintObserver.disconnect();
                }
                state.mapHintObserver = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            showHint();
                        }
                    });
                }, { threshold: 0.1 });
                state.mapHintObserver.observe(container);
            }

            var zoomIn = container.querySelector('.biw-zoom-in');
            var zoomOut = container.querySelector('.biw-zoom-out');
            var zoomReset = container.querySelector('.biw-zoom-reset');
            if (zoomIn) zoomIn.addEventListener('click', function () { svg.transition().duration(300).call(zoom.scaleBy, ZOOM_STEP_IN); });
            if (zoomOut) zoomOut.addEventListener('click', function () { svg.transition().duration(300).call(zoom.scaleBy, ZOOM_STEP_OUT); });
            if (zoomReset) zoomReset.addEventListener('click', function () { svg.transition().duration(400).call(zoom.transform, d3.zoomIdentity); });

            var slider = container.querySelector('#biw-year-slider');
            var label = container.querySelector('#biw-year-label');
            var playBtn = container.querySelector('#biw-play-btn');
            if (slider && label) {
                slider.value = year || maxYear;
                label.textContent = year || maxYear;

                var isPlaying = false;
                var playInterval = null;

                slider.addEventListener('input', function() {
                    var y = parseInt(this.value, 10);
                    label.textContent = y;
                    state.selectedYear = y;
                    updateMapColors(state.mapLayer, y);
                    renderDonut();
                    renderExtremes();
                    renderHistogram();
                    renderScatter();
                    updateSummary();
                    updateMapMarkers();
                    if (state.selectedCountry) {
                        openDrawer(state.selectedCountry, y);
                    }
                    updateHash();
                });

                playBtn.addEventListener('click', function() {
                    if (isPlaying) {
                        clearInterval(playInterval);
                        isPlaying = false;
                        playBtn.textContent = '▶';
                        return;
                    }
                    isPlaying = true;
                    playBtn.textContent = '⏸';
                    var currentYear = parseInt(slider.value, 10);
                    var maxYearAttr = parseInt(slider.max, 10);
                    playInterval = setInterval(function() {
                        if (currentYear >= maxYearAttr) {
                            clearInterval(playInterval);
                            isPlaying = false;
                            playBtn.textContent = '▶';
                            return;
                        }
                        currentYear++;
                        slider.value = currentYear;
                        label.textContent = currentYear;
                        state.selectedYear = currentYear;
                        updateMapColors(state.mapLayer, currentYear);
                        renderDonut();
                        renderExtremes();
                        renderHistogram();
                        renderScatter();
                        updateSummary();
                        updateMapMarkers();
                        if (state.selectedCountry) {
                            openDrawer(state.selectedCountry, currentYear);
                        }
                        updateHash();
                    }, 800);
                });
            }

            fetch('https://cdn.jsdelivr.net/npm/world-atlas@2/countries-110m.json')
                .then(function (res) { return res.json(); })
                .then(function (world) {
                    var countries = topojson.feature(world, world.objects.countries);
                    projection.fitExtent([[20, 20], [width - 20, height - 20]], countries);
                    state.mapProjection = projection;
                    state.mapPath = path;
                    state.mapFeatures = countries.features;

                    g.selectAll('path')
                        .data(countries.features)
                        .enter()
                        .append('path')
                        .attr('class', function (d) {
                            var iso3 = getIso3(d.id || d.properties?.id);
                            return 'biw-country country-' + (iso3 || d.id);
                        })
                        .attr('d', path)
                        .attr('fill', function (d) {
                            var iso3 = getIso3(d.id || d.properties?.id);
                            if (!iso3) return 'var(--biw-no-data)';
                            var c = state.all.find(function (i) { return i.iso3 === iso3; });
                            return getCountryColor(c, layer, year);
                        })
                        .attr('stroke', function (d) {
                            var iso3 = getIso3(d.id || d.properties?.id);
                            if (!iso3) return 'rgba(255,255,255,0.15)';
                            return state.all.find(function (i) { return i.iso3 === iso3; }) ? 'var(--biw-obsidian)' : 'rgba(255,255,255,0.15)';
                        })
                        .attr('stroke-width', function (d) {
                            var iso3 = getIso3(d.id || d.properties?.id);
                            if (!iso3) return 1;
                            return state.all.find(function (i) { return i.iso3 === iso3; }) ? 0.5 : 1;
                        })
                        .on('mouseover', function (e, d) {
                            var iso3 = getIso3(d.id || d.properties?.id);
                            var c = state.all.find(function (i) { return i.iso3 === iso3; });
                            var tt = container.querySelector('.biw-map-tooltip');
                            if (c) {
                                // ─── Generic tooltip: show all pillars ──
                                var score = getHistoricalScore(iso3, state.selectedYear) ?? c[scoreKey];
                                var dqi = c.composite_dqi !== undefined && c.composite_dqi !== null ? Math.round(c.composite_dqi) + '%' : '—';
                                var html = '<div style="font-weight:800;">' + esc(c.name) + '</div>' +
                                    '<div>Rank: ' + rankHtml(c) + '</div>' +
                                    '<div>' + esc(scoreLabel) + ': ' + fmtNum(score) + '</div>' +
                                    '<div style="font-size:11px;color:' + getColor(score) + ';">' + esc(getRiskLabel(score)) + '</div>' +
                                    '<hr style="border-color:var(--biw-border);margin:4px 0;">';
                                // Add pillar rows
                                pillars.forEach(function(p) {
                                    var val = getHistoricalPillar(iso3, state.selectedYear, p.key) ?? c[p.key];
                                    if (val !== null && val !== undefined) {
                                        html += '<div style="font-size:10px;display:flex;justify-content:space-between;">' +
                                            '<span>' + esc(p.label) + '</span><span>' + fmtNum(val) + '</span></div>';
                                    }
                                });
                                html += '<div style="font-size:10px;display:flex;justify-content:space-between;margin-top:4px;">' +
                                    '<span>DQI</span><span>' + dqi + '</span></div>';
                                tt.innerHTML = html;
                                var rect2 = container.getBoundingClientRect();
                                tt.style.left = (e.clientX - rect2.left + 12) + 'px';
                                tt.style.top = (e.clientY - rect2.top + 12) + 'px';
                                tt.style.display = 'block';
                            }
                        })
                        .on('mousemove', function (e) {
                            var tt = container.querySelector('.biw-map-tooltip');
                            if (tt.style.display === 'block') {
                                var rect2 = container.getBoundingClientRect();
                                tt.style.left = (e.clientX - rect2.left + 12) + 'px';
                                tt.style.top = (e.clientY - rect2.top + 12) + 'px';
                            }
                        })
                        .on('mouseout', function () {
                            var tt = container.querySelector('.biw-map-tooltip');
                            tt.style.display = 'none';
                        })
                        .on('click', function (e, d) {
                            var iso3 = getIso3(d.id || d.properties?.id);
                            if (!iso3) return;
                            var c = state.all.find(function (i) { return i.iso3 === iso3; });
                            if (c) selectCountry(c.iso3);
                        });

                    updateMapMarkers();
                })
                .catch(function (err) {
                    console.warn('Map data failed to load:', err);
                    var tt = container.querySelector('.biw-map-tooltip');
                    if (tt) {
                        tt.innerHTML = 'Map data unavailable';
                        tt.style.display = 'block';
                        tt.style.top = '50%';
                        tt.style.left = '50%';
                        tt.style.transform = 'translate(-50%,-50%)';
                        tt.style.background = 'rgba(0,0,0,0.7)';
                        tt.style.padding = '20px';
                        tt.style.borderRadius = '8px';
                    }
                });
        }

        function updateMapColors(layer, year) {
            var container = document.getElementById('biw-map');
            if (!container || !state.d3Ready || typeof d3 === 'undefined') return;
            var svg = d3.select(container).select('svg');
            svg.selectAll('path.biw-country')
                .transition()
                .duration(300)
                .attr('fill', function (d) {
                    var iso3 = getIso3(d.id || d.properties?.id);
                    if (!iso3) return 'var(--biw-no-data)';
                    var c = state.all.find(function (i) { return i.iso3 === iso3; });
                    return getCountryColor(c, layer, year);
                });
            state.mapLayer = layer;
            updateHash();
        }

        function getCountryColor(c, layer, year) {
            if (!c) return 'var(--biw-no-data)';
            var val;
            if (layer === 'score') {
                val = getHistoricalScore(c.iso3, year) ?? c[scoreKey];
            } else {
                val = getHistoricalPillar(c.iso3, year, layer) ?? c[layer];
            }
            if (val == null) return 'var(--biw-no-data)';
            var b = band(val);
            return orientedBandColors()[b] || 'var(--biw-no-data)';
        }

        // ─── Map markers ──────────────────────────────────────────
        function updateMapMarkers() {
            var container = document.getElementById('biw-map');
            if (!container || !state.d3Ready || typeof d3 === 'undefined' || !state.mapProjection) return;
            var svg = d3.select(container).select('svg');
            var g = svg.select('g');

            g.selectAll('.biw-map-marker').remove();

            var scored = state.all.map(function (c) {
                var score = getHistoricalScore(c.iso3, state.selectedYear) ?? c[scoreKey];
                var rank = getRecomputedRank(c.iso3, state.selectedYear) || 9999;
                var prevYear = state.selectedYear - 1;
                var prevRank = getRecomputedRank(c.iso3, prevYear);
                var rankChange = (prevRank !== null && prevRank !== undefined) ? Math.abs(prevRank - rank) : 0;
                return { country: c, score: score, rank: rank, rankChange: rankChange };
            }).filter(function (d) { return d.score !== null && d.score !== undefined; });

            if (scored.length === 0) return;

            // BUGFIX (2026-09): "most"/"least"/"mover" used to be picked
            // purely by score/rank-change, then silently dropped later
            // (line ~1660, "if (!feature) return;") if that specific
            // country isn't present as its own polygon in the simplified
            // world map — which is common for small territories (e.g.
            // Macao SAR is often merged into China's shape in simplified
            // world atlases). That produced fewer than 3 visible markers
            // with no indication why. Now falls back to the next-best
            // candidate whenever the top choice can't actually be placed.
            function findFeatureFor(iso3) {
                var features = state.mapFeatures || [];
                return features.find(function (f) {
                    return getIso3(f.id || f.properties?.id) === iso3;
                });
            }

            var byScoreDesc = scored.slice().sort(function (a, b) { return b.score - a.score; });
            var most = byScoreDesc.find(function (d) { return findFeatureFor(d.country.iso3); }) || byScoreDesc[0];

            var byScoreAsc = scored.slice().sort(function (a, b) { return a.score - b.score; });
            var least = byScoreAsc.find(function (d) { return findFeatureFor(d.country.iso3); }) || byScoreAsc[0];

            var moverCandidates = scored.filter(function (d) {
                return d.country.iso3 !== most.country.iso3 && d.country.iso3 !== least.country.iso3;
            });
            var moverPool = moverCandidates.length > 0 ? moverCandidates : scored;
            var byRankChangeDesc = moverPool.slice().sort(function (a, b) { return b.rankChange - a.rankChange; });
            var mover = byRankChangeDesc.find(function (d) { return findFeatureFor(d.country.iso3); }) || byRankChangeDesc[0];

            var markers = [
                { country: most.country, label: 'Highest scoring', color: '#22D3EE' },
                { country: least.country, label: 'Lowest scoring', color: '#22D3EE' },
                { country: mover.country, label: 'Biggest mover', color: '#22D3EE' }
            ];

            var unique = [];
            var seen = {};
            markers.forEach(function (m) {
                if (!seen[m.country.iso3]) {
                    seen[m.country.iso3] = true;
                    unique.push(m);
                }
            });

            var projection = state.mapProjection;
            var features = state.mapFeatures || [];
            unique.forEach(function (marker) {
                var iso3 = marker.country.iso3;
                var feature = features.find(function (f) {
                    return getIso3(f.id || f.properties?.id) === iso3;
                });
                if (!feature) return;
                var centroid = d3.geoPath().projection(projection).centroid(feature);
                if (!centroid || isNaN(centroid[0]) || isNaN(centroid[1])) return;

                var offsetX = (Math.random() - 0.5) * 6;
                var offsetY = (Math.random() - 0.5) * 6;

                var markerGroup = g.append('g')
                    .attr('class', 'biw-map-marker')
                    .attr('transform', 'translate(' + (centroid[0] + offsetX) + ',' + (centroid[1] + offsetY) + ')');

                var glow = markerGroup.append('circle')
                    .attr('r', 10)
                    .attr('fill', 'none')
                    .attr('stroke', '#22D3EE')
                    .attr('stroke-width', 3)
                    .style('opacity', 0.9)
                    .style('filter', 'drop-shadow(0 0 8px #8E44AD)');

                markerGroup.append('circle')
                    .attr('r', 5)
                    .attr('fill', '#22D3EE')
                    .style('opacity', 1);

                var pulse = function () {
                    glow.transition()
                        .duration(1200)
                        .attr('r', 18)
                        .style('opacity', 0)
                        .transition()
                        .duration(0)
                        .attr('r', 10)
                        .style('opacity', 0.9)
                        .on('end', pulse);
                };
                pulse();

                markerGroup.on('mouseenter', function (e) {
                    var tt = container.querySelector('.biw-map-tooltip');
                    if (tt) {
                        tt.innerHTML = '<strong>' + esc(marker.label) + '</strong><br>' + esc(marker.country.name);
                        var rect2 = container.getBoundingClientRect();
                        tt.style.left = (e.clientX - rect2.left + 12) + 'px';
                        tt.style.top = (e.clientY - rect2.top + 12) + 'px';
                        tt.style.display = 'block';
                    }
                })
                .on('mouseleave', function () {
                    var tt = container.querySelector('.biw-map-tooltip');
                    if (tt) tt.style.display = 'none';
                })
                .on('click', function () {
                    selectCountry(marker.country.iso3);
                });
            });
        }

        // ─── Drawer ──────────────────────────────────────────────────
        function openDrawer(c, year) {
            if (!c) return;
            year = year || state.selectedYear || maxYear;
            state.selectedCountry = c;

            var histEntry = getHistoricalEntry(c.iso3, year);
            var score, rank, rankDisplay, coverage, pillarsData, dqi, vintage;
            if (histEntry) {
                score = histEntry.composite_score !== undefined ? histEntry.composite_score : c[scoreKey];
                rank = histEntry.rank !== undefined ? histEntry.rank : c.rank;
                rankDisplay = histEntry.rank_display || c.rank_display;
                coverage = histEntry.coverage_type || c.coverage;
                pillarsData = histEntry.pillars || {};
                dqi = histEntry.composite_dqi !== undefined ? histEntry.composite_dqi : c.composite_dqi;
                vintage = histEntry.vintage_summary || c.vintage_summary;
            } else {
                score = c[scoreKey];
                rank = c.rank;
                rankDisplay = c.rank_display;
                coverage = c.coverage;
                pillarsData = {};
                pillars.forEach(function(p) { pillarsData[p.key] = c[p.key]; });
                dqi = c.composite_dqi;
                vintage = c.vintage_summary;
            }

            document.getElementById('drawer-country').textContent = c.name;
            document.getElementById('drawer-score').textContent = score || '—';

            var rankEl = document.getElementById('drawer-rank');
            // BUGFIX (2026-09): same fix as rankHtml() above — partial
            // coverage must always show its range, not get silently
            // overridden by the recomputed rank.
            if (coverage === 'partial' && rankDisplay && !rankDisplay.is_definitive && rankDisplay.string_format) {
                rankEl.innerHTML = rankDisplay.string_format;
            } else {
                var recomputedRank = getRecomputedRank(c.iso3, year);
                if (recomputedRank) {
                    rankEl.textContent = '#' + recomputedRank;
                } else if (rankDisplay && rankDisplay.string_format) {
                    rankEl.innerHTML = rankDisplay.string_format;
                } else if (rank !== undefined && rank !== null) {
                    rankEl.textContent = '#' + rank;
                } else {
                    rankEl.textContent = '—';
                }
            }

            var riskLabel = getRiskLabel(score);
            var riskClass = getRiskClass(score);
            var badgeEl = document.getElementById('drawer-risk-badge');
            badgeEl.innerHTML = ' <span class="biw-risk-badge ' + riskClass.replace('biw-badge-', '') + '">' + riskLabel.toUpperCase() + '</span>';

            var delta = deltaInfo(c);
            var deltaEl = document.getElementById('drawer-delta');
            if (delta.type === 'up') deltaEl.innerHTML = '<span class="' + deltaColorClass('up') + '">▲ ' + delta.value + '</span>';
            else if (delta.type === 'down') deltaEl.innerHTML = '<span class="' + deltaColorClass('down') + '">▼ ' + delta.value + '</span>';
            else if (delta.type === 'flat') deltaEl.innerHTML = '<span class="biw-delta-flat">—</span>';
            else deltaEl.innerHTML = '<span class="biw-delta-new">NEW</span>';

            var dqiContainer = document.getElementById('drawer-dqi-badge');
            if (dqiContainer) {
                if (dqi !== null && dqi !== undefined) {
                    var dqiNum = Math.round(dqi);
                    var dqiClass = dqiNum >= 70 ? 'high' : (dqiNum >= 40 ? 'medium' : 'low');
                    dqiContainer.innerHTML = '<span class="biw-dqi-badge ' + dqiClass + '">Data Quality Index (DQI): ' + dqiNum + '%</span>';
                } else {
                    dqiContainer.innerHTML = '<span class="biw-dqi-badge none">Data Quality Index (DQI): —</span>';
                }
            }

            var cov = document.getElementById('drawer-coverage');
            var covType = coverage || 'partial';
            cov.textContent = covType === 'full' ? 'FULL INDEX' : 'PARTIAL INDEX';
            cov.className = 'drawer-coverage ' + covType;

            var pillarHtml = '';
            pillars.forEach(function (p) {
                var v = pillarsData[p.key] !== undefined ? pillarsData[p.key] : c[p.key];
                var pct = (v !== null && v !== undefined) ? v : 0;
                var color = p.color || '#60a5fa';
                pillarHtml += '<div class="pillar-row">' +
                    '<div class="label">' + esc(p.label) + '</div>' +
                    '<div class="track"><div class="fill" style="width:' + pct + '%;background:' + esc(color) + ';"></div></div>' +
                    '<div class="value">' + (v !== null && v !== undefined ? fmtNum(v) : '—') + '</div>' +
                    '</div>';
            });
            document.getElementById('drawer-pillars').innerHTML = pillarHtml;

            var maxPillar = null, maxVal = -1;
            pillars.forEach(function (p) {
                var v = pillarsData[p.key] !== undefined ? pillarsData[p.key] : c[p.key];
                if (v !== null && v !== undefined && v > maxVal) {
                    maxVal = v;
                    maxPillar = p.label;
                }
            });
            var whyText = maxPillar
                ? 'Shows its largest contributing factor in <strong>' + esc(maxPillar) + '</strong> (' + fmtNum(maxVal) + ').'
                : 'Balanced across pillars.';
            document.getElementById('drawer-why').innerHTML = '💡 <strong>Why this score?</strong> ' + whyText + ' ' +
                (coverage === 'partial' ? '<br><em style="color:var(--biw-good)">Partial coverage – rank is a projected range.</em>' : '');

            renderRadar(c, year);
            renderHistoryChart(c);
            renderSensitivity(c);
            renderProvenance(c);

            var wlBtn = document.getElementById('drawer-watchlist');
            var isStarred = watchlist.indexOf(c.iso3) !== -1;
            wlBtn.textContent = isStarred ? '★ Watchlist' : '☆ Watchlist';
            wlBtn.className = isStarred ? 'active' : '';

            var cmpBtn = document.getElementById('drawer-compare');
            var inCompare = state.compareList.some(function (item) { return item.iso3 === c.iso3; });
            cmpBtn.textContent = inCompare ? '⊟ Remove Compare' : '◫ Compare';
            cmpBtn.className = inCompare ? 'active' : '';

            drawerOverlay.classList.add('open');
            drawer.classList.add('open');
        }

        function closeDrawer() {
            drawerOverlay.classList.remove('open');
            drawer.classList.remove('open');
            state.selectedCountry = null;
        }

        function selectCountry(iso3) {
            var c = state.all.find(function (d) { return d.iso3 === iso3; });
            if (c) openDrawer(c, state.selectedYear);
        }

        // ─── Radar ──────────────────────────────────────────────────
        function renderRadar(c, year) {
            var container = document.getElementById('drawer-radar');
            if (!container || !state.d3Ready || typeof d3 === 'undefined') return;
            container.innerHTML = '';
            var width = container.offsetWidth || 260;
            var height = container.offsetHeight || 230;
            var radius = Math.min(width, height) * 0.38;
            var centerX = width / 2;
            var centerY = height / 2;

            var svg = d3.select(container).append('svg')
                .attr('width', width)
                .attr('height', height)
                .append('g')
                .attr('transform', 'translate(' + centerX + ',' + centerY + ')');

            var levels = 5;
            var maxScore = 100;
            var angleSlice = (Math.PI * 2) / pillars.length;

            for (var level = 1; level <= levels; level++) {
                var r = (radius / levels) * level;
                svg.append('circle')
                    .attr('r', r)
                    .attr('fill', 'none')
                    .attr('stroke', 'var(--biw-border)')
                    .attr('stroke-width', 1)
                    .attr('stroke-dasharray', '2,4')
                    .attr('opacity', 1);
            }

            pillars.forEach(function (p, i) {
                var angle = i * angleSlice - Math.PI / 2;
                var x = radius * Math.cos(angle);
                var y = radius * Math.sin(angle);
                svg.append('line')
                    .attr('x1', 0)
                    .attr('y1', 0)
                    .attr('x2', x)
                    .attr('y2', y)
                    .attr('stroke', 'var(--biw-border)')
                    .attr('stroke-width', 0.5);

                var labelX = (radius + 15) * Math.cos(angle);
                var labelY = (radius + 15) * Math.sin(angle);
                svg.append('text')
                    .attr('x', labelX)
                    .attr('y', labelY)
                    .attr('text-anchor', 'middle')
                    .attr('dominant-baseline', 'middle')
                    .style('font-size', '10px')
                    .style('fill', 'var(--biw-slate)')
                    .text(p.label);
            });

            var points = [];
            pillars.forEach(function (p, i) {
                var val = getHistoricalPillar(c.iso3, year, p.key) ?? c[p.key];
                val = (val !== null && val !== undefined) ? val : 0;
                var r = (val / maxScore) * radius;
                var angle = i * angleSlice - Math.PI / 2;
                points.push([r * Math.cos(angle), r * Math.sin(angle)]);
            });

            var line = d3.line()
                .x(function(d) { return d[0]; })
                .y(function(d) { return d[1]; })
                .curve(d3.curveLinearClosed);

            svg.append('path')
                .datum(points)
                .attr('d', line)
                .attr('fill', 'var(--biw-champagne)')
                .attr('fill-opacity', 0.15)
                .attr('stroke', 'var(--biw-champagne)')
                .attr('stroke-width', 2.5);

            points.forEach(function (p) {
                svg.append('circle')
                    .attr('cx', p[0])
                    .attr('cy', p[1])
                    .attr('r', 4)
                    .attr('fill', 'var(--biw-champagne)');
            });
        }

        // ─── History chart ──────────────────────────────────────────
        function renderHistoryChart(c) {
            var container = document.getElementById('drawer-history-chart');
            if (!container) return;
            container.innerHTML = '';
            var history = state.history[c.iso3] || [];

            if (history.length < 2) {
                container.innerHTML = '<div class="no-history">Need more data for trend</div>';
                return;
            }

            var yearMap = {};
            history.forEach(function (entry) {
                var period = entry.period || '';
                var year = parseInt(period.substring(0, 4), 10);
                if (!isNaN(year)) {
                    if (!yearMap[year] || period > yearMap[year].period) {
                        yearMap[year] = entry;
                    }
                }
            });

            var years = Object.keys(yearMap).sort();
            var data = years.map(function (y) {
                var entry = yearMap[y];
                return {
                    period: entry.period,
                    year: parseInt(y, 10),
                    score: entry.composite_score,
                    rank: entry.rank,
                    isForwardFill: false,
                    sourceYear: null
                };
            });

            if (data.length < 2) {
                container.innerHTML = '<div class="no-history">Need more years for trend</div>';
                return;
            }

            var last = data[data.length - 1];
            if (last) {
                var histEntry = history.find(function (h) { return h.period === last.period; });
                if (histEntry) {
                    var energySource = histEntry.data_year_energy;
                    var hhiSource = histEntry.data_year_hhi;
                    var maritimeSource = histEntry.data_year_maritime;
                    if (energySource && energySource < last.year) {
                        last.isForwardFill = true;
                        last.sourceYear = energySource;
                    } else if (hhiSource && hhiSource < last.year) {
                        last.isForwardFill = true;
                        last.sourceYear = hhiSource;
                    } else if (maritimeSource && maritimeSource < last.year) {
                        last.isForwardFill = true;
                        last.sourceYear = maritimeSource;
                    }
                }
            }

            var width = container.clientWidth || 300;
            var height = 80;
            var margin = { top: 8, right: 20, bottom: 20, left: 30 };
            var innerWidth = width - margin.left - margin.right;
            var innerHeight = height - margin.top - margin.bottom;

            var svg = d3.select(container)
                .append('svg')
                .attr('width', width)
                .attr('height', height)
                .append('g')
                .attr('transform', 'translate(' + margin.left + ',' + margin.top + ')');

            var xScale = d3.scalePoint()
                .domain(data.map(function(d) { return d.period; }))
                .range([0, innerWidth]);

            var yMin = d3.min(data, function(d) { return d.score; }) * 0.9;
            var yMax = d3.max(data, function(d) { return d.score; }) * 1.1;
            if (yMin === yMax) {
                yMin = yMin - 5;
                yMax = yMax + 5;
            }
            var yScale = d3.scaleLinear()
                .domain([yMin, yMax])
                .range([innerHeight, 0]);

            svg.append('g')
                .attr('class', 'grid')
                .call(d3.axisLeft(yScale).ticks(3).tickSize(-innerWidth).tickFormat(''))
                .style('stroke', 'var(--biw-border)')
                .style('stroke-dasharray', '2,2')
                .style('opacity', 0.5);

            var solidData = data;
            var dashedData = [];
            var lastPoint = data[data.length - 1];
            if (lastPoint.isForwardFill) {
                solidData = data.slice(0, -1);
                dashedData = [data[data.length - 2], lastPoint];
            }

            var line = d3.line()
                .x(function(d) { return xScale(d.period); })
                .y(function(d) { return yScale(d.score); })
                .curve(d3.curveMonotoneX);

            if (solidData.length > 1) {
                svg.append('path')
                    .datum(solidData)
                    .attr('class', 'history-line-solid')
                    .attr('d', line)
                    .attr('fill', 'none')
                    .attr('stroke', 'var(--biw-champagne)')
                    .attr('stroke-width', 2);
            }

            if (dashedData.length > 1) {
                svg.append('path')
                    .datum(dashedData)
                    .attr('class', 'history-line-dashed')
                    .attr('d', line)
                    .attr('fill', 'none')
                    .attr('stroke', 'var(--biw-champagne)')
                    .attr('stroke-width', 2)
                    .attr('stroke-dasharray', '6,4');
            }

            var area = d3.area()
                .x(function(d) { return xScale(d.period); })
                .y0(innerHeight)
                .y1(function(d) { return yScale(d.score); })
                .curve(d3.curveMonotoneX);

            if (solidData.length > 1) {
                svg.append('path')
                    .datum(solidData)
                    .attr('class', 'history-area')
                    .attr('d', area)
                    .attr('fill', 'var(--biw-champagne)')
                    .attr('fill-opacity', 0.1);
            }

            var tooltip = document.createElement('div');
            tooltip.className = 'history-tooltip';
            container.style.position = 'relative';
            container.appendChild(tooltip);

            data.forEach(function (d) {
                var isFilled = !d.isForwardFill;
                var dot = svg.append('circle')
                    .attr('cx', xScale(d.period))
                    .attr('cy', yScale(d.score))
                    .attr('r', 4)
                    .attr('fill', isFilled ? 'var(--biw-champagne)' : 'transparent')
                    .attr('stroke', 'var(--biw-champagne)')
                    .attr('stroke-width', isFilled ? 0 : 2.5)
                    .style('cursor', 'pointer');

                dot.on('mouseenter', function(event) {
                    var label = d.period + (d.isForwardFill ? ' (estimated)' : '');
                    var sourceInfo = d.isForwardFill ? '· Data from ' + d.sourceYear : '';
                    tooltip.innerHTML = '<strong>' + label + '</strong><br>Score: ' + fmtNum(d.score) +
                        '<br>Rank: #' + d.rank + (sourceInfo ? '<br>' + sourceInfo : '');
                    tooltip.classList.add('visible');
                    var rect = container.getBoundingClientRect();
                    var left = event.clientX - rect.left + 10;
                    var top = event.clientY - rect.top - 30;
                    if (left + 120 > rect.width) left = rect.width - 120;
                    if (top < 0) top = 10;
                    tooltip.style.left = left + 'px';
                    tooltip.style.top = top + 'px';
                })
                .on('mouseleave', function() {
                    tooltip.classList.remove('visible');
                });
            });

            svg.append('g')
                .attr('transform', 'translate(0,' + innerHeight + ')')
                .call(d3.axisBottom(xScale).tickValues(xScale.domain()).tickFormat(function(d) {
                    var parts = d.split('-');
                    if (parts.length === 2) return parts[0];
                    return d;
                }).tickSize(0))
                .style('font-size', '8px')
                .style('fill', 'var(--biw-slate-dim)');
        }

        // ─── Sensitivity ────────────────────────────────────────────
        function renderSensitivity(c) {
            var container = document.getElementById('drawer-sensitivity');
            if (!container) return;
            var si = c.sensitivity_interval;
            if (!si || si.point === undefined || si.ci_low === undefined || si.ci_high === undefined) {
                container.style.display = 'none';
                return;
            }
            container.style.display = 'flex';
            var low = si.ci_low;
            var high = si.ci_high;
            var point = si.point;
            var minVal = Math.min(low, point) - 2;
            var maxVal = Math.max(high, point) + 2;
            var range = maxVal - minVal;
            var pctLow = ((low - minVal) / range) * 100;
            var pctHigh = ((high - minVal) / range) * 100;
            var pctPoint = ((point - minVal) / range) * 100;

            container.innerHTML =
                '<span class="label">Sensitivity range (95%):</span>' +
                '<span class="range">' + fmtNum(low) + ' – ' + fmtNum(high) + '</span>' +
                '<div class="range-bar">' +
                '  <div class="fill" style="left:' + pctLow + '%;width:' + (pctHigh - pctLow) + '%;"></div>' +
                '  <div class="point" style="left:' + pctPoint + '%;"></div>' +
                '</div>';
        }

        // ─── Provenance ─────────────────────────────────────────────
        function renderProvenance(c) {
            var container = document.getElementById('drawer-provenance');
            if (!container) return;
            var freshness = c.data_freshness;
            if (!freshness || typeof freshness !== 'object') {
                container.style.display = 'none';
                return;
            }
            container.style.display = 'block';
            var items = document.getElementById('drawer-provenance-items');
            if (!items) return;
            var html = '';
            var pillarMap = {};
            pillars.forEach(function(p) {
                pillarMap[p.key] = p.label;
            });
            for (var key in freshness) {
                var info = freshness[key];
                if (!info) continue;
                var dotClass = 'good';
                var label = 'Good';
                if (info.quality === 'stale') { dotClass = 'stale'; label = 'Stale'; }
                else if (info.quality === 'aged') { dotClass = 'aged'; label = 'Aged'; }
                else if (!info.available) { dotClass = 'missing'; label = 'Missing'; }
                var pillarLabel = pillarMap[key] || key;
                var source = info.source || 'unknown';
                var year = info.year || '?';
                html += '<div class="provenance-item">' +
                    '<span class="dot ' + dotClass + '"></span>' +
                    '<span class="pillar">' + esc(pillarLabel) + '</span>' +
                    '<span class="info">' + esc(source) + ' (' + esc(year) + ') — ' + esc(label) + '</span>' +
                    '</div>';
            }
            items.innerHTML = html || '<div class="provenance-item" style="color:var(--biw-slate-dim);">No provenance data available</div>';
        }

        // ─── Watchlist ──────────────────────────────────────────────
        function toggleWatchlist(iso3) {
            var idx = watchlist.indexOf(iso3);
            if (idx === -1) watchlist.push(iso3);
            else watchlist.splice(idx, 1);
            try { localStorage.setItem(watchlistKey, JSON.stringify(watchlist)); } catch (e) {}
            render();
            if (state.selectedCountry && state.selectedCountry.iso3 === iso3) {
                openDrawer(state.selectedCountry, state.selectedYear);
            }
        }

        // ─── Compare ────────────────────────────────────────────────
        function toggleCompare(iso3) {
            var idx = state.compareList.findIndex(function (item) { return item.iso3 === iso3; });
            if (idx === -1) {
                var MAX_COMPARE = 4;
                if (state.compareList.length >= MAX_COMPARE) {
                    alert('You can compare up to ' + MAX_COMPARE + ' countries.');
                    return;
                }
                var c = state.all.find(function (d) { return d.iso3 === iso3; });
                if (c) state.compareList.push(c);
            } else {
                state.compareList.splice(idx, 1);
            }
            updateCompareDock();
            render();
            if (state.selectedCountry && state.selectedCountry.iso3 === iso3) {
                openDrawer(state.selectedCountry, state.selectedYear);
            }
        }

        function updateCompareDock() {
            var dock = document.getElementById('biw-compare-dock');
            var items = document.getElementById('biw-compare-items');
            var count = document.getElementById('biw-compare-count');
            if (!dock || !items) return;

            count.textContent = state.compareList.length;
            if (state.compareList.length === 0) {
                dock.classList.remove('visible');
                return;
            }
            dock.classList.add('visible');

            items.innerHTML = state.compareList.map(function (c) {
                return '<div class="dock-item">' + esc(c.name) + ' <button class="remove" data-action="compare-remove" data-iso="' + c.iso3 + '">✕</button></div>';
            }).join('');
        }

        function loadGroup(groupName) {
            var iso3s = countryGroups[groupName];
            if (!iso3s) return;
            var countries = iso3s.map(function(iso3) {
                return state.all.find(function(c) { return c.iso3 === iso3; });
            }).filter(function(c) { return c; });
            if (countries.length === 0) return;
            state.compareList = countries.slice(0, 4);
            updateCompareDock();
            render();
            showCompareModal();
        }

        function showCompareModal() {
            var countries = state.compareList;
            if (countries.length < 2) {
                alert('Select at least 2 countries to compare.');
                return;
            }

            var radarBox = document.getElementById('compare-radar-box');
            var tableWrap = document.getElementById('compare-table-wrap');

            radarBox.innerHTML = '';
            if (state.d3Ready && typeof d3 !== 'undefined') {
                var svg = d3.select(radarBox).append('svg')
                    .attr('width', '100%')
                    .attr('viewBox', '0 0 280 280')
                    .style('max-height', '300px');
                var g = svg.append('g').attr('transform', 'translate(140,140)');
                var radius = 95;
                var angleSlice = (Math.PI * 2) / pillars.length;
                var colorPalette = ['var(--biw-verygood)', 'var(--biw-good)', 'var(--biw-poor)', 'var(--biw-verypoor)', '#c084fc'];

                for (var lvl = 1; lvl <= 5; lvl++) {
                    g.append('circle')
                        .attr('r', (radius / 5) * lvl)
                        .attr('fill', 'none')
                        .attr('stroke', 'var(--biw-border)')
                        .attr('stroke-width', 1)
                        .attr('stroke-dasharray', '2,4')
                        .attr('opacity', 1);
                }

                pillars.forEach(function (p, i) {
                    var angle = i * angleSlice - Math.PI / 2;
                    var x = radius * Math.cos(angle);
                    var y = radius * Math.sin(angle);
                    g.append('line')
                        .attr('x1', 0)
                        .attr('y1', 0)
                        .attr('x2', x)
                        .attr('y2', y)
                        .attr('stroke', 'var(--biw-border)')
                        .attr('stroke-width', 0.5);
                    var labelX = (radius + 16) * Math.cos(angle);
                    var labelY = (radius + 16) * Math.sin(angle);
                    g.append('text')
                        .attr('x', labelX)
                        .attr('y', labelY)
                        .attr('text-anchor', 'middle')
                        .attr('dominant-baseline', 'middle')
                        .style('font-size', '10px')
                        .style('fill', 'var(--biw-slate)')
                        .text(p.label);
                });

                var countryColors = [];
                countries.forEach(function (c, idx) {
                    var color = colorPalette[idx % colorPalette.length];
                    countryColors.push(color);
                    var points = [];
                    pillars.forEach(function (p, i) {
                        var val = getHistoricalPillar(c.iso3, state.selectedYear, p.key) ?? c[p.key];
                        val = (val !== null && val !== undefined) ? val : 0;
                        var r = (val / 100) * radius;
                        var angle = i * angleSlice - Math.PI / 2;
                        points.push([r * Math.cos(angle), r * Math.sin(angle)]);
                    });
                    var line = d3.line()
                        .x(function(d) { return d[0]; })
                        .y(function(d) { return d[1]; })
                        .curve(d3.curveLinearClosed);
                    g.append('path')
                        .datum(points)
                        .attr('d', line)
                        .attr('fill', color)
                        .attr('fill-opacity', 0.15)
                        .attr('stroke', color)
                        .attr('stroke-width', 2.5);
                    points.forEach(function (p) {
                        g.append('circle')
                            .attr('cx', p[0])
                            .attr('cy', p[1])
                            .attr('r', 5)
                            .attr('fill', color);
                    });
                });
                window._compareColors = countryColors;
            }

            var colors = window._compareColors || ['#5EEAD4', '#FCD34D', '#FB923C', '#F87171', '#c084fc'];
            var tableHtml = '<table><thead><tr><th>Metric</th>';
            countries.forEach(function (c, idx) {
                tableHtml += '<th style="border-left: 3px solid ' + colors[idx % colors.length] + '; padding-left: 12px;">' + esc(c.name) + '</th>';
            });
            tableHtml += '</tr></thead><tbody>';
            var rows = [
                { label: 'Rank', key: 'rank', fn: function(c) { return rankHtml(c); } },
                { label: scoreLabel, key: scoreKey, fn: function(c) { return fmtNum(getScoreForYear(c.iso3, state.selectedYear) ?? c[scoreKey]); } },
                {
                    label: 'DQI',
                    key: 'dqi',
                    fn: function(c) {
                        var dqi = c.composite_dqi !== null && c.composite_dqi !== undefined ? Math.round(c.composite_dqi) : null;
                        if (dqi !== null) {
                            var cls = dqi >= 70 ? 'high' : (dqi >= 40 ? 'medium' : 'low');
                            return '<span class="dqi-compare ' + cls + '">' + dqi + '%</span>';
                        }
                        return '<span class="dqi-compare none">—</span>';
                    }
                },
                { label: 'Coverage', key: coverageKey, fn: function(c) { return c[coverageKey] || '—'; } }
            ];
            pillars.forEach(function (p) {
                rows.push({ label: p.label, key: p.key, fn: function(c) { return fmtNum(getHistoricalPillar(c.iso3, state.selectedYear, p.key) ?? c[p.key]); } });
            });
            rows.forEach(function (row) {
                tableHtml += '<tr><td style="font-weight:600;">' + esc(row.label) + '</td>';
                countries.forEach(function (c, idx) {
                    tableHtml += '<td style="border-left: 3px solid ' + colors[idx % colors.length] + '; padding-left: 12px;">' + row.fn(c) + '</td>';
                });
                tableHtml += '</tr>';
            });
            tableHtml += '</tbody></table>';
            tableWrap.innerHTML = tableHtml;

            document.getElementById('biw-compare-overlay').classList.add('visible');
            document.getElementById('biw-compare-modal').classList.add('visible');
        }

        function closeCompareModal() {
            document.getElementById('biw-compare-overlay').classList.remove('visible');
            document.getElementById('biw-compare-modal').classList.remove('visible');
        }

        function clearCompare() {
            state.compareList = [];
            updateCompareDock();
            render();
        }

        // ─── CSV Export ──────────────────────────────────────────────
        function exportCSV() {
            var data = state.filtered.length ? state.filtered : state.all;
            if (!data || data.length === 0) {
                alert('No data to export.');
                return;
            }

            var headers = ['Country', 'Rank', 'Score'];
            pillars.forEach(function(p) {
                headers.push(p.label);
            });
            headers.push('Coverage');
            headers.push('DQI');

            function escapeCSV(val) {
                if (val === null || val === undefined) return '';
                var str = String(val);
                if (str.indexOf(',') !== -1 || str.indexOf('"') !== -1 || str.indexOf('\n') !== -1) {
                    return '"' + str.replace(/"/g, '""') + '"';
                }
                return str;
            }

            var rows = data.map(function(c) {
                var score = getScoreForYear(c.iso3, state.selectedYear) ?? c[scoreKey];
                var rank = getRecomputedRank(c.iso3, state.selectedYear) || '';
                var dqi = c.composite_dqi !== undefined && c.composite_dqi !== null ? Math.round(c.composite_dqi) + '%' : '';
                var coverage = c[coverageKey] || '';

                var row = [
                    c.name || '',
                    rank,
                    score !== undefined && score !== null ? fmtNum(score) : ''
                ];
                pillars.forEach(function(p) {
                    var val = getHistoricalPillar(c.iso3, state.selectedYear, p.key) ?? c[p.key];
                    row.push(val !== undefined && val !== null ? fmtNum(val) : '');
                });
                row.push(coverage);
                row.push(dqi);

                return row.map(escapeCSV).join(',');
            });

            var csv = escapeCSV(headers.join(',')) + '\n' + rows.join('\n');
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = slug + '_export_' + new Date().toISOString().split('T')[0] + '.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        }

        // ─── Dark mode ──────────────────────────────────────────────
        function toggleDark() {
            state.isDark = !state.isDark;
            root.classList.toggle('biw-light', !state.isDark);
            var btn = document.getElementById('biw-dark-toggle');
            if (btn) {
                btn.innerHTML = state.isDark
                    ? '<span class="icon">🌙</span> Dark'
                    : '<span class="icon">☀️</span> Light';
            }
            try { localStorage.setItem('biw_dark_mode_' + slug, state.isDark ? 'dark' : 'light'); } catch (e) {}
        }

        function openShareModal() {
            alert('Share via the share buttons (𝕏, in, 🔗) in the toolbar.');
        }

        function showMethodologyPopup() {
            document.getElementById('biw-method-overlay').classList.add('visible');
            document.getElementById('biw-method-modal').classList.add('visible');
        }
        function closeMethodologyPopup() {
            document.getElementById('biw-method-overlay').classList.remove('visible');
            document.getElementById('biw-method-modal').classList.remove('visible');
        }

        // ─── URL Hash State ──────────────────────────────────────────
        function updateHash() {
            var parts = [];
            if (state.selectedYear !== maxYear) parts.push('year=' + state.selectedYear);
            if (state.mapLayer !== 'score') parts.push('layer=' + state.mapLayer);
            if (state.selectedCountry) parts.push('country=' + state.selectedCountry.iso3);
            if (state.showWatchlistOnly) parts.push('watchlist=1');
            if (parts.length) {
                window.location.hash = parts.join('&');
            } else {
                window.location.hash = '';
            }
        }

        function decodeHash() {
            var hash = window.location.hash.replace('#', '');
            if (!hash) return;
            var params = {};
            hash.split('&').forEach(function(p) {
                var kv = p.split('=');
                params[kv[0]] = decodeURIComponent(kv[1] || '');
            });
            if (params.year) {
                var y = parseInt(params.year, 10);
                if (y >= minYear && y <= maxYear) {
                    state.selectedYear = y;
                    var slider = document.getElementById('biw-year-slider');
                    var label = document.getElementById('biw-year-label');
                    if (slider) slider.value = y;
                    if (label) label.textContent = y;
                }
            }
            if (params.layer) {
                state.mapLayer = params.layer;
                var btns = document.querySelectorAll('.biw-seg-btn[data-layer]');
                btns.forEach(function(b) {
                    b.classList.toggle('active', b.dataset.layer === params.layer);
                });
                if (state.d3Ready) {
                    updateMapColors(state.mapLayer, state.selectedYear);
                }
            }
            if (params.country) {
                var c = state.all.find(function(d) { return d.iso3 === params.country; });
                if (c) selectCountry(c.iso3);
            }
            if (params.watchlist === '1') {
                state.showWatchlistOnly = true;
                var toggle = document.querySelector('.biw-watchlist-toggle');
                if (toggle) toggle.classList.add('active');
            }
        }

        // ─── Block Comparison Feature ───────────────────────────────

        function populateBlockSelectors() {
            var selectA = document.getElementById('biw-block-a');
            var selectB = document.getElementById('biw-block-b');
            if (!selectA || !selectB) return;
            var options = Object.keys(countryGroups).map(function(name) {
                return '<option value="' + name + '">' + name + '</option>';
            }).join('');
            selectA.innerHTML = '<option value="">— Select —</option>' + options;
            selectB.innerHTML = '<option value="">— None —</option>' + options;
        }

        function openBlockSelector(blockA, blockB) {
            var modal = document.getElementById('biw-block-modal');
            var overlay = document.getElementById('biw-block-overlay');
            if (!modal || !overlay) return;

            document.getElementById('biw-block-a').value = blockA || '';
            document.getElementById('biw-block-b').value = blockB || '';

            modal.classList.add('visible');
            overlay.classList.add('visible');

            document.getElementById('block-tabs-wrapper').style.display = 'none';
            document.getElementById('biw-block-results').style.display = 'none';

            if (blockA && blockB) {
                document.getElementById('biw-block-title').innerHTML = '🏛️ ' + esc(blockA) + ' <span style="color:var(--biw-slate-dim)">vs</span> ' + esc(blockB);
            } else if (blockA) {
                document.getElementById('biw-block-title').textContent = '🏛️ ' + blockA;
            } else {
                document.getElementById('biw-block-title').textContent = '🏛️ Compare Groups';
            }

            document.getElementById('biw-block-compare-btn').onclick = function() {
                var a = document.getElementById('biw-block-a').value;
                var b = document.getElementById('biw-block-b').value;
                if (!a) { alert('Please select at least one group.'); return; }
                runBlockComparison(a, b || null);
            };

            document.getElementById('biw-block-close').onclick = closeBlockCompare;
            overlay.onclick = closeBlockCompare;
        }

        function closeBlockCompare() {
            document.getElementById('biw-block-modal').classList.remove('visible');
            document.getElementById('biw-block-overlay').classList.remove('visible');
        }

        function runBlockComparison(blockA, blockB) {
            var resultContainer = document.getElementById('biw-block-results');
            if (!resultContainer) return;

            document.getElementById('block-tabs-wrapper').style.display = 'flex';
            resultContainer.style.display = 'block';

            var statsA = computeBlockStats(blockA);
            var statsB = blockB ? computeBlockStats(blockB) : null;

            var overviewTab = document.getElementById('block-tab-overview');
            if (blockB && statsB) {
                overviewTab.innerHTML = renderTwoBlockOverview(statsA, statsB);
            } else if (statsA) {
                overviewTab.innerHTML = renderSingleBlockOverview(statsA);
            } else {
                overviewTab.innerHTML = '<p style="color:var(--biw-slate-dim)">No data available for the selected group.</p>';
            }

            var detailedTab = document.getElementById('block-tab-detailed');
            detailedTab.innerHTML = renderDetailedComparison(statsA, statsB);

            document.querySelectorAll('.block-tab').forEach(function(tab) {
                tab.onclick = function() {
                    document.querySelectorAll('.block-tab').forEach(function(t) { t.classList.remove('active'); });
                    this.classList.add('active');
                    var target = this.dataset.tab;
                    document.getElementById('block-tab-overview').style.display = target === 'overview' ? 'block' : 'none';
                    document.getElementById('block-tab-detailed').style.display = target === 'detailed' ? 'block' : 'none';
                };
            });
            document.querySelector('.block-tab[data-tab="overview"]').classList.add('active');
            document.getElementById('block-tab-overview').style.display = 'block';
            document.getElementById('block-tab-detailed').style.display = 'none';

            setTimeout(function() {
                if (state.d3Ready && typeof d3 !== 'undefined') {
                    drawBlockRadar(statsA, statsB);
                    if (statsB) {
                        drawBlockPillarComparison(statsA, statsB, 'block-pillar-mini-container');
                    }
                    drawBlockDistribution(statsA, statsB);
                    drawBlockPillarComparison(statsA, statsB, 'block-pillar-container');
                }
            }, 60);

            document.getElementById('biw-block-share').onclick = function() {
                var url = window.location.href.split('#')[0];
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(function() { alert('Link copied to clipboard!'); }, function() { prompt('Copy this link:', url); });
                } else {
                    prompt('Copy this link:', url);
                }
            };
            document.getElementById('biw-block-download').onclick = function() {
                exportBlockCSV(statsA, statsB);
            };
        }

        function computeBlockStats(groupName) {
            var iso3s = countryGroups[groupName];
            if (!iso3s || !state.all || state.all.length === 0) return null;
            var members = iso3s.map(function(iso3) {
                return state.all.find(function(c) { return c.iso3 === iso3; });
            }).filter(function(c) { return c; });
            if (members.length === 0) return null;
            var scores = members.map(function(c) {
                return getScoreForYear(c.iso3, state.selectedYear) ?? c[scoreKey];
            }).filter(function(s) { return s !== null && s !== undefined; });
            if (scores.length === 0) return null;
            var sum = scores.reduce(function(a, b) { return a + b; }, 0);
            var avg = sum / scores.length;
            var min = Math.min.apply(null, scores);
            var max = Math.max.apply(null, scores);
            var stddev = 0;
            if (scores.length > 1) {
                var variance = scores.reduce(function(acc, s) { return acc + Math.pow(s - avg, 2); }, 0) / scores.length;
                stddev = Math.sqrt(variance);
            }
            var mostVuln = members.reduce(function(a, b) {
                var scoreA = getScoreForYear(a.iso3, state.selectedYear) ?? a[scoreKey];
                var scoreB = getScoreForYear(b.iso3, state.selectedYear) ?? b[scoreKey];
                return (scoreA || 0) > (scoreB || 0) ? a : b;
            });
            var leastVuln = members.reduce(function(a, b) {
                var scoreA = getScoreForYear(a.iso3, state.selectedYear) ?? a[scoreKey];
                var scoreB = getScoreForYear(b.iso3, state.selectedYear) ?? b[scoreKey];
                return (scoreA || 0) < (scoreB || 0) ? a : b;
            });
            var pillarAverages = {};
            pillars.forEach(function(p) {
                var vals = members.map(function(c) {
                    return getHistoricalPillar(c.iso3, state.selectedYear, p.key) ?? c[p.key];
                }).filter(function(v) { return v !== null && v !== undefined; });
                pillarAverages[p.key] = vals.length ? (vals.reduce(function(a, b) { return a + b; }, 0) / vals.length) : 0;
            });
            return { name: groupName, members: members, scores: scores, count: members.length, avg: avg, min: min, max: max, stddev: stddev, mostVuln: mostVuln, leastVuln: leastVuln, pillarAverages: pillarAverages };
        }

        function renderSingleBlockOverview(stats) {
            if (!stats) return '<p style="color:var(--biw-slate-dim)">No data available for this group.</p>';
            var html = '<div class="block-overview-content">';
            html += '<div class="block-overview-grid">';
            html += '<div class="block-overview-radar"><div id="block-radar-container" style="height:280px;"></div></div>';
            html += '<div class="block-overview-summary">';
            html += '<div class="block-summary-cards">' +
                '<div class="block-stat-card"><span class="stat-label">Countries</span><span class="stat-value">' + stats.count + '</span></div>' +
                '<div class="block-stat-card"><span class="stat-label">Average Score</span><span class="stat-value">' + fmtNum(stats.avg) + '</span></div>' +
                '<div class="block-stat-card"><span class="stat-label">Range</span><span class="stat-value">' + fmtNum(stats.min) + ' – ' + fmtNum(stats.max) + '</span></div>' +
                '<div class="block-stat-card"><span class="stat-label">Std Dev</span><span class="stat-value">' + fmtNum(stats.stddev) + '</span></div>' +
                '</div>';
            html += '<div class="block-extremes">' +
                '<div><strong>Highest scoring:</strong> <span class="block-country-link" data-iso3="' + stats.mostVuln.iso3 + '">' + esc(stats.mostVuln.name) + '</span> (' + fmtNum(getScoreForYear(stats.mostVuln.iso3, state.selectedYear) ?? stats.mostVuln[scoreKey]) + ')</div>' +
                '<div><strong>Lowest scoring:</strong> <span class="block-country-link" data-iso3="' + stats.leastVuln.iso3 + '">' + esc(stats.leastVuln.name) + '</span> (' + fmtNum(getScoreForYear(stats.leastVuln.iso3, state.selectedYear) ?? stats.leastVuln[scoreKey]) + ')</div>' +
                '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            return html;
        }

        function renderTwoBlockOverview(statsA, statsB) {
            if (!statsA || !statsB) return '<p style="color:var(--biw-slate-dim)">No data available for one of the groups.</p>';
            var diff = statsA.avg - statsB.avg;
            var diffText = diff > 0
                ? statsA.name + ' scores <strong>' + fmtNum(Math.abs(diff)) + '</strong> points higher on average'
                : statsB.name + ' scores <strong>' + fmtNum(Math.abs(diff)) + '</strong> points higher on average';
            var html = '<div class="block-overview-content">';
            html += '<div class="block-overview-grid">';
            html += '<div class="block-overview-radar"><div id="block-radar-container" style="height:300px;"></div><div class="block-legend"><span class="legend-a">● ' + esc(statsA.name) + '</span><span class="legend-b">● ' + esc(statsB.name) + '</span></div></div>';
            html += '<div class="block-overview-summary">';
            html += '<div class="block-summary-cards two-block">' +
                '<div class="block-stat-card block-a"><span class="stat-label">' + esc(statsA.name) + '</span><span class="stat-value">' + fmtNum(statsA.avg) + '</span><span class="stat-sub">' + statsA.count + ' countries · σ ' + fmtNum(statsA.stddev) + '</span></div>' +
                '<div class="block-stat-card block-b"><span class="stat-label">' + esc(statsB.name) + '</span><span class="stat-value">' + fmtNum(statsB.avg) + '</span><span class="stat-sub">' + statsB.count + ' countries · σ ' + fmtNum(statsB.stddev) + '</span></div>' +
                '</div>';
            html += '<div class="block-diff-indicator"><span>📊 ' + diffText + '</span></div>';
            html += '<div class="block-extremes two-col">' +
                '<div><strong>' + esc(statsA.name) + ' highest scoring:</strong> <span class="block-country-link" data-iso3="' + statsA.mostVuln.iso3 + '">' + esc(statsA.mostVuln.name) + '</span> (' + fmtNum(getScoreForYear(statsA.mostVuln.iso3, state.selectedYear) ?? statsA.mostVuln[scoreKey]) + ')</div>' +
                '<div><strong>' + esc(statsB.name) + ' highest scoring:</strong> <span class="block-country-link" data-iso3="' + statsB.mostVuln.iso3 + '">' + esc(statsB.mostVuln.name) + '</span> (' + fmtNum(getScoreForYear(statsB.mostVuln.iso3, state.selectedYear) ?? statsB.mostVuln[scoreKey]) + ')</div>' +
                '<div><strong>' + esc(statsA.name) + ' lowest scoring:</strong> <span class="block-country-link" data-iso3="' + statsA.leastVuln.iso3 + '">' + esc(statsA.leastVuln.name) + '</span> (' + fmtNum(getScoreForYear(statsA.leastVuln.iso3, state.selectedYear) ?? statsA.leastVuln[scoreKey]) + ')</div>' +
                '<div><strong>' + esc(statsB.name) + ' lowest scoring:</strong> <span class="block-country-link" data-iso3="' + statsB.leastVuln.iso3 + '">' + esc(statsB.leastVuln.name) + '</span> (' + fmtNum(getScoreForYear(statsB.leastVuln.iso3, state.selectedYear) ?? statsB.leastVuln[scoreKey]) + ')</div>' +
                '</div>';
            html += '</div>';
            html += '</div>';
            html += '</div>';
            return html;
        }

        function renderDetailedComparison(statsA, statsB) {
            var html = '<div class="block-detailed-content">';
            if (statsA && statsB) {
                html += '<div class="block-chart-wrap"><h4>Metrics Comparison</h4><div class="block-detailed-table"><table><thead><tr><th>Metric</th><th class="col-a">' + esc(statsA.name) + '</th><th class="col-b">' + esc(statsB.name) + '</th><th>Difference</th></tr></thead><tbody>';
                var rows = [
                    { label: 'Countries', a: statsA.count, b: statsB.count, diff: false },
                    { label: 'Average Score', a: fmtNum(statsA.avg), b: fmtNum(statsB.avg), diff: true, valA: statsA.avg, valB: statsB.avg },
                    { label: 'Min Score', a: fmtNum(statsA.min), b: fmtNum(statsB.min), diff: true, valA: statsA.min, valB: statsB.min },
                    { label: 'Max Score', a: fmtNum(statsA.max), b: fmtNum(statsB.max), diff: true, valA: statsA.max, valB: statsB.max },
                    { label: 'Std Deviation', a: fmtNum(statsA.stddev), b: fmtNum(statsB.stddev), diff: true, valA: statsA.stddev, valB: statsB.stddev }
                ];
                pillars.forEach(function(p) {
                    var valA = statsA.pillarAverages[p.key] !== undefined ? statsA.pillarAverages[p.key] : null;
                    var valB = statsB.pillarAverages[p.key] !== undefined ? statsB.pillarAverages[p.key] : null;
                    rows.push({ label: p.label, a: valA !== null ? fmtNum(valA) : '—', b: valB !== null ? fmtNum(valB) : '—', diff: true, valA: valA || 0, valB: valB || 0 });
                });
                rows.forEach(function(row) {
                    var diffCell = '';
                    if (row.diff) {
                        var d = row.valA - row.valB;
                        var diffClass = d > 0 ? 'diff-a' : (d < 0 ? 'diff-b' : 'diff-none');
                        diffCell = '<td class="' + diffClass + '">' + (d > 0 ? '+' : '') + fmtNum(d) + '</td>';
                    } else {
                        diffCell = '<td class="diff-none">—</td>';
                    }
                    html += '<tr><td>' + esc(row.label) + '</td><td class="col-a">' + row.a + '</td><td class="col-b">' + row.b + '</td>' + diffCell + '</tr>';
                });
                html += '</tbody></table></div></div>';
                html += '<div class="block-chart-wrap"><h4>Score Distribution</h4><div id="block-dist-container" style="height:160px;"></div></div>';
                html += '<div class="block-chart-wrap"><h4>Pillar Breakdown</h4><div id="block-pillar-container" style="height:240px;"></div></div>';
                html += '<div class="block-chart-wrap"><h4>All Members</h4><div class="block-member-table-wrap"><table class="block-member-table"><thead><tr><th>Country</th><th>Group</th><th>Score</th><th>Rank</th><th>Coverage</th></tr></thead><tbody>';
                var allMembers = [];
                statsA.members.forEach(function(c) { allMembers.push({ country: c, block: statsA.name, blockClass: 'block-a' }); });
                statsB.members.forEach(function(c) { allMembers.push({ country: c, block: statsB.name, blockClass: 'block-b' }); });
                allMembers.sort(function(a, b) {
                    var scoreA = getScoreForYear(a.country.iso3, state.selectedYear) ?? a.country[scoreKey];
                    var scoreB = getScoreForYear(b.country.iso3, state.selectedYear) ?? b.country[scoreKey];
                    return (scoreB || 0) - (scoreA || 0);
                });
                allMembers.forEach(function(m) {
                    var score = getScoreForYear(m.country.iso3, state.selectedYear) ?? m.country[scoreKey];
                    var rank = getRecomputedRank(m.country.iso3, state.selectedYear);
                    var cov = m.country[coverageKey] || 'partial';
                    html += '<tr><td><span class="block-country-link" data-iso3="' + m.country.iso3 + '">' + esc(m.country.name) + '</span></td>' +
                        '<td><span class="block-badge ' + m.blockClass + '">' + esc(m.block) + '</span></td>' +
                        '<td><span class="block-score-badge" style="background:' + getColor(score) + '20;color:' + getColor(score) + ';">' + fmtNum(score) + '</span></td>' +
                        '<td>#' + (rank || '—') + '</td>' +
                        '<td><span class="block-cov-badge ' + cov + '">' + cov + '</span></td></tr>';
                });
                html += '</tbody></table></div></div>';
            } else if (statsA) {
                html += '<div class="block-chart-wrap"><h4>Score Distribution</h4><div id="block-dist-container" style="height:160px;"></div></div>';
                html += '<div class="block-chart-wrap"><h4>Pillar Breakdown</h4><div id="block-pillar-container" style="height:240px;"></div></div>';
                html += '<div class="block-chart-wrap"><h4>Member Countries</h4><div class="block-member-table-wrap"><table class="block-member-table"><thead><tr><th>Country</th><th>Score</th><th>Rank</th><th>Coverage</th></tr></thead><tbody>';
                var sortedMembers = statsA.members.slice().sort(function(a, b) {
                    var scoreA = getScoreForYear(a.iso3, state.selectedYear) ?? a[scoreKey];
                    var scoreB = getScoreForYear(b.iso3, state.selectedYear) ?? b[scoreKey];
                    return (scoreB || 0) - (scoreA || 0);
                });
                sortedMembers.forEach(function(c) {
                    var score = getScoreForYear(c.iso3, state.selectedYear) ?? c[scoreKey];
                    var rank = getRecomputedRank(c.iso3, state.selectedYear);
                    var cov = c[coverageKey] || 'partial';
                    html += '<tr><td><span class="block-country-link" data-iso3="' + c.iso3 + '">' + esc(c.name) + '</span></td>' +
                        '<td><span class="block-score-badge" style="background:' + getColor(score) + '20;color:' + getColor(score) + ';">' + fmtNum(score) + '</span></td>' +
                        '<td>#' + (rank || '—') + '</td>' +
                        '<td><span class="block-cov-badge ' + cov + '">' + cov + '</span></td></tr>';
                });
                html += '</tbody></table></div></div>';
            }
            html += '</div>';
            return html;
        }

        function drawBlockRadar(statsA, statsB) {
            var container = document.getElementById('block-radar-container');
            if (!container) return;
            container.innerHTML = '';
            if (!state.d3Ready || typeof d3 === 'undefined') return;
            if (!statsA) {
                container.innerHTML = '<div style="text-align:center;padding:40px;color:var(--biw-slate-dim);">No data to display.</div>';
                return;
            }
            var width = container.offsetWidth || 500;
            var height = container.offsetHeight || 260;
            var radius = Math.min(width, height) * 0.32;
            var centerX = width / 2;
            var centerY = height / 2 + 10;

            var svg = d3.select(container).append('svg')
                .attr('width', width)
                .attr('height', height)
                .append('g')
                .attr('transform', 'translate(' + centerX + ',' + centerY + ')');

            var levels = 5;
            var maxScore = 100;
            var angleSlice = (Math.PI * 2) / pillars.length;

            for (var level = 1; level <= levels; level++) {
                var r = (radius / levels) * level;
                svg.append('circle')
                    .attr('r', r)
                    .attr('fill', 'none')
                    .attr('stroke', 'var(--biw-border)')
                    .attr('stroke-width', 1)
                    .attr('stroke-dasharray', '2,4')
                    .attr('opacity', 1);
            }

            pillars.forEach(function(p, i) {
                var angle = i * angleSlice - Math.PI / 2;
                var x = radius * Math.cos(angle);
                var y = radius * Math.sin(angle);
                svg.append('line')
                    .attr('x1', 0).attr('y1', 0).attr('x2', x).attr('y2', y)
                    .attr('stroke', 'var(--biw-border)')
                    .attr('stroke-width', 0.5);
                var labelX = (radius + 18) * Math.cos(angle);
                var labelY = (radius + 18) * Math.sin(angle);
                svg.append('text')
                    .attr('x', labelX).attr('y', labelY)
                    .attr('text-anchor', 'middle')
                    .attr('dominant-baseline', 'middle')
                    .style('font-size', '10px')
                    .style('fill', 'var(--biw-slate)')
                    .text(p.label);
            });

            function drawPoly(stats, color, labelOffsetY) {
                var points = pillars.map(function(p, i) {
                    var val = stats.pillarAverages[p.key] || 0;
                    var r = (val / maxScore) * radius;
                    var angle = i * angleSlice - Math.PI / 2;
                    return [r * Math.cos(angle), r * Math.sin(angle)];
                });
                var line = d3.line().x(function(d) { return d[0]; }).y(function(d) { return d[1]; }).curve(d3.curveLinearClosed);
                svg.append('path').datum(points).attr('d', line)
                    .attr('fill', color).attr('fill-opacity', 0.12)
                    .attr('stroke', color).attr('stroke-width', 2.5);
                points.forEach(function(p) {
                    svg.append('circle').attr('cx', p[0]).attr('cy', p[1]).attr('r', 4).attr('fill', color);
                });
                if (labelOffsetY) {
                    svg.append('text').attr('x', radius + 24).attr('y', labelOffsetY)
                        .style('font-size', '11px').style('fill', color).style('font-weight', 'bold')
                        .text(stats.name);
                }
            }

            drawPoly(statsA, 'var(--biw-champagne)', -radius - 8);
            if (statsB) {
                drawPoly(statsB, 'var(--biw-verypoor)', -radius - 24);
            }
        }

        function drawBlockPillarComparison(statsA, statsB, containerId) {
            var container = document.getElementById(containerId);
            if (!container || !statsA) return;
            container.innerHTML = '';
            if (!state.d3Ready || typeof d3 === 'undefined') return;

            var width = container.offsetWidth || 500;
            var height = container.offsetHeight || 220;
            var margin = { top: 20, right: 20, bottom: 50, left: 40 };
            var innerWidth = width - margin.left - margin.right;
            var innerHeight = height - margin.top - margin.bottom;

            var svg = d3.select(container).append('svg')
                .attr('width', width).attr('height', height)
                .append('g')
                .attr('transform', 'translate(' + margin.left + ',' + margin.top + ')');

            var data = pillars.map(function(p) {
                return { pillar: p.label, a: statsA.pillarAverages[p.key] || 0, b: statsB ? (statsB.pillarAverages[p.key] || 0) : 0 };
            });

            var x0 = d3.scaleBand().domain(data.map(function(d) { return d.pillar; })).rangeRound([0, innerWidth]).paddingInner(0.2);
            var x1 = d3.scaleBand().domain(['a', 'b']).rangeRound([0, x0.bandwidth()]).padding(0.08);
            var y = d3.scaleLinear().domain([0, 100]).rangeRound([innerHeight, 0]);

            var colorA = 'var(--biw-champagne)';
            var colorB = 'var(--biw-verypoor)';

            svg.append('g').attr('class', 'grid')
                .call(d3.axisLeft(y).ticks(5).tickSize(-innerWidth).tickFormat(''))
                .style('stroke', 'var(--biw-border)').style('stroke-dasharray', '2,2').style('opacity', 0.3);

            var pillarGroups = svg.selectAll('.pillar-group').data(data).enter().append('g')
                .attr('class', 'pillar-group')
                .attr('transform', function(d) { return 'translate(' + x0(d.pillar) + ',0)'; });

            pillarGroups.append('rect')
                .attr('x', x1('a')).attr('y', function(d) { return y(d.a); })
                .attr('width', x1.bandwidth()).attr('height', function(d) { return innerHeight - y(d.a); })
                .attr('fill', colorA).attr('rx', 3);

            pillarGroups.append('text')
                .attr('x', x1('a') + x1.bandwidth() / 2).attr('y', function(d) { return y(d.a) - 5; })
                .attr('text-anchor', 'middle').style('font-size', '9px').style('fill', 'var(--biw-text)').text(function(d) { return fmtNum(d.a); });

            if (statsB) {
                pillarGroups.append('rect')
                    .attr('x', x1('b')).attr('y', function(d) { return y(d.b); })
                    .attr('width', x1.bandwidth()).attr('height', function(d) { return innerHeight - y(d.b); })
                    .attr('fill', colorB).attr('rx', 3);
                pillarGroups.append('text')
                    .attr('x', x1('b') + x1.bandwidth() / 2).attr('y', function(d) { return y(d.b) - 5; })
                    .attr('text-anchor', 'middle').style('font-size', '9px').style('fill', 'var(--biw-text)').text(function(d) { return fmtNum(d.b); });
            }

            svg.append('g').attr('transform', 'translate(0,' + innerHeight + ')')
                .call(d3.axisBottom(x0)).style('font-size', '10px').style('fill', 'var(--biw-slate)')
                .selectAll('text').style('text-anchor', 'middle');

            svg.append('g').call(d3.axisLeft(y).ticks(5)).style('font-size', '9px').style('fill', 'var(--biw-slate-dim)');
        }

        function drawBlockDistribution(statsA, statsB) {
            var container = document.getElementById('block-dist-container');
            if (!container || !statsA) return;
            container.innerHTML = '';
            if (!state.d3Ready || typeof d3 === 'undefined') return;

            var width = container.offsetWidth || 500;
            var height = container.offsetHeight || 160;
            var margin = { top: 20, right: 30, bottom: 30, left: 40 };
            var innerWidth = width - margin.left - margin.right;
            var innerHeight = height - margin.top - margin.bottom;

            var svg = d3.select(container).append('svg')
                .attr('width', width).attr('height', height)
                .append('g')
                .attr('transform', 'translate(' + margin.left + ',' + margin.top + ')');

            var binSize = 5;
            var numBins = 20;
            function makeBins(scores) {
                var bins = Array.from({ length: numBins }, function() { return 0; });
                scores.forEach(function(s) {
                    var idx = Math.min(numBins - 1, Math.floor(s / binSize));
                    bins[idx]++;
                });
                return bins;
            }
            var binsA = makeBins(statsA.scores);
            var binsB = statsB ? makeBins(statsB.scores) : null;
            var maxCount = Math.max.apply(null, binsA.concat(binsB || [])) || 1;

            var x = d3.scaleLinear().domain([0, 100]).range([0, innerWidth]);
            var y = d3.scaleLinear().domain([0, maxCount]).range([innerHeight, 0]);

            svg.append('g').attr('class', 'grid')
                .call(d3.axisLeft(y).ticks(3).tickSize(-innerWidth).tickFormat(''))
                .style('stroke', 'var(--biw-border)').style('stroke-dasharray', '2,2').style('opacity', 0.3);

            var barWidth = innerWidth / numBins;
            svg.selectAll('.bar-a').data(binsA).enter().append('rect').attr('class', 'bar-a')
                .attr('x', function(d, i) { return i * barWidth; })
                .attr('y', function(d) { return y(d); })
                .attr('width', barWidth - 1).attr('height', function(d) { return innerHeight - y(d); })
                .attr('fill', 'var(--biw-champagne)').attr('opacity', 0.7);

            if (binsB) {
                svg.selectAll('.bar-b').data(binsB).enter().append('rect').attr('class', 'bar-b')
                    .attr('x', function(d, i) { return i * barWidth + 1; })
                    .attr('y', function(d) { return y(d); })
                    .attr('width', barWidth - 3).attr('height', function(d) { return innerHeight - y(d); })
                    .attr('fill', 'var(--biw-verypoor)').attr('opacity', 0.55);
            }

            bandThresholds.forEach(function(t) {
                svg.append('line').attr('x1', x(t)).attr('x2', x(t)).attr('y1', 0).attr('y2', innerHeight)
                    .attr('stroke', 'var(--biw-border)').attr('stroke-dasharray', '3,3').attr('opacity', 0.5);
            });

            svg.append('g').attr('transform', 'translate(0,' + innerHeight + ')')
                .call(d3.axisBottom(x).ticks(5)).style('font-size', '9px').style('fill', 'var(--biw-slate-dim)');
            svg.append('g').call(d3.axisLeft(y).ticks(3)).style('font-size', '9px').style('fill', 'var(--biw-slate-dim)');

            var legend = svg.append('g').attr('transform', 'translate(' + (innerWidth - 90) + ',0)');
            legend.append('rect').attr('width', 10).attr('height', 10).attr('fill', 'var(--biw-champagne)');
            legend.append('text').attr('x', 14).attr('y', 9).style('font-size', '10px').style('fill', 'var(--biw-text)').text(statsA.name);
            if (statsB) {
                legend.append('rect').attr('y', 14).attr('width', 10).attr('height', 10).attr('fill', 'var(--biw-verypoor)');
                legend.append('text').attr('x', 14).attr('y', 23).style('font-size', '10px').style('fill', 'var(--biw-text)').text(statsB.name);
            }
        }

        function drawBlockBarChart(statsA, statsB) {
            var container = document.getElementById('block-bar-container');
            if (!container || !statsA) return;
            container.innerHTML = '';
            if (!state.d3Ready || typeof d3 === 'undefined') return;
            var width = container.offsetWidth || 300;
            var height = container.offsetHeight || 150;
            var margin = { top: 20, right: 20, bottom: 20, left: 40 };
            var innerWidth = width - margin.left - margin.right;
            var innerHeight = height - margin.top - margin.bottom;
            var svg = d3.select(container).append('svg').attr('width', width).attr('height', height)
                .append('g').attr('transform', 'translate(' + margin.left + ',' + margin.top + ')');
            var data = [];
            if (statsA) data.push({ label: statsA.name, value: statsA.avg });
            if (statsB) data.push({ label: statsB.name, value: statsB.avg });
            var xScale = d3.scaleBand().domain(data.map(function(d) { return d.label; })).range([0, innerWidth]).padding(0.3);
            var yScale = d3.scaleLinear().domain([0, Math.max(100, d3.max(data, function(d) { return d.value; }) * 1.2)]).range([innerHeight, 0]);
            var colors = ['var(--biw-champagne)', 'var(--biw-verypoor)'];
            svg.selectAll('rect').data(data).enter().append('rect')
                .attr('x', function(d) { return xScale(d.label); })
                .attr('y', function(d) { return yScale(d.value); })
                .attr('width', xScale.bandwidth())
                .attr('height', function(d) { return innerHeight - yScale(d.value); })
                .attr('fill', function(d, i) { return colors[i] || 'var(--biw-champagne)'; });
            svg.selectAll('text.bar-label').data(data).enter().append('text').attr('class', 'bar-label')
                .attr('x', function(d) { return xScale(d.label) + xScale.bandwidth() / 2; })
                .attr('y', function(d) { return yScale(d.value) - 5; })
                .attr('text-anchor', 'middle').style('font-size', '10px').style('fill', 'var(--biw-text)').text(function(d) { return fmtNum(d.value); });
            svg.append('g').attr('transform', 'translate(0,' + innerHeight + ')').call(d3.axisBottom(xScale));
        }

        function exportBlockCSV(statsA, statsB) {
            if (!statsA && !statsB) return;
            var rows = [];
            var headers = ['Block', 'Countries', 'Average', 'Min', 'Max', 'StdDev'];
            pillars.forEach(function(p) { headers.push(p.label + ' (avg)'); });
            rows.push(headers);
            if (statsA) {
                var rowA = [statsA.name, statsA.count, fmtNum(statsA.avg), fmtNum(statsA.min), fmtNum(statsA.max), fmtNum(statsA.stddev)];
                pillars.forEach(function(p) { rowA.push(fmtNum(statsA.pillarAverages[p.key] || 0)); });
                rows.push(rowA);
            }
            if (statsB) {
                var rowB = [statsB.name, statsB.count, fmtNum(statsB.avg), fmtNum(statsB.min), fmtNum(statsB.max), fmtNum(statsB.stddev)];
                pillars.forEach(function(p) { rowB.push(fmtNum(statsB.pillarAverages[p.key] || 0)); });
                rows.push(rowB);
            }
            var csv = rows.map(function(row) { return row.join(','); }).join('\n');
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = slug + '_block_comparison.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        }

        // ─── Block Compare Entry Points ────────────────────────────

        document.addEventListener('click', function(e) {
            var card = e.target.closest('#biw-block-card');
            if (card && !e.target.closest('.block-card-btn') && !e.target.closest('.block-card-view-all')) {
                openBlockSelector(null, null);
            }
        });

        document.addEventListener('change', function(e) {
            var dropdown = e.target.closest('#biw-block-dropdown');
            if (dropdown) {
                var val = dropdown.value;
                if (val === '__all__') {
                    openBlockSelector(null, null);
                } else if (val) {
                    openBlockSelector(val, null);
                }
                dropdown.value = '';
            }
        });

        // ─── Header Methodology Link ─────────────────────────────────
        document.getElementById('biw-header-method').addEventListener('click', function(e) {
            e.preventDefault();
            showMethodologyPopup();
        });

        // ─── Events ──────────────────────────────────────────────────
        function bindControls() {
            // ─── Fix methodology popup close ──────────────────────────
            document.getElementById('biw-method-close').addEventListener('click', closeMethodologyPopup);
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && document.getElementById('biw-method-modal').classList.contains('visible')) {
                    closeMethodologyPopup();
                }
            });

            // ─── Single delegated listener to hide all tooltips ──────
            root.addEventListener('click', function(e) {
                var target = e.target;
                var isTooltip = target.closest('.biw-custom-tooltip') || target.closest('.biw-map-tooltip');
                if (!isTooltip) {
                    root.querySelectorAll('.biw-custom-tooltip.visible, .biw-map-tooltip.visible').forEach(function(t) {
                        t.classList.remove('visible', 'pinned');
                    });
                }
            });

            var darkBtn = document.getElementById('biw-dark-toggle');
            if (darkBtn) {
                // BUGFIX (2026-09): state.isDark defaults to false (light)
                // at initialization above, but this code only ever added
                // the CSS class needed to actually render light mode when
                // localStorage explicitly said 'light' — a first-time
                // visitor with nothing saved got dark mode by default
                // (the base, class-less CSS) despite the JS state saying
                // otherwise. Light is now the true default; only an
                // explicit saved 'dark' preference switches away from it.
                try {
                    var pref = localStorage.getItem('biw_dark_mode_' + slug);
                    if (pref === 'dark') {
                        state.isDark = true;
                        root.classList.remove('biw-light');
                        darkBtn.innerHTML = '<span class="icon">🌙</span> Dark';
                    } else {
                        state.isDark = false;
                        root.classList.add('biw-light');
                        darkBtn.innerHTML = '<span class="icon">☀️</span> Light';
                    }
                } catch (e) {
                    root.classList.add('biw-light');
                }
                darkBtn.addEventListener('click', toggleDark);
            }

            document.getElementById('biw-drawer-close').addEventListener('click', closeDrawer);
            document.getElementById('biw-drawer-overlay').addEventListener('click', closeDrawer);

            document.getElementById('drawer-watchlist').addEventListener('click', function () {
                if (state.selectedCountry) toggleWatchlist(state.selectedCountry.iso3);
            });
            document.getElementById('drawer-compare').addEventListener('click', function () {
                if (state.selectedCountry) toggleCompare(state.selectedCountry.iso3);
            });
            document.getElementById('drawer-share').addEventListener('click', openShareModal);

            document.getElementById('biw-compare-dock-close').addEventListener('click', function () {
                document.getElementById('biw-compare-dock').classList.remove('visible');
            });
            document.getElementById('biw-compare-view-btn').addEventListener('click', showCompareModal);
            document.getElementById('biw-compare-clear-btn').addEventListener('click', clearCompare);
            document.getElementById('biw-compare-modal-close').addEventListener('click', closeCompareModal);
            document.getElementById('biw-compare-overlay').addEventListener('click', closeCompareModal);
            document.getElementById('biw-compare-items').addEventListener('click', function (e) {
                var target = e.target.closest('[data-action="compare-remove"]');
                if (target) {
                    var iso = target.dataset.iso;
                    toggleCompare(iso);
                }
            });

            var exportBtn = document.getElementById('biw-btn-export');
            if (exportBtn) exportBtn.addEventListener('click', exportCSV);

            var search = q('.biw-search');
            if (search) search.addEventListener('input', render);

            var bandFilter = q('.biw-band-filter');
            if (bandFilter) bandFilter.addEventListener('change', render);

            var regionFilter = document.getElementById('biw-region-filter');
            if (regionFilter) regionFilter.addEventListener('change', render);

            var sortSelect = q('.biw-sort-select');
            if (sortSelect) sortSelect.addEventListener('change', render);

            var wlToggle = q('.biw-watchlist-toggle');
            if (wlToggle) {
                if (state.showWatchlistOnly) {
                    wlToggle.classList.add('active');
                } else {
                    wlToggle.classList.remove('active');
                }
                wlToggle.addEventListener('click', function () {
                    state.showWatchlistOnly = !state.showWatchlistOnly;
                    if (state.showWatchlistOnly) {
                        this.classList.add('active');
                    } else {
                        this.classList.remove('active');
                    }
                    render();
                    updateHash();
                });
            }

            var printBtn = q('.biw-btn-print');
            if (printBtn) printBtn.addEventListener('click', function () { window.print(); });

            qa('.biw-btn-share').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var kind = this.getAttribute('data-share');
                    var url = window.location.href;
                    var title = root.getAttribute('data-biw-title') || document.title;
                    var chart = this.getAttribute('data-chart');
                    var shareText = chart ? 'Check out the ' + title + ' ' + chart + ' chart: ' : 'Check out the ' + title + ': ';
                    if (kind === 'x') {
                        window.open('https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(url), '_blank');
                    } else if (kind === 'linkedin') {
                        window.open('https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(url), '_blank');
                    } else if (kind === 'copy') {
                        var fallback = function () {
                            var ta = document.createElement('textarea');
                            ta.value = url;
                            ta.style.position = 'fixed';
                            ta.style.opacity = '0';
                            document.body.appendChild(ta);
                            ta.select();
                            try { document.execCommand('copy'); } catch (e) {}
                            document.body.removeChild(ta);
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(url).then(function () {
                                btn.innerHTML = '✓';
                                setTimeout(function () { btn.innerHTML = '🔗'; }, 1500);
                            }, fallback);
                        } else {
                            fallback();
                            btn.innerHTML = '✓';
                            setTimeout(function () { btn.innerHTML = '🔗'; }, 1500);
                        }
                    }
                });
            });

            var layerBtns = qa('.biw-seg-btn');
            layerBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    layerBtns.forEach(function (b) { b.classList.remove('active'); });
                    this.classList.add('active');
                    state.mapLayer = this.getAttribute('data-layer');
                    updateMapColors(state.mapLayer, state.selectedYear);
                    updateMapMarkers();
                    updateHash();
                });
            });

            var scatterX = q('.biw-scatter-x');
            var scatterY = q('.biw-scatter-y');
            if (scatterX) scatterX.addEventListener('change', renderScatter);
            if (scatterY) scatterY.addEventListener('change', renderScatter);

            root.addEventListener('click', function (e) {
                var target = e.target;
                var actionEl = target.closest('[data-action]');
                if (actionEl) {
                    var action = actionEl.getAttribute('data-action');
                    if (action === 'star') {
                        var idx = parseInt(actionEl.getAttribute('data-idx'), 10);
                        var row = state.filtered[idx];
                        if (row) toggleWatchlist(row.iso3);
                        return;
                    }
                    if (action === 'detail') {
                        var idx = parseInt(actionEl.getAttribute('data-idx'), 10);
                        var row = state.filtered[idx];
                        if (row) selectCountry(row.iso3);
                        return;
                    }
                    if (action === 'compare-check') {
                        var idx = parseInt(actionEl.getAttribute('data-idx'), 10);
                        var row = state.filtered[idx];
                        if (row) toggleCompare(row.iso3);
                        return;
                    }
                }
                var tr = target.closest('tr[data-idx]');
                if (tr && !target.closest('button') && !target.closest('input')) {
                    var idx = parseInt(tr.getAttribute('data-idx'), 10);
                    var row = state.filtered[idx];
                    if (row) selectCountry(row.iso3);
                }
                var moverItem = target.closest('.biw-mover-item');
                if (moverItem) {
                    var iso3 = moverItem.getAttribute('data-iso3');
                    if (iso3) selectCountry(iso3);
                }
                var blockLink = target.closest('.block-country-link');
                if (blockLink) {
                    var iso3 = blockLink.dataset.iso3;
                    if (iso3) selectCountry(iso3);
                }
            });

            window.addEventListener('hashchange', function() {
                decodeHash();
                render();
            });

            decodeHash();
        }

        function renderDashboard() {
            if (view !== 'dashboard') return;
            renderMap('score', maxYear);
            renderDonut();
            renderExtremes();
            renderHistogram();
            renderScatter();
        }

        function esc(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/[&<>"']/g, function (m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
            });
        }
        function fmtNum(n) {
            if (n === null || n === undefined) return '—';
            return (Math.round(n * 10) / 10).toString();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    window.BlomstraIndexFrontendRescan = boot;
})();
