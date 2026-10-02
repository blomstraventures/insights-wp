/**
 * Sovereign Economic Resilience Index (SERI) — v5.4.0
 *
 * NOTE: This snippet depends on the "Shared Utilities" snippet being active
 *       (blomstra-index-utilities.php). Ensure it is loaded BEFORE this snippet.
 *
 * @package Blomstra\Insights\Indices\SERI
 * @since   3.5.5 (as GERI)
 * @version 5.4.0
 *
 * CHANGES (v5.1.0):
 * - Historical backfill (per-year background jobs, status table, admin panel),
 *   same architecture as SIVI. Historical years are scored by
 *   seri_build_composite() itself (new $historical argument) - one scoring path.
 * - DQI per pillar, composite DQI and vintage summary added to every country
 *   (live and historical) via seri_compute_dqi_fields().
 * - Live and historical snapshot rows now share seri_build_snapshot_rows()
 *   (canonical flat shape via blomstra_build_flat_snapshot_row()).
 *
 * CHANGES (v4.2.1):
 * - Renamed to Sovereign Economic Resilience Index (SERI)
 * - Inverted ranking: lower structural score = higher resilience (#1)
 * - Fixed partial rank projection to match ascending full-country list
 * - Admin page and user-facing labels updated
 * - Scenario builds no longer overwrite the live index
 * - JSON preset buttons fixed
 * - Defensive checks around blomstra_pillar_source_summary()
 * - Fiscal source summary fixed to return strings instead of arrays
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── UTILITIES INCLUDE ──────────────────────────────────────────────
// NOTE: In WPCode, load the utilities as a separate snippet.
// DO NOT uncomment require_once unless running in a plugin folder.
// require_once __DIR__ . '/../../shared/blomstra-index-utilities.php';

// ─── CONSTANTS ──────────────────────────────────────────────────────

// METHODOLOGY CHANGE (2026-09): v5.0.0 inverts every indicator's polarity
// so a HIGH composite score now means MORE resilient (previously, high
// meant more at-risk, matching a risk-score convention that contradicted
// this index's own name and its public methodology text). This is a
// Major version bump per the documentation constitution's change-
// management rule — it changes the meaning of every historical score,
// not just the display. See seri_build_composite() for the per-indicator
// detail.
define( 'SERI_VERSION', '5.4.0' );
define( 'SERI_OPTION_KEY', 'seri_composite_index' );
define( 'SERI_CRON_HOOK', 'seri_weekly_refresh' );
define( 'SERI_DAILY_CRON_HOOK', 'seri_daily_cron' );
define( 'SERI_REFRESH_HOOK', 'seri_async_refresh' );
define( 'SERI_MIN_PILLARS_REQUIRED', 3 );

// Pillar data storage keys
define( 'SERI_GOVERNANCE_KEY', 'seri_governance_data' );
define( 'SERI_MACRO_KEY', 'seri_macro_data' );
define( 'SERI_EXTERNAL_KEY', 'seri_external_data' );
define( 'SERI_FISCAL_KEY', 'seri_fiscal_data' );

// Pillar meta storage keys
define( 'SERI_GOVERNANCE_META_KEY', 'seri_governance_meta' );
define( 'SERI_MACRO_META_KEY', 'seri_macro_meta' );
define( 'SERI_EXTERNAL_META_KEY', 'seri_external_meta' );
define( 'SERI_FISCAL_META_KEY', 'seri_fiscal_meta' );

// ─── PILLAR WEIGHT DEFINITIONS ──────────────────────────────────────

function seri_get_pillar_weights() {
    return array(
        'governance' => array(
            'name' => 'Governance',
            'indicators' => array(
                'rule_of_law' => 33.3333,
                'control_of_corruption' => 33.3333,
                'political_stability' => 33.3333,
            ),
            'min_required' => 3,
            'min_weight' => 100,
            // BMS-1.1.0: WGI scores are bounded by construction and
            // extreme values reflect real governance conditions, not
            // reporting artifacts — no winsorization.
            'winsorize' => array(
                'rule_of_law' => 0.0,
                'control_of_corruption' => 0.0,
                'political_stability' => 0.0,
            ),
        ),
        'macro' => array(
            'name' => 'Macro Stability',
            'indicators' => array(
                'gni_growth' => 20,
                'inflation' => 20,
                'unemployment' => 20,
                'gdp_volatility' => 20,
                'inflation_volatility' => 20,
            ),
            'min_required' => 4,
            'min_weight' => 80,
            // BMS-1.1.0: inflation kept at its existing 1% (hyperinflation
            // countries are real, but 1% has always balanced this well).
            // gni_growth gets a light 1% for small-economy base-effect
            // spikes (e.g. one large one-off project posting as +40%
            // growth). The two volatility measures are DERIVED from
            // short/incomplete time series for some countries — that's
            // an artifact of data availability, not real volatility, so
            // they get 1% too. Unemployment reporting is inconsistent
            // enough across countries (informal-sector measurement gaps)
            // to warrant a light 1% as well.
            'winsorize' => array(
                'gni_growth' => 0.01,
                'inflation' => 0.01,
                'unemployment' => 0.01,
                'gdp_volatility' => 0.01,
                'inflation_volatility' => 0.01,
            ),
        ),
        'external' => array(
            'name' => 'External Vulnerability',
            'indicators' => array(
                'reserve_months' => 30,
                'external_debt' => 30,
                'current_account' => 30,
                'gni_gdp_divergence' => 10,
            ),
            'min_required' => 3,
            'min_weight' => 60,
            // BMS-1.1.0: reserve_months genuinely varies hugely and
            // meaningfully (crisis countries vs. resource-rich ones) —
            // winsorizing would suppress exactly the signal this pillar
            // exists to capture, so it's left alone. external_debt and
            // current_account (both % of GDP) can show artifact-driven
            // extremes for small offshore financial centers with
            // disproportionate cross-border flows — light 1%.
            // gni_gdp_divergence is a computed divergence measure prone
            // to noise in small economies — 1%.
            'winsorize' => array(
                'reserve_months' => 0.0,
                'external_debt' => 0.01,
                'current_account' => 0.01,
                'gni_gdp_divergence' => 0.01,
            ),
        ),
        'fiscal' => array(
            'name' => 'Fiscal Stress',
            'indicators' => array(
                'gov_debt' => 35,
                'gov_balance' => 35,
                'debt_trajectory' => 30,
            ),
            'min_required' => 2,
            'min_weight' => 70,
            // BMS-1.1.0: gov_debt and gov_balance extremes (Japan, Sudan,
            // Venezuela) are real fiscal conditions, not artifacts —
            // no winsorization, same reasoning as reserve_months above.
            // debt_trajectory is a computed trend measure that can be
            // noisy for countries with short debt-history data — 1%.
            'winsorize' => array(
                'gov_debt' => 0.0,
                'gov_balance' => 0.0,
                'debt_trajectory' => 0.01,
            ),
        ),
    );
}

// ─── INDICATOR DEFINITIONS ──────────────────────────────────────────

function seri_get_pillar_defs() {
    return array(
        'governance' => array(
            'name' => 'Governance',
            'indicators' => array(
                'GOV_WGI_RL.SC' => array( 'name' => 'rule_of_law', 'source' => 3 ),
                'GOV_WGI_CC.SC' => array( 'name' => 'control_of_corruption', 'source' => 3 ),
                'GOV_WGI_PV.SC' => array( 'name' => 'political_stability', 'source' => 3 ),
            ),
            'min_required' => 3,
            'min_weight' => 100,
        ),
        'macro' => array(
            'name' => 'Macro Stability',
            'indicators' => array(
                'NY.GNP.MKTP.KD.ZG' => array( 'name' => 'gni_growth', 'source' => null ),
                'FP.CPI.TOTL.ZG'    => array( 'name' => 'inflation', 'source' => null ),
                'SL.UEM.TOTL.ZS'    => array( 'name' => 'unemployment', 'source' => null ),
            ),
            'min_required' => 4,
            'min_weight' => 80,
        ),
        'external' => array(
            'name' => 'External Vulnerability',
            'indicators' => array(
                'FI.RES.TOTL.MO'    => array( 'name' => 'reserve_months', 'source' => null ),
                'DT.DOD.DECT.GN.ZS' => array( 'name' => 'external_debt', 'source' => null ),
                'BN.CAB.XOKA.GD.ZS' => array( 'name' => 'current_account', 'source' => null ),
            ),
            'min_required' => 3,
            'min_weight' => 60,
        ),
        'fiscal' => array(
            'name' => 'Fiscal Stress',
            'indicators' => array(
                'GC.DOD.TOTL.GD.ZS' => array( 'name' => 'gov_debt', 'source' => null ),
                'GC.NLD.TOTL.GD.ZS' => array( 'name' => 'gov_balance', 'source' => null ),
            ),
            'min_required' => 2,
            'min_weight' => 70,
        ),
    );
}

function seri_get_imf_forecast_defs() {
    return array(
        'NGDP_RPCH'   => 'gdp_growth_forecast',
        'PCPIPCH'     => 'inflation_forecast',
        'BCA_NGDPD'   => 'current_account_forecast',
        'GGXWDG_NGDP' => 'gov_debt_forecast',
        'GGXCNL_NGDP' => 'gov_balance_forecast',
        'LUR'         => 'unemployment_forecast',
    );
}

// ─── COMPOSITE WEIGHTS ─────────────────────────────────────────────

function seri_get_composite_weights() {
    return array(
        'governance' => 25,
        'macro'      => 25,
        'external'   => 25,
        'fiscal'     => 25,
    );
}

// ─── DATA FETCH HELPERS ────────────────────────────────────────────

function seri_fetch_wb_indicator( $code, $source = null, $force = false, $direct_api = false, $date_params = null ) {
    if ( function_exists( 'blomstra_fetch_wb_indicator_batch' ) && ! $direct_api ) {
        if ( $date_params ) {
            return seri_direct_wb_fetch( $code, $source, $date_params );
        }
        $data = blomstra_fetch_wb_indicator_batch( $code, $source, $force );
        if ( ! empty( $data ) ) {
            return $data;
        }
    }
    return seri_direct_wb_fetch( $code, $source, $date_params );
}

function seri_direct_wb_fetch( $code, $source = null, $date_params = null ) {
    $url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000";
    if ( $source ) {
        $url .= "&source={$source}";
    }
    if ( $date_params && isset( $date_params['start_year'] ) && isset( $date_params['end_year'] ) ) {
        $url .= "&date={$date_params['start_year']}:{$date_params['end_year']}";
    } else {
        $url .= '&mrnev=1';
    }
    $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'SERI-Direct/' . SERI_VERSION ) );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return array();
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
        return array();
    }
    $out = array();
    foreach ( $body[1] as $row ) {
        $iso3 = $row['countryiso3code'] ?? null;
        if ( ! $iso3 || strlen( $iso3 ) !== 3 ) {
            continue;
        }
        if ( preg_match( '/^[A-Z]{3}$/', $iso3 ) && $iso3 !== 'WLD' ) {
            $val = blomstra_safe_numeric( $row['value'] ?? null );
            $year = $row['date'] ?? null;
            if ( $val !== null ) {
                if ( $date_params && isset( $date_params['start_year'] ) ) {
                    if ( ! isset( $out[ $iso3 ] ) ) {
                        $out[ $iso3 ] = array();
                    }
                    $out[ $iso3 ][ $year ] = $val;
                } else {
                    $out[ $iso3 ] = array( 'value' => $val, 'year' => $year, 'source' => 'Direct API (SERI fallback)' );
                }
            }
        }
    }
    return $out;
}

// ─── IMF DATA FETCH ─────────────────────────────────────────────────

function seri_fetch_imf_indicator( $code, $direct_api = false ) {
    if ( function_exists( 'blomstra_fetch_imf_indicator_batch' ) && ! $direct_api ) {
        $data = blomstra_fetch_imf_indicator_batch( $code, false );
        if ( ! empty( $data ) ) {
            return $data;
        }
    }
    return seri_direct_imf_fetch_historical( $code );
}

function seri_direct_imf_fetch_historical( $code ) {
    $url = "https://www.imf.org/external/datamapper/api/v1/{$code}";
    $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'SERI-Direct/' . SERI_VERSION ) );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return array();
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! isset( $body['values'][ $code ] ) || ! is_array( $body['values'][ $code ] ) ) {
        return array();
    }
    $out = array();
    foreach ( $body['values'][ $code ] as $iso3 => $years ) {
        if ( ! is_array( $years ) || empty( $years ) ) {
            continue;
        }
        $available_years = array_keys( $years );
        rsort( $available_years );
        $latest_year = $available_years[0] ?? null;
        $val = blomstra_safe_numeric( $years[ $latest_year ] ?? null );
        if ( $latest_year && $val !== null ) {
            $out[ $iso3 ] = array(
                'value' => $val,
                'year' => (string) $latest_year,
                'source' => 'IMF WEO (historical estimate)',
            );
        }
    }
    return $out;
}

function seri_fetch_imf_forecast( $code, $horizon = 1, $force = false, $direct_api = false ) {
    if ( function_exists( 'blomstra_fetch_imf_forecast_batch' ) && ! $direct_api ) {
        $data = blomstra_fetch_imf_forecast_batch( $code, $horizon, $force );
        if ( ! empty( $data ) ) {
            return $data;
        }
    }
    return seri_direct_imf_fetch( $code, $horizon );
}

function seri_direct_imf_fetch( $code, $horizon = 1 ) {
    $url = "https://www.imf.org/external/datamapper/api/v1/{$code}";
    $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => 'SERI-Direct/' . SERI_VERSION ) );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return array();
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! isset( $body['values'][ $code ] ) || ! is_array( $body['values'][ $code ] ) ) {
        return array();
    }
    $current_year = (int) current_time( 'Y' );
    $target_year = $current_year + $horizon;
    $out = array();
    foreach ( $body['values'][ $code ] as $iso3 => $years ) {
        if ( is_array( $years ) ) {
            $val = blomstra_safe_numeric( $years[ $target_year ] ?? null );
            if ( $val !== null ) {
                $out[ $iso3 ] = array(
                    'value' => $val,
                    'year' => (string) $target_year,
                    'source' => 'Direct API (SERI fallback)',
                );
            }
        }
    }
    return $out;
}

// ─── VOLATILITY & HISTORY HELPERS ──────────────────────────────────

function seri_fetch_history_5yr( $code, $source = null, $direct_api = false ) {
    $current_year = (int) current_time( 'Y' );
    $start_year = $current_year - 5;
    $end_year = $current_year;
    $data = seri_fetch_wb_indicator( $code, $source, false, $direct_api, array( 'start_year' => $start_year, 'end_year' => $end_year ) );
    $out = array();
    foreach ( $data as $iso3 => $years ) {
        if ( is_array( $years ) ) {
            ksort( $years, SORT_NUMERIC );
            $out[ $iso3 ] = $years;
        }
    }
    return $out;
}

// ─── PILLAR FETCH FUNCTIONS ────────────────────────────────────────

function seri_fetch_governance( $force = false, $direct_api = false ) {
    $raw = array();
    $sources = array();
    $concepts = array(
        'rule_of_law'           => 'GOV_WGI_RL.SC',
        'control_of_corruption' => 'GOV_WGI_CC.SC',
        'political_stability'   => 'GOV_WGI_PV.SC',
    );

    foreach ( $concepts as $name => $code ) {
        $data = seri_fetch_wb_indicator( $code, 3, $force, $direct_api );
        if ( ! empty( $data ) && is_array( $data ) ) {
            foreach ( $data as $iso3 => $row ) {
                if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
                $val = blomstra_safe_numeric( $row['value'] ?? null );
                $raw[ $iso3 ][ $name ] = $val;
                $raw[ $iso3 ][ $name . '_year' ] = $row['year'] ?? null;
                $raw[ $iso3 ][ $name . '_source' ] = $row['source'] ?? 'Reference Data';
                blomstra_track_source( $sources, $iso3, $name, 'WGI', 'composite', $row['year'] ?? null );
            }
        }
    }

    update_option( SERI_GOVERNANCE_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    update_option( SERI_GOVERNANCE_KEY, array( 'data' => $raw, 'sources' => $sources ), false );
    return $raw;
}

function seri_fetch_macro( $force = false, $direct_api = false ) {
    $raw = array();
    $sources = array();

    // 1. GNI growth – primary: aggregate GNI growth. Fallback: GNI per-capita growth (documented).
    $gni_data = seri_fetch_wb_indicator( 'NY.GNP.MKTP.KD.ZG', null, $force, $direct_api );
    $gni_per_capita_data = seri_fetch_wb_indicator( 'NY.GNP.PCAP.KD.ZG', null, $force, $direct_api );

    if ( ! empty( $gni_data ) && is_array( $gni_data ) ) {
        foreach ( $gni_data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $val = blomstra_safe_numeric( $row['value'] ?? null );
            $raw[ $iso3 ]['gni_growth'] = $val;
            $raw[ $iso3 ]['gni_growth_year'] = $row['year'] ?? null;
            $raw[ $iso3 ]['gni_growth_source'] = $row['source'] ?? 'WB_WDI';
            blomstra_track_source( $sources, $iso3, 'gni_growth', 'WB_WDI', 'national', $row['year'] ?? null );
        }
    }

    // Fallback to per-capita where aggregate is missing
    if ( ! empty( $gni_per_capita_data ) && is_array( $gni_per_capita_data ) ) {
        foreach ( $gni_per_capita_data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            if ( ! isset( $raw[ $iso3 ]['gni_growth'] ) || ! is_numeric( $raw[ $iso3 ]['gni_growth'] ) ) {
                $val = blomstra_safe_numeric( $row['value'] ?? null );
                if ( $val !== null ) {
                    $raw[ $iso3 ]['gni_growth'] = $val;
                    $raw[ $iso3 ]['gni_growth_year'] = $row['year'] ?? null;
                    $raw[ $iso3 ]['gni_growth_source'] = $row['source'] ?? 'WB_WDI_per_capita (fallback)';
                    blomstra_track_source( $sources, $iso3, 'gni_growth', 'WB_WDI_per_capita', 'national', $row['year'] ?? null );
                }
            }
        }
    }

    // 2. Inflation
    $inf_data = seri_fetch_wb_indicator( 'FP.CPI.TOTL.ZG', null, $force, $direct_api );
    if ( ! empty( $inf_data ) && is_array( $inf_data ) ) {
        foreach ( $inf_data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $val = blomstra_safe_numeric( $row['value'] ?? null );
            $raw[ $iso3 ]['inflation'] = $val;
            $raw[ $iso3 ]['inflation_year'] = $row['year'] ?? null;
            $raw[ $iso3 ]['inflation_source'] = $row['source'] ?? 'WB_WDI';
            blomstra_track_source( $sources, $iso3, 'inflation', 'WB_WDI', 'national', $row['year'] ?? null );
        }
    }

    // 3. Unemployment
    $unem_data = seri_fetch_wb_indicator( 'SL.UEM.TOTL.ZS', null, $force, $direct_api );
    if ( ! empty( $unem_data ) && is_array( $unem_data ) ) {
        foreach ( $unem_data as $iso3 => $row ) {
            if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
            $val = blomstra_safe_numeric( $row['value'] ?? null );
            $raw[ $iso3 ]['unemployment'] = $val;
            $raw[ $iso3 ]['unemployment_year'] = $row['year'] ?? null;
            $raw[ $iso3 ]['unemployment_source'] = $row['source'] ?? 'WB_WDI';
            blomstra_track_source( $sources, $iso3, 'unemployment', 'WB_WDI', 'national', $row['year'] ?? null );
        }
    }

    // 4. GDP growth (for divergence only, not a fallback for GNI)
    $gdp_data = seri_fetch_wb_indicator( 'NY.GDP.MKTP.KD.ZG', null, $force, $direct_api );
    foreach ( $gdp_data as $iso3 => $row ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $val = blomstra_safe_numeric( $row['value'] ?? null );
        $raw[ $iso3 ]['gdp_growth'] = $val;
        $raw[ $iso3 ]['gdp_growth_year'] = $row['year'] ?? null;
        $raw[ $iso3 ]['gdp_growth_source'] = $row['source'] ?? 'WB_WDI';
        blomstra_track_source( $sources, $iso3, 'gdp_growth', 'WB_WDI', 'national', $row['year'] ?? null );
    }

    // 5. GDP volatility (derived)
    $gdp_history = seri_fetch_history_5yr( 'NY.GDP.MKTP.KD.ZG', null, $direct_api );
    foreach ( $gdp_history as $iso3 => $values ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $vals = array_values( $values );
        if ( count( $vals ) >= 4 ) {
            $raw[ $iso3 ]['gdp_volatility'] = blomstra_compute_stddev( $vals, true );
            $raw[ $iso3 ]['gdp_volatility_window'] = '5 years';
            $raw[ $iso3 ]['gdp_volatility_observations'] = count( $vals );
            $raw[ $iso3 ]['gdp_volatility_years'] = implode( ',', array_keys( $values ) );
            blomstra_track_source( $sources, $iso3, 'gdp_volatility', 'WB_WDI_derived', 'national' );
        } else {
            $raw[ $iso3 ]['gdp_volatility'] = null;
        }
    }

    // 6. Inflation volatility (derived)
    $inf_history = seri_fetch_history_5yr( 'FP.CPI.TOTL.ZG', null, $direct_api );
    foreach ( $inf_history as $iso3 => $values ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $vals = array_values( $values );
        if ( count( $vals ) >= 4 ) {
            $raw[ $iso3 ]['inflation_volatility'] = blomstra_compute_stddev( $vals, true );
            $raw[ $iso3 ]['inflation_volatility_window'] = '5 years';
            $raw[ $iso3 ]['inflation_volatility_observations'] = count( $vals );
            $raw[ $iso3 ]['inflation_volatility_years'] = implode( ',', array_keys( $values ) );
            blomstra_track_source( $sources, $iso3, 'inflation_volatility', 'WB_WDI_derived', 'national' );
        } else {
            $raw[ $iso3 ]['inflation_volatility'] = null;
        }
    }

    update_option( SERI_MACRO_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    update_option( SERI_MACRO_KEY, array( 'data' => $raw, 'sources' => $sources ), false );
    return $raw;
}

function seri_fetch_external( $force = false, $direct_api = false ) {
    $raw = array();
    $sources = array();

    // External indicators: reserves, external debt, current account
    $indicators = array(
        'FI.RES.TOTL.MO'    => 'reserve_months',
        'DT.DOD.DECT.GN.ZS' => 'external_debt',
        'BN.CAB.XOKA.GD.ZS' => 'current_account',
    );

    foreach ( $indicators as $code => $name ) {
        $data = seri_fetch_wb_indicator( $code, null, $force, $direct_api );
        if ( ! empty( $data ) && is_array( $data ) ) {
            foreach ( $data as $iso3 => $row ) {
                if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
                $val = blomstra_safe_numeric( $row['value'] ?? null );
                $raw[ $iso3 ][ $name ] = $val;
                $raw[ $iso3 ][ $name . '_year' ] = $row['year'] ?? null;
                $raw[ $iso3 ][ $name . '_source' ] = $row['source'] ?? 'WB_WDI';
                blomstra_track_source( $sources, $iso3, $name, 'WB_WDI', 'national', $row['year'] ?? null );
            }
        }
    }

    // NOTE: Removed the fallback to DT.DOD.DECT.CD.ZG (growth rate) because it measures
    // a different concept (annual % growth) than DT.DOD.DECT.GN.ZS (debt % GNI).
    // Missing data is now correctly treated as missing.

    update_option( SERI_EXTERNAL_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    update_option( SERI_EXTERNAL_KEY, array( 'data' => $raw, 'sources' => $sources ), false );
    return $raw;
}

function seri_fetch_fiscal( $force = false, $direct_api = false ) {
    $raw = array();
    $sources = array();

    // 1. PRIMARY: IMF WEO (general government debt)
    $imf_debt = seri_fetch_imf_indicator( 'GGXWDG_NGDP', $direct_api );
    $imf_balance = seri_fetch_imf_indicator( 'GGXCNL_NGDP', $direct_api );

    // 2. FALLBACK: World Bank (central government debt)
    $wb_debt = seri_fetch_wb_indicator( 'GC.DOD.TOTL.GD.ZS', null, $force, $direct_api );
    $wb_balance = seri_fetch_wb_indicator( 'GC.NLD.TOTL.GD.ZS', null, $force, $direct_api );

    // 3. Extract numeric values and years from the returned data
    $imf_debt_vals = array();
    $imf_debt_years = array();
    foreach ( $imf_debt as $iso3 => $row ) {
        $val = blomstra_safe_numeric( $row['value'] ?? null );
        if ( $val !== null ) {
            $imf_debt_vals[ $iso3 ] = $val;
            $imf_debt_years[ $iso3 ] = $row['year'] ?? null;
        }
    }

    $wb_debt_vals = array();
    $wb_debt_years = array();
    foreach ( $wb_debt as $iso3 => $row ) {
        $val = blomstra_safe_numeric( $row['value'] ?? null );
        if ( $val !== null ) {
            $wb_debt_vals[ $iso3 ] = $val;
            $wb_debt_years[ $iso3 ] = $row['year'] ?? null;
        }
    }

    $imf_balance_vals = array();
    $imf_balance_years = array();
    foreach ( $imf_balance as $iso3 => $row ) {
        $val = blomstra_safe_numeric( $row['value'] ?? null );
        if ( $val !== null ) {
            $imf_balance_vals[ $iso3 ] = $val;
            $imf_balance_years[ $iso3 ] = $row['year'] ?? null;
        }
    }

    $wb_balance_vals = array();
    $wb_balance_years = array();
    foreach ( $wb_balance as $iso3 => $row ) {
        $val = blomstra_safe_numeric( $row['value'] ?? null );
        if ( $val !== null ) {
            $wb_balance_vals[ $iso3 ] = $val;
            $wb_balance_years[ $iso3 ] = $row['year'] ?? null;
        }
    }

    // 4. Merge debt (IMF primary, WB fallback) using shared utility
    $merged_debt = blomstra_merge_with_fallback(
        $imf_debt_vals, $wb_debt_vals, $sources, 'gov_debt',
        'IMF_WEO', 'WB_WDI',
        'general_gov', 'central_gov'
    );

    // 5. Merge balance (IMF primary, WB fallback)
    $merged_balance = blomstra_merge_with_fallback(
        $imf_balance_vals, $wb_balance_vals, $sources, 'gov_balance',
        'IMF_WEO', 'WB_WDI',
        'general_gov', 'central_gov'
    );

    // 6. Populate raw data, restoring years from the source arrays
    foreach ( $merged_debt as $iso3 => $val ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $raw[ $iso3 ]['gov_debt'] = $val;
        $raw[ $iso3 ]['gov_debt_year'] = $imf_debt_years[ $iso3 ] ?? $wb_debt_years[ $iso3 ] ?? null;
    }

    foreach ( $merged_balance as $iso3 => $val ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $raw[ $iso3 ]['gov_balance'] = $val;
        $raw[ $iso3 ]['gov_balance_year'] = $imf_balance_years[ $iso3 ] ?? $wb_balance_years[ $iso3 ] ?? null;
    }

    // 7. Debt trajectory — CAGR from WB history (derived)
    $debt_hist = seri_fetch_history_5yr( 'GC.DOD.TOTL.GD.ZS', null, $direct_api );
    foreach ( $debt_hist as $iso3 => $years ) {
        if ( ! isset( $raw[ $iso3 ] ) ) $raw[ $iso3 ] = array();
        $ts = blomstra_sanitize_timeseries( $years, 4, 2 );
        if ( ! empty( $ts ) ) {
            $cagr = blomstra_compute_cagr( $ts );
            if ( $cagr !== null ) {
                $year_keys = array_keys( $ts );
                $raw[ $iso3 ]['debt_trajectory'] = $cagr;
                $raw[ $iso3 ]['debt_trajectory_oldest_year'] = $year_keys[0];
                $raw[ $iso3 ]['debt_trajectory_newest_year'] = $year_keys[ count( $year_keys ) - 1 ];
                $raw[ $iso3 ]['debt_trajectory_span'] = end( $year_keys ) - $year_keys[0];
                $raw[ $iso3 ]['debt_trajectory_observations'] = count( $year_keys );
                $raw[ $iso3 ]['debt_trajectory_quality'] = count( $year_keys ) >= 4 ? 'good' : 'limited';
                blomstra_track_source( $sources, $iso3, 'debt_trajectory', 'WB_WDI_derived', 'central_gov' );
            } else {
                $raw[ $iso3 ]['debt_trajectory'] = null;
                $raw[ $iso3 ]['debt_trajectory_quality'] = 'invalid';
            }
        } else {
            $raw[ $iso3 ]['debt_trajectory'] = null;
            $raw[ $iso3 ]['debt_trajectory_quality'] = 'insufficient_data';
        }
    }

    // Store sources and data
    $fiscal_store = array( 'data' => $raw, 'sources' => $sources );
    update_option( SERI_FISCAL_META_KEY, array( 'last_fetched' => current_time( 'mysql' ) ), false );
    update_option( SERI_FISCAL_KEY, $fiscal_store, false );
    return $raw;
}

// ─── SCENARIO STORAGE ─────────────────────────────────────────────

function seri_store_scenario( $output, $scenario_id ) {
    $key = SERI_OPTION_KEY . '_scenario_' . sanitize_key( $scenario_id );
    update_option( $key, $output, false );
}

function seri_list_scenarios() {
    global $wpdb;
    $results = array();
    $rows = $wpdb->get_results( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'seri_composite_index_scenario_%'" );
    foreach ( $rows as $row ) {
        $id = str_replace( 'seri_composite_index_scenario_', '', $row->option_name );
        $data = get_option( $row->option_name );
        if ( $data ) {
            $results[ $id ] = $data;
        }
    }
    return $results;
}

function seri_delete_scenario( $scenario_id ) {
    delete_option( SERI_OPTION_KEY . '_scenario_' . sanitize_key( $scenario_id ) );
}

// ─── SPEARMAN CORRELATION ─────────────────────────────────────────



// ─── COMPOSITE BUILDER ─────────────────────────────────────────────

function seri_build_composite( $force = false, $context = 'manual', $custom_weights = null, $custom_composite_weights = null, $historical = null ) {
    // Detect if this is a scenario build
    $is_scenario = ( $custom_weights !== null || $custom_composite_weights !== null );
    // Historical mode (v5.1.0): rows/sources for a past year are supplied by
    // the caller (seri_build_historical_snapshot). Scoring below is the SAME
    // code path as the live build; historical mode only swaps the input and
    // stops before forward pressure, persistence and alerts.
    $is_historical = is_array( $historical );

    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 120 );
    }

    // Load data and sources for each pillar
    $gov_store = get_option( SERI_GOVERNANCE_KEY, array() );
    $macro_store = get_option( SERI_MACRO_KEY, array() );
    $ext_store = get_option( SERI_EXTERNAL_KEY, array() );
    $fisc_store = get_option( SERI_FISCAL_KEY, array() );

    $gov_data = $gov_store['data'] ?? array();
    $macro_data = $macro_store['data'] ?? array();
    $ext_data = $ext_store['data'] ?? array();
    $fisc_data = $fisc_store['data'] ?? array();

    $gov_sources = $gov_store['sources'] ?? array();
    $macro_sources = $macro_store['sources'] ?? array();
    $ext_sources = $ext_store['sources'] ?? array();
    $fisc_sources = $fisc_store['sources'] ?? array();

    // Merge all sources for quality scoring
    $all_sources = array_merge_recursive( $gov_sources, $macro_sources, $ext_sources, $fisc_sources );

    $countries = function_exists( 'blomstra_get_global_country_list' )
        ? blomstra_get_global_country_list()
        : array();
    if ( empty( $countries ) ) {
        return array( 'error' => 'No country list available' );
    }
    $all_iso3 = array_keys( $countries );

    $rows = array();
    foreach ( $all_iso3 as $iso3 ) {
        $rows[ $iso3 ] = array_merge(
            $gov_data[ $iso3 ] ?? array(),
            $macro_data[ $iso3 ] ?? array(),
            $ext_data[ $iso3 ] ?? array(),
            $fisc_data[ $iso3 ] ?? array()
        );
        // NO GDP→GNI fallback. GNI stands alone.
    }

    if ( $is_historical ) {
        $rows         = $historical['rows'];
        $all_sources  = $historical['sources'];
        $fisc_sources = $historical['sources'];
    }

    // ─── GNI‑GDP DIVERGENCE ──────────────────────────────────────
    foreach ( $rows as $iso3 => &$row ) {
        $gni = isset( $row['gni_growth'] ) && is_numeric( $row['gni_growth'] ) ? (float) $row['gni_growth'] : null;
        $gdp = isset( $row['gdp_growth'] ) && is_numeric( $row['gdp_growth'] ) ? (float) $row['gdp_growth'] : null;
        if ( $gni !== null && $gdp !== null ) {
            $row['gni_gdp_divergence'] = $gdp - $gni;
            blomstra_track_source( $all_sources, $iso3, 'gni_gdp_divergence', 'WB_WDI_derived', 'national' );
        }
    }
    unset( $row );

    // Use custom weights if provided, else fallback to default
    $weight_defs = $custom_weights ?? seri_get_pillar_weights();
    $composite_weights = $custom_composite_weights ?? seri_get_composite_weights();

    // Ensure composite weights are valid
    $all_pillars = array( 'governance', 'macro', 'external', 'fiscal' );
    foreach ( $all_pillars as $p ) {
        if ( ! isset( $composite_weights[ $p ] ) || ! is_numeric( $composite_weights[ $p ] ) ) {
            $composite_weights[ $p ] = 25;
        }
    }

    $percentiles = array();

    // Governance
    // METHODOLOGY CHANGE (2026-09, BMS-1.2.0): governance indicators were
    // previously inverted (100 - raw) so that a HIGH percentile meant BAD
    // governance, matching a risk-score convention. SERI is a resilience
    // index — a high composite score should mean MORE resilient, not more
    // at-risk. Raw WGI values are already high=good, so used directly now.
    $gov_indicators = array_keys( $weight_defs['governance']['indicators'] );
    foreach ( $gov_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                $values[ $iso3 ] = $row[ $ind ];
            }
        }
        // BMS-1.1.0: winsor level now read from config (§2.8), not hardcoded.
        $winsor = $weight_defs['governance']['winsorize'][ $ind ] ?? 0.0;
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks_safe( $values, $winsor ) : array();
    }

    // Macro
    // METHODOLOGY CHANGE (2026-09, BMS-1.2.0): every indicator's polarity
    // flipped so a HIGH percentile now means more resilient/stable, not
    // more at-risk — see the governance pillar comment above for why.
    $macro_indicators = array_keys( $weight_defs['macro']['indicators'] );
    foreach ( $macro_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                if ( $ind === 'gni_growth' ) {
                    $values[ $iso3 ] = $row[ $ind ];
                } else {
                    $values[ $iso3 ] = - $row[ $ind ];
                }
            }
        }
        // BMS-1.1.0: winsor level now read from config (§2.8), not hardcoded.
        $winsor = $weight_defs['macro']['winsorize'][ $ind ] ?? 0.0;
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks_safe( $values, $winsor ) : array();
    }

    // External
    // METHODOLOGY CHANGE (2026-09, BMS-1.2.0): polarity flipped, see the
    // governance pillar comment above.
    $ext_indicators = array_keys( $weight_defs['external']['indicators'] );
    foreach ( $ext_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                if ( $ind === 'reserve_months' || $ind === 'current_account' ) {
                    $values[ $iso3 ] = $row[ $ind ];
                } elseif ( $ind === 'external_debt' ) {
                    $values[ $iso3 ] = - $row[ $ind ];
                } elseif ( $ind === 'gni_gdp_divergence' ) {
                    $values[ $iso3 ] = - $row[ $ind ];
                }
            }
        }
        // BMS-1.1.0: winsor level now read from config (§2.8), not hardcoded.
        $winsor = $weight_defs['external']['winsorize'][ $ind ] ?? 0.0;
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks_safe( $values, $winsor ) : array();
    }

    // Fiscal
    // METHODOLOGY CHANGE (2026-09, BMS-1.2.0): polarity flipped, see the
    // governance pillar comment above.
    $fisc_indicators = array_keys( $weight_defs['fiscal']['indicators'] );
    foreach ( $fisc_indicators as $ind ) {
        $values = array();
        foreach ( $rows as $iso3 => $row ) {
            if ( isset( $row[ $ind ] ) && is_numeric( $row[ $ind ] ) ) {
                if ( $ind === 'gov_balance' ) {
                    $values[ $iso3 ] = $row[ $ind ];
                } else {
                    $values[ $iso3 ] = - $row[ $ind ];
                }
            }
        }
        // BMS-1.1.0: winsor level now read from config (§2.8), not hardcoded.
        $winsor = $weight_defs['fiscal']['winsorize'][ $ind ] ?? 0.0;
        $percentiles[ $ind ] = ! empty( $values ) ? blomstra_compute_percentile_ranks_safe( $values, $winsor ) : array();
    }

    // 5. Compute pillar scores
    $pillar_scores = array();
    $excluded = array();

    foreach ( $rows as $iso3 => $row ) {
        $pillars = array();

        // Governance
        $gov_weights = $weight_defs['governance']['indicators'];
        $gov_weighted_sum = 0;
        $gov_weight_total = 0;
        $gov_count = 0;
        foreach ( $gov_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $gov_weighted_sum += $percentiles[ $ind ][ $iso3 ] * $w;
                $gov_weight_total += $w;
                $gov_count++;
            }
        }
        if ( $gov_count >= 3 ) {
            $pillars['governance'] = $gov_weighted_sum / $gov_weight_total;
        } else {
            $pillars['governance'] = null;
        }

        // Macro
        $macro_weights = $weight_defs['macro']['indicators'];
        $macro_weighted_sum = 0;
        $macro_weight_total = 0;
        $macro_count = 0;
        foreach ( $macro_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $macro_weighted_sum += $percentiles[ $ind ][ $iso3 ] * $w;
                $macro_weight_total += $w;
                $macro_count++;
            }
        }
        if ( $macro_count >= 4 && $macro_weight_total >= 80 ) {
            $pillars['macro'] = $macro_weighted_sum / $macro_weight_total;
        } else {
            $pillars['macro'] = null;
        }

        // External
        $ext_weights = $weight_defs['external']['indicators'];
        $ext_weighted_sum = 0;
        $ext_weight_total = 0;
        $ext_count = 0;
        foreach ( $ext_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $ext_weighted_sum += $percentiles[ $ind ][ $iso3 ] * $w;
                $ext_weight_total += $w;
                $ext_count++;
            }
        }
        if ( $ext_count >= 3 && $ext_weight_total >= 60 ) {
            $pillars['external'] = $ext_weighted_sum / $ext_weight_total;
        } else {
            $pillars['external'] = null;
        }

        // Fiscal
        $fisc_weights = $weight_defs['fiscal']['indicators'];
        $fisc_weighted_sum = 0;
        $fisc_weight_total = 0;
        $fisc_count = 0;
        foreach ( $fisc_weights as $ind => $w ) {
            if ( isset( $percentiles[ $ind ][ $iso3 ] ) ) {
                $fisc_weighted_sum += $percentiles[ $ind ][ $iso3 ] * $w;
                $fisc_weight_total += $w;
                $fisc_count++;
            }
        }
        if ( $fisc_count >= 2 && $fisc_weight_total >= 70 ) {
            $pillars['fiscal'] = $fisc_weighted_sum / $fisc_weight_total;
        } else {
            $pillars['fiscal'] = null;
        }

        $valid_pillars = array_filter( $pillars, function( $v ) { return $v !== null; } );
        $coverage = count( $valid_pillars );

        if ( $coverage < SERI_MIN_PILLARS_REQUIRED ) {
            $missing_names = implode( ', ', array_keys( array_filter( $pillars, function( $v ) { return $v === null; } ) ) );
            $excluded[ $iso3 ] = 'Insufficient pillar coverage: ' . $coverage . '/4 pillars available (missing: ' . $missing_names . ').';
            continue;
        }

        $pillar_scores[ $iso3 ] = $pillars;
        $pillar_scores[ $iso3 ]['_coverage'] = $coverage;
    }

    // 6. Compute composite with custom weights
    $country_output = array();
    $structural_scores = array();

    $global_pillar_values = array();
    foreach ( $all_pillars as $p ) {
        $values = array();
        foreach ( $pillar_scores as $iso3 => $pillars ) {
            if ( isset( $pillars[ $p ] ) && is_numeric( $pillars[ $p ] ) ) {
                $values[] = $pillars[ $p ];
            }
        }
        sort( $values );
        $global_pillar_values[ $p ] = $values;
    }

    foreach ( $pillar_scores as $iso3 => $pillars ) {
        $available_pillars = array_filter( $pillars, function( $v ) { return is_numeric( $v ); } );
        unset( $available_pillars['_coverage'] );
        $coverage = count( $available_pillars );
        $coverage_type = ( $coverage == 4 ) ? 'full' : 'partial';

        $composite = null;

        if ( $coverage == 4 ) {
            $weighted_sum = 0;
            $total_weight = 0;
            foreach ( $all_pillars as $p ) {
                $weighted_sum += $available_pillars[ $p ] * $composite_weights[ $p ];
                $total_weight += $composite_weights[ $p ];
            }
            $composite = $weighted_sum / $total_weight;
        } elseif ( $coverage == 3 ) {
            $available_weight = 0;
            foreach ( $available_pillars as $p => $score ) {
                $available_weight += $composite_weights[ $p ];
            }
            $weighted_sum = 0;
            foreach ( $available_pillars as $p => $score ) {
                $weighted_sum += $score * ( $composite_weights[ $p ] / $available_weight );
            }
            $composite = $weighted_sum;
        }

        if ( $composite === null ) {
            $excluded[ $iso3 ] = 'Could not compute composite.';
            continue;
        }

        $structural_scores[ $iso3 ] = $composite;

        // ─── DATA FRESHNESS / PROVENANCE ──────────────────────────
        // BUGFIX (2026-09): previously built per-pillar objects with
        // ad-hoc, pillar-specific key names (gni_year/gni_source for
        // macro, debt_year/balance_year for fiscal, etc.) and no
        // 'available' or 'quality' field at all. The shared frontend's
        // renderProvenance() expects one flat {available, quality,
        // source, year} object per pillar (the same shape
        // blomstra_data_quality_flag() produces for a single indicator).
        // Since 'available' was always undefined, every pillar for
        // every country displayed as "Missing" regardless of real
        // coverage (confirmed live: Sweden, full coverage on every
        // pillar, still showed "Missing" on all four) — and macro/
        // external/fiscal additionally showed "unknown (?)" since their
        // year/source keys never matched what the frontend reads.
        // Rebuilt here as one pillar-level summary: available = this
        // pillar actually has a score; year = the oldest contributing
        // indicator's year (the same measure seri_compute_dqi_fields()
        // uses for DQI); source = the predominant source across that
        // pillar's indicators (blomstra_pillar_source_summary(), already
        // used elsewhere in this function for fiscal_source_summary);
        // quality classified from how stale that year is, with the SAME
        // lag thresholds DQI uses, so provenance and the DQI badge
        // always agree with each other instead of being two unrelated,
        // independently-computed numbers.
        $provenance_ref_year  = $is_historical ? (int) $historical['year'] : (int) current_time( 'Y' );
        $provenance_indicators = array(
            'governance' => array( 'rule_of_law', 'control_of_corruption', 'political_stability' ),
            'macro'      => array( 'gni_growth', 'inflation', 'unemployment', 'gdp_volatility', 'inflation_volatility' ),
            'external'   => array( 'reserve_months', 'external_debt', 'current_account', 'gni_gdp_divergence' ),
            'fiscal'     => array( 'gov_debt', 'gov_balance', 'debt_trajectory' ),
        );
        $provenance_dqi_lags = seri_get_dqi_max_lags();
        $freshness = array();
        foreach ( $provenance_indicators as $p => $p_inds ) {
            $p_available = isset( $pillars[ $p ] ) && $pillars[ $p ] !== null;
            $p_year      = seri_pillar_data_year( $rows[ $iso3 ], $p );
            $p_dqi       = blomstra_compute_dqi( $p_year, $provenance_ref_year, $provenance_dqi_lags[ $p ] ?? 3 );
            $p_quality   = 'good';
            if ( $p_dqi !== null ) {
                if ( $p_dqi < 40 ) {
                    $p_quality = 'stale';
                } elseif ( $p_dqi < 70 ) {
                    $p_quality = 'aged';
                }
            }
            $p_summary = blomstra_pillar_source_summary( $all_sources, $iso3, $p_inds );
            $freshness[ $p ] = array(
                'available'       => $p_available,
                'staleness_years' => ( $p_year !== null ) ? ( $provenance_ref_year - $p_year ) : null,
                'source'          => ( $p_summary && ! empty( $p_summary['primary_source'] ) ) ? $p_summary['primary_source'] : null,
                'scope'           => $p_summary['primary_scope'] ?? null,
                'quality'         => $p_available ? $p_quality : 'missing',
                'year'            => $p_year,
            );
        }

        $missing_pillars_list = array();
        foreach ( array( 'governance', 'macro', 'external', 'fiscal' ) as $p ) {
            if ( ! isset( $pillars[ $p ] ) || $pillars[ $p ] === null ) {
                $missing_pillars_list[] = $p;
            }
        }

        // ─── DATA QUALITY ──────────────────────────────────────────
        $data_quality = array(
            'governance' => blomstra_pillar_quality_score(
                $all_sources, $iso3,
                array( 'rule_of_law', 'control_of_corruption', 'political_stability' )
            ),
            'macro' => blomstra_pillar_quality_score(
                $all_sources, $iso3,
                array( 'gni_growth', 'inflation', 'unemployment', 'gdp_volatility', 'inflation_volatility' )
            ),
            'external' => blomstra_pillar_quality_score(
                $all_sources, $iso3,
                array( 'reserve_months', 'external_debt', 'current_account', 'gni_gdp_divergence' )
            ),
            'fiscal' => blomstra_pillar_quality_score(
                $all_sources, $iso3,
                array( 'gov_debt', 'gov_balance', 'debt_trajectory' )
            ),
        );

        // ─── FISCAL SOURCE SUMMARY ──────────────────────────────
        $fisc_sources_safe = is_array( $fisc_sources ) ? $fisc_sources : array();
        $fiscal_summary = blomstra_pillar_source_summary(
            $fisc_sources_safe, $iso3,
            array( 'gov_debt', 'gov_balance', 'debt_trajectory' )
        );

        if ( $fiscal_summary === null ) {
            $fiscal_summary = array(
                'breakdown' => array(
                    'gov_debt' => array( 'source' => 'unknown' ),
                    'gov_balance' => array( 'source' => 'unknown' ),
                    'debt_trajectory' => array( 'source' => 'unknown' ),
                ),
                'scope_mixed' => false,
            );
        }

        $fiscal_source_summary = array(
            'gov_debt_source'        => $fiscal_summary['breakdown']['gov_debt']['source'] ?? 'unknown',
            'gov_balance_source'     => $fiscal_summary['breakdown']['gov_balance']['source'] ?? 'unknown',
            'debt_trajectory_source' => $fiscal_summary['breakdown']['debt_trajectory']['source'] ?? 'unknown',
            'sources_mixed'          => $fiscal_summary['scope_mixed'] ?? false,
            'has_trajectory'         => isset( $rows[ $iso3 ]['debt_trajectory'] ) && is_numeric( $rows[ $iso3 ]['debt_trajectory'] ),
            'trajectory_quality'     => $rows[ $iso3 ]['debt_trajectory_quality'] ?? null,
        );

        // ─── MEASUREMENT FLAGS ────────────────────────────────────
        $measurement_flags = array(
            'gni_is_gdp_fallback' => false,
            'fiscal_scope_mixed' => $fiscal_source_summary['sources_mixed'],
            'trajectory_quality' => $rows[ $iso3 ]['debt_trajectory_quality'] ?? 'missing',
            'trajectory_observations' => $rows[ $iso3 ]['debt_trajectory_observations'] ?? null,
            'trajectory_span_years' => $rows[ $iso3 ]['debt_trajectory_span'] ?? null,
            'coverage_ratio' => $coverage / 4,
            'is_definitive' => ( $coverage == 4 ),
            'missing_pillars' => $missing_pillars_list,
        );

        $country_output[ $iso3 ] = array(
            'iso3' => $iso3,
            'name' => $countries[ $iso3 ] ?? $iso3,
            'seri_structural' => round( $composite, 2 ),
            'coverage' => $coverage_type,
            'pillars_missing' => $missing_pillars_list,
            'data_freshness' => $freshness,
            'data_quality' => $data_quality,
            'fiscal_source_summary' => $fiscal_source_summary,
            'measurement_flags' => $measurement_flags,
            'governance_percentile' => isset( $pillars['governance'] ) ? round( $pillars['governance'], 2 ) : null,
            'macro_percentile'      => isset( $pillars['macro'] ) ? round( $pillars['macro'], 2 ) : null,
            'external_percentile'   => isset( $pillars['external'] ) ? round( $pillars['external'], 2 ) : null,
            'fiscal_percentile'     => isset( $pillars['fiscal'] ) ? round( $pillars['fiscal'], 2 ) : null,
            'pillars' => array(
                'governance' => array( 'score' => isset( $pillars['governance'] ) ? round( $pillars['governance'], 2 ) : null, 'weight' => $composite_weights['governance'] ?? 25 ),
                'macro'      => array( 'score' => isset( $pillars['macro'] ) ? round( $pillars['macro'], 2 ) : null, 'weight' => $composite_weights['macro'] ?? 25 ),
                'external'   => array( 'score' => isset( $pillars['external'] ) ? round( $pillars['external'], 2 ) : null, 'weight' => $composite_weights['external'] ?? 25 ),
                'fiscal'     => array( 'score' => isset( $pillars['fiscal'] ) ? round( $pillars['fiscal'], 2 ) : null, 'weight' => $composite_weights['fiscal'] ?? 25 ),
            ),
        );
    }

    // DQI / vintage (v5.1.0): same helper for live and historical builds.
    $dqi_ref_year = $is_historical ? (int) $historical['year'] : (int) current_time( 'Y' );
    foreach ( $country_output as $iso3 => &$dq_out ) {
        $dq_out = array_merge( $dq_out, seri_compute_dqi_fields( $rows[ $iso3 ] ?? array(), $dqi_ref_year, $composite_weights ) );
    }
    unset( $dq_out );

    // 7. Ranks (inverted for resilience: lower score = lower rank number)
    if ( function_exists( 'blomstra_build_full_rank_display' ) && function_exists( 'blomstra_build_partial_rank_display' ) ) {
        $full_countries = array();
        $partial_countries = array();
        foreach ( $country_output as $iso3 => $out ) {
            if ( $out['coverage'] === 'full' ) {
                $full_countries[ $iso3 ] = $out['seri_structural'];
            } else {
                $partial_countries[ $iso3 ] = $out['seri_structural'];
            }
        }

        // BUGFIX (2026-09): this comment and the asort() below were correct
        // under SERI's OLD polarity (before the v5.0.0 methodology flip),
        // where a lower score meant more resilient. Since every indicator
        // was inverted so a HIGH score now means more resilient, rank #1
        // must go to the HIGHEST score, matching every other index on this
        // engine. This is the actual root cause of a real, reported bug:
        // partial-coverage countries were showing wildly wrong rank ranges
        // (e.g. a country scoring in full-country-rank-~30 territory
        // showing a range like #103–155) because full-coverage countries
        // were still being sorted and ranked by the pre-flip direction.
        // Sort descending: highest score = most resilient = rank #1
        arsort( $full_countries );
        $full_composites_sorted = array_values( $full_countries );
        $full_rank_map = array();
        $i = 1;
        foreach ( $full_countries as $iso3 => $score ) {
            $full_rank_map[ $iso3 ] = $i;
            $i++;
        }

        // ── Partial rank projection ──────────────────────────────────
        // NOTE: the global-distribution interpolation to estimate the
        // missing pillar's injected value at each point is SERI's own
        // methodology choice, unchanged and untouched here. Only the
        // composite/rank math downstream of it now goes through the
        // shared, weight-aware, N-pillar-generic functions in the
        // Utility layer (BMS-1.1.0 §2.1) instead of an inline copy.
        $partial_rank_data = array();
        foreach ( $partial_countries as $iso3 => $score ) {
            $pillars = $pillar_scores[ $iso3 ];
            $available_pillars = array();
            $missing_pillar = null;
            foreach ( $all_pillars as $p ) {
                if ( isset( $pillars[ $p ] ) && is_numeric( $pillars[ $p ] ) ) {
                    $available_pillars[] = $p;
                } else {
                    $missing_pillar = $p;
                }
            }
            if ( ! $missing_pillar || count( $available_pillars ) < 3 ) {
                continue;
            }

            $global_vals = $global_pillar_values[ $missing_pillar ] ?? array();
            if ( empty( $global_vals ) ) {
                continue;
            }
            $n = count( $global_vals );

            $injected_values_by_point = array();
            foreach ( array( 0, 10, 50, 90, 100 ) as $p ) {
                $rank_idx = ( $p / 100 ) * ( $n - 1 );
                $low = floor( $rank_idx );
                $high = ceil( $rank_idx );
                if ( $low == $high ) {
                    $injected_values_by_point[ $p ] = $global_vals[ $low ] ?? 0;
                } else {
                    $frac = $rank_idx - $low;
                    $injected_values_by_point[ $p ] = $global_vals[ $low ] * ( 1 - $frac ) + $global_vals[ $high ] * $frac;
                }
            }

            $known_pillars = $pillars;
            unset( $known_pillars['_coverage'] );

            $hypothetical_composites = function_exists( 'blomstra_project_partial_rank_composite' )
                ? blomstra_project_partial_rank_composite( $known_pillars, $missing_pillar, $injected_values_by_point, $composite_weights )
                : array();
            if ( empty( $hypothetical_composites ) ) {
                continue;
            }

            $ranks_by_injection = array();
            foreach ( $hypothetical_composites as $point => $hyp_composite ) {
                // BUGFIX (2026-09): this comment and the comparison below
                // were correct under SERI's OLD (pre-v5.0.0) polarity.
                // blomstra_rank_in_full_index() is still hardcoded to
                // SIVI's descending convention, which is now actually the
                // SAME direction SERI needs post-flip — but this function
                // is left as an explicit, self-contained computation
                // rather than switched to call the shared one, to avoid
                // silently depending on another function's convention
                // never changing again. $full_composites_sorted is now
                // sorted descending (see above); rank increments for every
                // full-coverage country that scores HIGHER than this
                // hypothetical value.
                $rank = 1;
                foreach ( $full_composites_sorted as $full_score ) {
                    if ( $full_score > $hyp_composite ) {
                        $rank++;
                    } else {
                        break;
                    }
                }
                $ranks_by_injection[ $point ] = $rank;
            }
            $partial_rank_data[ $iso3 ] = blomstra_build_partial_rank_display( $ranks_by_injection );
        }

        foreach ( $country_output as $iso3 => &$out ) {
            if ( isset( $full_rank_map[ $iso3 ] ) ) {
                $rank = $full_rank_map[ $iso3 ];
                // NOTE: Reference Data's real blomstra_build_full_rank_display()
                // takes only $rank (1 arg) — a 2nd argument is silently
                // ignored by PHP, not an error, but it means 'total' never
                // gets set internally. Setting it externally restores the
                // original API contract.
                $out['rank_display'] = blomstra_build_full_rank_display( $rank );
                $out['rank_display']['total'] = count( $full_countries );
            } elseif ( isset( $partial_rank_data[ $iso3 ] ) ) {
                $out['rank_display'] = $partial_rank_data[ $iso3 ];
                $out['rank_display']['total'] = count( $full_countries );
            } else {
                $out['rank_display'] = null;
            }
        }
        unset( $out );
    }

    if ( $is_historical ) {
        return array(
            'countries' => $country_output,
            'excluded'  => $excluded,
            'weights'   => $composite_weights,
        );
    }

    // 8. Forward Pressure
    $imf_defs = seri_get_imf_forecast_defs();
    $imf_forecast = array();
    foreach ( $imf_defs as $code => $name ) {
        $data = seri_fetch_imf_forecast( $code, 1, false, false );
        $imf_forecast[ $name ] = $data;
    }
    $imf_current = array();
    foreach ( $imf_defs as $code => $name ) {
        if ( function_exists( 'blomstra_fetch_imf_indicator_batch' ) ) {
            $data = blomstra_fetch_imf_indicator_batch( $code, false );
            $imf_current[ $name ] = $data;
        }
    }
    $delta_values = array();
    $current_map = array(
        'gdp_growth_forecast' => 'gdp_growth',
        'inflation_forecast' => 'inflation',
        'current_account_forecast' => 'current_account',
        'gov_debt_forecast' => 'gov_debt',
        'gov_balance_forecast' => 'gov_balance',
        'unemployment_forecast' => 'unemployment'
    );
    foreach ( $imf_forecast as $name => $forecast_data ) {
        $current_name = $current_map[ $name ] ?? str_replace( '_forecast', '', $name );
        foreach ( $forecast_data as $iso3 => $fval ) {
            if ( isset( $imf_current[ $name ][ $iso3 ] ) && is_numeric( $imf_current[ $name ][ $iso3 ]['value'] ) ) {
                $current_val = $imf_current[ $name ][ $iso3 ]['value'];
                $delta = $fval['value'] - $current_val;
                if ( in_array( $current_name, array( 'gdp_growth', 'current_account', 'gov_balance' ) ) ) {
                    $delta = -$delta;
                }
                $delta_values[ $name ][ $iso3 ] = $delta;
            }
        }
    }
    $delta_percentiles = array();
    foreach ( $delta_values as $name => $deltas ) {
        $delta_percentiles[ $name ] = ! empty( $deltas ) ? blomstra_compute_percentile_ranks_safe( $deltas, 0.0 ) : array();
    }
    foreach ( $country_output as $iso3 => &$out ) {
        $fwd_scores = array();
        $direction_signals = array();
        foreach ( $imf_defs as $code => $name ) {
            if ( isset( $delta_percentiles[ $name ][ $iso3 ] ) ) {
                $fwd_scores[] = $delta_percentiles[ $name ][ $iso3 ];
            }
            if ( isset( $delta_values[ $name ][ $iso3 ] ) ) {
                $direction_signals[] = $delta_values[ $name ][ $iso3 ];
            }
        }
        if ( count( $fwd_scores ) >= 4 ) {
            $fwd = array_sum( $fwd_scores ) / count( $fwd_scores );
            $out['seri_forward_pressure'] = round( $fwd, 2 );
            if ( count( $direction_signals ) >= 4 ) {
                $avg_delta = array_sum( $direction_signals ) / count( $direction_signals );
                $out['forward_delta_avg'] = round( $avg_delta, 2 );
                if ( $avg_delta > 0.5 ) {
                    $out['forward_direction'] = 'Deteriorating';
                } elseif ( $avg_delta < -0.5 ) {
                    $out['forward_direction'] = 'Improving';
                } else {
                    $out['forward_direction'] = 'Stable';
                }
            } else {
                $out['forward_direction'] = null;
            }
        } else {
            $out['seri_forward_pressure'] = null;
            $out['forward_direction'] = null;
            $out['forward_delta_avg'] = null;
        }
    }
    unset( $out );

    // 9. Output
    $weo_vintage = function_exists( 'blomstra_get_weo_vintage' ) ? blomstra_get_weo_vintage() : 'April 2026';
    $output = array(
        'version'            => SERI_VERSION,
        'last_updated'       => current_time( 'mysql', true ),
        'reference_vintage'  => date( 'Y' ),
        'weo_vintage'        => $weo_vintage,
        'min_pillars_required' => SERI_MIN_PILLARS_REQUIRED,
        'weights' => array(
            'governance' => $composite_weights['governance'] ?? 25,
            'macro'      => $composite_weights['macro'] ?? 25,
            'external'   => $composite_weights['external'] ?? 25,
            'fiscal'     => $composite_weights['fiscal'] ?? 25,
        ),
        'methodology_note' => 'Sovereign Economic Resilience Index (SERI): lower structural scores indicate higher resilience. Fiscal pillar uses IMF WEO general government debt as primary, World Bank central government as fallback. Debt trajectory uses CAGR with 4+ years required for "good" quality. GNI growth is NOT imputed from GDP. External debt uses only DT.DOD.DECT.GN.ZS (stock % GNI) – no fallback is used because no equivalent stock indicator exists.',
        'total_countries'    => count( $country_output ),
        'excluded_countries' => count( $excluded ),
        'excluded_detail'    => $excluded,
        // BMS-1.1.0: SIVI already declares standard_version; SERI didn't. Added for consistency.
        '_meta' => array(
            'built_at'            => current_time( 'mysql' ),
            'status'              => 'valid',
            'standard_version'    => 'BMS-1.2.0',
            'methodology_version' => SERI_VERSION,
            'software_version'    => SERI_VERSION,
            'data_vintage'        => $weo_vintage,
        ),
        'countries'          => $country_output,
    );

    // 10. Cron safeguards
    $previous = get_option( SERI_OPTION_KEY, null );
    $should_keep_old = false;

    // Skip cron safeguard for scenario builds
    if ( ! $is_scenario && $context === 'cron' && $previous && ! empty( $previous['countries'] ) ) {
        $prev_count = count( $previous['countries'] );
        $new_count = count( $output['countries'] );
        if ( $new_count < 0.8 * $prev_count && $new_count < 50 ) {
            error_log( 'SERI: Automated build failed – new country count (' . $new_count . ') is significantly lower than previous (' . $prev_count . '). Keeping old composite.' );
            set_transient( 'seri_auto_build_failed', 'yes', DAY_IN_SECONDS );
            $should_keep_old = true;
        }
    }

    if ( $should_keep_old && $previous ) {
        return $previous;
    }

    // Only persist to the live option if this is a REAL build (not a scenario)
    if ( ! $is_scenario ) {
        $staging_key = SERI_OPTION_KEY . '_tmp';
        update_option( $staging_key, $output, false );
        update_option( SERI_OPTION_KEY, $output, false );
        delete_option( $staging_key );

        if ( function_exists( 'blomstra_index_snapshot_save' ) && function_exists( 'blomstra_build_flat_snapshot_row' ) ) {
            blomstra_index_snapshot_save( 'seri', seri_build_snapshot_rows( $country_output ) );
        }
    }

    return $output;
}

// ─── VALIDATION ON INIT ─────────────────────────────────────────────

function seri_initialize() {
    // BMS-1.1.0 fix: guard against shared utilities not being loaded yet
    // (SERI would previously fatal-error here if so; SIVI already had this guard).
    if ( function_exists( 'blomstra_validate_pillar_thresholds' ) ) {
        $validation = blomstra_validate_pillar_thresholds( seri_get_pillar_defs(), seri_get_pillar_weights() );
        if ( ! $validation['valid'] ) {
            foreach ( $validation['mismatches'] as $m ) {
                error_log( 'SERI Definition Mismatch: ' . $m['issue'] );
            }
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                wp_die( 'SERI pillar definitions are inconsistent. Check error log.' );
            }
        }
    }
}
add_action( 'init', 'seri_initialize' );

// ─── ASYNC FETCH CALLBACKS ─────────────────────────────────────────

function seri_async_fetch_governance_callback() {
    seri_fetch_governance( true, false );
}
add_action( 'seri_async_fetch_governance', 'seri_async_fetch_governance_callback' );

function seri_async_fetch_macro_callback() {
    seri_fetch_macro( true, false );
}
add_action( 'seri_async_fetch_macro', 'seri_async_fetch_macro_callback' );

function seri_async_fetch_external_callback() {
    seri_fetch_external( true, false );
}
add_action( 'seri_async_fetch_external', 'seri_async_fetch_external_callback' );

function seri_async_fetch_fiscal_callback() {
    seri_fetch_fiscal( true, false );
}
add_action( 'seri_async_fetch_fiscal', 'seri_async_fetch_fiscal_callback' );

// ─── ASYNC REFRESH ─────────────────────────────────────────────────

function seri_async_refresh_callback() {
    $direct_api = get_option( 'seri_emergency_direct_api_flag', false );
    if ( $direct_api ) {
        delete_option( 'seri_emergency_direct_api_flag' );
    }

    $previous = get_option( SERI_OPTION_KEY, null );

    seri_fetch_governance( true, $direct_api );
    seri_fetch_macro( true, $direct_api );
    seri_fetch_external( true, $direct_api );
    seri_fetch_fiscal( true, $direct_api );

    $result = seri_build_composite( false, 'async' );

    if ( isset( $result['error'] ) && $previous ) {
        error_log( 'SERI Async: Build failed, keeping previous composite.' );
        set_transient( 'seri_auto_build_failed', 'yes', DAY_IN_SECONDS );
        if ( get_option( SERI_OPTION_KEY, null ) !== $previous ) {
            update_option( SERI_OPTION_KEY, $previous, false );
        }
    }
}
add_action( SERI_REFRESH_HOOK, 'seri_async_refresh_callback' );

// ─── DAILY CRON ─────────────────────────────────────────────────────

add_action( 'init', function () {
    if ( ! wp_next_scheduled( SERI_DAILY_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'daily', SERI_DAILY_CRON_HOOK );
    }
} );

add_action( SERI_DAILY_CRON_HOOK, function () {
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'seri_daily', 'running', 'Daily cron: refreshing pillar data...' );
    }
    seri_fetch_governance( true, false );
    seri_fetch_macro( true, false );
    seri_fetch_external( true, false );
    seri_fetch_fiscal( true, false );
    $result = seri_build_composite( false, 'cron' );
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        // BUGFIX (2026-09): this previously reported 'success'
        // unconditionally, even when seri_build_composite() returned an
        // error (e.g. no country list available) — the same "declares
        // success regardless of outcome" bug already found and fixed in
        // the reference-data layer's cron handlers.
        if ( isset( $result['error'] ) ) {
            blomstra_update_cron_status( 'seri_daily', 'error', 'Daily build failed: ' . $result['error'], 0 );
        } else {
            $msg = isset( $result['total_countries'] ) ? $result['total_countries'] . ' countries scored.' : 'Build completed.';
            blomstra_update_cron_status( 'seri_daily', 'success', $msg, $result['total_countries'] ?? 0 );
        }
    }
} );

// ─── WEEKLY CRON ────────────────────────────────────────────────────

add_action( 'init', function () {
    if ( ! wp_next_scheduled( SERI_CRON_HOOK ) ) {
        wp_schedule_event( time() + 300, 'weekly', SERI_CRON_HOOK );
    }
} );

add_action( SERI_CRON_HOOK, function () {
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        blomstra_update_cron_status( 'seri', 'running', 'SERI weekly cron started...' );
    }
    seri_fetch_governance( true, false );
    seri_fetch_macro( true, false );
    seri_fetch_external( true, false );
    seri_fetch_fiscal( true, false );
    $result = seri_build_composite( false, 'cron' );
    if ( function_exists( 'blomstra_update_cron_status' ) ) {
        // BUGFIX (2026-09): same fix as the daily cron above — check for
        // an actual error instead of always declaring success.
        if ( isset( $result['error'] ) ) {
            blomstra_update_cron_status( 'seri', 'error', 'Weekly build failed: ' . $result['error'], 0 );
        } else {
            $msg = isset( $result['total_countries'] ) ? $result['total_countries'] . ' countries scored.' : 'Build completed.';
            blomstra_update_cron_status( 'seri', 'success', $msg, $result['total_countries'] ?? 0 );
        }
    }
} );

// ─── REST ENDPOINT ──────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/sovereign-economic-resilience-index', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $data = get_option( SERI_OPTION_KEY, null );
            if ( ! $data ) {
                return new WP_Error( 'no_data', 'Index has not been generated yet.', array( 'status' => 404 ) );
            }
            return $data;
        },
    ) );
} );

// ─── ADMIN PAGE ────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_submenu_page(
        'blomstra-insights-tools',
        'SERI Index',
        'SERI Index',
        'manage_options',
        'blomstra-sovereign-economic-resilience-index',
        'seri_render_admin_page'
    );
} );

// Redirect old GERI admin page to new SERI admin page
add_action( 'admin_init', function() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'blomstra-geoeconomic-risk-index' ) {
        wp_redirect( admin_url( 'admin.php?page=blomstra-sovereign-economic-resilience-index' ) );
        exit;
    }
} );

function seri_render_admin_page() {
    // ── Handle actions ────────────────────────────────────────────
    if ( isset( $_POST['seri_fetch_governance'] ) && check_admin_referer( 'seri_fetch_governance_action' ) ) {
        wp_schedule_single_event( time(), 'seri_async_fetch_governance' );
        echo '<div class="notice notice-info"><p>⏳ Governance fetch queued as background task. Refresh the page shortly.</p></div>';
    }
    if ( isset( $_POST['seri_fetch_macro'] ) && check_admin_referer( 'seri_fetch_macro_action' ) ) {
        wp_schedule_single_event( time(), 'seri_async_fetch_macro' );
        echo '<div class="notice notice-info"><p>⏳ Macro fetch queued as background task. Refresh the page shortly.</p></div>';
    }
    if ( isset( $_POST['seri_fetch_external'] ) && check_admin_referer( 'seri_fetch_external_action' ) ) {
        wp_schedule_single_event( time(), 'seri_async_fetch_external' );
        echo '<div class="notice notice-info"><p>⏳ External fetch queued as background task. Refresh the page shortly.</p></div>';
    }
    if ( isset( $_POST['seri_fetch_fiscal'] ) && check_admin_referer( 'seri_fetch_fiscal_action' ) ) {
        wp_schedule_single_event( time(), 'seri_async_fetch_fiscal' );
        echo '<div class="notice notice-info"><p>⏳ Fiscal fetch queued as background task. Refresh the page shortly.</p></div>';
    }

    // ── API Direct ──────────────────────────────────────────────────
    if ( isset( $_POST['seri_fetch_api_governance'] ) && check_admin_referer( 'seri_fetch_api_governance_action' ) ) {
        $data = seri_fetch_governance( true, true );
        echo '<div class="notice notice-success"><p>✅ Governance: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['seri_fetch_api_macro'] ) && check_admin_referer( 'seri_fetch_api_macro_action' ) ) {
        $data = seri_fetch_macro( true, true );
        echo '<div class="notice notice-success"><p>✅ Macro: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['seri_fetch_api_external'] ) && check_admin_referer( 'seri_fetch_api_external_action' ) ) {
        $data = seri_fetch_external( true, true );
        echo '<div class="notice notice-success"><p>✅ External: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }
    if ( isset( $_POST['seri_fetch_api_fiscal'] ) && check_admin_referer( 'seri_fetch_api_fiscal_action' ) ) {
        $data = seri_fetch_fiscal( true, true );
        echo '<div class="notice notice-success"><p>✅ Fiscal: fetched from API directly (' . count( $data ) . ' countries).</p></div>';
    }

    // ── Flush per pillar ──────────────────────────────────────────
    if ( isset( $_POST['seri_flush_governance'] ) && check_admin_referer( 'seri_flush_governance_action' ) ) {
        delete_option( SERI_GOVERNANCE_KEY );
        delete_option( SERI_GOVERNANCE_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Governance pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['seri_flush_macro'] ) && check_admin_referer( 'seri_flush_macro_action' ) ) {
        delete_option( SERI_MACRO_KEY );
        delete_option( SERI_MACRO_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Macro pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['seri_flush_external'] ) && check_admin_referer( 'seri_flush_external_action' ) ) {
        delete_option( SERI_EXTERNAL_KEY );
        delete_option( SERI_EXTERNAL_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ External pillar cache flushed.</p></div>';
    }
    if ( isset( $_POST['seri_flush_fiscal'] ) && check_admin_referer( 'seri_flush_fiscal_action' ) ) {
        delete_option( SERI_FISCAL_KEY );
        delete_option( SERI_FISCAL_META_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Fiscal pillar cache flushed.</p></div>';
    }

    // ── Build from cache ──────────────────────────────────────────
    if ( isset( $_POST['seri_build_cache'] ) && check_admin_referer( 'seri_build_cache_action' ) ) {
        $data = seri_build_composite( false, 'manual' );
        echo '<div class="notice notice-success"><p>✅ Composite built from pillar cache: ' . esc_html( $data['total_countries'] ) . ' countries scored (' . esc_html( $data['excluded_countries'] ) . ' excluded).</p></div>';
    }

    // ── Fetch All (Async) ──────────────────────────────────────────
    if ( isset( $_POST['seri_fetch_all_async'] ) && check_admin_referer( 'seri_fetch_all_async_action' ) ) {
        wp_schedule_single_event( time(), SERI_REFRESH_HOOK );
        echo '<div class="notice notice-info"><p>🔄 All pillars queued for background refresh. Please wait a few minutes and refresh the page.</p></div>';
    }

    // ── Emergency API ──────────────────────────────────────────────
    if ( isset( $_POST['seri_emergency_api_build'] ) && check_admin_referer( 'seri_emergency_api_build_action' ) ) {
        update_option( 'seri_emergency_direct_api_flag', true, false );
        wp_schedule_single_event( time(), SERI_REFRESH_HOOK );
        echo '<div class="notice notice-info"><p>🚨 Emergency API refresh queued as background task. Please wait a few minutes and refresh the page.</p></div>';
    }

    // ── Flush All ──────────────────────────────────────────────────
    if ( isset( $_POST['seri_flush_all_confirmed'] ) && check_admin_referer( 'seri_flush_all_action' ) ) {
        delete_option( SERI_GOVERNANCE_KEY );
        delete_option( SERI_MACRO_KEY );
        delete_option( SERI_EXTERNAL_KEY );
        delete_option( SERI_FISCAL_KEY );
        delete_option( SERI_GOVERNANCE_META_KEY );
        delete_option( SERI_MACRO_META_KEY );
        delete_option( SERI_EXTERNAL_META_KEY );
        delete_option( SERI_FISCAL_META_KEY );
        delete_option( SERI_OPTION_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ All SERI pillar caches and composite have been flushed.</p></div>';
    }

    // ── Force daily cron ──────────────────────────────────────────
    if ( isset( $_POST['seri_force_daily_cron'] ) && check_admin_referer( 'seri_force_daily_cron_action' ) ) {
        wp_schedule_single_event( time(), SERI_DAILY_CRON_HOOK );
        echo '<div class="notice notice-info"><p>🧪 Daily cron triggered (will refresh pillars and rebuild). Result will appear shortly.</p></div>';
    }

    // ─── SENSITIVITY TESTING ──────────────────────────────────────
    if ( isset( $_POST['seri_build_scenario'] ) && check_admin_referer( 'seri_build_scenario_action' ) ) {
        $scenario_name = sanitize_key( $_POST['seri_scenario_name'] );
        $raw_json = wp_unslash( $_POST['seri_custom_weights'] );
        $json = json_decode( $raw_json, true );

        if ( $json === null ) {
            echo '<div class="notice notice-error"><p>❌ Invalid JSON. Please check the syntax. Error: ' . json_last_error_msg() . '</p></div>';
        } elseif ( ! isset( $json['pillars'] ) || ! isset( $json['composite'] ) ) {
            echo '<div class="notice notice-error"><p>❌ JSON must include both <code>pillars</code> and <code>composite</code> keys.</p></div>';
        } else {
            $sum = array_sum( $json['composite'] );
            if ( abs( $sum - 100 ) > 0.1 ) {
                echo '<div class="notice notice-error"><p>❌ Composite weights must sum to 100. Current sum: ' . esc_html( $sum ) . '</p></div>';
            } else {
                $result = seri_build_composite( false, 'scenario', $json['pillars'], $json['composite'] );
                seri_store_scenario( $result, $scenario_name );
                echo '<div class="notice notice-success"><p>✅ Scenario <strong>' . esc_html( $scenario_name ) . '</strong> built: ' . esc_html( $result['total_countries'] ) . ' countries scored.</p></div>';
            }
        }
    }

    if ( isset( $_POST['seri_delete_scenario'] ) && check_admin_referer( 'seri_delete_scenario_action' ) ) {
        $scenario_id = sanitize_key( $_POST['seri_delete_scenario'] );
        seri_delete_scenario( $scenario_id );
        echo '<div class="notice notice-warning"><p>🗑️ Scenario <strong>' . esc_html( $scenario_id ) . '</strong> deleted.</p></div>';
    }

    // ── Historical backfill actions (v5.1.0) ──────────────────────
    seri_backfill_handle_actions();

    $existing = get_option( SERI_OPTION_KEY, null );
    $next_cron = wp_next_scheduled( SERI_CRON_HOOK );
    $last_cron = get_option( 'blomstra_cron_status', array() );
    $seri_status = $last_cron['seri'] ?? null;

    $gov_meta = get_option( SERI_GOVERNANCE_META_KEY, array() );
    $macro_meta = get_option( SERI_MACRO_META_KEY, array() );
    $ext_meta = get_option( SERI_EXTERNAL_META_KEY, array() );
    $fisc_meta = get_option( SERI_FISCAL_META_KEY, array() );

    $gov_store = get_option( SERI_GOVERNANCE_KEY, array() );
    $macro_store = get_option( SERI_MACRO_KEY, array() );
    $ext_store = get_option( SERI_EXTERNAL_KEY, array() );
    $fisc_store = get_option( SERI_FISCAL_KEY, array() );

    $gov_data = $gov_store['data'] ?? array();
    $macro_data = $macro_store['data'] ?? array();
    $ext_data = $ext_store['data'] ?? array();
    $fisc_data = $fisc_store['data'] ?? array();

    $gov_count = count( $gov_data );
    $macro_count = count( $macro_data );
    $ext_count = count( $ext_data );
    $fisc_count = count( $fisc_data );

    $pillar_freshness = array();
    foreach ( array( 'governance' => $gov_meta, 'macro' => $macro_meta, 'external' => $ext_meta, 'fiscal' => $fisc_meta ) as $key => $meta ) {
        $last_fetched = $meta['last_fetched'] ?? null;
        if ( $last_fetched ) {
            $diff = time() - strtotime( $last_fetched );
            $days = floor( $diff / DAY_IN_SECONDS );
            if ( $days == 0 ) {
                $pillar_freshness[ $key ] = 'Today ✅';
            } elseif ( $days == 1 ) {
                $pillar_freshness[ $key ] = '1 day ago ✅';
            } else {
                $pillar_freshness[ $key ] = $days . ' days ago ✅';
            }
        } else {
            $pillar_freshness[ $key ] = 'Never ❌';
        }
    }

    $composite_fresh = 'Never ❌';
    if ( $existing && isset( $existing['last_updated'] ) ) {
        $diff = time() - strtotime( $existing['last_updated'] );
        $days = floor( $diff / DAY_IN_SECONDS );
        if ( $days == 0 ) {
            $composite_fresh = 'Today ✅';
        } elseif ( $days == 1 ) {
            $composite_fresh = '1 day ago ✅';
        } else {
            $composite_fresh = $days . ' days ago ✅';
        }
    }

    $gov_status = $gov_count > 0 ? 'Scored ✓ (' . $gov_count . ')' : 'Not Scored';
    $macro_status = $macro_count > 0 ? 'Scored ✓ (' . $macro_count . ')' : 'Not Scored';
    $ext_status = $ext_count > 0 ? 'Scored ✓ (' . $ext_count . ')' : 'Not Scored';
    $fisc_status = $fisc_count > 0 ? 'Scored ✓ (' . $fisc_count . ')' : 'Not Scored';

    $composite_status = 'Not built yet';
    if ( $existing ) {
        $composite_status = 'Composite built from pillar cache with ' . $existing['total_countries'] . ' countries.';
    }

    echo '<div class="wrap"><h1>SERI – Sovereign Economic Resilience Index</h1>';

    // Dashboard cards
    echo '<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin:15px 0;">';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Governance Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $gov_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Macro Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $macro_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">External Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $ext_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #2271b1; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Fiscal Pillar</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . $fisc_status . '</p></div></div>';
    echo '<div class="postbox" style="border-left:4px solid #f56e28; margin:0; min-height:100px;">';
    echo '<div class="postbox-header"><h3 class="hndle" style="font-size:14px; margin:0; padding:8px 12px;">Composite Index</h3></div>';
    echo '<div class="inside" style="padding:8px 12px;"><p style="font-size:18px; margin:0; font-weight:bold;">' . ( $existing ? 'Scored ✓ (' . $existing['total_countries'] . ')' : 'Not Scored' ) . '</p>';
    echo '<p style="margin:4px 0 0; font-size:12px; color:#666;">' . $composite_status . '</p></div></div>';
    echo '</div>';

    // ─── COVERAGE BREAKDOWN ──────────────────────────────────────
    if ( $existing && ! empty( $existing['countries'] ) ) {
        $full_count = 0;
        $partial_count = 0;
        foreach ( $existing['countries'] as $country ) {
            if ( isset( $country['coverage'] ) ) {
                if ( $country['coverage'] === 'full' ) {
                    $full_count++;
                } else {
                    $partial_count++;
                }
            }
        }
        $excluded_count = $existing['excluded_countries'] ?? 0;
        $total_count = $existing['total_countries'] ?? 0;

        echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#f0f6fc; margin:15px 0;">';
        echo '<div class="inside" style="padding:10px 15px;">';
        echo '<h3 style="margin:0 0 8px 0; font-size:14px;">📊 Coverage Breakdown</h3>';
        echo '<div style="display:flex; flex-wrap:wrap; gap:20px;">';
        echo '<div><strong style="color:#2e7d32;">Full Index:</strong> ' . $full_count . ' countries <span style="color:#666;font-size:12px;">(all 4 pillars)</span></div>';
        echo '<div><strong style="color:#ed6c02;">Partial Index:</strong> ' . $partial_count . ' countries <span style="color:#666;font-size:12px;">(3/4 pillars)</span></div>';
        echo '<div><strong style="color:#d32f2f;">Excluded:</strong> ' . $excluded_count . ' countries <span style="color:#666;font-size:12px;">(&lt;3 pillars)</span></div>';
        echo '<div><strong style="color:#1976d2;">Total Scored:</strong> ' . $total_count . ' countries</div>';
        echo '</div>';
        echo '</div></div>';
    }

    // Freshness & System Status
    echo '<div class="postbox" style="border-left:4px solid #00a0d2; background:#f9f9f9;">';
    echo '<div class="inside" style="display:flex; flex-wrap:wrap; gap:30px; padding:10px 15px;">';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Governance</strong><span style="font-size:14px;">' . $pillar_freshness['governance'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Macro</strong><span style="font-size:14px;">' . $pillar_freshness['macro'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">External</strong><span style="font-size:14px;">' . $pillar_freshness['external'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Fiscal</strong><span style="font-size:14px;">' . $pillar_freshness['fiscal'] . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Composite Index</strong><span style="font-size:14px;">' . $composite_fresh . '</span></div>';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Build Lock</strong><span style="font-size:14px;">🔓 Free</span></div>';
    $last_run = null;
    if ( $seri_status && isset( $seri_status['last_attempt'] ) ) {
        $last_run = $seri_status['last_attempt'];
    }
    if ( isset( $last_cron['seri_daily'] ) && isset( $last_cron['seri_daily']['last_attempt'] ) ) {
        if ( ! $last_run || strtotime( $last_cron['seri_daily']['last_attempt'] ) > strtotime( $last_run ) ) {
            $last_run = $last_cron['seri_daily']['last_attempt'];
        }
    }
    $last_fire_display = $last_run ? $last_run . ' ✅' : 'Never ❌';
    echo '<div><strong style="display:block; font-size:13px; color:#666;">Last Real wp-cron Fire</strong><span style="font-size:14px;">' . $last_fire_display . '</span></div>';
    echo '<div style="margin-left:auto;">';
    echo '<form method="post">';
    wp_nonce_field( 'seri_force_daily_cron_action' );
    echo '<input type="submit" name="seri_force_daily_cron" class="button button-secondary" value="🧪 Force Daily Cron Now" style="font-size:12px;">';
    echo '</form>';
    echo '</div>';
    echo '</div></div>';

    if ( get_transient( 'seri_auto_build_failed' ) ) {
        echo '<div class="notice notice-error"><p>⚠️ The automated weekly build failed to fetch complete data. Please run a manual refresh.</p></div>';
        delete_transient( 'seri_auto_build_failed' );
    }

    echo '<div style="margin-top:20px;">';

    // Cron Status
    echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-clock"></span> Cron &amp; Automation</h2></div>';
    echo '<div class="inside">';
    echo '<p>Automated weekly refresh: <strong>' . ( $next_cron ? 'ACTIVE — next run ' . esc_html( date_i18n( 'Y-m-d H:i', $next_cron ) ) . ' UTC' : 'NOT SCHEDULED' ) . '</strong></p>';
    if ( $seri_status ) {
        echo '<p>Last weekly cron run: <strong>' . esc_html( $seri_status['status'] ) . '</strong> at ' . esc_html( $seri_status['last_attempt'] ) . ' — ' . esc_html( $seri_status['message'] ) . '</p>';
    }
    if ( isset( $last_cron['seri_daily'] ) ) {
        echo '<p>Last daily cron run: <strong>' . esc_html( $last_cron['seri_daily']['status'] ) . '</strong> at ' . esc_html( $last_cron['seri_daily']['last_attempt'] ) . ' — ' . esc_html( $last_cron['seri_daily']['message'] ) . '</p>';
    }
    echo '</div></div>';

    // Pillar controls
    echo '<div class="postbox" style="border-left:4px solid #135e96; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-database"></span> Pillar Data Layer</h2></div>';
    echo '<div class="inside">';
    echo '<p style="color:#666;"><strong>Fetch from Central Data</strong> — uses shared Reference Data cache (async).<br>';
    echo '<strong>Fetch from API Directly</strong> — bypasses Reference Data, calls API directly (sync, fallback).</p>';
    echo '<table class="widefat striped"><thead><tr><th>Pillar</th><th>Status</th><th>Fetch from Central</th><th>Fetch API Direct</th><th>Flush</th></tr></thead><tbody>';
    foreach ( array( 'governance' => 'Governance', 'macro' => 'Macro Stability', 'external' => 'External Vulnerability', 'fiscal' => 'Fiscal Stress' ) as $key => $label ) {
        $store = get_option( constant( 'SERI_' . strtoupper( $key ) . '_KEY' ), array() );
        $data = $store['data'] ?? array();
        $count = is_array( $data ) ? count( $data ) : 0;
        $status = $count > 0 ? '<span style="color:#2e7d32;">Cached ✓ (' . $count . ')</span>' : '<span style="color:#d63638;">Not Cached</span>';
        echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . $status . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'seri_fetch_' . $key . '_action' );
        echo '<input type="submit" name="seri_fetch_' . $key . '" class="button button-small" style="min-width:140px;" value="📥 Fetch (Async)">';
        echo '</form></td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'seri_fetch_api_' . $key . '_action' );
        echo '<input type="submit" name="seri_fetch_api_' . $key . '" class="button button-small button-secondary" style="min-width:140px;" value="🔌 API Direct (Sync)">';
        echo '</form></td><td>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'seri_flush_' . $key . '_action' );
        echo '<input type="submit" name="seri_flush_' . $key . '" class="button button-small button-link-delete" style="min-width:140px;" value="🗑️ Flush">';
        echo '</form></td></tr>';
    }
    echo '</tbody></table>';
    echo '</div></div>';

    // Composite Build
    echo '<div class="postbox" style="border-left:4px solid #f56e28; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-chart-area"></span> Composite &amp; Build</h2></div>';
    echo '<div class="inside">';
    if ( $existing ) {
        echo '<p>Last built: <strong>' . esc_html( $existing['last_updated'] ) . ' UTC</strong> — ' . esc_html( $existing['total_countries'] ) . ' countries scored, ' . esc_html( $existing['excluded_countries'] ?? 0 ) . ' excluded.</p>';
    } else {
        echo '<p>No composite exists yet.</p>';
    }

    echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:15px 0;">';
    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'seri_build_cache_action' );
    echo '<input type="submit" name="seri_build_cache" class="button button-primary" style="min-width:180px; font-weight:bold;" value="🔨 Build Index from Cache">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;">';
    wp_nonce_field( 'seri_fetch_all_async_action' );
    echo '<input type="submit" name="seri_fetch_all_async" class="button button-secondary" style="min-width:180px; font-weight:bold;" value="📥 Refresh All Pillars (Async)">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will fetch data directly from the API for ALL pillars, bypassing the Reference Data layer. This is a fallback. Continue?\');">';
    wp_nonce_field( 'seri_emergency_api_build_action' );
    echo '<input type="submit" name="seri_emergency_api_build" class="button button-secondary" style="min-width:180px; background:#d63638; color:#fff; border-color:#d63638; font-weight:bold;" value="🚨 Emergency API → Build (Async)">';
    echo '</form>';

    echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'WARNING: This will delete ALL pillar caches and the composite. Continue?\');">';
    wp_nonce_field( 'seri_flush_all_action' );
    echo '<input type="submit" name="seri_flush_all_confirmed" class="button button-secondary" style="min-width:180px; background:#d63638; color:#fff; border-color:#d63638;" value="🗑️ Flush ALL Caches">';
    echo '</form>';
    echo '</div>';

    echo '<p style="color:#666; font-size:12px; margin:0;"><strong>Build from Cache</strong> — uses existing pillar data (no API calls).<br>';
    echo '<strong>Refresh All Pillars (Async)</strong> — fetches fresh data from central cache in the background.<br>';
    echo '<strong>Emergency API</strong> — falls back to direct API calls (use when central cache is broken).<br>';
    echo '<strong>Flush ALL Caches</strong> — deletes all pillar and composite data (destructive).</p>';
    echo '</div></div>';

    // ─── HISTORICAL BACKFILL PANEL (v5.1.0) ───────────────────────
    seri_render_backfill_box();

    // ─── SENSITIVITY TESTING ──────────────────────────────────────
    $scenarios = seri_list_scenarios();
    $baseline = get_option( SERI_OPTION_KEY );

    echo '<div class="postbox" style="border-left:4px solid #9b51e0; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-admin-generic"></span> 🔬 Sensitivity Testing (Research)</h2></div>';
    echo '<div class="inside">';

    // Preset weights
    $preset_weights = array(
        'baseline'        => array( 'governance' => 25, 'macro' => 25, 'external' => 25, 'fiscal' => 25 ),
        'gov-heavy'       => array( 'governance' => 60, 'macro' => 20, 'external' => 10, 'fiscal' => 10 ),
        'gov-light'       => array( 'governance' => 10, 'macro' => 30, 'external' => 30, 'fiscal' => 30 ),
        'macro-heavy'     => array( 'governance' => 10, 'macro' => 60, 'external' => 20, 'fiscal' => 10 ),
        'macro-light'     => array( 'governance' => 30, 'macro' => 10, 'external' => 30, 'fiscal' => 30 ),
        'external-heavy'  => array( 'governance' => 10, 'macro' => 20, 'external' => 60, 'fiscal' => 10 ),
        'external-light'  => array( 'governance' => 30, 'macro' => 30, 'external' => 10, 'fiscal' => 30 ),
        'fiscal-heavy'    => array( 'governance' => 10, 'macro' => 20, 'external' => 10, 'fiscal' => 60 ),
        'fiscal-light'    => array( 'governance' => 30, 'macro' => 30, 'external' => 30, 'fiscal' => 10 ),
    );

    $preset_js = array();
    foreach ( $preset_weights as $key => $weights ) {
        $preset_js[ $key ] = array(
            'pillars'   => seri_get_pillar_weights(),
            'composite' => $weights,
        );
    }
    $preset_json = wp_json_encode( $preset_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

    echo '<p><strong>Presets:</strong> Click a button to load a predefined weighting scheme.</p>';
    echo '<div style="display:flex; flex-wrap:wrap; gap:5px; margin-bottom:15px;">';

    foreach ( $preset_weights as $key => $weights ) {
        $label = str_replace( '-', ' ', $key );
        $label = ucwords( $label );
        echo '<button type="button" class="button preset-btn" data-preset="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</button> ';
    }
    echo '</div>';
    ?>
    <script>
    var seriPresets = <?php echo $preset_json; ?>;
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.preset-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var presetName = this.dataset.preset;
                var preset = seriPresets[presetName];
                if (preset) {
                    document.getElementById('seri_custom_weights').value = JSON.stringify(preset, null, 4);
                    document.getElementById('seri_scenario_name').value = presetName;
                }
            });
        });
    });
    </script>
    <?php

    echo '<form method="post" style="margin-top:10px;">';
    wp_nonce_field( 'seri_build_scenario_action' );
    echo '<p><strong>Custom Weights JSON</strong></p>';
    echo '<p style="color:#666; font-size:12px;">Edit the JSON below to define custom pillar weights. <code>pillars</code> controls within-pillar indicator weights (rarely changed). <code>composite</code> controls the 4 pillar weights (must sum to 100).</p>';
    $default_json = wp_json_encode( array( 'pillars' => seri_get_pillar_weights(), 'composite' => seri_get_composite_weights() ), JSON_PRETTY_PRINT );
    echo '<textarea id="seri_custom_weights" name="seri_custom_weights" style="width:100%;height:180px;font-family:monospace;font-size:12px;padding:8px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;">' . esc_textarea( $default_json ) . '</textarea>';

    echo '<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:10px;">';
    echo '<label><strong>Scenario ID:</strong></label>';
    echo '<input type="text" id="seri_scenario_name" name="seri_scenario_name" placeholder="e.g., gov-heavy-60" style="width:200px;" required pattern="[a-z0-9\-]+">';
    echo '<input type="submit" name="seri_build_scenario" class="button button-primary" value="🔬 Build Scenario">';
    echo '</div>';
    echo '</form>';

    // Scenario comparison table
    if ( ! empty( $scenarios ) && $baseline ) {
        echo '<h4 style="margin-top:20px;">Scenario Comparison</h4>';
        echo '<table class="widefat striped"><thead><tr><th>Scenario</th><th>Countries</th><th>Spearman ρ vs Baseline</th><th>Top Mover</th><th>Action</th></tr></thead><tbody>';

        $baseline_ranks = array();
        foreach ( $baseline['countries'] as $iso3 => $c ) {
            if ( isset( $c['rank_display']['best_estimate'] ) ) {
                $baseline_ranks[ $iso3 ] = $c['rank_display']['best_estimate'];
            }
        }

        foreach ( $scenarios as $id => $scenario ) {
            $scenario_ranks = array();
            foreach ( $scenario['countries'] as $iso3 => $c ) {
                if ( isset( $c['rank_display']['best_estimate'] ) ) {
                    $scenario_ranks[ $iso3 ] = $c['rank_display']['best_estimate'];
                }
            }

            $common = array_intersect_key( $baseline_ranks, $scenario_ranks );
            $x = array();
            $y = array();
            foreach ( $common as $iso3 => $br ) {
                $x[] = $br;
                $y[] = $scenario_ranks[ $iso3 ];
            }

            $rho = 'N/A';
            if ( count( $x ) > 2 ) {
                $rho = function_exists( 'blomstra_spearman_correlation' )
                    ? round( blomstra_spearman_correlation( $x, $y ), 3 )
                    : 0;
            }

            $max_delta = 0;
            $top_mover = '-';
            foreach ( $common as $iso3 => $br ) {
                $delta = abs( $br - $scenario_ranks[ $iso3 ] );
                if ( $delta > $max_delta ) {
                    $max_delta = $delta;
                    $top_mover = $iso3 . ' (±' . $delta . ')';
                }
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html( $id ) . '</strong></td>';
            echo '<td>' . esc_html( $scenario['total_countries'] ) . '</td>';
            echo '<td>' . esc_html( $rho ) . '</td>';
            echo '<td>' . esc_html( $top_mover ) . '</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'seri_delete_scenario_action' );
            echo '<input type="hidden" name="seri_delete_scenario" value="' . esc_attr( $id ) . '">';
            echo '<input type="submit" class="button button-small button-link-delete" value="Delete" onclick="return confirm(\'Delete scenario ' . esc_js( $id ) . '?\');">';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p style="margin-top:10px; color:#666;">No scenarios built yet. Use the presets and build a scenario to see comparison data.</p>';
    }

    echo '</div></div>';

    // Preview with Rank
    if ( $existing && ! empty( $existing['countries'] ) ) {
        $countries = $existing['countries'];
        uasort( $countries, function( $a, $b ) {
            return ( $a['seri_structural'] ?? 0 ) <=> ( $b['seri_structural'] ?? 0 );
        } );
        $lowest = array_slice( $countries, 0, 10, true );
        $highest = array_slice( $countries, -10, 10, true );

        echo '<div style="margin-top:20px;">';

        // Highest Resilience / Lowest Risk
        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📊 10 Most Resilient Countries</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<table class="widefat striped"><thead><tr><th>Rank</th><th>Country</th><th>Structural Score</th><th>Forward Pressure</th><th>Direction</th></tr></thead><tbody>';
        foreach ( $lowest as $name => $row ) {
            $rank_display = $row['rank_display'] ?? null;
            $rank_text = '';
            if ( $rank_display && isset( $rank_display['best_estimate'] ) ) {
                $rank_text = '#' . $rank_display['best_estimate'];
                if ( isset( $rank_display['range_80_low'] ) && isset( $rank_display['range_80_high'] ) &&
                     $rank_display['range_80_low'] !== $rank_display['range_80_high'] ) {
                    $rank_text = '#' . $rank_display['range_80_low'] . '–#' . $rank_display['range_80_high'] . '*';
                }
            } else {
                $rank_text = '—';
            }
            echo '<tr><td>' . esc_html( $rank_text ) . '</td><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['seri_structural'] ?? '—' ) . '</td><td>' . esc_html( $row['seri_forward_pressure'] ?? '—' ) . '</td><td>' . esc_html( $row['forward_direction'] ?? '—' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div></details>';

        // Lowest Resilience - Highest Risk
        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📈 10 Least Resilient Countries</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<table class="widefat striped"><thead><tr><th>Rank</th><th>Country</th><th>Structural Score</th><th>Forward Pressure</th><th>Direction</th></tr></thead><tbody>';
        foreach ( $highest as $name => $row ) {
            $rank_display = $row['rank_display'] ?? null;
            $rank_text = '';
            if ( $rank_display && isset( $rank_display['best_estimate'] ) ) {
                $rank_text = '#' . $rank_display['best_estimate'];
                if ( isset( $rank_display['range_80_low'] ) && isset( $rank_display['range_80_high'] ) &&
                     $rank_display['range_80_low'] !== $rank_display['range_80_high'] ) {
                    $rank_text = '#' . $rank_display['range_80_low'] . '–#' . $rank_display['range_80_high'] . '*';
                }
            } else {
                $rank_text = '—';
            }
            echo '<tr><td>' . esc_html( $rank_text ) . '</td><td>' . esc_html( $name ) . '</td><td>' . esc_html( $row['seri_structural'] ?? '—' ) . '</td><td>' . esc_html( $row['seri_forward_pressure'] ?? '—' ) . '</td><td>' . esc_html( $row['forward_direction'] ?? '—' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div></details>';

        // Excluded
        if ( ! empty( $existing['excluded_detail'] ) ) {
            echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
            echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">🚫 Excluded — Insufficient Data (' . count( $existing['excluded_detail'] ) . ')</summary>';
            echo '<div style="padding:15px; background:#fff;">';
            echo '<table class="widefat striped"><thead><tr><th>Country</th><th>Reason</th></tr></thead><tbody>';
            foreach ( $existing['excluded_detail'] as $name => $reason ) {
                echo '<tr><td>' . esc_html( $name ) . '</td><td>' . esc_html( $reason ) . '</td></tr>';
            }
            echo '</tbody></table>';
            echo '</div></details>';
        }

        echo '<details style="background:#f0f6fc; border:1px solid #ccd0d4; border-radius:4px; padding:0; margin-top:10px;">';
        echo '<summary style="cursor:pointer; font-weight:bold; padding:10px 15px; background:#e8f0fe; border-bottom:1px solid #ccd0d4; border-radius:4px 4px 0 0;">📄 Raw JSON Output</summary>';
        echo '<div style="padding:15px; background:#fff;">';
        echo '<textarea readonly style="width:100%;height:200px;font-family:monospace;font-size:12px;">' . esc_textarea( wp_json_encode( $existing, JSON_PRETTY_PRINT ) ) . '</textarea>';
        echo '</div></details>';
        echo '</div>';
    }

    echo '</div>';
}

// ============================================================================
// HISTORICAL BACKFILL (SERI v5.3.0)
//
// v5.3.0 fixes a real bug found after v5.2.0 went live: most backfilled
// years scored far too few countries, worsening the further back the year
// (2020-2022 scored 0, 2023-2024 scored a handful, only 2025 looked right).
// Root cause was in the SHARED blomstra_fetch_wb_historical_batch() in
// global-reference-data.php: for any indicator with no explicit `source`
// (10 of our 13 World Bank codes — everything except the 3 WGI/governance
// ones), it appended `&mrnev=1` ("most recent non-empty value") to the
// request even when a genuine multi-year date range was also requested.
// That silently collapsed a wide range (e.g. 1992-2026) down to ONE data
// point per country instead of a real time series. Point-in-time lookups
// for a year close to "now" could still reach that one collapsed point via
// the lookback window; older years could not, and scored almost nothing.
// See PATCH.md for the one-line fix to that shared function — it does not
// change any existing single-year caller's behavior.
// That stale, collapsed data is also sitting in that function's own
// transient cache (1-week TTL) from earlier runs, so fixing the URL alone
// doesn't help until it's cleared — see "Clear Cached Series & Reset"
// below, added for exactly this recovery.
//
// v5.2.0 fixed a different, now-resolved problem: a single backfill year
// previously made 13 World Bank calls + 2 IMF calls INLINE, in one
// request. On a cold cache that easily took several minutes, which either
// PHP's own execution limit or the web server's own hard timeout killed
// mid-request — and a server-level kill happens before PHP ever runs the
// "mark this year failed" cleanup, which is why a year could sit on
// "Running" forever with no error. The "Run/Retry" button also ran
// synchronously inside the admin page load, which is why clicking it left
// the page blank until it finished or was killed.
//
// Fix, in two parts:
//   1. Fetching is now its own step ("Warm Up Reference Data"): one World
//      Bank or IMF indicator is fetched per background tick (~15 ticks
//      total, ~15-20s apart), each tick doing exactly ONE network call.
//      Results are stored in their own WordPress options (persistent,
//      autoload off). A year's actual scoring job then reads ONLY from
//      those options — zero network calls — so it finishes in a few
//      seconds regardless of hosting timeouts.
//   2. Every admin action (single-year Run/Retry included) now schedules
//      a background job and returns immediately, exactly like "Backfill
//      All" already did — no action blocks the admin page anymore.
//
// This still reuses the SAME reference-data layer SIVI's backfill uses
// (global-reference-data.php is not touched or duplicated):
//   - WB indicators: blomstra_fetch_wb_historical_batch() — the existing
//     shared range fetcher, called once per indicator during warm-up.
//   - IMF indicators (fiscal pillar): the IMF WEO datamapper endpoint
//     returns a country's full time series in one response; since no
//     existing helper returns that full series (every one narrows it to
//     a single year), the warm-up fetches it directly — 2 codes total,
//     the one genuinely new fetch this feature needs.
//   - Scoring: historical years run through seri_build_composite() itself
//     (via its $historical argument) — identical pillar math, percentile
//     ranking, coverage rules and ranking logic to the live build.
//   - Snapshot shape: live and historical rows both go through
//     seri_build_snapshot_rows() / blomstra_build_flat_snapshot_row(), so
//     the two can't diverge in shape the way SIVI's did before its own
//     v3.3.0 fix.
//
// A year is never silently built from missing data: if a required series
// hasn't been warmed up yet, the year fails fast with a clear message
// instead of hanging.
// ============================================================================

define( 'SERI_BACKFILL_MIN_YEAR', 2002 );          // first year of annual (non-biennial) WGI releases
define( 'SERI_HIST_LOOKBACK_YEARS', 5 );           // how far back a point-in-time value may be carried for a given year
define( 'SERI_HIST_VOL_WINDOW_YEARS', 5 );         // trailing window used for volatility / debt-trajectory CAGR
define( 'SERI_HIST_PARTIAL_THRESHOLD', 100 );      // fewer scored countries than this = status "partial"
define( 'SERI_BACKFILL_YEAR_HOOK', 'seri_backfill_year_cron' );
define( 'SERI_BACKFILL_LOCK_KEY', 'seri_backfill_lock' );
define( 'SERI_BACKFILL_STATUS_KEY', 'seri_backfill_status' );
define( 'SERI_HIST_PREFETCH_HOOK', 'seri_hist_prefetch_tick' );
define( 'SERI_HIST_PREFETCH_LOCK', 'seri_hist_prefetch_lock' );
define( 'SERI_HIST_PREFETCH_THEN_BACKFILL', 'seri_hist_prefetch_then_backfill' );
define( 'SERI_HIST_PREFETCH_TICK_GAP', 20 ); // seconds between warm-up ticks — gentle on the host and the API

// ─── Indicators this feature needs, and where each is stored ─────────────

function seri_hist_prefetch_items() {
    $wb = array(
        'rule_of_law'           => array( 'GOV_WGI_RL.SC', 3 ),
        'control_of_corruption' => array( 'GOV_WGI_CC.SC', 3 ),
        'political_stability'   => array( 'GOV_WGI_PV.SC', 3 ),
        'gni_growth'            => array( 'NY.GNP.MKTP.KD.ZG', null ),
        'gni_growth_percap'     => array( 'NY.GNP.PCAP.KD.ZG', null ),
        'inflation'             => array( 'FP.CPI.TOTL.ZG', null ),
        'unemployment'          => array( 'SL.UEM.TOTL.ZS', null ),
        'gdp_growth'            => array( 'NY.GDP.MKTP.KD.ZG', null ),
        'reserve_months'        => array( 'FI.RES.TOTL.MO', null ),
        'external_debt'         => array( 'DT.DOD.DECT.GN.ZS', null ),
        'current_account'       => array( 'BN.CAB.XOKA.GD.ZS', null ),
        'gov_debt_wb'           => array( 'GC.DOD.TOTL.GD.ZS', null ),
        'gov_balance_wb'        => array( 'GC.NLD.TOTL.GD.ZS', null ),
    );
    $items = array();
    foreach ( $wb as $field => $spec ) {
        $items[] = array(
            'type'        => 'wb',
            'field'       => $field,
            'code'        => $spec[0],
            'source'      => $spec[1],
            'storage_key' => 'seri_hist_series_wb_' . sanitize_key( $field ),
            'label'       => $spec[0] . ( $spec[1] ? ' (WGI)' : ' (WDI)' ),
        );
    }
    foreach ( array( 'GGXWDG_NGDP' => 'gov_debt_imf', 'GGXCNL_NGDP' => 'gov_balance_imf' ) as $code => $field ) {
        $items[] = array(
            'type'        => 'imf',
            'field'       => $field,
            'code'        => $code,
            'source'      => null,
            'storage_key' => 'seri_hist_series_imf_' . sanitize_key( $field ),
            'label'       => $code . ' (IMF WEO)',
        );
    }
    return $items;
}

function seri_hist_series_get( $storage_key ) {
    $stored = get_option( $storage_key, null );
    return ( is_array( $stored ) && isset( $stored['data'] ) ) ? $stored['data'] : null;
}

function seri_hist_series_set( $storage_key, $data ) {
    update_option( $storage_key, array( 'data' => $data, 'fetched_at' => current_time( 'mysql' ) ), false );
}

/** Read-only lookups used by the (fast) per-year scoring job. */
function seri_hist_wb_series( $field ) {
    foreach ( seri_hist_prefetch_items() as $item ) {
        if ( $item['type'] === 'wb' && $item['field'] === $field ) {
            $data = seri_hist_series_get( $item['storage_key'] );
            return is_array( $data ) ? $data : array();
        }
    }
    return array();
}

function seri_hist_imf_series( $field ) {
    foreach ( seri_hist_prefetch_items() as $item ) {
        if ( $item['type'] === 'imf' && $item['field'] === $field ) {
            $data = seri_hist_series_get( $item['storage_key'] );
            return is_array( $data ) ? $data : array();
        }
    }
    return array();
}

/** Most recent value at or before $year, no older than $lookback years. */
function seri_hist_latest( $series, $iso3, $year, $lookback ) {
    if ( empty( $series[ $iso3 ] ) || ! is_array( $series[ $iso3 ] ) ) {
        return null;
    }
    for ( $y = $year; $y >= $year - $lookback; $y-- ) {
        if ( isset( $series[ $iso3 ][ $y ] ) && is_numeric( $series[ $iso3 ][ $y ] ) ) {
            return array( 'value' => (float) $series[ $iso3 ][ $y ], 'year' => $y );
        }
    }
    return null;
}

/** Year-sorted slice of one country's series within [$from, $to]. */
function seri_hist_window( $country_series, $from, $to ) {
    $out = array();
    if ( ! is_array( $country_series ) ) {
        return $out;
    }
    foreach ( $country_series as $y => $v ) {
        if ( (int) $y >= $from && (int) $y <= $to && is_numeric( $v ) ) {
            $out[ (int) $y ] = (float) $v;
        }
    }
    ksort( $out, SORT_NUMERIC );
    return $out;
}

// ─── Warm-up: fetch exactly one indicator per tick ─────────────────────────

function seri_hist_prefetch_status() {
    $items  = seri_hist_prefetch_items();
    $total  = count( $items );
    $cached = 0;
    $rows   = array();
    foreach ( $items as $item ) {
        $data     = seri_hist_series_get( $item['storage_key'] );
        $has_data = is_array( $data ) && ! empty( $data );
        if ( $has_data ) {
            $cached++;
        }
        $rows[] = array(
            'label'    => $item['label'],
            'cached'   => $has_data,
            'countries' => $has_data ? count( $data ) : 0,
        );
    }
    return array( 'total' => $total, 'cached' => $cached, 'ready' => ( $cached === $total ), 'rows' => $rows );
}

/** Fetch ONE item's series. Returns true on success, false on failure. */
function seri_hist_prefetch_fetch_one( $item ) {
    if ( $item['type'] === 'wb' ) {
        if ( ! function_exists( 'blomstra_fetch_wb_historical_batch' ) ) {
            return false;
        }
        $end   = (int) current_time( 'Y' );
        $start = SERI_BACKFILL_MIN_YEAR - SERI_HIST_LOOKBACK_YEARS - SERI_HIST_VOL_WINDOW_YEARS;
        $data  = blomstra_fetch_wb_historical_batch( $item['code'], $start, $end, $item['source'], false );
        if ( ! is_array( $data ) || empty( $data ) ) {
            return false;
        }
        seri_hist_series_set( $item['storage_key'], $data );
        return true;
    }

    // IMF: the datamapper endpoint returns the whole series in one response.
    $out = array();
    $url = 'https://www.imf.org/external/datamapper/api/v1/' . $item['code'];
    $response = wp_remote_get( $url, array( 'timeout' => 45, 'user-agent' => 'SERI-Direct/' . SERI_VERSION ) );
    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $map  = defined( 'BLOMSTRA_IMF_TO_ISO3_MAP' ) ? BLOMSTRA_IMF_TO_ISO3_MAP : array();
        if ( isset( $body['values'][ $item['code'] ] ) && is_array( $body['values'][ $item['code'] ] ) ) {
            foreach ( $body['values'][ $item['code'] ] as $imf_code => $years ) {
                $iso3 = $map[ $imf_code ] ?? $imf_code;
                if ( ! is_array( $years ) ) {
                    continue;
                }
                foreach ( $years as $y => $v ) {
                    if ( is_numeric( $v ) ) {
                        $out[ $iso3 ][ (int) $y ] = (float) $v;
                    }
                }
            }
        }
    }
    if ( empty( $out ) ) {
        return false;
    }
    seri_hist_series_set( $item['storage_key'], $out );
    return true;
}

add_action( SERI_HIST_PREFETCH_HOOK, 'seri_hist_prefetch_tick_callback' );
function seri_hist_prefetch_tick_callback() {
    if ( ! get_transient( SERI_HIST_PREFETCH_LOCK ) ) {
        return; // cancelled
    }
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 90 ); // this tick does exactly one network call
    }

    $next = null;
    foreach ( seri_hist_prefetch_items() as $item ) {
        $data = seri_hist_series_get( $item['storage_key'] );
        if ( ! is_array( $data ) || empty( $data ) ) {
            $next = $item;
            break;
        }
    }

    if ( $next === null ) {
        // Everything is warmed up.
        delete_transient( SERI_HIST_PREFETCH_LOCK );
        $chain = get_option( SERI_HIST_PREFETCH_THEN_BACKFILL, null );
        if ( is_array( $chain ) ) {
            delete_option( SERI_HIST_PREFETCH_THEN_BACKFILL );
            seri_backfill_schedule_years( (int) $chain['start'], (int) $chain['end'] );
        }
        return;
    }

    $ok = seri_hist_prefetch_fetch_one( $next );
    if ( ! $ok ) {
        error_log( 'SERI warm-up: failed to fetch ' . $next['label'] . ' — will retry on the next tick.' );
    }
    wp_schedule_single_event( time() + SERI_HIST_PREFETCH_TICK_GAP, SERI_HIST_PREFETCH_HOOK );
}

function seri_hist_prefetch_start( $then_backfill_range = null ) {
    if ( get_transient( SERI_HIST_PREFETCH_LOCK ) !== false ) {
        return false; // already running
    }
    set_transient( SERI_HIST_PREFETCH_LOCK, time(), HOUR_IN_SECONDS );
    if ( is_array( $then_backfill_range ) ) {
        update_option( SERI_HIST_PREFETCH_THEN_BACKFILL, $then_backfill_range, false );
    }
    wp_schedule_single_event( time() + 3, SERI_HIST_PREFETCH_HOOK );
    return true;
}

// ─── Historical data assembly (reads the warmed-up cache only — no network) ──

/**
 * Build the merged per-country raw rows (and source map) for one year, in
 * the exact field shape seri_build_composite() expects from the live
 * fetchers (seri_fetch_governance / macro / external / fiscal). If a field
 * name in a live fetcher changes, change it here too.
 *
 * @return array  array( 'rows' => ..., 'sources' => ... ) or array( 'error' => string )
 */
function seri_hist_build_rows( $year, $countries ) {
    $year = (int) $year;
    $lb   = SERI_HIST_LOOKBACK_YEARS;

    $status = seri_hist_prefetch_status();
    if ( ! $status['ready'] ) {
        return array( 'error' => 'Reference data not warmed up yet (' . $status['cached'] . ' of ' . $status['total'] . ' series cached). Click "Warm Up Reference Data" and wait for it to finish, then retry.' );
    }

    $wb = array();
    foreach ( array( 'rule_of_law', 'control_of_corruption', 'political_stability', 'gni_growth', 'gni_growth_percap', 'inflation', 'unemployment', 'gdp_growth', 'reserve_months', 'external_debt', 'current_account', 'gov_debt_wb', 'gov_balance_wb' ) as $field ) {
        $wb[ $field ] = seri_hist_wb_series( $field );
    }
    $imf_debt    = seri_hist_imf_series( 'gov_debt_imf' );
    $imf_balance = seri_hist_imf_series( 'gov_balance_imf' );

    $rows    = array();
    $sources = array();

    foreach ( array_keys( $countries ) as $iso3 ) {
        $row = array();

        // Governance (WGI)
        foreach ( array( 'rule_of_law', 'control_of_corruption', 'political_stability' ) as $name ) {
            $hit = seri_hist_latest( $wb[ $name ], $iso3, $year, $lb );
            if ( $hit ) {
                $row[ $name ]             = $hit['value'];
                $row[ $name . '_year' ]   = $hit['year'];
                $row[ $name . '_source' ] = 'WGI';
                blomstra_track_source( $sources, $iso3, $name, 'WGI', 'composite', $hit['year'] );
            }
        }

        // Macro: GNI growth (aggregate primary, per-capita fallback — as live)
        $hit = seri_hist_latest( $wb['gni_growth'], $iso3, $year, $lb );
        if ( $hit ) {
            $row['gni_growth']        = $hit['value'];
            $row['gni_growth_year']   = $hit['year'];
            $row['gni_growth_source'] = 'WB_WDI';
            blomstra_track_source( $sources, $iso3, 'gni_growth', 'WB_WDI', 'national', $hit['year'] );
        } else {
            $hit2 = seri_hist_latest( $wb['gni_growth_percap'], $iso3, $year, $lb );
            if ( $hit2 ) {
                $row['gni_growth']        = $hit2['value'];
                $row['gni_growth_year']   = $hit2['year'];
                $row['gni_growth_source'] = 'WB_WDI_per_capita (fallback)';
                blomstra_track_source( $sources, $iso3, 'gni_growth', 'WB_WDI_per_capita', 'national', $hit2['year'] );
            }
        }

        foreach ( array( 'inflation', 'unemployment', 'gdp_growth' ) as $name ) {
            $hit = seri_hist_latest( $wb[ $name ], $iso3, $year, $lb );
            if ( $hit ) {
                $row[ $name ]             = $hit['value'];
                $row[ $name . '_year' ]   = $hit['year'];
                $row[ $name . '_source' ] = 'WB_WDI';
                blomstra_track_source( $sources, $iso3, $name, 'WB_WDI', 'national', $hit['year'] );
            }
        }

        // Macro: derived volatilities over a trailing window ending at $year
        $vol_map = array( 'gdp_volatility' => 'gdp_growth', 'inflation_volatility' => 'inflation' );
        foreach ( $vol_map as $vname => $src_field ) {
            $win  = seri_hist_window( $wb[ $src_field ][ $iso3 ] ?? array(), $year - SERI_HIST_VOL_WINDOW_YEARS, $year );
            $vals = array_values( $win );
            if ( count( $vals ) >= 4 ) {
                $row[ $vname ]                   = blomstra_compute_stddev( $vals, true );
                $row[ $vname . '_window' ]       = SERI_HIST_VOL_WINDOW_YEARS . ' years';
                $row[ $vname . '_observations' ] = count( $vals );
                $row[ $vname . '_years' ]        = implode( ',', array_keys( $win ) );
                blomstra_track_source( $sources, $iso3, $vname, 'WB_WDI_derived', 'national' );
            } else {
                $row[ $vname ] = null;
            }
        }

        // External
        foreach ( array( 'reserve_months', 'external_debt', 'current_account' ) as $name ) {
            $hit = seri_hist_latest( $wb[ $name ], $iso3, $year, $lb );
            if ( $hit ) {
                $row[ $name ]             = $hit['value'];
                $row[ $name . '_year' ]   = $hit['year'];
                $row[ $name . '_source' ] = 'WB_WDI';
                blomstra_track_source( $sources, $iso3, $name, 'WB_WDI', 'national', $hit['year'] );
            }
        }

        // Fiscal: IMF WEO primary (general government), WB fallback (central)
        $hit = seri_hist_latest( $imf_debt, $iso3, $year, $lb );
        if ( $hit ) {
            $row['gov_debt']      = $hit['value'];
            $row['gov_debt_year'] = $hit['year'];
            blomstra_track_source( $sources, $iso3, 'gov_debt', 'IMF_WEO', 'general_gov', $hit['year'] );
        } else {
            $hit = seri_hist_latest( $wb['gov_debt_wb'], $iso3, $year, $lb );
            if ( $hit ) {
                $row['gov_debt']      = $hit['value'];
                $row['gov_debt_year'] = $hit['year'];
                blomstra_track_source( $sources, $iso3, 'gov_debt', 'WB_WDI', 'central_gov', $hit['year'] );
            }
        }
        $hit = seri_hist_latest( $imf_balance, $iso3, $year, $lb );
        if ( $hit ) {
            $row['gov_balance']      = $hit['value'];
            $row['gov_balance_year'] = $hit['year'];
            blomstra_track_source( $sources, $iso3, 'gov_balance', 'IMF_WEO', 'general_gov', $hit['year'] );
        } else {
            $hit = seri_hist_latest( $wb['gov_balance_wb'], $iso3, $year, $lb );
            if ( $hit ) {
                $row['gov_balance']      = $hit['value'];
                $row['gov_balance_year'] = $hit['year'];
                blomstra_track_source( $sources, $iso3, 'gov_balance', 'WB_WDI', 'central_gov', $hit['year'] );
            }
        }

        // Fiscal: debt trajectory (CAGR of central-gov debt over the trailing window)
        $debt_win = seri_hist_window( $wb['gov_debt_wb'][ $iso3 ] ?? array(), $year - SERI_HIST_VOL_WINDOW_YEARS, $year );
        $ts       = blomstra_sanitize_timeseries( $debt_win, 4, 2 );
        if ( ! empty( $ts ) ) {
            $cagr = blomstra_compute_cagr( $ts );
            if ( $cagr !== null ) {
                $year_keys = array_keys( $ts );
                $row['debt_trajectory']              = $cagr;
                $row['debt_trajectory_oldest_year']  = $year_keys[0];
                $row['debt_trajectory_newest_year']  = $year_keys[ count( $year_keys ) - 1 ];
                $row['debt_trajectory_span']         = end( $year_keys ) - $year_keys[0];
                $row['debt_trajectory_observations'] = count( $year_keys );
                $row['debt_trajectory_quality']      = count( $year_keys ) >= 4 ? 'good' : 'limited';
                blomstra_track_source( $sources, $iso3, 'debt_trajectory', 'WB_WDI_derived', 'central_gov' );
            } else {
                $row['debt_trajectory']         = null;
                $row['debt_trajectory_quality'] = 'invalid';
            }
        } else {
            $row['debt_trajectory']         = null;
            $row['debt_trajectory_quality'] = 'insufficient_data';
        }

        $rows[ $iso3 ] = $row;
    }

    return array( 'rows' => $rows, 'sources' => $sources );
}

// ─── DQI / vintage (shared by live and historical builds) ─────────────────
//
// SERI has never disclosed data freshness the way SIVI's DQI does. Adding
// it only to historical rows would leave the live seri_composite_index and
// the historical snapshot rows in two different shapes — exactly the bug
// class SIVI hit before its v3.3.0 fix (see the shared changelog). So this
// is computed the same way for both live and historical builds.

function seri_get_dqi_max_lags() {
    // Max acceptable data age in years per pillar before DQI reaches 0.
    return array(
        'governance' => 3,
        'macro'      => 3,
        'external'   => 4,
        'fiscal'     => 3,
    );
}

/**
 * Representative data year for a pillar = the OLDEST contributing
 * indicator year (conservative). Derived indicators (volatility, debt
 * trajectory) count at their newest observation year.
 */
function seri_pillar_data_year( $row, $pillar ) {
    $years  = array();
    $direct = array(
        'governance' => array( 'rule_of_law_year', 'control_of_corruption_year', 'political_stability_year' ),
        'macro'      => array( 'gni_growth_year', 'inflation_year', 'unemployment_year' ),
        'external'   => array( 'reserve_months_year', 'external_debt_year', 'current_account_year' ),
        'fiscal'     => array( 'gov_debt_year', 'gov_balance_year', 'debt_trajectory_newest_year' ),
    );
    foreach ( $direct[ $pillar ] ?? array() as $k ) {
        if ( isset( $row[ $k ] ) && is_numeric( $row[ $k ] ) && (int) $row[ $k ] > 0 ) {
            $years[] = (int) $row[ $k ];
        }
    }
    if ( $pillar === 'macro' ) {
        foreach ( array( 'gdp_volatility', 'inflation_volatility' ) as $v ) {
            if ( isset( $row[ $v ] ) && is_numeric( $row[ $v ] ) && ! empty( $row[ $v . '_years' ] ) ) {
                $ys = array_map( 'intval', explode( ',', $row[ $v . '_years' ] ) );
                $years[] = max( $ys );
            }
        }
    }
    return empty( $years ) ? null : min( $years );
}

function seri_compute_dqi_fields( $row, $ref_year, $composite_weights ) {
    $out   = array();
    $pd    = array();
    $parts = array();
    foreach ( seri_get_dqi_max_lags() as $p => $lag ) {
        $y                        = seri_pillar_data_year( $row, $p );
        $dqi                      = blomstra_compute_dqi( $y, $ref_year, $lag );
        $out[ 'data_year_' . $p ] = $y;
        $out[ 'dqi_' . $p ]       = $dqi;
        $pd[] = array( 'dqi' => $dqi, 'weight' => $composite_weights[ $p ] ?? 25 );
        if ( $y !== null ) {
            $parts[] = ucfirst( $p ) . ': ' . $y;
        }
    }
    $out['composite_dqi']   = blomstra_compute_composite_dqi( $pd );
    $out['vintage_summary'] = ! empty( $parts ) ? implode( ', ', $parts ) : 'No data';
    return $out;
}

// ─── Canonical snapshot rows (live AND historical) ────────────────────────

function seri_build_snapshot_rows( $country_output ) {
    $snap = array();
    foreach ( $country_output as $iso3 => $data ) {
        $scores = array();
        $dqi    = array();
        foreach ( array( 'governance', 'macro', 'external', 'fiscal' ) as $p ) {
            $scores[ $p ] = $data['pillars'][ $p ]['score'] ?? null;
            $dqi[ $p ]    = $data[ 'dqi_' . $p ] ?? null;
        }
        $snap[ $iso3 ] = blomstra_build_flat_snapshot_row(
            $data['seri_structural'] ?? null,
            $data['rank_display']['best_estimate'] ?? null,
            $data['coverage'] ?? 'full',
            $scores,
            $dqi,
            $data['composite_dqi'] ?? null,
            $data['vintage_summary'] ?? null
        );
    }
    return $snap;
}

// ─── One historical year (fast: no network calls once warmed up) ──────────

function seri_build_historical_snapshot( $year ) {
    if ( ! function_exists( 'blomstra_index_snapshot_save' ) || ! function_exists( 'blomstra_build_flat_snapshot_row' ) ) {
        return array( 'success' => false, 'countries' => 0, 'error' => 'Shared utilities not active (blomstra_index_snapshot_save / blomstra_build_flat_snapshot_row missing).' );
    }
    if ( function_exists( 'set_time_limit' ) ) {
        @set_time_limit( 120 );
    }
    $countries = function_exists( 'blomstra_get_global_country_list' ) ? blomstra_get_global_country_list() : array();
    if ( empty( $countries ) ) {
        return array( 'success' => false, 'countries' => 0, 'error' => 'No country list available.' );
    }

    $data = seri_hist_build_rows( $year, $countries );
    if ( isset( $data['error'] ) ) {
        return array( 'success' => false, 'countries' => 0, 'error' => $data['error'] );
    }

    $result = seri_build_composite( false, 'historical', null, null, array(
        'year'    => (int) $year,
        'rows'    => $data['rows'],
        'sources' => $data['sources'],
    ) );
    if ( isset( $result['error'] ) ) {
        return array( 'success' => false, 'countries' => 0, 'error' => $result['error'] );
    }
    if ( empty( $result['countries'] ) ) {
        return array( 'success' => false, 'countries' => 0, 'error' => 'Scoring produced zero countries for ' . (int) $year . '.' );
    }

    $saved = blomstra_index_snapshot_save( 'seri', seri_build_snapshot_rows( $result['countries'] ), (int) $year . '-01' );
    return array(
        'success'   => $saved > 0,
        'countries' => (int) $saved,
        'excluded'  => count( $result['excluded'] ?? array() ),
        'error'     => $saved > 0 ? null : 'Snapshot save wrote 0 rows.',
    );
}

// ─── Status tracking ──────────────────────────────────────────────────────

function seri_get_backfill_range() {
    $range = blomstra_get_index_backfill_range( 'seri' );
    $start = max( (int) $range['start'], SERI_BACKFILL_MIN_YEAR );
    $end   = max( (int) $range['end'], $start );
    return array( 'start' => $start, 'end' => $end );
}

function seri_get_backfill_status() {
    $range   = seri_get_backfill_range();
    $default = array();
    for ( $y = $range['start']; $y <= $range['end']; $y++ ) {
        $default[ $y ] = array( 'status' => 'not_started', 'countries' => 0, 'last_attempt' => null, 'error' => null );
    }
    $status = get_option( SERI_BACKFILL_STATUS_KEY, $default );
    if ( ! is_array( $status ) ) {
        $status = $default;
    }
    foreach ( $default as $y => $val ) {
        if ( ! isset( $status[ $y ] ) ) {
            $status[ $y ] = $val;
        }
    }
    return $status;
}

function seri_update_backfill_status( $year, $status, $countries = null, $error = null ) {
    $current = seri_get_backfill_status();
    $current[ $year ] = array(
        'status'       => $status,
        'countries'    => $countries !== null ? (int) $countries : ( $current[ $year ]['countries'] ?? 0 ),
        'last_attempt' => current_time( 'mysql' ),
        'error'        => $error,
    );
    update_option( SERI_BACKFILL_STATUS_KEY, $current, false );
}

function seri_backfill_check_completion() {
    $status   = seri_get_backfill_status();
    $range    = seri_get_backfill_range();
    $terminal = array( 'success', 'partial', 'failed' );
    for ( $y = $range['start']; $y <= $range['end']; $y++ ) {
        if ( ! in_array( $status[ $y ]['status'] ?? 'not_started', $terminal, true ) ) {
            return;
        }
    }
    delete_transient( SERI_BACKFILL_LOCK_KEY );
    error_log( 'SERI backfill completed - lock cleared.' );
}

/** Schedule one background job per year in [$start, $end]. */
function seri_backfill_schedule_years( $start, $end ) {
    set_transient( SERI_BACKFILL_LOCK_KEY, time(), 2 * HOUR_IN_SECONDS );
    $i = 0;
    for ( $y = $start; $y <= $end; $y++, $i++ ) {
        // Each job now only reads already-cached data and scores it, so a
        // short, even stagger is enough — no need for the wide spacing a
        // network-bound job would have needed.
        wp_schedule_single_event( time() + 10 + ( $i * 20 ), SERI_BACKFILL_YEAR_HOOK, array( $y ) );
        seri_update_backfill_status( $y, 'scheduled', 0, null );
    }
}

/** Run + record one year. Always invoked via cron — never inline in a page load. */
function seri_run_backfill_year( $year ) {
    $year = (int) $year;
    seri_update_backfill_status( $year, 'running', null, null );

    $done = false;
    register_shutdown_function( function () use ( $year, &$done ) {
        if ( ! $done ) {
            seri_update_backfill_status( $year, 'failed', 0, 'Job terminated before completing (likely a PHP time or memory limit).' );
        }
    } );

    $result = seri_build_historical_snapshot( $year );
    if ( $result['success'] ) {
        if ( $result['countries'] < SERI_HIST_PARTIAL_THRESHOLD ) {
            seri_update_backfill_status( $year, 'partial', $result['countries'], 'Only ' . $result['countries'] . ' countries scored (expected at least ' . SERI_HIST_PARTIAL_THRESHOLD . '). Source coverage for this year is thin.' );
        } else {
            seri_update_backfill_status( $year, 'success', $result['countries'], null );
        }
    } else {
        seri_update_backfill_status( $year, 'failed', 0, $result['error'] ?? 'Unknown error' );
    }
    $done = true;
    return $result;
}

// ─── Cron callback ──────────────────────────────────────────────────────

add_action( SERI_BACKFILL_YEAR_HOOK, 'seri_backfill_year_cron_callback', 10, 1 );
function seri_backfill_year_cron_callback( $year ) {
    if ( ! get_transient( SERI_BACKFILL_LOCK_KEY ) ) {
        error_log( "SERI backfill cron for $year called but lock missing - skipping." );
        return;
    }
    seri_run_backfill_year( (int) $year );
    seri_backfill_check_completion();
}

// ─── Admin: actions (called at the top of seri_render_admin_page) ─────────

function seri_backfill_handle_actions() {
    global $wpdb;

    if ( isset( $_POST['seri_save_backfill_range'] ) && check_admin_referer( 'seri_backfill_range_action', 'seri_backfill_range_nonce' ) ) {
        $start = (int) $_POST['seri_backfill_start'];
        $end   = (int) $_POST['seri_backfill_end'];
        if ( $start >= SERI_BACKFILL_MIN_YEAR && $start <= $end && $end <= (int) current_time( 'Y' ) ) {
            update_option( 'seri_backfill_range_start', $start, false );
            update_option( 'seri_backfill_range_end', $end, false );
            echo '<div class="notice notice-success"><p>✅ SERI backfill range set to ' . $start . '–' . $end . '.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ Invalid range. Start must be ≤ End, at least ' . SERI_BACKFILL_MIN_YEAR . ', and End cannot be in the future.</p></div>';
        }
    }

    if ( isset( $_POST['seri_hist_warm_up'] ) && check_admin_referer( 'seri_hist_warm_up_action', 'seri_hist_warm_up_nonce' ) ) {
        $started = seri_hist_prefetch_start();
        if ( $started ) {
            echo '<div class="notice notice-success"><p>🔄 Warming up reference data in the background — about 15 short steps, ~20 seconds apart. Refresh this page to watch progress.</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p>⚠️ A warm-up is already running.</p></div>';
        }
    }

    if ( isset( $_POST['seri_hist_warm_up_cancel'] ) && check_admin_referer( 'seri_hist_warm_up_cancel_action', 'seri_hist_warm_up_cancel_nonce' ) ) {
        delete_transient( SERI_HIST_PREFETCH_LOCK );
        delete_option( SERI_HIST_PREFETCH_THEN_BACKFILL );
        echo '<div class="notice notice-warning"><p>⏹️ Warm-up cancelled.</p></div>';
    }

    if ( isset( $_POST['seri_hist_clear_and_rewarm'] ) && check_admin_referer( 'seri_hist_clear_and_rewarm_action', 'seri_hist_clear_and_rewarm_nonce' ) ) {
        delete_transient( SERI_HIST_PREFETCH_LOCK );
        delete_option( SERI_HIST_PREFETCH_THEN_BACKFILL );
        $end   = (int) current_time( 'Y' );
        $start = SERI_BACKFILL_MIN_YEAR - SERI_HIST_LOOKBACK_YEARS - SERI_HIST_VOL_WINDOW_YEARS;
        foreach ( seri_hist_prefetch_items() as $item ) {
            delete_option( $item['storage_key'] );
            if ( $item['type'] === 'wb' ) {
                // v5.3.0 recovery: also bust the SHARED World Bank range-fetch
                // cache for this exact (code, source, range) — it still holds
                // the collapsed, pre-fix data from earlier runs (1-week TTL),
                // keyed the same way blomstra_fetch_wb_historical_batch()
                // computes its own cache key.
                $shared_key = 'blomstra_wb_historical_' . md5( $item['code'] . '|' . (string) $item['source'] . '|' . $start . '|' . $end );
                delete_transient( $shared_key );
                delete_transient( $shared_key . '_tmp' );
            }
        }
        delete_option( SERI_BACKFILL_STATUS_KEY );
        echo '<div class="notice notice-warning"><p>🧹 Cleared all cached reference series for SERI\'s backfill, including the shared World Bank cache entries affected by the mrnev/date-range bug, and reset backfill status. Click "Warm Up Reference Data" to refetch with the fix applied.</p></div>';
    }

    if ( isset( $_POST['seri_backfill_all'] ) && check_admin_referer( 'seri_backfill_all_action', 'seri_backfill_all_nonce' ) ) {
        if ( get_transient( SERI_BACKFILL_LOCK_KEY ) !== false ) {
            echo '<div class="notice notice-warning"><p>⚠️ Backfill is already running. Use "Cancel and clear lock" if it is stuck.</p></div>';
        } else {
            $range  = seri_get_backfill_range();
            $status = seri_hist_prefetch_status();
            if ( $status['ready'] ) {
                seri_backfill_schedule_years( $range['start'], $range['end'] );
                echo '<div class="notice notice-success"><p>✅ Backfill scheduled for ' . $range['start'] . '–' . $range['end'] . ' (' . ( $range['end'] - $range['start'] + 1 ) . ' background jobs, ~20 seconds apart). Refresh this page to follow progress.</p></div>';
            } else {
                $started = seri_hist_prefetch_start( $range );
                if ( $started ) {
                    echo '<div class="notice notice-info"><p>🔄 Reference data isn\'t warmed up yet (' . $status['cached'] . ' of ' . $status['total'] . ' series cached) — warming it up first, then the ' . $range['start'] . '–' . $range['end'] . ' backfill will start automatically. Refresh this page to follow progress.</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>⚠️ Warm-up is already running — the backfill will start automatically once it finishes.</p></div>';
                    update_option( SERI_HIST_PREFETCH_THEN_BACKFILL, $range, false );
                }
            }
        }
    }

    if ( isset( $_POST['seri_backfill_year'] ) && check_admin_referer( 'seri_backfill_year_action', 'seri_backfill_year_nonce' ) ) {
        $year  = (int) $_POST['seri_backfill_year'];
        $range = seri_get_backfill_range();
        if ( $year < $range['start'] || $year > $range['end'] ) {
            echo '<div class="notice notice-error"><p>Invalid year. Must be between ' . $range['start'] . ' and ' . $range['end'] . '.</p></div>';
        } else {
            // v5.2.0: always scheduled in the background — never run inline
            // during the admin page load (that was why the page went blank).
            set_transient( SERI_BACKFILL_LOCK_KEY, time(), 2 * HOUR_IN_SECONDS );
            wp_schedule_single_event( time() + 5, SERI_BACKFILL_YEAR_HOOK, array( $year ) );
            seri_update_backfill_status( $year, 'scheduled', 0, null );
            echo '<div class="notice notice-success"><p>✅ ' . $year . ' queued for background processing. Refresh this page in a few seconds to see the result.</p></div>';
        }
    }

    if ( isset( $_POST['seri_backfill_cancel'] ) && check_admin_referer( 'seri_backfill_cancel_action', 'seri_backfill_cancel_nonce' ) ) {
        $range = seri_get_backfill_range();
        for ( $y = $range['start']; $y <= $range['end']; $y++ ) {
            wp_clear_scheduled_hook( SERI_BACKFILL_YEAR_HOOK, array( $y ) );
        }
        delete_transient( SERI_BACKFILL_LOCK_KEY );
        $status = seri_get_backfill_status();
        foreach ( $status as $y => $st ) {
            if ( is_array( $st ) && in_array( $st['status'] ?? '', array( 'scheduled', 'running' ), true ) ) {
                seri_update_backfill_status( (int) $y, 'not_started', 0, 'Cancelled by admin.' );
            }
        }
        echo '<div class="notice notice-warning"><p>⏹️ Pending backfill jobs cancelled and lock cleared.</p></div>';
    }

    if ( isset( $_POST['seri_purge_history'] ) && check_admin_referer( 'seri_purge_history_action', 'seri_purge_history_nonce' ) ) {
        $deleted = $wpdb->delete( $wpdb->prefix . 'blomstra_index_history', array( 'index_slug' => 'seri' ), array( '%s' ) );
        delete_option( SERI_BACKFILL_STATUS_KEY );
        echo '<div class="notice notice-warning"><p>🗑️ Deleted ' . (int) $deleted . ' SERI snapshot rows and reset backfill status. Rebuild the index, then run the backfill.</p></div>';
    }
}

// ─── Admin: panel (called inside seri_render_admin_page) ──────────────────

function seri_render_backfill_box() {
    $range         = seri_get_backfill_range();
    $status        = seri_get_backfill_status();
    $is_running    = ( get_transient( SERI_BACKFILL_LOCK_KEY ) !== false );
    $prefetch      = seri_hist_prefetch_status();
    $warming_up    = ( get_transient( SERI_HIST_PREFETCH_LOCK ) !== false );

    echo '<div class="postbox" style="border-left:4px solid #9b51e0; background:#fff;">';
    echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-backup"></span> 📅 Historical Backfill</h2></div>';
    echo '<div class="inside">';
    echo '<p style="color:#666;">Builds one snapshot per year using the <strong>current</strong> SERI methodology (v' . esc_html( SERI_VERSION ) . '), from the same World Bank / IMF reference data used by the rest of this plugin. Values are the latest observation at or before each year (World Bank/IMF revise their series, so this is a reconstruction using today\'s published numbers, not what SERI would have shown at the time). Data older than ' . (int) SERI_HIST_LOOKBACK_YEARS . ' years is not carried forward.</p>';

    // ─── Reference data cache status ───────────────────────────────
    echo '<div style="background:#f7f4fb; border:1px solid #e0d5f0; border-radius:6px; padding:12px 16px; margin-bottom:15px;">';
    echo '<p style="margin:0 0 8px 0;"><strong>Reference data cache:</strong> ' . $prefetch['cached'] . ' of ' . $prefetch['total'] . ' series ready' . ( $prefetch['ready'] ? ' ✅' : '' ) . '.';
    if ( $warming_up ) {
        echo ' <span style="color:#2271b1;">⏳ Warming up now — refresh to watch progress.</span>';
    }
    echo '</p>';
    if ( ! $prefetch['ready'] ) {
        echo '<details style="margin-bottom:8px;"><summary style="cursor:pointer; color:#2271b1;">Show series status</summary><ul style="margin:6px 0 0 20px;">';
        foreach ( $prefetch['rows'] as $row ) {
            echo '<li>' . ( $row['cached'] ? '✅' : '⬜' ) . ' ' . esc_html( $row['label'] ) . ( $row['cached'] ? ' (' . (int) $row['countries'] . ' countries)' : '' ) . '</li>';
        }
        echo '</ul></details>';
    }
    echo '<div style="display:flex; gap:10px;">';
    if ( ! $warming_up ) {
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field( 'seri_hist_warm_up_action', 'seri_hist_warm_up_nonce' );
        echo '<input type="submit" name="seri_hist_warm_up" class="button button-secondary" value="🔄 Warm Up Reference Data">';
        echo '</form>';
    } else {
        echo '<form method="post" style="display:inline;">';
        wp_nonce_field( 'seri_hist_warm_up_cancel_action', 'seri_hist_warm_up_cancel_nonce' );
        echo '<input type="submit" name="seri_hist_warm_up_cancel" class="button button-secondary" value="⏹️ Cancel Warm-Up">';
        echo '</form>';
    }
    echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'This clears all cached World Bank/IMF series for SERI\\\'s backfill (including the shared World Bank cache entries affected by the mrnev/date-range bug) and resets backfill status. Continue?\');">';
    wp_nonce_field( 'seri_hist_clear_and_rewarm_action', 'seri_hist_clear_and_rewarm_nonce' );
    echo '<input type="submit" name="seri_hist_clear_and_rewarm" class="button button-secondary" value="🧹 Clear Cached Series &amp; Reset">';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '<form method="post" style="margin-bottom:12px;">';
    wp_nonce_field( 'seri_backfill_range_action', 'seri_backfill_range_nonce' );
    echo '<div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap;">';
    echo '<div><label>Start Year: <input type="number" name="seri_backfill_start" value="' . esc_attr( $range['start'] ) . '" min="' . SERI_BACKFILL_MIN_YEAR . '" max="2100"></label></div>';
    echo '<div><label>End Year: <input type="number" name="seri_backfill_end" value="' . esc_attr( $range['end'] ) . '" min="' . SERI_BACKFILL_MIN_YEAR . '" max="2100"></label></div>';
    echo '<div><button type="submit" name="seri_save_backfill_range" class="button button-secondary">💾 Save Range</button></div>';
    echo '</div></form>';

    $colors = array( 'success' => '#2e7d32', 'partial' => '#f0ad4e', 'failed' => '#d63638', 'scheduled' => '#2271b1', 'running' => '#2271b1' );
    echo '<table class="widefat striped"><thead><tr><th>Year</th><th>Status</th><th>Countries</th><th>Last Attempt</th><th>Message</th><th>Action</th></tr></thead><tbody>';
    for ( $y = $range['start']; $y <= $range['end']; $y++ ) {
        $st    = $status[ $y ];
        $color = $colors[ $st['status'] ] ?? '#999';
        echo '<tr>';
        echo '<td><strong>' . esc_html( $y ) . '</strong></td>';
        echo '<td style="color:' . $color . ';">' . esc_html( ucfirst( str_replace( '_', ' ', $st['status'] ) ) ) . '</td>';
        echo '<td>' . esc_html( $st['countries'] ) . '</td>';
        echo '<td>' . ( $st['last_attempt'] ? esc_html( $st['last_attempt'] ) : '—' ) . '</td>';
        echo '<td>' . ( $st['error'] ? esc_html( $st['error'] ) : '—' ) . '</td>';
        echo '<td>';
        if ( in_array( $st['status'], array( 'failed', 'partial', 'not_started', 'success' ), true ) ) {
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'seri_backfill_year_action', 'seri_backfill_year_nonce' );
            echo '<input type="hidden" name="seri_backfill_year" value="' . esc_attr( $y ) . '">';
            echo '<input type="submit" class="button button-small" value="' . ( $st['status'] === 'success' ? 'Rebuild' : 'Run / Retry' ) . '">';
            echo '</form>';
        } else {
            echo '—';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:15px;">';
    if ( ! $is_running ) {
        echo '<form method="post" onsubmit="return confirm(\'Schedule backfill for ' . (int) $range['start'] . '-' . (int) $range['end'] . '?\');">';
        wp_nonce_field( 'seri_backfill_all_action', 'seri_backfill_all_nonce' );
        echo '<input type="submit" name="seri_backfill_all" class="button button-primary" value="📦 Backfill All Years (' . (int) $range['start'] . '–' . (int) $range['end'] . ')">';
        echo '</form>';
    } else {
        echo '<p style="color:#2271b1; margin:0;">⏳ Backfill is running in the background. Refresh to see progress.</p>';
    }
    echo '<form method="post">';
    wp_nonce_field( 'seri_backfill_cancel_action', 'seri_backfill_cancel_nonce' );
    echo '<input type="submit" name="seri_backfill_cancel" class="button button-secondary" value="⏹️ Cancel and clear lock">';
    echo '</form>';
    echo '<form method="post" onsubmit="return confirm(\'Delete ALL SERI snapshot history (including pre-5.0.0 snapshots built under the old polarity)? This cannot be undone.\');">';
    wp_nonce_field( 'seri_purge_history_action', 'seri_purge_history_nonce' );
    echo '<input type="submit" name="seri_purge_history" class="button button-secondary" style="background:#d63638; color:#fff; border-color:#d63638;" value="🗑️ Purge ALL SERI snapshot history">';
    echo '</form>';
    echo '</div>';

    echo '<p style="color:#666; font-size:12px; margin-top:8px;">Every button here queues a background job and returns immediately — nothing runs inline on this page anymore, so clicking a button will never leave the page blank. Refresh to see progress. DQI and vintage in each snapshot reflect the data year actually used per pillar (oldest contributing indicator).</p>';
    echo '</div></div>';
}
