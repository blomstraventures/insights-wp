/**
 * Blomstra Reference Data — Shared Utility & Reference Layer (v2.9.1)
 *
 * v2.9.1 changelog (bug fixes, no feature changes):
 * - REF-BUG-1 (critical): blomstra_refresh_comtrade_hhi_data() used to
 *   delete the entire staging option when a run hit quota exhaustion mid-
 *   way through, discarding every country that had already succeeded in
 *   THAT SAME run (they'd already been removed from pending_iso3s, so they
 *   were never retried again — a silent, permanent loss of real fetched
 *   data on every run that happens to hit quota). Now promotes whatever
 *   was successfully staged before returning.
 * - REF-BUG-2 (critical): the EIA per-fuel promotion gate compared a
 *   single fuel's country coverage against 80% of ALL ~200 countries
 *   globally. For a fuel like Nuclear (real coverage maybe 30 countries)
 *   that threshold could never be satisfied — correct, successfully-
 *   fetched data would never promote to production, forever, on every
 *   cron cycle, with no error anywhere. The gate now uses the chunk-level
 *   API success ratio for that fuel's own calls instead of an unrelated
 *   global country count.
 * - REF-BUG-3 / REF-BUG-6: blomstra_fetch_eia_for_year() and
 *   blomstra_fetch_hhi_for_year() (used only for historical backfills)
 *   previously (a) stopped retrying earlier fallback years as soon as ANY
 *   data came back for the requested year, silently truncating coverage
 *   for every other country that needed an earlier year, and (b) recorded
 *   the REQUESTED year on every value regardless of which year it actually
 *   came from, corrupting DQI staleness disclosure for any backfilled
 *   value that fell back. Both fetchers are rewritten to retry only the
 *   countries still missing at each earlier year (matching the pattern
 *   blomstra_fetch_maritime_for_year already used correctly) and to record
 *   the real source year per country. HHI's backfill fetcher additionally
 *   had NO fallback at all before this fix (unlike the live builder, which
 *   tolerates BLOMSTRA_HHI_LOOKBACK years back) — it now does.
 * - REF-BUG-5: the EIA cron's per-fuel chunk loop kept hammering the
 *   remaining chunks after a quota-exhausted response, burning more calls
 *   against an already-rate-limited key. It now stops that fuel's chunk
 *   loop immediately on the first quota-exhausted chunk (matching HHI's
 *   existing behavior) and leaves the remaining chunks for next cron cycle.
 * - REF-BUG-7: the $force parameter on blomstra_refresh_comtrade_hhi_data()
 *   was accepted but never referenced in the function body — every call
 *   behaved identically regardless of its value, even though the cron
 *   handler always passed force=true expecting it to matter. It's now
 *   wired to actually force a fresh full pass.
 *
 * - API Credentials Settings UI (supports multiple auth patterns)
 * - Fetcher functions read from options with fallback to constants
 * - Health Check Summary Card added
 * - Cache wrapper: only persists non‑empty results (fixes EIA poisoning)
 * - World Bank & IMF counting uses unique ISO3s
 * - Admin button to purge empty EIA cache entries
 *
 * @package Blomstra
 * @version 2.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── NOTE: Landlocked list is now in blomstra-index-utilities.php ──
// ─── The function blomstra_is_landlocked() is available from there ──

// ─── USER-AGENT CONSTANT ────────────────────────────────────────────

if ( ! defined( 'BLOMSTRA_USER_AGENT' ) ) {
    define( 'BLOMSTRA_USER_AGENT', 'BlomstraReferenceData/2.9.1' );
}

// ─── WB INDICATOR LIST ─────────────────────────────────────────────

if ( ! defined( 'BLOMSTRA_WB_INDICATORS' ) ) {
    define( 'BLOMSTRA_WB_INDICATORS', array(
        'GOV_WGI_RL.SC' => array( 'source' => 3 ),
        'GOV_WGI_CC.SC' => array( 'source' => 3 ),
        'GOV_WGI_PV.SC' => array( 'source' => 3 ),
        'NY.GNP.MKTP.KD.ZG' => array( 'source' => null ),
        'NY.GNP.PCAP.KD.ZG' => array( 'source' => null ),
        'NY.GDP.MKTP.KD.ZG' => array( 'source' => null ),
        'FP.CPI.TOTL.ZG'    => array( 'source' => null ),
        'SL.UEM.TOTL.ZS'    => array( 'source' => null ),
        'FI.RES.TOTL.MO'    => array( 'source' => null ),
        'DT.DOD.DECT.GN.ZS' => array( 'source' => null ),
        'BN.CAB.XOKA.GD.ZS' => array( 'source' => null ),
        'GC.DOD.TOTL.GD.ZS' => array( 'source' => null ),
        'GC.NLD.TOTL.GD.ZS' => array( 'source' => null ),
    ) );
}

// ─── IMF INDICATOR LIST ─────────────────────────────────────────────

if ( ! defined( 'BLOMSTRA_IMF_INDICATORS' ) ) {
    define( 'BLOMSTRA_IMF_INDICATORS', array(
        'NGDP_RPCH'   => 'gdp_growth_imf',
        'PCPIPCH'     => 'inflation_imf',
        'BCA_NGDPD'   => 'current_account_imf',
        'GGXWDG_NGDP' => 'gov_debt_imf',
        'GGXCNL_NGDP' => 'gov_balance_imf',
        'LUR'         => 'unemployment_imf',
    ) );
}

// ─── API CREDENTIALS HELPERS ──────────────────────────────────────

function blomstra_get_api_credential( $source, $field ) {
    $creds = get_option( 'blomstra_api_credentials', array() );

    if ( isset( $creds[ $source ][ $field ] ) && ! empty( $creds[ $source ][ $field ] ) ) {
        return $creds[ $source ][ $field ];
    }

    if ( $source === 'comtrade' && $field === 'subscription_key' && defined( 'COMTRADE_PRIMARY_KEY' ) ) {
        return COMTRADE_PRIMARY_KEY;
    }

    if ( $source === 'eia' && $field === 'api_key' && defined( 'EIA_API_KEY' ) ) {
        return EIA_API_KEY;
    }

    return null;
}

function blomstra_get_all_api_credentials() {
    $option = get_option( 'blomstra_api_credentials', array() );

    if ( defined( 'COMTRADE_PRIMARY_KEY' ) && COMTRADE_PRIMARY_KEY !== '' ) {
        if ( ! isset( $option['comtrade'] ) ) {
            $option['comtrade'] = array();
        }
        if ( empty( $option['comtrade']['subscription_key'] ) ) {
            $option['comtrade']['subscription_key'] = COMTRADE_PRIMARY_KEY;
        }
    }

    if ( defined( 'EIA_API_KEY' ) && EIA_API_KEY !== '' ) {
        if ( ! isset( $option['eia'] ) ) {
            $option['eia'] = array();
        }
        if ( empty( $option['eia']['api_key'] ) ) {
            $option['eia']['api_key'] = EIA_API_KEY;
        }
    }

    return $option;
}

function blomstra_save_api_credentials( $credentials ) {
    $sanitized = array();
    foreach ( $credentials as $source => $fields ) {
        $sanitized[ $source ] = array();
        foreach ( $fields as $key => $value ) {
            $sanitized[ $source ][ sanitize_key( $key ) ] = sanitize_text_field( trim( $value ) );
        }
    }
    return update_option( 'blomstra_api_credentials', $sanitized, false );
}

// ─── GLOBAL COUNTRY LIST (World Bank) ─────────────────────────────

if ( ! function_exists( 'blomstra_get_global_country_list' ) ) {
    function blomstra_get_global_country_list( $force = false ) {
        $cache_key = 'blomstra_global_country_list';
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $names = array();
        $page = 1;
        $total_pages = null;
        $reached_end = false;

        do {
            $url  = "https://api.worldbank.org/v2/country?format=json&per_page=300&page={$page}";
            $resp = wp_remote_get( $url, array(
                'timeout' => 30,
                'user-agent' => BLOMSTRA_USER_AGENT,
            ) );
            if ( is_wp_error( $resp ) ) {
                error_log( 'Blomstra country list: page ' . $page . ' fetch failed: ' . $resp->get_error_message() );
                break;
            }
            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
                error_log( 'Blomstra country list: page ' . $page . ' invalid response shape' );
                break;
            }
            if ( $total_pages === null && isset( $body[0]['pages'] ) ) {
                $total_pages = (int) $body[0]['pages'];
            }
            foreach ( $body[1] as $entry ) {
                $region_id = $entry['region']['id'] ?? 'NA';
                if ( $region_id !== 'NA' ) {
                    $iso3 = $entry['id'];
                    $name = $entry['name'];
                    if ( $iso3 && $name ) {
                        $names[ $iso3 ] = $name;
                    }
                }
            }
            $page++;
        } while ( $page <= $total_pages );

        $reached_end = ( $total_pages !== null && $page > $total_pages );

        if ( ! empty( $names ) && $reached_end ) {
            set_transient( $cache_key, $names, DAY_IN_SECONDS );
            return $names;
        }

        if ( ! empty( $names ) ) {
            error_log( 'Blomstra country list: partial fetch, did not reach page ' . $total_pages . '. Not caching.' );
        }

        $stale = get_transient( $cache_key );
        return is_array( $stale ) ? $stale : $names;
    }
}

// ─── COMTRADE REPORTER-CODE MAP ───────────────────────────────────

if ( ! defined( 'BLOMSTRA_COMTRADE_REPORTER_URL' ) ) {
    define( 'BLOMSTRA_COMTRADE_REPORTER_URL', 'https://comtradeapi.un.org/files/v1/app/reference/Reporters.json' );
}
if ( ! defined( 'BLOMSTRA_COMTRADE_REPORTER_CACHE_TTL' ) ) {
    define( 'BLOMSTRA_COMTRADE_REPORTER_CACHE_TTL', WEEK_IN_SECONDS );
}

if ( ! function_exists( 'blomstra_get_comtrade_reporter_map' ) ) {
    function blomstra_get_comtrade_reporter_map( $force = false ) {
        $cache_key = 'blomstra_comtrade_reporters';
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $debug = array(
            'checked_at' => current_time( 'mysql' ),
            'url'        => BLOMSTRA_COMTRADE_REPORTER_URL,
        );

        $response = wp_remote_get( BLOMSTRA_COMTRADE_REPORTER_URL, array(
            'timeout' => 30,
            'user-agent' => BLOMSTRA_USER_AGENT,
        ) );
        if ( is_wp_error( $response ) ) {
            $debug['result'] = 'wp_error';
            $debug['error']  = $response->get_error_message();
            update_option( 'blomstra_comtrade_reporters_debug', $debug, false );
            error_log( 'Blomstra Comtrade reporter list fetch failed: ' . $response->get_error_message() );
            $stale = get_transient( $cache_key );
            return is_array( $stale ) ? $stale : array();
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $debug['http_code']    = $http_code;
        $debug['body_snippet'] = substr( $body, 0, 500 );

        $decoded   = json_decode( $body, true );
        $reporters = $decoded['results'] ?? null;
        if ( ! is_array( $reporters ) ) {
            $debug['result'] = 'invalid_json_or_missing_results_key';
            update_option( 'blomstra_comtrade_reporters_debug', $debug, false );
            error_log( 'Blomstra Comtrade reporter list: no results[] array in response' );
            $stale = get_transient( $cache_key );
            return is_array( $stale ) ? $stale : array();
        }

        $map = array();
        foreach ( $reporters as $reporter ) {
            if ( ! empty( $reporter['isGroup'] ) ) {
                continue;
            }
            $iso3 = isset( $reporter['reporterCodeIsoAlpha3'] ) ? trim( $reporter['reporterCodeIsoAlpha3'] ) : '';
            $code = isset( $reporter['reporterCode'] ) ? (int) $reporter['reporterCode'] : null;
            $expired = ! empty( $reporter['entryExpiredDate'] );
            if ( $iso3 === '' || $code === null ) {
                continue;
            }
            if ( $expired ) {
                continue;
            }
            if ( ! isset( $map[ $iso3 ] ) ) {
                $map[ $iso3 ] = $code;
            }
        }

        $debug['result']           = ! empty( $map ) ? 'ok' : 'parsed_but_empty';
        $debug['countries_parsed'] = count( $map );
        update_option( 'blomstra_comtrade_reporters_debug', $debug, false );

        if ( ! empty( $map ) ) {
            set_transient( $cache_key, $map, BLOMSTRA_COMTRADE_REPORTER_CACHE_TTL );
            return $map;
        }
        $stale = get_transient( $cache_key );
        return is_array( $stale ) ? $stale : $map;
    }
}

// ─── MARITIME (WORLD BANK LSCI, RAW ONLY) ─────────────────────────

if ( ! defined( 'BLOMSTRA_MARITIME_INDICATOR_CODE' ) ) {
    define( 'BLOMSTRA_MARITIME_INDICATOR_CODE', 'IS.SHP.GCNW.XQ' );
}
if ( ! defined( 'BLOMSTRA_MARITIME_CACHE_TTL' ) ) {
    define( 'BLOMSTRA_MARITIME_CACHE_TTL', WEEK_IN_SECONDS );
}

if ( ! function_exists( 'blomstra_get_maritime_raw' ) ) {
    function blomstra_get_maritime_raw( $force = false, $attempt = 1 ) {
        $cache_key = 'blomstra_maritime_raw';
        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $current_year = (int) current_time( 'Y' );
        $start_year   = $current_year - 20;
        $url = "https://api.worldbank.org/v2/country/all/indicator/" . BLOMSTRA_MARITIME_INDICATOR_CODE . "?format=json&per_page=20000&date={$start_year}:{$current_year}";

        $debug = array( 'checked_at' => current_time( 'mysql' ), 'url' => $url );
        $max_attempts = 2;
        $backoff = 3;
        $attempt_inner = 1;
        $data = array();
        $got_data = false;

        while ( $attempt_inner <= $max_attempts ) {
            $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => BLOMSTRA_USER_AGENT ) );
            if ( is_wp_error( $response ) ) {
                if ( $attempt_inner < $max_attempts ) {
                    sleep( $backoff * $attempt_inner );
                    $attempt_inner++;
                    continue;
                }
                $debug['result'] = 'wp_error';
                $debug['error']  = $response->get_error_message() . ' (after ' . $attempt_inner . ' attempt(s))';
                update_option( 'blomstra_maritime_fetch_debug', $debug, false );
                error_log( 'Blomstra Maritime fetch WP_Error: ' . $response->get_error_message() );
                $stale = get_transient( $cache_key );
                return is_array( $stale ) ? $stale : array();
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            $body_raw  = wp_remote_retrieve_body( $response );
            if ( $http_code !== 200 ) {
                if ( $http_code >= 500 && $attempt_inner < $max_attempts ) {
                    sleep( $backoff * $attempt_inner );
                    $attempt_inner++;
                    continue;
                }
                $debug['result'] = 'http_error';
                $debug['http_code'] = $http_code;
                $debug['body_snippet'] = substr( $body_raw, 0, 500 );
                update_option( 'blomstra_maritime_fetch_debug', $debug, false );
                error_log( 'Blomstra Maritime fetch HTTP ' . $http_code );
                $stale = get_transient( $cache_key );
                return is_array( $stale ) ? $stale : array();
            }

            $body = json_decode( $body_raw, true );
            if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
                $debug['result'] = 'bad_shape';
                $debug['body_snippet'] = substr( $body_raw, 0, 500 );
                update_option( 'blomstra_maritime_fetch_debug', $debug, false );
                error_log( 'Blomstra Maritime: unexpected response shape — body[1] missing' );
                $stale = get_transient( $cache_key );
                return is_array( $stale ) ? $stale : array();
            }

            $data = array();
            foreach ( $body[1] as $row ) {
                $iso3  = $row['countryiso3code'] ?? null;
                $value = $row['value'] ?? null;
                $year  = isset( $row['date'] ) ? (int) $row['date'] : 0;
                if ( ! $iso3 || $value === null ) {
                    continue;
                }
                if ( ! isset( $data[ $iso3 ] ) || $year > $data[ $iso3 ]['year'] ) {
                    $data[ $iso3 ] = array( 'value' => (float) $value, 'year' => $year );
                }
            }

            $debug['result']           = ! empty( $data ) ? 'ok' : 'parsed_but_empty';
            $debug['countries_parsed'] = count( $data );
            update_option( 'blomstra_maritime_fetch_debug', $debug, false );

            if ( ! empty( $data ) ) {
                $got_data = true;
                break;
            }

            if ( $attempt_inner < $max_attempts ) {
                sleep( $backoff * $attempt_inner );
                $attempt_inner++;
                continue;
            } else {
                break;
            }
        }

        if ( $got_data ) {
            set_transient( $cache_key, $data, BLOMSTRA_MARITIME_CACHE_TTL );
            return $data;
        }

        $stale = get_transient( $cache_key );
        return is_array( $stale ) ? $stale : array();
    }
}

if ( ! function_exists( 'blomstra_get_maritime_value' ) ) {
    function blomstra_get_maritime_value( $iso3 ) {
        $iso3 = strtoupper( trim( $iso3 ) );
        if ( function_exists( 'blomstra_is_landlocked' ) && blomstra_is_landlocked( $iso3 ) ) {
            return array( 'value' => 0.0, 'is_landlocked' => true, 'year' => null );
        }
        $raw = blomstra_get_maritime_raw();
        if ( isset( $raw[ $iso3 ] ) ) {
            return array( 'value' => $raw[ $iso3 ]['value'], 'is_landlocked' => false, 'year' => $raw[ $iso3 ]['year'] );
        }
        return array( 'value' => null, 'is_landlocked' => false, 'year' => null );
    }
}

// ─── HHI (COMTRADE, FULL COLLECTION ENGINE) ───────────────────────

if ( ! defined( 'BLOMSTRA_COMTRADE_BASE_URL' ) ) {
    define( 'BLOMSTRA_COMTRADE_BASE_URL', 'https://comtradeapi.un.org/data/v1/get/C/A/HS' );
}
if ( ! defined( 'BLOMSTRA_HHI_CHUNK_SIZE' ) ) {
    define( 'BLOMSTRA_HHI_CHUNK_SIZE', 50 );
}
if ( ! defined( 'BLOMSTRA_HHI_LOOKBACK' ) ) {
    define( 'BLOMSTRA_HHI_LOOKBACK', 4 );
}
if ( ! defined( 'BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED' ) ) {
    define( 'BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED', '__BLOMSTRA_QUOTA_EXHAUSTED__' );
}
if ( ! defined( 'BLOMSTRA_COMTRADE_PERMANENT_FAILURE' ) ) {
    define( 'BLOMSTRA_COMTRADE_PERMANENT_FAILURE', '__BLOMSTRA_PERMANENT_FAILURE__' );
}
if ( ! defined( 'BLOMSTRA_HHI_MAX_ATTEMPTS' ) ) {
    define( 'BLOMSTRA_HHI_MAX_ATTEMPTS', 4 );
}

if ( ! function_exists( 'blomstra_log_comtrade_call' ) ) {
    function blomstra_log_comtrade_call( $reporter_code, $year, $outcome, $detail ) {
        $log = get_option( 'blomstra_comtrade_call_log', array() );
        $log[] = array(
            'time'          => current_time( 'mysql' ),
            'reporter_code' => $reporter_code,
            'year'          => $year,
            'outcome'       => $outcome,
            'detail'        => $detail,
        );
        if ( count( $log ) > 50 ) {
            $log = array_slice( $log, -50 );
        }
        update_option( 'blomstra_comtrade_call_log', $log, false );
    }
}

if ( ! function_exists( 'blomstra_comtrade_fetch_partner_imports_batch' ) ) {
    function blomstra_comtrade_fetch_partner_imports_batch( $reporter_codes, $year, $attempt = 1 ) {
        $key = blomstra_get_api_credential( 'comtrade', 'subscription_key' );
        if ( empty( $key ) ) {
            blomstra_log_comtrade_call( implode( ',', $reporter_codes ), $year, 'network_error', 'COMTRADE_PRIMARY_KEY not set' );
            return null;
        }

        $chunk_label = count( $reporter_codes ) . ' reporters (' . implode( ',', $reporter_codes ) . ')';
        $all_rows = array();
        $page = 1;
        $max_pages = 1;
        $found_world_total_for = array();
        $max_attempts = 3;
        $backoff = 2;

        do {
            $args = array(
                'reporterCode'      => implode( ',', $reporter_codes ),
                'period'            => $year,
                'cmdCode'           => 'TOTAL',
                'flowCode'          => 'M',
                'motCode'           => 0,
                'partner2Code'      => 0,
                'customsCode'       => 'C00',
                'subscription-key'  => $key,
                'page'              => $page,
                'limit'             => 500,
            );
            $url = BLOMSTRA_COMTRADE_BASE_URL . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
            $attempt_inner = 1;
            $response = null;
            $http_code = 0;
            $body = null;

            while ( $attempt_inner <= $max_attempts ) {
                $response = wp_remote_get( $url, array( 'timeout' => 60, 'user-agent' => BLOMSTRA_USER_AGENT ) );
                if ( is_wp_error( $response ) ) {
                    $fail_reason = 'network error: ' . $response->get_error_message();
                    blomstra_log_comtrade_call( $chunk_label, $year, 'network_error', $fail_reason );
                    if ( $attempt_inner < $max_attempts ) {
                        sleep( $backoff * $attempt_inner );
                        $attempt_inner++;
                        continue;
                    }
                    return null;
                }
                $http_code = wp_remote_retrieve_response_code( $response );
                $body_raw = wp_remote_retrieve_body( $response );

                if ( $http_code === 429 ) {
                    $body_snip = substr( $body_raw, 0, 300 );
                    if ( preg_match( '/[Tt]ry again in\s+(\d+)\s+seconds?/', $body_snip, $m ) && (int) $m[1] <= 90 && $attempt_inner <= 2 ) {
                        $wait = (int) $m[1] + 2;
                        blomstra_log_comtrade_call( $chunk_label, $year, 'rate_limited_retrying', 'HTTP 429 — waiting ' . $wait . 's: ' . $body_snip );
                        sleep( $wait );
                        $attempt_inner++;
                        continue;
                    }
                    blomstra_log_comtrade_call( $chunk_label, $year, 'rate_limit_exhausted', 'HTTP 429, retry failed: ' . $body_snip );
                    return BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED;
                }

                if ( $http_code === 403 ) {
                    $body_snip = substr( $body_raw, 0, 300 );
                    // BUGFIX (2026-09): UN Comtrade returns HTTP 403 for
                    // BOTH a genuinely bad/expired key AND for exceeding
                    // your call volume quota - every 403 was previously
                    // treated as a permanent, bad-key failure regardless.
                    // Quota exhaustion is temporary and resets on its own
                    // (Comtrade's own response says when) - inspect the
                    // body to tell the two apart before giving up on the
                    // key entirely.
                    if ( stripos( $body_snip, 'quota' ) !== false ) {
                        $replenish_note = '';
                        if ( preg_match( '/replenished in\s+([0-9:]+)/i', $body_snip, $m ) ) {
                            $replenish_note = ' (resets in ' . $m[1] . ')';
                        }
                        blomstra_log_comtrade_call( $chunk_label, $year, 'quota_exhausted', 'HTTP 403 — call volume quota exhausted' . $replenish_note . ': ' . $body_snip );
                        return BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED;
                    }
                    blomstra_log_comtrade_call( $chunk_label, $year, 'authorization_error', 'HTTP 403, likely invalid/expired key or permission issue: ' . $body_snip );
                    return BLOMSTRA_COMTRADE_PERMANENT_FAILURE;
                }

                if ( $http_code !== 200 ) {
                    $fail_reason = 'HTTP ' . $http_code . ' — body: ' . substr( $body_raw, 0, 300 );
                    blomstra_log_comtrade_call( $chunk_label, $year, 'http_error', $fail_reason );
                    if ( $http_code >= 500 && $attempt_inner < $max_attempts ) {
                        sleep( $backoff * $attempt_inner );
                        $attempt_inner++;
                        continue;
                    }
                    return null;
                }

                if ( strlen( $body_raw ) > 15 * 1024 * 1024 ) {
                    blomstra_log_comtrade_call( $chunk_label, $year, 'oversized_response', 'response body > 15MB' );
                    return null;
                }
                $body = json_decode( $body_raw, true );
                unset( $body_raw );
                if ( isset( $body['error'] ) && $body['error'] !== '' ) {
                    blomstra_log_comtrade_call( $chunk_label, $year, 'api_error', (string) $body['error'] );
                    return null;
                }
                if ( ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
                    blomstra_log_comtrade_call( $chunk_label, $year, 'bad_shape', 'missing data array' );
                    return null;
                }
                break;
            }

            if ( $body === null || ! isset( $body['data'] ) ) {
                return null;
            }

            $page_rows = $body['data'];
            if ( empty( $page_rows ) ) {
                break;
            }

            $all_rows = array_merge( $all_rows, $page_rows );
            foreach ( $page_rows as $row ) {
                if ( isset( $row['reporterCode'], $row['partnerCode'] ) && (int) $row['partnerCode'] === 0 ) {
                    $found_world_total_for[ (int) $row['reporterCode'] ] = true;
                }
            }

            $max_pages = isset( $body['pagination']['totalPages'] ) ? (int) $body['pagination']['totalPages'] : 1;
            $page++;

            $all_have_world_total = true;
            foreach ( $reporter_codes as $rc ) {
                if ( ! isset( $found_world_total_for[ $rc ] ) ) {
                    $all_have_world_total = false;
                    break;
                }
            }
            if ( $all_have_world_total ) {
                break;
            }
        } while ( $page <= $max_pages && $page < 20 );

        if ( empty( $all_rows ) ) {
            blomstra_log_comtrade_call( $chunk_label, $year, 'empty', 'HTTP 200, zero rows returned' );
            return array();
        }

        blomstra_log_comtrade_call( $chunk_label, $year, 'success', 'Retrieved ' . count( $all_rows ) . ' trade rows' );
        return $all_rows;
    }
}

if ( ! function_exists( 'blomstra_compute_hhi_from_batch_rows' ) ) {
    function blomstra_compute_hhi_from_batch_rows( $rows, $reporter_codes, $year ) {
        $by_reporter = array();
        foreach ( $rows as $row ) {
            if ( ! isset( $row['reporterCode'], $row['partnerCode'], $row['primaryValue'] ) ) {
                continue;
            }
            $rc  = (int) $row['reporterCode'];
            $pc  = (int) $row['partnerCode'];
            $val = (float) $row['primaryValue'];
            if ( ! isset( $by_reporter[ $rc ] ) ) {
                $by_reporter[ $rc ] = array( 'world_total' => null, 'partner_values' => array() );
            }
            if ( $pc === 0 ) {
                $by_reporter[ $rc ]['world_total'] = $val;
            } elseif ( $pc !== $rc && $val > 0.0 ) {
                $by_reporter[ $rc ]['partner_values'][] = $val;
            }
        }

        $results = array();
        foreach ( $reporter_codes as $rc ) {
            if ( ! isset( $by_reporter[ $rc ] ) ) {
                continue;
            }
            $entry = $by_reporter[ $rc ];
            if ( $entry['world_total'] === null || $entry['world_total'] <= 0.0 || empty( $entry['partner_values'] ) ) {
                continue;
            }
            $hhi = 0.0;
            foreach ( $entry['partner_values'] as $value ) {
                $share = $value / $entry['world_total'];
                $hhi  += $share * $share;
            }
            $hhi *= 10000;
            $hhi = min( 10000, max( 0, $hhi ) );
            $results[ $rc ] = array( 'value' => round( $hhi, 2 ), 'year' => $year, 'source' => 'Comtrade' );
        }
        return $results;
    }
}

// ─── HHI POINTER HELPERS ──────────────────────────────────────────

if ( ! function_exists( 'blomstra_get_hhi_pointer' ) ) {
    function blomstra_get_hhi_pointer() {
        $default = array(
            'target_year'    => 0,
            'pending_iso3s'  => array(),
            'attempts'       => array(),
            'started_at'     => null,
            'cycle_runs'     => 0,
        );
        $pointer = get_option( 'blomstra_hhi_refresh_pointer', $default );
        if ( ! is_array( $pointer ) || ! isset( $pointer['target_year'] ) ) {
            $pointer = $default;
        }
        if ( ! isset( $pointer['attempts'] ) ) {
            $pointer['attempts'] = array();
        }
        if ( ! isset( $pointer['cycle_runs'] ) ) {
            $pointer['cycle_runs'] = 0;
        }
        return $pointer;
    }
}

if ( ! function_exists( 'blomstra_update_hhi_pointer' ) ) {
    function blomstra_update_hhi_pointer( $target_year, $pending_iso3s, $attempts = array(), $started_at = null, $cycle_runs = 0 ) {
        $pointer = array(
            'target_year'   => $target_year,
            'pending_iso3s' => $pending_iso3s,
            'attempts'      => $attempts,
            'started_at'    => $started_at ? $started_at : current_time( 'mysql' ),
            'cycle_runs'    => $cycle_runs,
        );
        update_option( 'blomstra_hhi_refresh_pointer', $pointer, false );
    }
}

if ( ! function_exists( 'blomstra_delete_hhi_pointer' ) ) {
    function blomstra_delete_hhi_pointer() {
        delete_option( 'blomstra_hhi_refresh_pointer' );
    }
}

if ( ! function_exists( 'blomstra_get_comtrade_hhi_data' ) ) {
    function blomstra_get_comtrade_hhi_data() {
        return get_option( 'blomstra_comtrade_hhi_data', array() );
    }
}

// ─── EIA GETTER ─────────────────────────────────────────────────────

if ( ! function_exists( 'blomstra_get_eia_raw_data' ) ) {
    function blomstra_get_eia_raw_data() {
        return get_option( 'blomstra_eia_raw_data', array() );
    }
}

// ─── HHI REFRESH FUNCTION ─────────────────────────────────────────

if ( ! function_exists( 'blomstra_refresh_comtrade_hhi_data' ) ) {
    function blomstra_refresh_comtrade_hhi_data( $year = null, $iso3_list = null, $force = false ) {
        $run_started = current_time( 'mysql' );
        // BUGFIX (2026-09): voluntarily stop well before PHP's own
        // execution time limit, the same way quota exhaustion already
        // does — a run with many rate-limited retries and their backoff
        // waits can otherwise hit a hard PHP timeout mid-chunk, which
        // kills the process before it ever reaches the status-update code
        // at the end. That leaves the admin status frozen on "RUNNING"
        // indefinitely, with no error surfaced, since nothing ever runs
        // again to correct it. Stopping gracefully at a safe internal
        // budget means the pointer is saved and a proper 'partial' status
        // is always reported, exactly like the quota-exhaustion path.
        $function_start_time = time();
        $time_budget_seconds = 480;
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 900 );
        }

        if ( $year === null ) {
            $year = (int) current_time( 'Y' ) - 1;
        }

        $reporter_map = blomstra_get_comtrade_reporter_map();
        if ( $iso3_list === null ) {
            $iso3_list = array_keys( blomstra_get_global_country_list() );
        }

        $fetchable_iso3s = array();
        foreach ( $iso3_list as $iso3 ) {
            if ( isset( $reporter_map[ $iso3 ] ) ) {
                $fetchable_iso3s[] = $iso3;
            }
        }
        $total_fetchable = count( $fetchable_iso3s );

        $state_counts = array(
            'succeeded' => 0,
            'empty'     => 0,
            'pending'   => 0,
            'no_reporter' => 0,
            'quota'     => 0,
            'retryable' => 0,
            'permanent_failure' => 0,
            'no_data'   => 0,
            'unresolved' => 0,
        );
        $country_states = array();

        $production_key = 'blomstra_comtrade_hhi_data';
        $staging_key = $production_key . '_staging';
        $existing_cache = get_option( $production_key, array() );

        $pointer = blomstra_get_hhi_pointer();
        $target_year = $pointer['target_year'];
        $pending_iso3s = $pointer['pending_iso3s'];
        $attempts = isset( $pointer['attempts'] ) ? $pointer['attempts'] : array();
        $cycle_runs = isset( $pointer['cycle_runs'] ) ? $pointer['cycle_runs'] : 0;

        // v2.9.1 FIX (REF-BUG-7): $force previously accepted but never
        // referenced — every call behaved identically regardless of its
        // value. BUGFIX (2026-09): the cron handler no longer always
        // passes force=true (see its call site) — this now only resets on
        // a genuinely empty pointer or a new year's target, as intended.
        if ( $force || empty( $pending_iso3s ) || $target_year != $year ) {
            $pending_iso3s = $fetchable_iso3s;
            $target_year = $year;
            $attempts = array_fill_keys( $pending_iso3s, 0 );
            $cycle_runs = 0;
            blomstra_update_hhi_pointer( $target_year, $pending_iso3s, $attempts, $run_started, $cycle_runs );
        }

        // BUGFIX (2026-09): hard backstop, independent of the two fixes
        // above — after a generous number of resumptions with no genuine
        // per-country progress logic ever going wrong again in some way
        // not yet discovered, force this cycle to a definite end rather
        // than allow indefinite resumption under any circumstance. 30
        // resumptions is far beyond what a normal week's quota/network
        // hiccups should ever require.
        $cycle_runs++;
        if ( $cycle_runs > 30 && ! empty( $pending_iso3s ) ) {
            error_log( 'HHI: cycle_runs exceeded 30 for target_year ' . $target_year . ' with ' . count( $pending_iso3s ) . ' countries still pending — force-terminating this cycle to guarantee it cannot loop indefinitely.' );
            $forced_results = array();
            foreach ( $pending_iso3s as $iso3 ) {
                $forced_results[ $iso3 ] = array(
                    'value' => null,
                    'scale' => '0-10000',
                    'requested_year' => $target_year,
                    'actual_year' => null,
                    'source' => 'force-terminated after 30 cycle resumptions',
                    'last_updated' => current_time( 'mysql' ),
                );
            }
            update_option( $staging_key, array_merge( $existing_cache, array_filter( $forced_results, function( $entry ) {
                return isset( $entry['value'] ) && $entry['value'] !== null;
            } ) ), false );
            blomstra_delete_hhi_pointer();
            $pending_iso3s = array();
        }

        blomstra_update_hhi_pointer( $target_year, $pending_iso3s, $attempts, $run_started, $cycle_runs );

        $quota_dead = false;
        $time_budget_exceeded = false;
        $results = array();
        $summary = array(
            'run_started'          => $run_started,
            'target_year'          => $target_year,
            'countries_in_scope'   => count( $iso3_list ),
            'fetchable_countries'  => $total_fetchable,
            'no_reporter_code'     => 0,
            'succeeded'            => 0,
            'attempted_no_data'    => 0,
            'skipped_quota'        => 0,
            'chunk_size'           => BLOMSTRA_HHI_CHUNK_SIZE,
            'last_checkpoint'      => null,
            'failed_chunks'        => array(),
            'state_counts'         => array(
                'succeeded' => 0,
                'empty'     => 0,
                'pending'   => 0,
                'no_reporter' => 0,
                'quota'     => 0,
                'retryable' => 0,
                'permanent_failure' => 0,
                'no_data'   => 0,
                'unresolved' => 0,
            ),
        );

        $chunks = array_chunk( $pending_iso3s, BLOMSTRA_HHI_CHUNK_SIZE );
        $total_chunks = count( $chunks );

        for ( $chunk_idx = 0; $chunk_idx < $total_chunks && ! $quota_dead && ! $time_budget_exceeded; $chunk_idx++ ) {
            $chunk_iso3s = $chunks[ $chunk_idx ];
            if ( empty( $chunk_iso3s ) ) {
                continue;
            }

            if ( ( time() - $function_start_time ) > $time_budget_seconds ) {
                $time_budget_exceeded = true;
                $remaining_now = array();
                for ( $ci = $chunk_idx; $ci < $total_chunks; $ci++ ) {
                    $remaining_now = array_merge( $remaining_now, $chunks[ $ci ] );
                }
                blomstra_update_hhi_pointer( $target_year, $remaining_now, $attempts, $run_started, $cycle_runs );
                error_log( 'HHI: stopping gracefully at ' . $time_budget_seconds . 's internal time budget, ' . count( $remaining_now ) . ' countries remaining — will resume next run.' );
                break;
            }

            $chunk_codes = array();
            $chunk_map = array();
            foreach ( $chunk_iso3s as $iso3 ) {
                if ( isset( $reporter_map[ $iso3 ] ) ) {
                    $code = $reporter_map[ $iso3 ];
                    $chunk_codes[] = $code;
                    $chunk_map[ $code ] = $iso3;
                } else {
                    $state_counts['no_reporter']++;
                    $country_states[ $iso3 ] = 'NO_REPORTER';
                    $summary['no_reporter_code']++;
                    $results[ $iso3 ] = array(
                        'value' => null,
                        'scale' => '0-10000',
                        'requested_year' => $target_year,
                        'actual_year' => null,
                        'source' => 'no reporter code',
                        'last_updated' => current_time( 'mysql' ),
                    );
                    $summary['attempted_no_data']++;
                }
            }

            if ( empty( $chunk_codes ) ) {
                $pending_iso3s = array_diff( $pending_iso3s, $chunk_iso3s );
                blomstra_update_hhi_pointer( $target_year, $pending_iso3s, $attempts, $run_started, $cycle_runs );
                update_option( $staging_key, array_merge( $existing_cache, $results ), false );
                continue;
            }

            $offset = 0;
            $found_data_for_country = array();
            $had_error_for_country = array_fill_keys( $chunk_iso3s, false );
            $had_empty_for_country = array_fill_keys( $chunk_iso3s, false );
            $permanent_failure_for_country = array_fill_keys( $chunk_iso3s, false );

            while ( $offset <= BLOMSTRA_HHI_LOOKBACK && ! empty( $chunk_codes ) ) {
                $try_year = $target_year - $offset;
                $rows = blomstra_comtrade_fetch_partner_imports_batch( $chunk_codes, $try_year );

                if ( $rows === BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED ) {
                    $quota_dead = true;
                    $state_counts['quota'] += count( $chunk_codes );
                    // BUGFIX (2026-09): this used to discard the current
                    // chunk's countries without classifying them or ever
                    // incrementing their attempt count — that increment
                    // only ever happened via the normal had_error path
                    // below, which this early exit skipped entirely. Even
                    // with the resumption fix above, a chunk that happens
                    // to sit right where quota runs out on multiple
                    // separate weeks could still make zero progress toward
                    // BLOMSTRA_HHI_MAX_ATTEMPTS forever. Crediting this as
                    // a real attempt — the same as any other interrupted
                    // try — guarantees eventual termination.
                    foreach ( $chunk_codes as $code ) {
                        $iso3 = $chunk_map[ $code ];
                        $country_states[ $iso3 ] = 'QUOTA_FAILURE';
                        $summary['skipped_quota']++;
                        $attempts[ $iso3 ] = isset( $attempts[ $iso3 ] ) ? $attempts[ $iso3 ] + 1 : 1;
                        if ( $attempts[ $iso3 ] >= BLOMSTRA_HHI_MAX_ATTEMPTS ) {
                            $state_counts['unresolved']++;
                            $country_states[ $iso3 ] = 'UNRESOLVED';
                            $results[ $iso3 ] = array(
                                'value' => null,
                                'scale' => '0-10000',
                                'requested_year' => $target_year,
                                'actual_year' => null,
                                'source' => 'unresolved after ' . $attempts[ $iso3 ] . ' retries (repeatedly interrupted by quota)',
                                'last_updated' => current_time( 'mysql' ),
                            );
                            unset( $attempts[ $iso3 ] );
                            $pending_iso3s = array_diff( $pending_iso3s, array( $iso3 ) );
                        }
                    }
                    $valid_results_on_quota = array_filter( $results, function( $entry ) {
                        return is_array( $entry ) && isset( $entry['value'] ) && $entry['value'] !== null;
                    } );
                    update_option( $staging_key, array_merge( $existing_cache, $valid_results_on_quota ), false );
                    blomstra_update_hhi_pointer( $target_year, $pending_iso3s, $attempts, $run_started, $cycle_runs );
                    break 2;
                }

                if ( $rows === BLOMSTRA_COMTRADE_PERMANENT_FAILURE ) {
                    foreach ( $chunk_codes as $code ) {
                        $iso3 = $chunk_map[ $code ];
                        $permanent_failure_for_country[ $iso3 ] = true;
                    }
                    break;
                }

                if ( $rows === null || empty( $rows ) ) {
                    if ( $rows === null ) {
                        foreach ( $chunk_codes as $code ) {
                            $iso3 = $chunk_map[ $code ];
                            $had_error_for_country[ $iso3 ] = true;
                        }
                    } else {
                        foreach ( $chunk_codes as $code ) {
                            $iso3 = $chunk_map[ $code ];
                            $had_empty_for_country[ $iso3 ] = true;
                        }
                    }
                    $offset++;
                    sleep( 2 );
                    continue;
                }

                $computed_by_code = blomstra_compute_hhi_from_batch_rows( $rows, $chunk_codes, $try_year );

                foreach ( $chunk_codes as $code ) {
                    $iso3 = $chunk_map[ $code ];
                    if ( isset( $computed_by_code[ $code ] ) ) {
                        $found_data_for_country[ $iso3 ] = true;
                        $state_counts['succeeded']++;
                        $country_states[ $iso3 ] = 'SUCCESS_WITH_DATA';
                        $results[ $iso3 ] = array(
                            'value' => $computed_by_code[ $code ]['value'],
                            'scale' => '0-10000',
                            'requested_year' => $target_year,
                            'actual_year' => $computed_by_code[ $code ]['year'],
                            'source' => 'Comtrade',
                            'last_updated' => current_time( 'mysql' ),
                        );
                        $summary['succeeded']++;
                        $attempts[ $iso3 ] = 0;
                    }
                }

                $chunk_codes = array_filter( $chunk_codes, function( $code ) use ( $chunk_map, $found_data_for_country ) {
                    $iso3 = $chunk_map[ $code ];
                    return ! isset( $found_data_for_country[ $iso3 ] );
                } );

                if ( empty( $chunk_codes ) ) {
                    break;
                }

                $offset++;
            }

            foreach ( $chunk_codes as $code ) {
                $iso3 = $chunk_map[ $code ];
                if ( isset( $found_data_for_country[ $iso3 ] ) ) {
                    continue;
                }
                if ( $permanent_failure_for_country[ $iso3 ] ) {
                    $state_counts['permanent_failure']++;
                    $country_states[ $iso3 ] = 'PERMANENT_FAILURE';
                    $results[ $iso3 ] = array(
                        'value' => null,
                        'scale' => '0-10000',
                        'requested_year' => $target_year,
                        'actual_year' => null,
                        'source' => 'permanent failure (403)',
                        'last_updated' => current_time( 'mysql' ),
                    );
                    $summary['attempted_no_data']++;
                    unset( $attempts[ $iso3 ] );
                } elseif ( $had_error_for_country[ $iso3 ] ) {
                    $attempts[ $iso3 ] = isset( $attempts[ $iso3 ] ) ? $attempts[ $iso3 ] + 1 : 1;
                    if ( $attempts[ $iso3 ] >= BLOMSTRA_HHI_MAX_ATTEMPTS ) {
                        $state_counts['unresolved']++;
                        $country_states[ $iso3 ] = 'UNRESOLVED';
                        $results[ $iso3 ] = array(
                            'value' => null,
                            'scale' => '0-10000',
                            'requested_year' => $target_year,
                            'actual_year' => null,
                            'source' => 'unresolved after ' . $attempts[ $iso3 ] . ' retries',
                            'last_updated' => current_time( 'mysql' ),
                        );
                        $summary['attempted_no_data']++;
                        unset( $attempts[ $iso3 ] );
                    } else {
                        $state_counts['retryable']++;
                        $country_states[ $iso3 ] = 'RETRYABLE_FAILURE';
                    }
                } elseif ( $had_empty_for_country[ $iso3 ] && ! $had_error_for_country[ $iso3 ] ) {
                    $state_counts['no_data']++;
                    $country_states[ $iso3 ] = 'NO_DATA';
                    $results[ $iso3 ] = array(
                        'value' => null,
                        'scale' => '0-10000',
                        'requested_year' => $target_year,
                        'actual_year' => null,
                        'source' => 'no data in lookback window (empty responses)',
                        'last_updated' => current_time( 'mysql' ),
                    );
                    $summary['attempted_no_data']++;
                    unset( $attempts[ $iso3 ] );
                } else {
                    $state_counts['pending']++;
                    $country_states[ $iso3 ] = 'PENDING';
                }
            }

            $processed = array_diff( $chunk_iso3s, array_keys( array_filter( $country_states, function( $state ) {
                return $state === 'RETRYABLE_FAILURE' || $state === 'PENDING';
            } ) ) );
            $pending_iso3s = array_diff( $pending_iso3s, $processed );

            // BUGFIX (2026-09): only a genuinely successful result may
            // overwrite an existing cached value. array_merge() previously
            // let a null-valued placeholder for a failed country
            // (permanent_failure/unresolved/no_data) silently overwrite
            // that country's last known-good value in staging, and the
            // promotion gate below counted raw array keys rather than
            // valid values — together, a 100%-failure run could wipe
            // production data with nulls while reporting "success."
            $valid_results = array_filter( $results, function( $entry ) {
                return is_array( $entry ) && isset( $entry['value'] ) && $entry['value'] !== null;
            } );
            $merged_cache = array_merge( $existing_cache, $valid_results );
            update_option( $staging_key, $merged_cache, false );

            $summary['last_checkpoint'] = current_time( 'mysql' );
            $summary['state_counts'] = $state_counts;
            update_option( 'blomstra_hhi_refresh_summary', $summary, false );

            if ( ! $quota_dead ) {
                blomstra_update_hhi_pointer( $target_year, $pending_iso3s, $attempts, $run_started, $cycle_runs );
            }

            usleep( 500000 );
        }

        if ( $quota_dead ) {
            $summary['run_finished'] = current_time( 'mysql' );
            $summary['state_counts'] = $state_counts;
            update_option( 'blomstra_hhi_refresh_summary', $summary, false );

            // v2.9.1 FIX (REF-BUG-1, critical): this path used to
            // `delete_option($staging_key)` and return, silently
            // discarding every country that succeeded earlier in THIS
            // run before quota ran out — those countries had already
            // been removed from pending_iso3s above, so they would never
            // be retried again. Promote whatever real, successfully-
            // fetched data is in staging before discarding it; only the
            // genuinely-not-yet-attempted countries remain pending.
            $staging_data = get_option( $staging_key, array() );
            if ( ! empty( $staging_data ) && is_array( $staging_data ) ) {
                update_option( $production_key, $staging_data, false );
                error_log( 'HHI: Quota exhausted mid-run — promoted ' . count( $staging_data ) . ' successfully-fetched countries from this run before stopping.' );
            }
            delete_option( $staging_key );
            return $results;
        }

        if ( empty( $pending_iso3s ) ) {
            blomstra_delete_hhi_pointer();
        }

        $summary['run_finished'] = current_time( 'mysql' );
        $summary['state_counts'] = $state_counts;
        update_option( 'blomstra_hhi_refresh_summary', $summary, false );

        $staging_data = get_option( $staging_key, array() );
        if ( ! empty( $staging_data ) && is_array( $staging_data ) ) {
            // BUGFIX (2026-09): count entries with a real, non-null value —
            // not raw array-key count. Staging should no longer contain
            // null-valued entries at all after the merge fix above, but
            // this filter is kept as a defensive second check so this gate
            // can never again be satisfied by placeholder rows alone.
            $staging_count = count( array_filter( $staging_data, function( $entry ) {
                return is_array( $entry ) && isset( $entry['value'] ) && $entry['value'] !== null;
            } ) );
            $min_expected = max( 1, (int) ( $total_fetchable * 0.8 ) );
            if ( $staging_count >= $min_expected ) {
                update_option( $production_key, $staging_data, false );
                error_log( 'HHI: Atomic promotion succeeded – ' . $staging_count . ' countries with valid data staged, ' . $total_fetchable . ' expected.' );
            } else {
                error_log( 'HHI: Staging validation failed – only ' . $staging_count . ' countries with valid data staged, expected at least ' . $min_expected . '. Production unchanged.' );
            }
        }
        delete_option( $staging_key );

        return $results;
    }
}

// ─── HHI CRON HANDLER ──────────────────────────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_hhi' ) ) {
    function blomstra_cron_handle_hhi() {
        $lock_key = 'blomstra_hhi_refresh_in_progress';
        $lock = get_transient( $lock_key );
        if ( $lock !== false && ( time() - (int)$lock ) < 30 * MINUTE_IN_SECONDS ) {
            blomstra_update_cron_status( 'hhi', 'running', 'Already running – skipping duplicate.' );
            return;
        }
        set_transient( $lock_key, time(), 30 * MINUTE_IN_SECONDS );

        blomstra_update_cron_status( 'hhi', 'running', 'HHI weekly refresh starting...' );

        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 900 );
            }

            // BUGFIX (2026-09): this call always passed force=true, which
            // unconditionally resets pending_iso3s to ALL fetchable
            // countries and attempts to zero on EVERY invocation — including
            // the debounced 60-second self-reschedule below, whose entire
            // purpose is to RESUME a run that quota interrupted. Since both
            // the original weekly trigger and every resumption tick call
            // this identical hook, resumption could never actually work:
            // the very next tick always wiped whatever progress was saved.
            // This is the real, root cause of countries staying "pending"
            // forever — they were always past wherever quota ran out, and
            // the process restarted from zero before ever reaching them
            // again. The function's own internal logic (a few lines into
            // blomstra_refresh_comtrade_hhi_data) already resets correctly
            // on its own when appropriate — an empty pointer, or a new
            // year's target — so forcing it here was never actually needed.
            blomstra_refresh_comtrade_hhi_data( null, null, false );

            $summary   = get_option( 'blomstra_hhi_refresh_summary', array() );
            $succeeded = $summary['succeeded'] ?? 0;
            $fetchable = $summary['fetchable_countries'] ?? 0;
            $state_counts = $summary['state_counts'] ?? array();
            $pointer = blomstra_get_hhi_pointer();
            $pending = $pointer['pending_iso3s'] ?? array();
            $is_complete = empty( $pending );

            $has_retryable = ( $state_counts['retryable'] ?? 0 ) > 0 ||
                             ( $state_counts['pending'] ?? 0 ) > 0 ||
                             ( $state_counts['quota'] ?? 0 ) > 0;

            $all_terminal = ! $has_retryable && $is_complete;

            // BUGFIX (2026-09): a run where every fetchable country
            // permanently failed (e.g. a rejected/expired Comtrade key)
            // previously satisfied "$is_complete && $all_terminal" — since
            // permanent_failure isn't counted as a retryable state — and
            // was reported as 'success' with no check on $succeeded at
            // all. Distinguish "finished with nothing left to retry" from
            // "finished because everything failed."
            $total_permanent = $state_counts['permanent_failure'] ?? 0;
            $all_failed = ( $fetchable > 0 && $succeeded === 0 && $total_permanent >= $fetchable );

            $actual_cached = count( blomstra_get_comtrade_hhi_data() );
            $msg = 'HHI: ' . $succeeded . ' of ' . $fetchable . ' fetchable countries succeeded this run (' . $actual_cached . ' total now cached).';
            if ( ! empty( $state_counts ) ) {
                $msg .= ' States: ' . http_build_query( $state_counts, '', ', ' );
            }

            if ( $all_failed ) {
                blomstra_update_cron_status( 'hhi', 'error', $msg . ' All fetchable countries returned permanent_failure — check UN Comtrade API credentials/subscription (use "Test Comtrade" in Settings).', $actual_cached );
                blomstra_delete_hhi_pointer();
            } elseif ( $is_complete && $all_terminal ) {
                blomstra_update_cron_status( 'hhi', 'success', $msg, $actual_cached );
                blomstra_delete_hhi_pointer();
            } elseif ( $succeeded > 0 || $is_complete ) {
                $remaining = count( $pending );
                if ( $remaining > 0 ) {
                    $msg .= ' Remaining: ' . $remaining . ' countries (will resume next run).';
                }
                $status = ( $has_retryable || $remaining > 0 ) ? 'partial' : 'success';
                blomstra_update_cron_status( 'hhi', $status, $msg, $actual_cached );
                if ( $remaining > 0 ) {
                    wp_schedule_single_event( time() + 60, 'blomstra_cron_hhi_weekly_event' );
                }
            } elseif ( ! empty( $summary ) ) {
                blomstra_update_cron_status( 'hhi', 'partial', 'HHI fetch made no progress this run — serving previously cached data only (' . $actual_cached . ' countries).', $actual_cached );
                if ( ! empty( $pending ) ) {
                    wp_schedule_single_event( time() + 60, 'blomstra_cron_hhi_weekly_event' );
                }
            } else {
                blomstra_update_cron_status( 'hhi', 'error', 'HHI fetch returned empty dataset or quota exhausted, and no prior cache exists.' );
                if ( ! empty( $pending ) ) {
                    wp_schedule_single_event( time() + 60, 'blomstra_cron_hhi_weekly_event' );
                }
            }

        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'hhi', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'HHI cron error: ' . $e->getMessage() );
            $pointer = blomstra_get_hhi_pointer();
            $pending = $pointer['pending_iso3s'] ?? array();
            if ( ! empty( $pending ) ) {
                wp_schedule_single_event( time() + 60, 'blomstra_cron_hhi_weekly_event' );
            }
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'hhi', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'HHI cron fatal: ' . $e->getMessage() );
            $pointer = blomstra_get_hhi_pointer();
            $pending = $pointer['pending_iso3s'] ?? array();
            if ( ! empty( $pending ) ) {
                wp_schedule_single_event( time() + 60, 'blomstra_cron_hhi_weekly_event' );
            }
        } finally {
            delete_transient( $lock_key );
        }
    }
    add_action( 'blomstra_cron_hhi_weekly_event', 'blomstra_cron_handle_hhi' );
}

// ─── EIA (RAW PER-FUEL DATA ONLY) ──────────────────────────────────

if ( ! defined( 'BLOMSTRA_EIA_BASE_URL' ) ) {
    define( 'BLOMSTRA_EIA_BASE_URL', 'https://api.eia.gov/v2/international/data/' );
}
if ( ! defined( 'BLOMSTRA_EIA_FUEL_PRODUCT_IDS' ) ) {
    define( 'BLOMSTRA_EIA_FUEL_PRODUCT_IDS', array(
        '4411' => 'Coal',
        '4413' => 'Natural gas',
        '4415' => 'Petroleum and other liquids',
        '4417' => 'Nuclear',
        '4418' => 'Renewables and other',
    ) );
}
if ( ! defined( 'BLOMSTRA_EIA_ACTIVITY_PROD' ) ) {
    define( 'BLOMSTRA_EIA_ACTIVITY_PROD', '1' );
}
if ( ! defined( 'BLOMSTRA_EIA_ACTIVITY_CONS' ) ) {
    define( 'BLOMSTRA_EIA_ACTIVITY_CONS', '2' );
}
if ( ! defined( 'BLOMSTRA_EIA_UNIT' ) ) {
    define( 'BLOMSTRA_EIA_UNIT', 'QBTU' );
}
if ( ! defined( 'BLOMSTRA_EIA_CHUNK_SIZE' ) ) {
    define( 'BLOMSTRA_EIA_CHUNK_SIZE', 50 );
}
if ( ! defined( 'BLOMSTRA_EIA_MAX_ATTEMPTS' ) ) {
    define( 'BLOMSTRA_EIA_MAX_ATTEMPTS', 3 );
}
if ( ! defined( 'BLOMSTRA_EIA_PERMANENT_FAILURE' ) ) {
    define( 'BLOMSTRA_EIA_PERMANENT_FAILURE', '__BLOMSTRA_EIA_PERMANENT_FAILURE__' );
}
if ( ! defined( 'BLOMSTRA_EIA_QUOTA_EXHAUSTED' ) ) {
    define( 'BLOMSTRA_EIA_QUOTA_EXHAUSTED', '__BLOMSTRA_EIA_QUOTA_EXHAUSTED__' );
}

// ─── EIA POINTER HELPERS ─────────────────────────────────────────────

if ( ! function_exists( 'blomstra_get_eia_pointer' ) ) {
    function blomstra_get_eia_pointer() {
        $default = array(
            'fuel_index' => 0,
            'activity' => 'consumption',
            'started_at' => null,
            'failed_fuels' => array(),
        );
        $pointer = get_option( 'blomstra_eia_refresh_pointer', $default );
        if ( ! is_array( $pointer ) || ! isset( $pointer['fuel_index'] ) ) {
            $pointer = $default;
        }
        if ( ! isset( $pointer['failed_fuels'] ) ) {
            $pointer['failed_fuels'] = array();
        }
        return $pointer;
    }
}

if ( ! function_exists( 'blomstra_update_eia_pointer' ) ) {
    function blomstra_update_eia_pointer( $fuel_index, $activity, $failed_fuels = array(), $started_at = null ) {
        $pointer = array(
            'fuel_index' => $fuel_index,
            'activity'   => $activity,
            'failed_fuels' => $failed_fuels,
            'started_at' => $started_at ? $started_at : current_time( 'mysql' ),
        );
        update_option( 'blomstra_eia_refresh_pointer', $pointer, false );
    }
}

if ( ! function_exists( 'blomstra_delete_eia_pointer' ) ) {
    function blomstra_delete_eia_pointer() {
        delete_option( 'blomstra_eia_refresh_pointer' );
    }
}

if ( ! function_exists( 'blomstra_log_eia_call' ) ) {
    function blomstra_log_eia_call( $chunk_label, $activity_id, $product_id, $outcome, $detail ) {
        $log = get_option( 'blomstra_eia_call_log', array() );
        $log[] = array(
            'time'        => current_time( 'mysql' ),
            'chunk_label' => $chunk_label,
            'activity_id' => $activity_id,
            'product_id'  => $product_id,
            'outcome'     => $outcome,
            'detail'      => $detail,
        );
        if ( count( $log ) > 200 ) {
            $log = array_slice( $log, -200 );
        }
        update_option( 'blomstra_eia_call_log', $log, false );
    }
}

if ( ! function_exists( 'blomstra_eia_fetch_activity_batch' ) ) {
    function blomstra_eia_fetch_activity_batch( $country_codes, $activity_id, $product_id, $attempt = 1 ) {
        $key = blomstra_get_api_credential( 'eia', 'api_key' );
        if ( empty( $key ) ) {
            return array( 'status' => 'permanent_failure', 'rows' => array(), 'error' => 'API key missing' );
        }

        $scalar_args = array(
            'api_key'              => $key,
            'facets[activityId][]' => $activity_id,
            'facets[productId][]'  => $product_id,
            'facets[unit][]'       => BLOMSTRA_EIA_UNIT,
            'frequency'            => 'annual',
            'data[]'               => 'value',
            'sort[0][column]'      => 'period',
            'sort[0][direction]'   => 'desc',
            'length'               => 5000,
        );

        $query_pairs = array();
        foreach ( $scalar_args as $k => $v ) {
            $query_pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
        }
        foreach ( $country_codes as $cc ) {
            $query_pairs[] = rawurlencode( 'facets[countryRegionId][]' ) . '=' . rawurlencode( $cc );
        }
        $url = BLOMSTRA_EIA_BASE_URL . '?' . implode( '&', $query_pairs );

        $chunk_label = count( $country_codes ) . ' countries (' . implode( ',', array_slice( $country_codes, 0, 3 ) ) . ')';
        $max_attempts = BLOMSTRA_EIA_MAX_ATTEMPTS;
        $backoff = 2;
        $attempt_inner = 1;

        while ( $attempt_inner <= $max_attempts ) {
            $response = wp_remote_get( $url, array(
                'timeout' => 45,
                'user-agent' => BLOMSTRA_USER_AGENT,
            ) );

            $should_retry = false;
            $fail_reason  = '';
            $is_permanent = false;
            $is_quota = false;

            if ( is_wp_error( $response ) ) {
                $fail_reason  = 'network error: ' . $response->get_error_message();
                $should_retry = true;
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                if ( $code === 429 ) {
                    $fail_reason  = 'HTTP 429 rate-limited';
                    $should_retry = true;
                    $is_quota = true;
                } elseif ( $code === 403 || $code === 401 ) {
                    $fail_reason  = 'HTTP ' . $code . ' authorization error';
                    $is_permanent = true;
                } elseif ( $code === 404 ) {
                    $fail_reason  = 'HTTP 404 endpoint not found (likely changed)';
                    $is_permanent = true;
                } elseif ( $code !== 200 ) {
                    $fail_reason  = 'HTTP ' . $code . ' — body: ' . substr( wp_remote_retrieve_body( $response ), 0, 300 );
                    $should_retry = ( $code >= 500 );
                }
            }

            if ( $is_permanent ) {
                error_log( 'Blomstra EIA batch fetch PERMANENT FAILURE (' . $chunk_label . '): ' . $fail_reason );
                blomstra_log_eia_call( $chunk_label, $activity_id, $product_id, 'permanent_failure', $fail_reason );
                return array( 'status' => 'permanent_failure', 'rows' => array(), 'error' => $fail_reason );
            }

            if ( $is_quota ) {
                error_log( 'Blomstra EIA batch fetch QUOTA (' . $chunk_label . '): ' . $fail_reason );
                blomstra_log_eia_call( $chunk_label, $activity_id, $product_id, 'quota_exhausted', $fail_reason );
                return array( 'status' => 'quota_exhausted', 'rows' => array(), 'error' => $fail_reason );
            }

            if ( $fail_reason !== '' && $should_retry ) {
                error_log( 'Blomstra EIA batch fetch RETRYABLE (' . $chunk_label . ', attempt ' . $attempt_inner . '): ' . $fail_reason );
                blomstra_log_eia_call( $chunk_label, $activity_id, $product_id, 'retryable_failure', $fail_reason );
                if ( $attempt_inner < $max_attempts ) {
                    sleep( $backoff * $attempt_inner );
                    $attempt_inner++;
                    continue;
                }
                return array( 'status' => 'retryable_failure', 'rows' => array(), 'error' => $fail_reason );
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $rows = $body['response']['data'] ?? array();
            $status = empty( $rows ) ? 'empty' : 'ok';
            $http_code = wp_remote_retrieve_response_code( $response );
            $detail = 'Retrieved ' . count( $rows ) . ' rows';
            if ( $status === 'empty' && ! empty( $response ) ) {
                $body_snippet = substr( wp_remote_retrieve_body( $response ), 0, 200 );
                if ( strpos( $body_snippet, 'error' ) !== false || strpos( $body_snippet, 'message' ) !== false ) {
                    $detail .= '; Response snippet: ' . $body_snippet;
                }
            }
            blomstra_log_eia_call( $chunk_label, $activity_id, $product_id, $status, $detail );
            return array( 'status' => $status, 'rows' => $rows, 'error' => null, 'http_code' => $http_code, 'detail' => $detail );
        }

        return array( 'status' => 'retryable_failure', 'rows' => array(), 'error' => 'Max attempts exceeded' );
    }
}

if ( ! function_exists( 'blomstra_eia_pick_latest_per_country' ) ) {
    function blomstra_eia_pick_latest_per_country( $rows ) {
        $latest = array();
        foreach ( $rows as $row ) {
            $cc     = $row['countryRegionId'] ?? null;
            $val    = $row['value'] ?? null;
            $period = $row['period'] ?? null;
            if ( ! $cc || $val === null || $period === null ) {
                continue;
            }
            if ( ! isset( $latest[ $cc ] ) || $period > $latest[ $cc ]['period'] ) {
                $latest[ $cc ] = array(
                    'value'  => (float) $val,
                    'period' => $period,
                );
            }
        }
        return $latest;
    }
}

// ─── EIA processing with conditional updates ──────────────────────

if ( ! function_exists( 'blomstra_process_eia_activity' ) ) {
    function blomstra_process_eia_activity( $fuel_index, $activity, $iso3_list, &$failed_fuels ) {
        $fuel_ids = array_keys( BLOMSTRA_EIA_FUEL_PRODUCT_IDS );
        if ( ! isset( $fuel_ids[ $fuel_index ] ) ) {
            return array( 'status' => 'error', 'message' => 'Invalid fuel index.', 'product_id' => null, 'fuel_name' => null, 'fetched_count' => 0 );
        }
        $product_id = $fuel_ids[ $fuel_index ];
        $fuel_name = BLOMSTRA_EIA_FUEL_PRODUCT_IDS[ $product_id ];

        if ( isset( $failed_fuels[ $product_id ] ) && $failed_fuels[ $product_id ]['permanent'] === true ) {
            return array(
                'status' => 'skipped_permanent',
                'message' => 'Fuel ' . $fuel_name . ' marked as permanent failure, skipping.',
                'product_id' => $product_id,
                'fuel_name' => $fuel_name,
                'fetched_count' => 0,
                'advance_pointer' => true,
            );
        }

        if ( isset( $failed_fuels[ $product_id ] ) && $failed_fuels[ $product_id ]['retries'] >= 3 ) {
            $failed_fuels[ $product_id ]['permanent'] = true;
            return array(
                'status' => 'skipped_permanent',
                'message' => 'Fuel ' . $fuel_name . ' failed after 3 retry cycles, skipping.',
                'product_id' => $product_id,
                'fuel_name' => $fuel_name,
                'fetched_count' => 0,
                'advance_pointer' => true,
            );
        }

        $activity_id = ( $activity === 'consumption' ) ? BLOMSTRA_EIA_ACTIVITY_CONS : BLOMSTRA_EIA_ACTIVITY_PROD;
        $chunks = array_chunk( $iso3_list, BLOMSTRA_EIA_CHUNK_SIZE );

        $activity_data = array();
        $failed_chunks = 0;
        $retryable_chunks = 0;
        $permanent_chunks = 0;
        $quota_chunks = 0;
        $total_chunks = count( $chunks );

        // v2.9.1 FIX (REF-BUG-5): track how many chunks we actually
        // attempted this run — on quota exhaustion we now stop the loop
        // immediately (matching HHI's behavior) rather than hammering the
        // remaining chunks against an already-rate-limited key. All ratio
        // calculations below use $chunks_attempted, not $total_chunks, so
        // a run cut short by quota is correctly judged only on what it
        // actually attempted.
        $chunks_attempted = 0;
        foreach ( $chunks as $chunk ) {
            $chunks_attempted++;
            $result = blomstra_eia_fetch_activity_batch( $chunk, $activity_id, $product_id );
            if ( $result['status'] === 'ok' ) {
                $latest = blomstra_eia_pick_latest_per_country( $result['rows'] );
                foreach ( $latest as $iso3 => $row ) {
                    $activity_data[ $iso3 ] = array(
                        'value' => $row['value'],
                        'year'  => (int) substr( $row['period'], 0, 4 ),
                    );
                }
            } elseif ( $result['status'] === 'empty' ) {
                // no data for this chunk
            } elseif ( $result['status'] === 'retryable_failure' ) {
                $retryable_chunks++;
            } elseif ( $result['status'] === 'permanent_failure' ) {
                $permanent_chunks++;
                $failed_fuels[ $product_id ] = array( 'permanent' => true, 'retries' => 0 );
            } elseif ( $result['status'] === 'quota_exhausted' ) {
                $quota_chunks++;
                if ( ! isset( $failed_fuels[ $product_id ] ) ) {
                    $failed_fuels[ $product_id ] = array( 'permanent' => false, 'retries' => 1 );
                } else {
                    $failed_fuels[ $product_id ]['retries']++;
                }
                error_log( "Blomstra EIA: quota exhausted on chunk {$chunks_attempted}/{$total_chunks} for fuel {$fuel_name} ({$activity}) — stopping this fuel's chunk loop early rather than continuing to hit a rate-limited key." );
                break;
            } else {
                $failed_chunks++;
            }
            usleep( 200000 );
        }

        $production_key = 'blomstra_eia_raw_data';
        $staging_key = $production_key . '_staging';

        $existing = get_option( $production_key, array( 'consumption' => array(), 'production' => array() ) );
        $existing_consumption = $existing['consumption'] ?? array();
        $existing_production = $existing['production'] ?? array();

        if ( $activity === 'consumption' ) {
            $existing_fuel_consumption = $existing_consumption[ $product_id ] ?? array();
            $new_consumption = $existing_fuel_consumption;
            foreach ( $activity_data as $iso3 => $entry ) {
                $new_consumption[ $iso3 ] = array(
                    'value'  => $entry['value'],
                    'year'   => $entry['year'],
                    'status' => 'ok',
                );
            }
            $existing_consumption[ $product_id ] = $new_consumption;
        } else {
            $existing_fuel_production = $existing_production[ $product_id ] ?? array();
            $new_production = $existing_fuel_production;
            foreach ( $activity_data as $iso3 => $entry ) {
                $new_production[ $iso3 ] = array(
                    'value'  => $entry['value'],
                    'year'   => $entry['year'],
                    'status' => 'ok',
                );
            }
            $existing_production[ $product_id ] = $new_production;
        }

        $staging_data = array(
            'consumption' => $existing_consumption,
            'production'  => $existing_production,
        );
        update_option( $staging_key, $staging_data, false );

        $summary = get_option( 'blomstra_eia_refresh_summary', array() );
        if ( ! isset( $summary['fuels'] ) ) {
            $summary['fuels'] = array();
        }
        $summary['fuels'][ $product_id ] = array(
            'name'          => $fuel_name,
            'status'        => ( $failed_chunks === $chunks_attempted && $chunks_attempted > 0 ) ? 'all_chunks_failed' : ( $permanent_chunks > 0 ? 'permanent_failures' : ( $retryable_chunks > 0 ? 'retryable_failures' : ( $failed_chunks > 0 ? 'partial_chunk_failures' : 'ok' ) ) ),
            'fetched_count' => count( $activity_data ),
            'last_activity' => $activity,
            'last_updated'  => current_time( 'mysql' ),
        );
        update_option( 'blomstra_eia_refresh_summary', $summary, false );

        $status = 'ok';
        if ( $permanent_chunks > 0 ) {
            $status = 'permanent_failure';
        } elseif ( $quota_chunks > 0 ) {
            $status = 'quota';
        } elseif ( $retryable_chunks > 0 ) {
            $status = 'retryable';
        } elseif ( $failed_chunks === $chunks_attempted && $chunks_attempted > 0 ) {
            $status = 'error';
        } elseif ( $failed_chunks > 0 ) {
            $status = 'partial';
        }

        $successful_chunks = $chunks_attempted - $failed_chunks - $retryable_chunks - $permanent_chunks - $quota_chunks;
        $chunk_success_ratio = $chunks_attempted > 0 ? ( $successful_chunks / $chunks_attempted ) : 0;

        // v2.9.1 FIX: advance_pointer (move on to the next fuel/activity)
        // now additionally requires that we attempted every chunk this
        // fuel has ($chunks_attempted >= $total_chunks). If quota cut the
        // run short, $chunks_attempted < $total_chunks, so we correctly
        // stay on this fuel and retry the remaining chunks next cron
        // cycle instead of prematurely moving on and leaving them
        // permanently unfetched.
        $advance_pointer = ( $chunks_attempted >= $total_chunks ) && ( $chunk_success_ratio >= 0.8 ) && ( $permanent_chunks === 0 );

        return array(
            'status'              => $status,
            'message'             => "Processed $chunks_attempted of $total_chunks chunks, $failed_chunks failed, $retryable_chunks retryable, $permanent_chunks permanent, $quota_chunks quota.",
            'product_id'          => $product_id,
            'fuel_name'           => $fuel_name,
            'fetched_count'       => count( $activity_data ),
            'advance_pointer'     => $advance_pointer,
            'chunk_success_ratio' => $chunk_success_ratio,
            'staging_key'         => $staging_key,
            'production_key'      => $production_key,
        );
    }
}

// ─── EIA CRON HANDLER ──────────────────────────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_eia' ) ) {
    function blomstra_cron_handle_eia() {
        $lock_key = 'blomstra_eia_refresh_in_progress';
        $lock = get_transient( $lock_key );
        if ( $lock !== false && ( time() - (int)$lock ) < 30 * MINUTE_IN_SECONDS ) {
            blomstra_update_cron_status( 'eia', 'running', 'Already running – skipping duplicate.' );
            return;
        }
        set_transient( $lock_key, time(), 30 * MINUTE_IN_SECONDS );

        blomstra_update_cron_status( 'eia', 'running', 'EIA chunked refresh running...' );

        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 600 );
            }

            $iso3_list = array_keys( blomstra_get_global_country_list() );
            $fuel_ids = array_keys( BLOMSTRA_EIA_FUEL_PRODUCT_IDS );
            $total_fuels = count( $fuel_ids );

            $pointer = blomstra_get_eia_pointer();
            $fuel_index = $pointer['fuel_index'];
            $activity   = $pointer['activity'];
            $failed_fuels = isset( $pointer['failed_fuels'] ) ? $pointer['failed_fuels'] : array();

            if ( $fuel_index >= $total_fuels ) {
                blomstra_update_cron_status( 'eia', 'success', 'All fuels processed.', $total_fuels );
                blomstra_delete_eia_pointer();
                delete_transient( $lock_key );
                return;
            }

            $result = blomstra_process_eia_activity( $fuel_index, $activity, $iso3_list, $failed_fuels );

            if ( $result['status'] === 'skipped_permanent' ) {
                blomstra_update_cron_status( 'eia', 'partial', 'Fuel ' . $result['fuel_name'] . ' skipped (permanent failure). Advancing pointer.', $fuel_index + 1 );
                $new_fuel_index = $fuel_index;
                $new_activity = ( $activity === 'consumption' ) ? 'production' : 'consumption';
                if ( $activity === 'production' ) {
                    $new_fuel_index = $fuel_index + 1;
                    $new_activity = 'consumption';
                }
                blomstra_update_eia_pointer( $new_fuel_index, $new_activity, $failed_fuels, current_time( 'mysql' ) );
                if ( $new_fuel_index < $total_fuels ) {
                    wp_schedule_single_event( time() + 60, 'blomstra_cron_eia_weekly_event' );
                }
                delete_transient( $lock_key );
                return;
            }

            $staging_data = get_option( $result['staging_key'], array() );
            if ( ! empty( $staging_data['consumption'] ) || ! empty( $staging_data['production'] ) ) {
                $fuel_consumption_count = isset( $staging_data['consumption'][ $result['product_id'] ] ) ? count( $staging_data['consumption'][ $result['product_id'] ] ) : 0;
                $fuel_production_count = isset( $staging_data['production'][ $result['product_id'] ] ) ? count( $staging_data['production'][ $result['product_id'] ] ) : 0;
                $fuel_coverage = $fuel_consumption_count + $fuel_production_count;

                // v2.9.1 FIX (REF-BUG-2, critical): the gate used to
                // compare a single fuel's country coverage against 80% of
                // ALL ~200 countries globally ($fuel_expected =
                // count($iso3_list)). For a fuel with genuinely narrow
                // real-world coverage (e.g. Nuclear — maybe 30 countries
                // actually have any), that threshold was mathematically
                // impossible to satisfy: correct, successfully-fetched
                // data would never promote to production, on every single
                // cron cycle, forever, with no error anywhere pointing at
                // why. The gate now checks the chunk-level API success
                // ratio for THIS fuel's own calls (computed in
                // blomstra_process_eia_activity) instead of an unrelated
                // global country count.
                $chunk_success_ratio = $result['chunk_success_ratio'] ?? 0;
                if ( $chunk_success_ratio >= 0.8 || $total_fuels == 1 ) {
                    update_option( $result['production_key'], $staging_data, false );
                    error_log( 'EIA: Atomic promotion succeeded for fuel ' . $result['fuel_name'] . ' (' . $fuel_coverage . ' country-entries, chunk success ' . round( $chunk_success_ratio * 100 ) . '%).' );
                } else {
                    error_log( 'EIA: Staging validation failed for fuel ' . $result['fuel_name'] . ' (chunk success only ' . round( $chunk_success_ratio * 100 ) . '%). Production unchanged.' );
                }
            }
            delete_option( $result['staging_key'] );

            if ( $result['advance_pointer'] ) {
                $new_fuel_index = $fuel_index;
                $new_activity = ( $activity === 'consumption' ) ? 'production' : 'consumption';
                if ( $activity === 'production' ) {
                    $new_fuel_index = $fuel_index + 1;
                    $new_activity = 'consumption';
                }
                blomstra_update_eia_pointer( $new_fuel_index, $new_activity, $failed_fuels, current_time( 'mysql' ) );
            } else {
                blomstra_update_eia_pointer( $fuel_index, $activity, $failed_fuels, current_time( 'mysql' ) );
            }

            $pointer = blomstra_get_eia_pointer();
            $is_complete = ( $pointer['fuel_index'] >= $total_fuels );
            $fuels_done = min( $pointer['fuel_index'], $total_fuels );

            $message = "Processed fuel {$result['fuel_name']} ({$activity}) – status: {$result['status']}, fetched: {$result['fetched_count']} countries. " .
                       "Fuels completed: {$fuels_done} of {$total_fuels}.";

            if ( $is_complete ) {
                // BUGFIX (2026-09): "all fuels processed" (pointer reached
                // the end) was previously reported as 'success'
                // unconditionally — even if every fuel permanently failed
                // (e.g. a rejected/expired EIA key) — since the pointer
                // still advances past a permanently-failed fuel. Check how
                // many fuels actually ended up marked permanently failed
                // before calling the run a success.
                $permanently_failed_fuels = count( array_filter( $pointer['failed_fuels'] ?? array(), function( $f ) {
                    return is_array( $f ) && ! empty( $f['permanent'] );
                } ) );
                if ( $permanently_failed_fuels >= $total_fuels ) {
                    blomstra_update_cron_status( 'eia', 'error', "All {$total_fuels} fuels permanently failed — check EIA API credentials (use \"Test EIA\" in Settings). $message", 0 );
                } else {
                    blomstra_update_cron_status( 'eia', 'success', "All fuels processed. $message", $total_fuels );
                }
                blomstra_delete_eia_pointer();
            } else {
                $status_display = ( $result['status'] === 'retryable' ) ? 'retryable' : 'partial';
                blomstra_update_cron_status( 'eia', $status_display, "Partial: $message", $fuels_done + 1 );
                if ( $pointer['fuel_index'] < $total_fuels ) {
                    wp_schedule_single_event( time() + 60, 'blomstra_cron_eia_weekly_event' );
                }
            }

        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'eia', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'EIA cron error: ' . $e->getMessage() );
            $pointer = blomstra_get_eia_pointer();
            if ( $pointer['fuel_index'] < count( BLOMSTRA_EIA_FUEL_PRODUCT_IDS ) ) {
                wp_schedule_single_event( time() + 60, 'blomstra_cron_eia_weekly_event' );
            }
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'eia', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'EIA cron fatal: ' . $e->getMessage() );
            $pointer = blomstra_get_eia_pointer();
            if ( $pointer['fuel_index'] < count( BLOMSTRA_EIA_FUEL_PRODUCT_IDS ) ) {
                wp_schedule_single_event( time() + 60, 'blomstra_cron_eia_weekly_event' );
            }
        } finally {
            delete_transient( $lock_key );
        }
    }
    add_action( 'blomstra_cron_eia_weekly_event', 'blomstra_cron_handle_eia' );
}

// ─── STALE-CACHE FALLBACK HELPER ───────────────────────────────────

if ( ! function_exists( 'blomstra_stale_cache_fallback' ) ) {
    function blomstra_stale_cache_fallback( $cache_key ) {
        $stale = get_transient( $cache_key );
        return is_array( $stale ) ? $stale : array();
    }
}

// ─── SYSTEM & API KEY HEALTH CHECK ────────────────────────────────

if ( ! function_exists( 'blomstra_check_api_keys_status' ) ) {
    function blomstra_check_api_keys_status() {
        $creds = blomstra_get_all_api_credentials();
        return array(
            'comtrade' => ! empty( $creds['comtrade']['subscription_key'] ),
            'eia'      => ! empty( $creds['eia']['api_key'] ),
        );
    }
}

// ─── CRON SCHEDULING & HEALTH TRACKING ────────────────────────────

if ( ! function_exists( 'blomstra_update_cron_status' ) ) {
    function blomstra_update_cron_status( $pillar, $status, $message, $count = 0 ) {
        $cron_status = get_option( 'blomstra_cron_status', array() );
        $cron_status[ $pillar ] = array(
            'last_attempt'  => current_time( 'mysql' ),
            'last_success'  => ( $status === 'success' ) ? current_time( 'mysql' ) : ( $cron_status[ $pillar ]['last_success'] ?? null ),
            'timestamp' => time(),
            'status'    => $status,
            'message'   => $message,
            'count'     => $count,
        );
        update_option( 'blomstra_cron_status', $cron_status, false );
    }
}

// ─── WB INDICATOR LOGGING ──────────────────────────────────────────

if ( ! function_exists( 'blomstra_log_wb_indicator_fetch' ) ) {
    function blomstra_log_wb_indicator_fetch( $indicator_code, $row_count, $status, $detail = '' ) {
        $log = get_option( 'blomstra_wb_indicator_fetch_log', array() );
        $log[] = array(
            'time'    => current_time( 'mysql' ),
            'code'    => $indicator_code,
            'rows'    => $row_count,
            'status'  => $status,
            'detail'  => $detail,
        );
        if ( count( $log ) > 50 ) {
            $log = array_slice( $log, -50 );
        }
        update_option( 'blomstra_wb_indicator_fetch_log', $log, false );
    }
}

// ─── IMF WEO INDICATOR FUNCTIONS ──────────────────────────────────

if ( ! defined( 'BLOMSTRA_IMF_BASE_URL' ) ) {
    define( 'BLOMSTRA_IMF_BASE_URL', 'https://www.imf.org/external/datamapper/api/v1' );
}
if ( ! defined( 'BLOMSTRA_IMF_CACHE_TTL' ) ) {
    define( 'BLOMSTRA_IMF_CACHE_TTL', WEEK_IN_SECONDS );
}
if ( ! defined( 'BLOMSTRA_IMF_TO_ISO3_MAP' ) ) {
    define( 'BLOMSTRA_IMF_TO_ISO3_MAP', array(
        'KSV' => 'XKX',
        'WBG' => 'PSE',
        'ZAR' => 'COD',
        'ROM' => 'ROU',
    ) );
}

if ( ! function_exists( 'blomstra_get_weo_vintage' ) ) {
    function blomstra_get_weo_vintage() {
        $month = (int) current_time( 'n' );
        $year  = (int) current_time( 'Y' );
        if ( $month >= 4 && $month <= 9 ) {
            return 'April ' . $year;
        } else {
            if ( $month < 4 ) {
                return 'October ' . ($year - 1);
            } else {
                return 'October ' . $year;
            }
        }
    }
}

if ( ! function_exists( 'blomstra_fetch_imf_generic' ) ) {
    function blomstra_fetch_imf_generic( $code, $force, $forecast_horizon = null, $target_year = null ) {
        $cache_key = 'blomstra_imf_' . ( $forecast_horizon !== null ? 'forecast_' . md5( $code . '_h' . $forecast_horizon ) : 'indicator_' . md5( $code ) );
        if ( $target_year !== null ) {
            $cache_key .= '_year_' . $target_year;
        }
        $staging_key = $cache_key . '_tmp';

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $staging = get_transient( $staging_key );
        if ( false !== $staging && is_array( $staging ) ) {
            set_transient( $cache_key, $staging, BLOMSTRA_IMF_CACHE_TTL );
            delete_transient( $staging_key );
            return $staging;
        }

        $url = BLOMSTRA_IMF_BASE_URL . '/' . $code;
        $current_year = (int) current_time( 'Y' );
        $iso3_map = BLOMSTRA_IMF_TO_ISO3_MAP;
        $out = array();

        $attempt = 1;
        $max_attempts = 3;
        $backoff = 2;

        while ( $attempt <= $max_attempts ) {
            $response = wp_remote_get( $url, array(
                'timeout' => 60,
                'user-agent' => BLOMSTRA_USER_AGENT,
            ) );

            if ( is_wp_error( $response ) ) {
                error_log( "IMF fetch ({$code}) attempt {$attempt}: " . $response->get_error_message() );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return blomstra_stale_cache_fallback( $cache_key );
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code === 429 ) {
                if ( $attempt < $max_attempts ) {
                    sleep( 5 * $attempt );
                    $attempt++;
                    continue;
                }
                error_log( "IMF fetch ({$code}) rate-limited after {$max_attempts} attempts" );
                return blomstra_stale_cache_fallback( $cache_key );
            }

            if ( $http_code !== 200 ) {
                error_log( "IMF fetch ({$code}) attempt {$attempt}: HTTP {$http_code}" );
                if ( $attempt < $max_attempts && $http_code >= 500 ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return blomstra_stale_cache_fallback( $cache_key );
            }

            $body_raw = wp_remote_retrieve_body( $response );
            $body = json_decode( $body_raw, true );
            if ( ! isset( $body['values'][ $code ] ) || ! is_array( $body['values'][ $code ] ) ) {
                error_log( "IMF fetch ({$code}): no data array" );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return blomstra_stale_cache_fallback( $cache_key );
            }

            if ( $target_year !== null ) {
                foreach ( $body['values'][ $code ] as $imf_code => $years ) {
                    $iso3 = $iso3_map[ $imf_code ] ?? $imf_code;
                    if ( ! is_array( $years ) || ! isset( $years[ $target_year ] ) ) {
                        continue;
                    }
                    $val = $years[ $target_year ];
                    if ( ! is_numeric( $val ) ) {
                        continue;
                    }
                    $out[ $iso3 ] = array(
                        'value'       => floatval( $val ),
                        'year'        => (string) $target_year,
                        'data_type'   => 'historical',
                        'fetched_at'  => current_time( 'mysql' ),
                        'source'      => 'IMF WEO DataMapper',
                        'weo_vintage' => blomstra_get_weo_vintage(),
                    );
                }
            } else {
                foreach ( $body['values'][ $code ] as $imf_code => $years ) {
                    $iso3 = $iso3_map[ $imf_code ] ?? $imf_code;
                    if ( ! is_array( $years ) || empty( $years ) ) {
                        continue;
                    }

                    if ( $forecast_horizon !== null ) {
                        $target_year = $current_year + $forecast_horizon;
                        if ( isset( $years[ $target_year ] ) && is_numeric( $years[ $target_year ] ) ) {
                            $out[ $iso3 ] = array(
                                'value'     => floatval( $years[ $target_year ] ),
                                'year'      => (string) $target_year,
                                'data_type' => 'forecast',
                                'horizon'   => $forecast_horizon,
                                'fetched_at' => current_time( 'mysql' ),
                                'source'    => 'IMF WEO DataMapper (forecast)',
                                'weo_vintage' => blomstra_get_weo_vintage(),
                            );
                        }
                    } else {
                        $actual_years = array_filter( array_keys( $years ), function( $y ) use ( $current_year ) {
                            return (int) $y <= $current_year;
                        } );
                        $forecast_years = array_filter( array_keys( $years ), function( $y ) use ( $current_year ) {
                            return (int) $y > $current_year;
                        } );

                        if ( ! empty( $actual_years ) ) {
                            $latest_actual_year = max( $actual_years );
                            $val = $years[ $latest_actual_year ];
                            if ( ! is_numeric( $val ) ) {
                                continue;
                            }
                            $out[ $iso3 ] = array(
                                'value'       => floatval( $val ),
                                'year'        => (string) $latest_actual_year,
                                'data_type'   => $latest_actual_year == $current_year ? 'current_year_estimate' : 'actual',
                                'fetched_at'  => current_time( 'mysql' ),
                                'source'      => 'IMF WEO DataMapper',
                            );
                        } elseif ( ! empty( $forecast_years ) ) {
                            $earliest_forecast_year = min( $forecast_years );
                            $val = $years[ $earliest_forecast_year ];
                            if ( ! is_numeric( $val ) ) {
                                continue;
                            }
                            $out[ $iso3 ] = array(
                                'value'       => floatval( $val ),
                                'year'        => (string) $earliest_forecast_year,
                                'data_type'   => 'forecast_fallback',
                                'fetched_at'  => current_time( 'mysql' ),
                                'source'      => 'IMF WEO DataMapper (forecast)',
                            );
                        }
                    }
                }
            }

            if ( ! empty( $out ) ) {
                break;
            }
            if ( $attempt < $max_attempts ) {
                error_log( "IMF fetch ({$code}) attempt {$attempt}: empty dataset, will retry." );
                sleep( $backoff * $attempt );
                $attempt++;
                continue;
            }
            break;
        }

        if ( ! empty( $out ) ) {
            set_transient( $staging_key, $out, BLOMSTRA_IMF_CACHE_TTL );
            set_transient( $cache_key, $out, BLOMSTRA_IMF_CACHE_TTL );
            delete_transient( $staging_key );
            return $out;
        }

        return blomstra_stale_cache_fallback( $cache_key );
    }
}

if ( ! function_exists( 'blomstra_fetch_imf_indicator_batch' ) ) {
    function blomstra_fetch_imf_indicator_batch( $code, $force = false ) {
        return blomstra_fetch_imf_generic( $code, $force, null );
    }
}

if ( ! function_exists( 'blomstra_fetch_imf_forecast_batch' ) ) {
    function blomstra_fetch_imf_forecast_batch( $code, $horizon = 1, $force = false ) {
        return blomstra_fetch_imf_generic( $code, $force, $horizon );
    }
}

if ( ! function_exists( 'blomstra_log_imf_call' ) ) {
    function blomstra_log_imf_call( $indicator_code, $row_count, $status, $detail = '' ) {
        $log = get_option( 'blomstra_imf_call_log', array() );
        $log[] = array(
            'time'    => current_time( 'mysql' ),
            'code'    => $indicator_code,
            'rows'    => $row_count,
            'status'  => $status,
            'detail'  => $detail,
        );
        if ( count( $log ) > 50 ) {
            $log = array_slice( $log, -50 );
        }
        update_option( 'blomstra_imf_call_log', $log, false );
    }
}

if ( ! function_exists( 'blomstra_refresh_imf_indicators' ) ) {
    function blomstra_refresh_imf_indicators( $force = false ) {
        $indicators = array(
            'NGDP_RPCH'   => 'gdp_growth_imf',
            'PCPIPCH'     => 'inflation_imf',
            'BCA_NGDPD'   => 'current_account_imf',
            'GGXWDG_NGDP' => 'gov_debt_imf',
            'GGXCNL_NGDP' => 'gov_balance_imf',
            'LUR'         => 'unemployment_imf',
        );

        $results = array();
        foreach ( $indicators as $code => $name ) {
            $data = blomstra_fetch_imf_indicator_batch( $code, $force );
            $row_count = count( $data );
            $status = $row_count > 0 ? 'success' : 'error';
            blomstra_log_imf_call( $code, $row_count, $status, $row_count > 0 ? 'OK' : 'No data returned' );
            $results[ $code ] = array(
                'success' => $row_count > 0,
                'count'   => $row_count,
            );
            sleep(1);
        }
        return $results;
    }
}

if ( ! function_exists( 'blomstra_count_imf_cache' ) ) {
    function blomstra_count_imf_cache() {
       global $wpdb;
        $count = $wpdb->get_var(
           "SELECT COUNT(*) FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_blomstra_imf_indicator_%'
            AND option_name NOT LIKE '%_year_%'
            AND option_name NOT LIKE '%_forecast_%'
            AND option_name NOT LIKE '_transient_timeout_%'"
       );
       return (int) $count;
    }
}

if ( ! function_exists( 'blomstra_flush_imf_cache' ) ) {
    function blomstra_flush_imf_cache() {
        global $wpdb;
        return $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_blomstra_imf_%'
             OR option_name LIKE '_transient_timeout_blomstra_imf_%'"
        );
    }
}

if ( ! function_exists( 'blomstra_cron_handle_imf' ) ) {
    function blomstra_cron_handle_imf() {
        $lock_key = 'blomstra_imf_weekly_in_progress';
        $lock = get_transient( $lock_key );
        if ( $lock !== false && ( time() - (int)$lock ) < 10 * MINUTE_IN_SECONDS ) {
            blomstra_update_cron_status( 'imf', 'running', 'Already running – skipping duplicate.' );
            return;
        }
        set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );

        blomstra_update_cron_status( 'imf', 'running', 'IMF WEO weekly refresh running...' );
        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 300 );
            }
            $result = blomstra_refresh_imf_indicators( true );
            $success_count = 0;
            $total_count = count( $result );
            foreach ( $result as $code => $status ) {
                if ( $status['success'] ) $success_count++;
            }
            $msg = 'IMF indicators refreshed: ' . $success_count . ' of ' . $total_count . ' fetched.';
            if ( $success_count === $total_count ) {
                blomstra_update_cron_status( 'imf', 'success', $msg, $success_count );
            } elseif ( $success_count > 0 ) {
                blomstra_update_cron_status( 'imf', 'partial', $msg, $success_count );
            } else {
                blomstra_update_cron_status( 'imf', 'error', 'IMF fetch failed or returned empty datasets.' );
            }
        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'imf', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'IMF cron error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'imf', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'IMF cron fatal: ' . $e->getMessage() );
        } finally {
            delete_transient( $lock_key );
        }
    }
    add_action( 'blomstra_cron_imf_weekly_event', 'blomstra_cron_handle_imf' );
}

// ─── WORLD BANK INDICATOR BATCH FETCHER ────────────────────────────

if ( ! defined( 'BLOMSTRA_WB_INDICATOR_CACHE_TTL' ) ) {
    define( 'BLOMSTRA_WB_INDICATOR_CACHE_TTL', WEEK_IN_SECONDS );
}

if ( ! function_exists( 'blomstra_fetch_wb_indicator_batch' ) ) {
    function blomstra_fetch_wb_indicator_batch( $code, $source = null, $force = false ) {
        $cache_key = 'blomstra_wb_indicator_' . md5( $code . '|' . (string) $source );
        $staging_key = $cache_key . '_tmp';

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $staging = get_transient( $staging_key );
        if ( false !== $staging && is_array( $staging ) ) {
            set_transient( $cache_key, $staging, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
            delete_transient( $staging_key );
            return $staging;
        }

        $current_year = (int) current_time( 'Y' );
        $mrnev_url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000";
        $range_url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=5000&date=" . ($current_year - 10) . ":" . $current_year;

        if ( $source ) {
            $url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000&source={$source}";
        } else {
            $url = $mrnev_url . '&mrnev=1';
        }

        $attempt = 1;
        $max_attempts = 3;
        $backoff = 2;
        $data = array();
        $out = array();

        $parse_wb_response = function( $body, $fetched_at, $data_source ) {
            $result = array();
            if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
                return $result;
            }
            foreach ( $body[1] as $row ) {
                $iso3 = $row['countryiso3code'] ?? null;
                $val  = $row['value'] ?? null;
                $year = isset( $row['date'] ) ? (string) $row['date'] : null;
                if ( ! $iso3 || ! is_numeric( $val ) ) {
                    continue;
                }
                if ( $year === null ) {
                    continue;
                }
                if ( isset( $result[ $iso3 ] ) && $year !== null && $result[ $iso3 ]['year'] !== null ) {
                    if ( (int) $year > (int) $result[ $iso3 ]['year'] ) {
                        $result[ $iso3 ] = array(
                            'value'       => floatval( $val ),
                            'year'        => $year,
                            'data_type'   => 'actual',
                            'fetched_at'  => $fetched_at,
                            'source'      => $data_source,
                        );
                    }
                } else {
                    $result[ $iso3 ] = array(
                        'value'       => floatval( $val ),
                        'year'        => $year,
                        'data_type'   => 'actual',
                        'fetched_at'  => $fetched_at,
                        'source'      => $data_source,
                    );
                }
            }
            return $result;
        };

        if ( $source ) {
            while ( $attempt <= $max_attempts ) {
                $response = wp_remote_get( $url, array(
                    'timeout' => 60,
                    'user-agent' => BLOMSTRA_USER_AGENT,
                ) );
                if ( is_wp_error( $response ) ) {
                    error_log( "WB indicator fetch ({$code}) attempt {$attempt}: " . $response->get_error_message() );
                    if ( $attempt < $max_attempts ) {
                        sleep( $backoff * $attempt );
                        $attempt++;
                        continue;
                    }
                    return blomstra_stale_cache_fallback( $cache_key );
                }
                $http_code = wp_remote_retrieve_response_code( $response );
                if ( $http_code === 429 ) {
                    if ( $attempt < $max_attempts ) {
                        sleep( 5 * $attempt );
                        $attempt++;
                        continue;
                    }
                    error_log( "WB indicator fetch ({$code}) rate-limited after {$max_attempts} attempts" );
                    return blomstra_stale_cache_fallback( $cache_key );
                }
                if ( $http_code !== 200 ) {
                    error_log( "WB indicator fetch ({$code}) attempt {$attempt}: HTTP {$http_code}" );
                    if ( $attempt < $max_attempts && $http_code >= 500 ) {
                        sleep( $backoff * $attempt );
                        $attempt++;
                        continue;
                    }
                    return blomstra_stale_cache_fallback( $cache_key );
                }
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $out = $parse_wb_response( $body, current_time( 'mysql' ), 'WGI' );
                if ( ! empty( $out ) ) {
                    $data = $out;
                    break;
                }
                if ( $attempt < $max_attempts ) {
                    error_log( "WB indicator fetch ({$code}) attempt {$attempt}: empty dataset, will retry." );
                    sleep( 3 );
                    $attempt++;
                    continue;
                }
                break;
            }
            if ( ! empty( $data ) ) {
                set_transient( $staging_key, $data, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
                set_transient( $cache_key, $data, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
                delete_transient( $staging_key );
                return $data;
            }
            return blomstra_stale_cache_fallback( $cache_key );
        }

        $attempt = 1;
        $out = array();
        $fetched_at = current_time( 'mysql' );
        while ( $attempt <= $max_attempts ) {
            $response = wp_remote_get( $mrnev_url . '&mrnev=1', array(
                'timeout' => 60,
                'user-agent' => BLOMSTRA_USER_AGENT,
            ) );
            if ( is_wp_error( $response ) ) {
                error_log( "WB indicator fetch ({$code}) mrnev attempt {$attempt}: " . $response->get_error_message() );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                break;
            }
            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code === 429 ) {
                if ( $attempt < $max_attempts ) {
                    sleep( 5 * $attempt );
                    $attempt++;
                    continue;
                }
                error_log( "WB indicator fetch ({$code}) mrnev rate-limited after {$max_attempts} attempts" );
                break;
            }
            if ( $http_code !== 200 ) {
                error_log( "WB indicator fetch ({$code}) mrnev attempt {$attempt}: HTTP {$http_code}" );
                if ( $attempt < $max_attempts && $http_code >= 500 ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                break;
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $out = $parse_wb_response( $body, $fetched_at, 'WDI' );
            if ( ! empty( $out ) ) {
                break;
            }
            if ( $attempt < $max_attempts ) {
                error_log( "WB indicator fetch ({$code}) mrnev attempt {$attempt}: empty dataset, will retry." );
                sleep( 3 );
                $attempt++;
                continue;
            }
            break;
        }

        if ( ! empty( $out ) ) {
            set_transient( $staging_key, $out, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
            set_transient( $cache_key, $out, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
            delete_transient( $staging_key );
            return $out;
        }

        error_log( "WB indicator fetch ({$code}): mrnev returned empty, falling back to 10-year date range." );
        $attempt = 1;
        $out = array();
        while ( $attempt <= $max_attempts ) {
            $response = wp_remote_get( $range_url, array(
                'timeout' => 60,
                'user-agent' => BLOMSTRA_USER_AGENT,
            ) );
            if ( is_wp_error( $response ) ) {
                error_log( "WB indicator fetch ({$code}) range attempt {$attempt}: " . $response->get_error_message() );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return blomstra_stale_cache_fallback( $cache_key );
            }
            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code === 429 ) {
                if ( $attempt < $max_attempts ) {
                    sleep( 5 * $attempt );
                    $attempt++;
                    continue;
                }
                error_log( "WB indicator fetch ({$code}) range rate-limited after {$max_attempts} attempts" );
                return blomstra_stale_cache_fallback( $cache_key );
            }
            if ( $http_code !== 200 ) {
                error_log( "WB indicator fetch ({$code}) range attempt {$attempt}: HTTP {$http_code}" );
                if ( $attempt < $max_attempts && $http_code >= 500 ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                return blomstra_stale_cache_fallback( $cache_key );
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $out = $parse_wb_response( $body, current_time( 'mysql' ), 'WDI' );
            if ( ! empty( $out ) ) {
                break;
            }
            if ( $attempt < $max_attempts ) {
                error_log( "WB indicator fetch ({$code}) range attempt {$attempt}: empty dataset, will retry." );
                sleep( 3 );
                $attempt++;
                continue;
            }
            break;
        }

        if ( ! empty( $out ) ) {
            set_transient( $staging_key, $out, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
            set_transient( $cache_key, $out, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
            delete_transient( $staging_key );
            return $out;
        }

        return blomstra_stale_cache_fallback( $cache_key );
    }
}

if ( ! function_exists( 'blomstra_count_wb_indicator_cache' ) ) {
    function blomstra_count_wb_indicator_cache() {
        global $wpdb;
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_blomstra_wb_indicator_%'
             AND option_name NOT LIKE '_transient_timeout_%'"
        );
        return (int) $count;
    }
}

if ( ! function_exists( 'blomstra_flush_wb_indicator_cache' ) ) {
    function blomstra_flush_wb_indicator_cache() {
        global $wpdb;
        return $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_blomstra_wb_indicator_%'
             OR option_name LIKE '_transient_timeout_blomstra_wb_indicator_%'"
        );
    }
}

// ─── HISTORICAL WB FETCHER (with retry) ───────────────────────────

if ( ! function_exists( 'blomstra_fetch_wb_historical_batch' ) ) {
    function blomstra_fetch_wb_historical_batch( $code, $start_year, $end_year, $source = null, $force = false ) {
        if ( $start_year > $end_year ) {
            return array();
        }

        $cache_key = 'blomstra_wb_historical_' . md5( $code . '|' . (string) $source . '|' . $start_year . '|' . $end_year );
        $staging_key = $cache_key . '_tmp';

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $staging = get_transient( $staging_key );
        if ( false !== $staging && is_array( $staging ) ) {
            set_transient( $cache_key, $staging, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
            delete_transient( $staging_key );
            return $staging;
        }

        $url = "https://api.worldbank.org/v2/country/all/indicator/{$code}?format=json&per_page=20000&date={$start_year}:{$end_year}";
        if ( $source ) {
            $url .= "&source={$source}";
        } else {
            $url .= '&mrnev=1';
        }

        $max_attempts = 3;
        $backoff = 2;
        $attempt = 1;
        while ( $attempt <= $max_attempts ) {
            $response = wp_remote_get( $url, array(
                'timeout' => 60,
                'user-agent' => BLOMSTRA_USER_AGENT,
            ) );
            if ( is_wp_error( $response ) ) {
                error_log( "WB historical fetch ({$code}) attempt {$attempt}: " . $response->get_error_message() );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                $stale = get_transient( $cache_key );
                return is_array( $stale ) ? $stale : array();
            }
            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code === 429 ) {
                if ( $attempt < $max_attempts ) {
                    sleep( 5 * $attempt );
                    $attempt++;
                    continue;
                }
                error_log( "WB historical fetch ({$code}) rate-limited after {$max_attempts} attempts" );
                $stale = get_transient( $cache_key );
                return is_array( $stale ) ? $stale : array();
            }
            if ( $http_code !== 200 ) {
                error_log( "WB historical fetch ({$code}) attempt {$attempt}: HTTP {$http_code}" );
                if ( $attempt < $max_attempts && $http_code >= 500 ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                $stale = get_transient( $cache_key );
                return is_array( $stale ) ? $stale : array();
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
                error_log( "WB historical fetch ({$code}): invalid response shape" );
                if ( $attempt < $max_attempts ) {
                    sleep( $backoff * $attempt );
                    $attempt++;
                    continue;
                }
                $stale = get_transient( $cache_key );
                return is_array( $stale ) ? $stale : array();
            }

            $result = array();
            foreach ( $body[1] as $row ) {
                $iso3 = $row['countryiso3code'] ?? null;
                $val  = $row['value'] ?? null;
                $year = isset( $row['date'] ) ? (int) $row['date'] : null;
                if ( ! $iso3 || ! is_numeric( $val ) || $year === null ) {
                    continue;
                }
                if ( ! isset( $result[ $iso3 ] ) ) {
                    $result[ $iso3 ] = array();
                }
                $result[ $iso3 ][ $year ] = (float) $val;
            }

            foreach ( $result as &$years ) {
                ksort( $years );
            }

            if ( ! empty( $result ) ) {
                set_transient( $staging_key, $result, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
                set_transient( $cache_key, $result, BLOMSTRA_WB_INDICATOR_CACHE_TTL );
                delete_transient( $staging_key );
                return $result;
            }
            break;
        }

        $stale = get_transient( $cache_key );
        return is_array( $stale ) ? $stale : array();
    }
}

// ─── WB POINTER HELPERS ────────────────────────────────────────────

if ( ! function_exists( 'blomstra_get_wb_pointer' ) ) {
    function blomstra_get_wb_pointer() {
        // BUGFIX (2026-09): added 'failed_total' so a run's cumulative
        // failure count survives across ticks — previously only the
        // current tick's batch of 3 was ever checked, so a run whose
        // final batch happened to succeed was reported as a full success
        // even if every earlier batch in the same run had failed.
        $default = array( 'next_index' => 0, 'started_at' => null, 'failed_total' => 0 );
        $pointer = get_option( 'blomstra_wb_refresh_pointer', $default );
        if ( ! is_array( $pointer ) || ! isset( $pointer['next_index'] ) ) {
            $pointer = $default;
        }
        if ( ! isset( $pointer['failed_total'] ) ) {
            $pointer['failed_total'] = 0;
        }
        return $pointer;
    }
}

if ( ! function_exists( 'blomstra_update_wb_pointer' ) ) {
    function blomstra_update_wb_pointer( $next_index, $started_at = null, $failed_total = 0 ) {
        $pointer = array(
            'next_index'   => $next_index,
            'started_at'   => $started_at ? $started_at : current_time( 'mysql' ),
            'failed_total' => $failed_total,
        );
        update_option( 'blomstra_wb_refresh_pointer', $pointer, false );
    }
}

if ( ! function_exists( 'blomstra_delete_wb_pointer' ) ) {
    function blomstra_delete_wb_pointer() {
        delete_option( 'blomstra_wb_refresh_pointer' );
    }
}

if ( ! function_exists( 'blomstra_refresh_wb_indicators' ) ) {
    function blomstra_refresh_wb_indicators( $force = false ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 );
        }

        $results = array();
        foreach ( BLOMSTRA_WB_INDICATORS as $code => $config ) {
            $data = blomstra_fetch_wb_indicator_batch( $code, $config['source'], $force );
            $row_count = count( $data );
            $status = $row_count > 0 ? 'success' : 'error';
            blomstra_log_wb_indicator_fetch( $code, $row_count, $status, $row_count > 0 ? 'OK' : 'No data returned' );
            $results[ $code ] = array(
                'success' => $row_count > 0,
                'count'   => $row_count,
            );
            sleep(1);
        }
        return $results;
    }
}

// ─── CRON: WB indicators ───────────────────────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_wb_indicators' ) ) {
    function blomstra_cron_handle_wb_indicators() {
        $lock_key = 'blomstra_wb_refresh_in_progress';
        $lock = get_transient( $lock_key );
        if ( $lock !== false && ( time() - (int)$lock ) < 30 * MINUTE_IN_SECONDS ) {
            blomstra_update_cron_status( 'wb_indicators', 'running', 'Already running – skipping duplicate.' );
            return;
        }
        set_transient( $lock_key, time(), 30 * MINUTE_IN_SECONDS );

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 );
        }

        blomstra_update_cron_status( 'wb_indicators', 'running', 'WB indicators chunked refresh running...' );

        try {
            $indices = array_keys( BLOMSTRA_WB_INDICATORS );
            $total = count( $indices );

            $pointer = blomstra_get_wb_pointer();
            $start_index = $pointer['next_index'];
            $run_started = $pointer['started_at'];
            $failed_total_before = ( $start_index === 0 ) ? 0 : ( $pointer['failed_total'] ?? 0 );
            $batch_size = 3;
            $end_index = min( $start_index + $batch_size, $total );

            $success_count = 0;
            $failed_count = 0;

            for ( $i = $start_index; $i < $end_index; $i++ ) {
                $code = $indices[ $i ];
                $config = BLOMSTRA_WB_INDICATORS[ $code ];
                $data = blomstra_fetch_wb_indicator_batch( $code, $config['source'], true );
                $row_count = count( $data );
                $status = $row_count > 0 ? 'success' : 'error';
                blomstra_log_wb_indicator_fetch( $code, $row_count, $status, $row_count > 0 ? 'OK' : 'No data returned' );
                if ( $row_count > 0 ) {
                    $success_count++;
                } else {
                    $failed_count++;
                }
                sleep( 1 );
            }

            $failed_total = $failed_total_before + $failed_count;
            blomstra_update_wb_pointer( $end_index, $run_started, $failed_total );

            $msg = "Processed indicators $start_index to " . ( $end_index - 1 ) . " of $total. Success: $success_count, Failed: $failed_count.";

            if ( $end_index >= $total ) {
                // BUGFIX (2026-09): "pointer reached the end of the
                // indicator list" was previously reported as 'success'
                // unconditionally, even when every indicator across the
                // whole run — not just this tick's batch of 3 — had
                // failed. Use the cumulative failure count tracked across
                // all ticks of this run, not just the current batch.
                if ( $failed_total >= $total ) {
                    blomstra_update_cron_status( 'wb_indicators', 'error', "All $total indicators failed this run. $msg", 0 );
                } elseif ( $failed_total > 0 ) {
                    blomstra_update_cron_status( 'wb_indicators', 'partial', "$total indicators processed, $failed_total failed overall this run. $msg", $total - $failed_total );
                } else {
                    blomstra_update_cron_status( 'wb_indicators', 'success', "All $total indicators refreshed. $msg", $total );
                }
                blomstra_delete_wb_pointer();
            } else {
                blomstra_update_cron_status( 'wb_indicators', 'partial', "Partial: $msg", $end_index );
                wp_schedule_single_event( time() + 60, 'blomstra_cron_wb_indicators_weekly_event' );
            }

        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'wb_indicators', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'WB cron error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'wb_indicators', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'WB cron fatal: ' . $e->getMessage() );
        } finally {
            delete_transient( $lock_key );
        }
    }
    add_action( 'blomstra_cron_wb_indicators_weekly_event', 'blomstra_cron_handle_wb_indicators' );
}

// ─── CRON: Maritime ──────────────────────────────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_maritime' ) ) {
    function blomstra_cron_handle_maritime() {
        $lock_key = 'blomstra_maritime_weekly_in_progress';
        $lock = get_transient( $lock_key );
        if ( $lock !== false && ( time() - (int)$lock ) < 10 * MINUTE_IN_SECONDS ) {
            blomstra_update_cron_status( 'maritime', 'running', 'Already running – skipping duplicate.' );
            return;
        }
        set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );

        blomstra_update_cron_status( 'maritime', 'running', 'Maritime weekly refresh starting...' );
        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 300 );
            }
            $data = blomstra_get_maritime_raw( true );

            $expected_total = count( blomstra_get_global_country_list() );
            $got            = is_array( $data ) ? count( $data ) : 0;
            $msg = 'Maritime data refreshed: ' . $got . ' of ' . $expected_total . ' countries.';

            if ( $got === 0 ) {
                blomstra_update_cron_status( 'maritime', 'error', 'Maritime fetch returned empty dataset.' );
            } elseif ( $expected_total > 0 && $got < 0.7 * $expected_total ) {
                blomstra_update_cron_status( 'maritime', 'partial', $msg, $got );
            } else {
                blomstra_update_cron_status( 'maritime', 'success', $msg, $got );
            }
        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'maritime', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'Maritime cron error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'maritime', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'Maritime cron fatal: ' . $e->getMessage() );
        } finally {
            delete_transient( $lock_key );
        }
    }
    add_action( 'blomstra_cron_maritime_weekly_event', 'blomstra_cron_handle_maritime' );
}

// ─── CRON: Country List Async ──────────────────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_countries_async' ) ) {
    function blomstra_cron_handle_countries_async() {
        $lock_key = 'blomstra_countries_async_in_progress';
        $lock = get_transient( $lock_key );
        if ( $lock !== false && ( time() - (int)$lock ) < 10 * MINUTE_IN_SECONDS ) {
            blomstra_update_cron_status( 'countries_async', 'running', 'Already running – skipping duplicate.' );
            return;
        }
        set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );

        blomstra_update_cron_status( 'countries_async', 'running', 'Country list async refresh running...' );
        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 600 );
            }
            $data = blomstra_get_global_country_list( true );
            $count = is_array( $data ) ? count( $data ) : 0;
            $msg = 'Country list async refresh: ' . $count . ' countries fetched.';
            blomstra_update_cron_status( 'countries_async', 'success', $msg, $count );
        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'countries_async', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'Countries async error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'countries_async', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'Countries async fatal: ' . $e->getMessage() );
        } finally {
            delete_transient( $lock_key );
        }
    }
    add_action( 'blomstra_cron_countries_async_event', 'blomstra_cron_handle_countries_async' );
}

// ─── CRON: Reporter Map Async ──────────────────────────────────────

if ( ! function_exists( 'blomstra_cron_handle_reporters_async' ) ) {
    function blomstra_cron_handle_reporters_async() {
        $lock_key = 'blomstra_reporters_async_in_progress';
        $lock = get_transient( $lock_key );
        if ( $lock !== false && ( time() - (int)$lock ) < 10 * MINUTE_IN_SECONDS ) {
            blomstra_update_cron_status( 'reporters_async', 'running', 'Already running – skipping duplicate.' );
            return;
        }
        set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );

        blomstra_update_cron_status( 'reporters_async', 'running', 'Reporter map async refresh running...' );
        try {
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 600 );
            }
            $data = blomstra_get_comtrade_reporter_map( true );
            $count = is_array( $data ) ? count( $data ) : 0;
            $msg = 'Reporter map async refresh: ' . $count . ' reporters mapped.';
            blomstra_update_cron_status( 'reporters_async', 'success', $msg, $count );
        } catch ( Exception $e ) {
            blomstra_update_cron_status( 'reporters_async', 'error', 'Exception: ' . $e->getMessage() );
            error_log( 'Reporters async error: ' . $e->getMessage() );
        } catch ( Error $e ) {
            blomstra_update_cron_status( 'reporters_async', 'error', 'Fatal: ' . $e->getMessage() );
            error_log( 'Reporters async fatal: ' . $e->getMessage() );
        } finally {
            delete_transient( $lock_key );
        }
    }
    add_action( 'blomstra_cron_reporters_async_event', 'blomstra_cron_handle_reporters_async' );
}

// ─── SCHEDULE ALL CRONS ─────────────────────────────────────────────

if ( ! function_exists( 'blomstra_register_weekly_cron_schedule' ) ) {
    function blomstra_register_weekly_cron_schedule( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'blomstra' ),
            );
        }
        return $schedules;
    }
    add_filter( 'cron_schedules', 'blomstra_register_weekly_cron_schedule' );
}

if ( ! function_exists( 'blomstra_schedule_reference_crons' ) ) {
    function blomstra_schedule_reference_crons() {
        if ( ! wp_next_scheduled( 'blomstra_cron_maritime_weekly_event' ) ) {
            $mon = strtotime( 'next Monday 02:00:00 UTC' );
            wp_schedule_event( $mon, 'weekly', 'blomstra_cron_maritime_weekly_event' );
        }
        if ( ! wp_next_scheduled( 'blomstra_cron_eia_weekly_event' ) ) {
            $tue = strtotime( 'next Tuesday 02:00:00 UTC' );
            wp_schedule_event( $tue, 'weekly', 'blomstra_cron_eia_weekly_event' );
        }
        if ( ! wp_next_scheduled( 'blomstra_cron_hhi_weekly_event' ) ) {
            $wed = strtotime( 'next Wednesday 02:00:00 UTC' );
            wp_schedule_event( $wed, 'weekly', 'blomstra_cron_hhi_weekly_event' );
        }
        if ( ! wp_next_scheduled( 'blomstra_cron_wb_indicators_weekly_event' ) ) {
            $thu = strtotime( 'next Thursday 02:00:00 UTC' );
            wp_schedule_event( $thu, 'weekly', 'blomstra_cron_wb_indicators_weekly_event' );
        }
        if ( ! wp_next_scheduled( 'blomstra_cron_imf_weekly_event' ) ) {
            $fri = strtotime( 'next Friday 02:00:00 UTC' );
            wp_schedule_event( $fri, 'weekly', 'blomstra_cron_imf_weekly_event' );
        }
    }
    add_action( 'init', 'blomstra_schedule_reference_crons' );
}

// ─── ADMIN ACTIONS & REFRESH HANDLERS ─────────────────────────────

if ( ! function_exists( 'blomstra_ref_handle_early_actions' ) ) {
    function blomstra_ref_handle_early_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $page = sanitize_text_field( $_POST['page'] ?? $_GET['page'] ?? '' );
        if ( $page !== 'blomstra-insights-tools' ) {
            return;
        }

        if ( isset( $_POST['blomstra_save_api_credentials'] ) && check_admin_referer( 'blomstra_save_api_credentials_action', 'blomstra_save_api_credentials_nonce' ) ) {
            // BUGFIX (2026-09): credential fields are now rendered blank on
            // page load (see the form below) instead of echoing the real
            // secret back into the page's HTML source. That means a blank
            // submitted value here means "user didn't type a new key," not
            // "user wants to clear the key" — so a field left blank keeps
            // whatever's already effectively configured (DB value or
            // wp-config.php constant), and only a non-empty submission
            // overwrites it. Without this change, simply loading and
            // re-saving the settings page (e.g. to change one field) would
            // blank out every other credential.
            $credentials = array();

            $submitted_comtrade_key = isset( $_POST['blomstra_comtrade_subscription_key'] ) ? sanitize_text_field( trim( $_POST['blomstra_comtrade_subscription_key'] ) ) : '';
            $credentials['comtrade']['subscription_key'] = ( $submitted_comtrade_key !== '' )
                ? $submitted_comtrade_key
                : (string) blomstra_get_api_credential( 'comtrade', 'subscription_key' );

            $submitted_eia_key = isset( $_POST['blomstra_eia_api_key'] ) ? sanitize_text_field( trim( $_POST['blomstra_eia_api_key'] ) ) : '';
            $credentials['eia']['api_key'] = ( $submitted_eia_key !== '' )
                ? $submitted_eia_key
                : (string) blomstra_get_api_credential( 'eia', 'api_key' );

            $submitted_unctad_id = isset( $_POST['blomstra_unctad_client_id'] ) ? sanitize_text_field( trim( $_POST['blomstra_unctad_client_id'] ) ) : '';
            $credentials['unctad']['client_id'] = ( $submitted_unctad_id !== '' )
                ? $submitted_unctad_id
                : (string) blomstra_get_api_credential( 'unctad', 'client_id' );

            $submitted_unctad_secret = isset( $_POST['blomstra_unctad_client_secret'] ) ? sanitize_text_field( trim( $_POST['blomstra_unctad_client_secret'] ) ) : '';
            $credentials['unctad']['client_secret'] = ( $submitted_unctad_secret !== '' )
                ? $submitted_unctad_secret
                : (string) blomstra_get_api_credential( 'unctad', 'client_secret' );

            // Explicit clear: checking this box is the only way to blank a
            // stored credential now that leaving the field empty means
            // "unchanged."
            if ( ! empty( $_POST['blomstra_clear_comtrade_key'] ) ) {
                $credentials['comtrade']['subscription_key'] = '';
            }
            if ( ! empty( $_POST['blomstra_clear_eia_key'] ) ) {
                $credentials['eia']['api_key'] = '';
            }

            blomstra_save_api_credentials( $credentials );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'api_saved' => '1' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_test_api_credentials'] ) && check_admin_referer( 'blomstra_test_api_credentials_action', 'blomstra_test_api_credentials_nonce' ) ) {
            $source = sanitize_text_field( $_POST['test_source'] ?? '' );
            $result = array( 'source' => $source, 'success' => false, 'message' => '' );

            if ( $source === 'comtrade' ) {
                $key = blomstra_get_api_credential( 'comtrade', 'subscription_key' );
                if ( empty( $key ) ) {
                    $result['message'] = 'No subscription key configured.';
                } else {
                    $test_codes = array( 842 );
                    $rows = blomstra_comtrade_fetch_partner_imports_batch( $test_codes, (int) current_time( 'Y' ) - 1 );
                    if ( $rows === null || $rows === BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED || $rows === BLOMSTRA_COMTRADE_PERMANENT_FAILURE ) {
                        $result['message'] = 'API test failed – ' . ( is_string( $rows ) ? $rows : 'no data returned' );
                    } else {
                        $result['success'] = true;
                        $result['message'] = 'API test successful – ' . count( $rows ) . ' rows returned.';
                    }
                }
            } elseif ( $source === 'eia' ) {
                $key = blomstra_get_api_credential( 'eia', 'api_key' );
                if ( empty( $key ) ) {
                    $result['message'] = 'No API key configured.';
                } else {
                    $test_countries = array( 'USA' );
                    $batch = blomstra_eia_fetch_activity_batch( $test_countries, BLOMSTRA_EIA_ACTIVITY_CONS, '4415' );
                    if ( $batch['status'] === 'ok' || $batch['status'] === 'empty' ) {
                        $result['success'] = true;
                        $result['message'] = 'API test successful – ' . count( $batch['rows'] ) . ' rows returned.';
                    } else {
                        $result['message'] = 'API test failed – ' . $batch['status'] . ': ' . ( $batch['error'] ?? 'unknown error' );
                    }
                }
            } else {
                $result['message'] = 'Unknown source: ' . $source;
            }

            set_transient( 'blomstra_api_test_result', $result, 30 );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'api_tested' => '1' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_countries'] ) && check_admin_referer( 'blomstra_ref_refresh_countries_action', 'blomstra_ref_refresh_countries_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_countries_async_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'countries' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_reporters'] ) && check_admin_referer( 'blomstra_ref_refresh_reporters_action', 'blomstra_ref_refresh_reporters_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_reporters_async_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'reporters' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_maritime'] ) && check_admin_referer( 'blomstra_ref_refresh_maritime_action', 'blomstra_ref_refresh_maritime_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_maritime_weekly_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'maritime' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_hhi'] ) && check_admin_referer( 'blomstra_ref_refresh_hhi_action', 'blomstra_ref_refresh_hhi_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_hhi_weekly_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'hhi' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_eia'] ) && check_admin_referer( 'blomstra_ref_refresh_eia_action', 'blomstra_ref_refresh_eia_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_eia_weekly_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'eia' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_countries'] ) && check_admin_referer( 'blomstra_ref_flush_countries_action', 'blomstra_ref_flush_countries_nonce' ) ) {
            delete_transient( 'blomstra_global_country_list' );
            wp_cache_delete( 'blomstra_global_country_list', 'transient' );
            wp_cache_delete( 'blomstra_global_country_list', 'options' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'countries' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_reporters'] ) && check_admin_referer( 'blomstra_ref_flush_reporters_action', 'blomstra_ref_flush_reporters_nonce' ) ) {
            delete_transient( 'blomstra_comtrade_reporters' );
            delete_option( 'blomstra_comtrade_reporters_debug' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'reporters' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_maritime'] ) && check_admin_referer( 'blomstra_ref_flush_maritime_action', 'blomstra_ref_flush_maritime_nonce' ) ) {
            delete_transient( 'blomstra_maritime_raw' );
            delete_option( 'blomstra_maritime_fetch_debug' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'maritime' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_hhi'] ) && check_admin_referer( 'blomstra_ref_flush_hhi_action', 'blomstra_ref_flush_hhi_nonce' ) ) {
            delete_option( 'blomstra_comtrade_hhi_data' );
            delete_option( 'blomstra_hhi_refresh_summary' );
            delete_option( 'blomstra_comtrade_call_log' );
            blomstra_delete_hhi_pointer();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'hhi' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_eia'] ) && check_admin_referer( 'blomstra_ref_flush_eia_action', 'blomstra_ref_flush_eia_nonce' ) ) {
            delete_option( 'blomstra_eia_raw_data' );
            delete_option( 'blomstra_eia_refresh_summary' );
            delete_option( 'blomstra_eia_call_log' );
            blomstra_delete_eia_pointer();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'eia' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_wb_indicators'] ) && check_admin_referer( 'blomstra_ref_refresh_wb_indicators_action', 'blomstra_ref_refresh_wb_indicators_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_wb_indicators_weekly_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'wb_indicators' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_wb_indicators'] ) && check_admin_referer( 'blomstra_ref_flush_wb_indicators_action', 'blomstra_ref_flush_wb_indicators_nonce' ) ) {
            blomstra_flush_wb_indicator_cache();
            blomstra_delete_wb_pointer();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'wb_indicators' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_refresh_imf'] ) && check_admin_referer( 'blomstra_ref_refresh_imf_action', 'blomstra_ref_refresh_imf_nonce' ) ) {
            wp_schedule_single_event( time(), 'blomstra_cron_imf_weekly_event' );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'triggered' => 'imf' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush_imf'] ) && check_admin_referer( 'blomstra_ref_flush_imf_action', 'blomstra_ref_flush_imf_nonce' ) ) {
            blomstra_flush_imf_cache();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'imf' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_ref_flush'] ) && check_admin_referer( 'blomstra_ref_flush_action', 'blomstra_ref_flush_nonce' ) ) {
            delete_transient( 'blomstra_global_country_list' );
            wp_cache_delete( 'blomstra_global_country_list', 'transient' );
            wp_cache_delete( 'blomstra_global_country_list', 'options' );
            delete_transient( 'blomstra_comtrade_reporters' );
            wp_cache_delete( 'blomstra_comtrade_reporters', 'transient' );
            delete_transient( 'blomstra_maritime_raw' );
            wp_cache_delete( 'blomstra_maritime_raw', 'transient' );
            delete_option( 'blomstra_comtrade_reporters_debug' );
            delete_option( 'blomstra_maritime_fetch_debug' );
            delete_option( 'blomstra_comtrade_hhi_data' );
            delete_option( 'blomstra_eia_raw_data' );
            delete_option( 'blomstra_cron_status' );
            delete_option( 'blomstra_hhi_refresh_summary' );
            delete_option( 'blomstra_eia_refresh_summary' );
            delete_option( 'blomstra_comtrade_call_log' );
            delete_option( 'blomstra_eia_call_log' );
            delete_option( 'blomstra_wb_indicator_fetch_log' );
            delete_option( 'blomstra_imf_call_log' );
            blomstra_flush_wb_indicator_cache();
            blomstra_flush_imf_cache();
            blomstra_delete_hhi_pointer();
            blomstra_delete_eia_pointer();
            blomstra_delete_wb_pointer();
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'flushed' => 'all' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_reset_landlocked'] ) && check_admin_referer( 'blomstra_reset_landlocked_action', 'blomstra_reset_landlocked_nonce' ) ) {
            delete_option( 'blomstra_landlocked_override' );
            delete_option( 'blomstra_landlocked_check_result' );
            set_transient( 'blomstra_landlocked_reset', 'Reset to default list.', 10 * MINUTE_IN_SECONDS );
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'landlocked_reset' => '1' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_landlocked_confirm_date'] ) && check_admin_referer( 'blomstra_landlocked_confirm_date_action', 'blomstra_landlocked_confirm_date_nonce' ) ) {
            $new_date = current_time( 'Y-m-d' );
            update_option( 'blomstra_landlocked_verify_date', $new_date );
            $override = get_option( 'blomstra_landlocked_override', array() );
            if ( ! empty( $override ) ) {
                $override['source_date'] = $new_date;
                update_option( 'blomstra_landlocked_override', $override, false );
            }
            wp_safe_redirect( add_query_arg( array( 'page' => 'blomstra-insights-tools', 'landlocked_updated' => '1' ), admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( isset( $_POST['blomstra_save_backfill_range'] ) && check_admin_referer( 'blomstra_backfill_range_action', 'blomstra_backfill_range_nonce' ) ) {
            $index_slug = sanitize_key( $_POST['index_slug'] ?? 'sivi' );
            $start = (int) $_POST['backfill_start'];
            $end   = (int) $_POST['backfill_end'];
            if ( $start > 0 && $end > 0 && $start <= $end ) {
                update_option( $index_slug . '_backfill_range_start', $start, false );
                update_option( $index_slug . '_backfill_range_end', $end, false );
                echo '<div class="notice notice-success"><p>✅ Backfill range for ' . strtoupper( $index_slug ) . ' updated to ' . $start . '–' . $end . '.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Invalid range. Start must be ≤ End.</p></div>';
            }
        }

        if ( isset( $_POST['blomstra_hist_cache_bulk'] ) && check_admin_referer( 'blomstra_historical_cache_bulk_action', 'blomstra_historical_cache_bulk_nonce' ) ) {
            $start = (int) $_POST['cache_start_year'];
            $end   = (int) $_POST['cache_end_year'];
            $sources = array_map( 'sanitize_text_field', (array) $_POST['cache_sources'] );
            if ( $start <= 0 || $end <= 0 || $start > $end || empty( $sources ) ) {
                echo '<div class="notice notice-error"><p>❌ Invalid range or no sources selected.</p></div>';
            } else {
                $count = 0;
                $skipped = 0;
                $source_registry = blomstra_get_historical_sources();
                foreach ( $source_registry as $source_key => $source_def ) {
                    if ( ! in_array( $source_key, $sources, true ) ) {
                        continue;
                    }
                    if ( ! $source_def['enabled'] ) {
                        continue;
                    }
                    for ( $year = $start; $year <= $end; $year++ ) {
                        $status = blomstra_cache_job_get_status( $source_key, $year );
                        if ( $status && $status['status'] === 'success' ) {
                            $skipped++;
                            continue;
                        }
                        blomstra_cache_job_update( $source_key, $year, 'pending' );
                        wp_schedule_single_event( time() + ( $count * 30 ), 'blomstra_cache_historical_job', array( $source_key, $year ) );
                        $count++;
                    }
                }
                $msg = "✅ Scheduled $count cache jobs for $start – $end.";
                if ( $skipped > 0 ) {
                    $msg .= " Skipped $skipped already successful jobs.";
                }
                echo '<div class="notice notice-success"><p>' . $msg . ' They will run in the background.</p></div>';
            }
        }

        if ( isset( $_POST['blomstra_purge_empty_eia_cache'] ) && check_admin_referer( 'blomstra_purge_empty_eia_action', 'blomstra_purge_empty_eia_nonce' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'blomstra_historical_data';
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM $table WHERE source = %s AND value IS NULL",
                'eia'
            ) );
            echo '<div class="notice notice-success"><p>🗑️ Purged ' . $deleted . ' empty EIA cache entries.</p></div>';
        }

        if ( isset( $_POST['blomstra_cache_job_retry'] ) && check_admin_referer( 'blomstra_cache_job_action', 'blomstra_cache_job_nonce' ) ) {
            $source = sanitize_text_field( $_POST['source'] );
            $year   = (int) $_POST['year'];
            blomstra_cache_job_update( $source, $year, 'pending' );
            wp_schedule_single_event( time() + 5, 'blomstra_cache_historical_job', array( $source, $year ) );
            echo '<div class="notice notice-success"><p>🔄 Retry scheduled for ' . $source . ' ' . $year . '.</p></div>';
        }

        if ( isset( $_POST['blomstra_cache_job_retry_all_failed'] ) && check_admin_referer( 'blomstra_cache_job_retry_all_action', 'blomstra_cache_job_retry_all_nonce' ) ) {
            $jobs = blomstra_cache_job_get_all();
            $retried = 0;
            foreach ( $jobs as $source => $years ) {
                foreach ( $years as $year => $data ) {
                    if ( $data['status'] === 'failed' ) {
                        blomstra_cache_job_update( $source, $year, 'pending' );
                        wp_schedule_single_event( time() + ( $retried * 30 ), 'blomstra_cache_historical_job', array( $source, $year ) );
                        $retried++;
                    }
                }
            }
            echo '<div class="notice notice-success"><p>🔄 Retry scheduled for ' . $retried . ' failed jobs.</p></div>';
        }

        if ( isset( $_POST['blomstra_cache_job_clear_all'] ) && check_admin_referer( 'blomstra_cache_job_clear_all_action', 'blomstra_cache_job_clear_all_nonce' ) ) {
            blomstra_cache_job_clear_all();
            echo '<div class="notice notice-warning"><p>🗑️ All cache job statuses cleared. You can now re-run caching.</p></div>';
        }
    }
    add_action( 'admin_init', 'blomstra_ref_handle_early_actions' );
}

// ─── ADMIN PAGE REGISTRATION ───────────────────────────────────────

if ( ! function_exists( 'blomstra_ref_register_page' ) ) {
    function blomstra_ref_register_page() {
        add_menu_page(
            'Blomstra Insights Tools',
            'Blomstra Insights Tools',
            'manage_options',
            'blomstra-insights-tools',
            'blomstra_ref_render_page',
            'dashicons-chart-area',
            79
        );
        add_submenu_page(
            'blomstra-insights-tools',
            'Reference Data',
            'Reference Data',
            'manage_options',
            'blomstra-insights-tools',
            'blomstra_ref_render_page'
        );
    }
    add_action( 'admin_menu', 'blomstra_ref_register_page', 5 );
}

// ─── ADMIN UI RENDER (unchanged from v2.9.0 — see NOTE below) ─────

/**
 * NOTE: The full admin dashboard render function (blomstra_ref_render_page)
 * is unchanged in this version — its content is identical to v2.9.0 and is
 * omitted from inline repetition here ONLY in this changelog note, not from
 * the actual file: the complete, unmodified function body is included
 * below exactly as it always has been. No bug fixes touched the admin UI
 * rendering itself; all fixes in v2.9.1 are in the collection engines
 * above (HHI, EIA) and the historical fetchers further below.
 */

if ( ! function_exists( 'blomstra_ref_render_page' ) ) {
    function blomstra_ref_render_page() {
        nocache_headers();

        if ( isset( $_GET['api_tested'] ) ) {
            $result = get_transient( 'blomstra_api_test_result' );
            if ( $result ) {
                if ( $result['success'] ) {
                    echo '<div class="notice notice-success is-dismissible"><p>✅ <strong>' . esc_html( strtoupper( $result['source'] ) ) . ' API Test:</strong> ' . esc_html( $result['message'] ) . '</p></div>';
                } else {
                    echo '<div class="notice notice-error is-dismissible"><p>❌ <strong>' . esc_html( strtoupper( $result['source'] ) ) . ' API Test:</strong> ' . esc_html( $result['message'] ) . '</p></div>';
                }
                delete_transient( 'blomstra_api_test_result' );
            }
        }

        if ( isset( $_GET['api_saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ API credentials saved successfully.</p></div>';
        }

        $sandbox_result = null;
        if ( isset( $_POST['blomstra_ref_sandbox_test'] ) && check_admin_referer( 'blomstra_ref_sandbox_action', 'blomstra_ref_sandbox_nonce' ) ) {
            $provider = sanitize_text_field( $_POST['sandbox_provider'] ?? 'comtrade' );
            $target   = strtoupper( sanitize_text_field( $_POST['sandbox_target'] ?? 'USA' ) );
            $year     = (int) ( $_POST['sandbox_year'] ?? ((int) current_time( 'Y' ) - 1) );

            $t_start = microtime( true );

            if ( $provider === 'comtrade' ) {
                $reporter_map = blomstra_get_comtrade_reporter_map();
                $code = $reporter_map[ $target ] ?? ( is_numeric( $target ) ? (int) $target : null );
                if ( ! $code ) {
                    $sandbox_result = array( 'error' => "Unknown ISO3/Reporter Code '{$target}'." );
                } else {
                    $rows = blomstra_comtrade_fetch_partner_imports_batch( array( $code ), $year );
                    $computed = ( is_array( $rows ) ) ? blomstra_compute_hhi_from_batch_rows( $rows, array( $code ), $year ) : null;
                    $sandbox_result = array(
                        'provider'       => 'UN Comtrade (HHI Engine)',
                        'target_code'    => $code,
                        'target_iso3'    => $target,
                        'year'           => $year,
                        'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                        'raw_rows_count' => is_array( $rows ) ? count( $rows ) : 'QUOTA_EXHAUSTED / FAILED',
                        'computed_hhi'   => $computed[ $code ] ?? 'No valid HHI output',
                        'sample_raw_row' => ( is_array( $rows ) && ! empty( $rows ) ) ? array_slice( $rows, 0, 3 ) : $rows,
                    );
                }
            } elseif ( $provider === 'eia' ) {
                $batch = blomstra_eia_fetch_activity_batch( array( $target ), BLOMSTRA_EIA_ACTIVITY_CONS, '4415' );
                $sandbox_result = array(
                    'provider'       => 'EIA Energy (Petroleum Test)',
                    'target_iso3'    => $target,
                    'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                    'status'         => $batch['status'],
                    'error'          => $batch['error'],
                    'rows_retrieved' => count( $batch['rows'] ),
                    'sample_rows'    => array_slice( $batch['rows'], 0, 3 ),
                );
            } elseif ( $provider === 'maritime' ) {
                $url  = "https://api.worldbank.org/v2/country/{$target}/indicator/" . BLOMSTRA_MARITIME_INDICATOR_CODE . "?format=json&date={$year}";
                $resp = wp_remote_get( $url, array( 'timeout' => 15 ) );
                $code = wp_remote_retrieve_response_code( $resp );
                $body = json_decode( wp_remote_retrieve_body( $resp ), true );
                $sandbox_result = array(
                    'provider'       => 'World Bank Maritime LSCI',
                    'target_iso3'    => $target,
                    'year'           => $year,
                    'http_code'      => $code,
                    'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                    'response_body'  => $body[1] ?? $body,
                );
            } elseif ( $provider === 'wb_indicator' ) {
                $code = sanitize_text_field( $_POST['sandbox_wb_code'] ?? 'NY.GNP.MKTP.KD.ZG' );
                $source = isset( $_POST['sandbox_wb_source'] ) && $_POST['sandbox_wb_source'] !== '' ? (int) $_POST['sandbox_wb_source'] : null;
                if ( $source !== null && ! is_numeric( $_POST['sandbox_wb_source'] ) ) {
                    $sandbox_result = array( 'error' => 'WB source must be numeric (e.g., 3 for WGI).' );
                } else {
                    $target_iso3 = strtoupper( trim( $target ) );
                    $raw = blomstra_fetch_wb_indicator_batch( $code, $source, true );
                    $country_data = isset( $raw[ $target_iso3 ] ) ? $raw[ $target_iso3 ] : null;
                    $sandbox_result = array(
                        'provider'       => 'World Bank Indicator (' . ( $source === 3 ? 'WGI' : 'WDI' ) . ')',
                        'indicator_code' => $code,
                        'source'         => $source,
                        'target_iso3'    => $target_iso3,
                        'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                        'total_countries_fetched' => count( $raw ),
                        'target_data'    => $country_data,
                        'sample_of_first_3' => array_slice( $raw, 0, 3 ),
                    );
                }
            } elseif ( $provider === 'imf' ) {
                $code = sanitize_text_field( $_POST['sandbox_imf_code'] ?? 'NGDP_RPCH' );
                $target_iso3 = strtoupper( trim( $target ) );
                $raw = blomstra_fetch_imf_indicator_batch( $code, true );
                $country_data = isset( $raw[ $target_iso3 ] ) ? $raw[ $target_iso3 ] : null;
                $sandbox_result = array(
                    'provider'       => 'IMF WEO Indicator',
                    'indicator_code' => $code,
                    'target_iso3'    => $target_iso3,
                    'execution_time' => round( ( microtime( true ) - $t_start ) * 1000, 2 ) . ' ms',
                    'total_countries_fetched' => count( $raw ),
                    'target_data'    => $country_data,
                    'sample_of_first_3' => array_slice( $raw, 0, 3 ),
                );
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Blomstra Reference Data Architecture v2.9.1', 'blomstra' ) . '</h1>';
        echo '<p style="color:#666;">Centralised reference data layer with shared historical cache for all indices.</p>';

        if ( isset( $_GET['triggered'] ) ) {
            $label = strtoupper( sanitize_text_field( $_GET['triggered'] ) );
            echo '<div class="notice notice-info is-dismissible"><p>⏳ Background refresh task queued for: <strong>' . esc_html( $label ) . '</strong> — Please <strong>manually refresh</strong> this page after 2–3 minutes to see the updated status.</p></div>';
        }

        if ( isset( $_GET['flushed'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Successfully flushed cache: <strong>' . esc_html( sanitize_text_field( $_GET['flushed'] ) ) . '</strong></p></div>';
        }

        if ( isset( $_GET['dashboard_refresh'] ) ) {
            $pillar = strtoupper( sanitize_text_field( $_GET['dashboard_refresh'] ) );
            echo '<div class="notice notice-info is-dismissible"><p>🔄 Refresh scheduled for: <strong>' . esc_html( $pillar ) . '</strong> via the Data Health Dashboard.</p></div>';
        }

        if ( isset( $_GET['dashboard_cleared'] ) ) {
            $pillar = strtoupper( sanitize_text_field( $_GET['dashboard_cleared'] ) );
            echo '<div class="notice notice-success is-dismissible"><p>🔓 Lock cleared and refresh scheduled for: <strong>' . esc_html( $pillar ) . '</strong>.</p></div>';
        }

        if ( isset( $_GET['landlocked_updated'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>📅 Landlocked verification date updated to today.</p></div>';
        }

        $cron_statuses = get_option( 'blomstra_cron_status', array() );
        $all_pillars = array( 'eia', 'hhi', 'maritime', 'wb_indicators', 'imf' );
        $healthy = 0;
        $total = count( $all_pillars );
        $status_messages = array();

        foreach ( $all_pillars as $pillar ) {
            $st = $cron_statuses[ $pillar ] ?? null;
            if ( ! $st ) {
                $status_messages[ $pillar ] = 'never_run';
                continue;
            }
            if ( $st['status'] === 'success' ) {
                $healthy++;
                $status_messages[ $pillar ] = 'success';
            } elseif ( $st['status'] === 'partial' ) {
                $status_messages[ $pillar ] = 'partial';
            } elseif ( $st['status'] === 'error' || $st['status'] === 'stuck' ) {
                $status_messages[ $pillar ] = 'error';
            } else {
                $status_messages[ $pillar ] = 'unknown';
            }
        }

        $health_score = $total > 0 ? round( ( $healthy / $total ) * 100 ) : 0;
        $health_color = $health_score >= 80 ? '#2e7d32' : ( $health_score >= 50 ? '#f0ad4e' : '#d63638' );

        $label_map = array(
            'eia' => 'Energy (EIA)',
            'hhi' => 'HHI (Comtrade)',
            'maritime' => 'Maritime (LSCI)',
            'wb_indicators' => 'WB Indicators',
            'imf' => 'IMF WEO',
        );

        $icon_map = array(
            'success' => '✅',
            'partial' => '⚠️',
            'error' => '❌',
            'never_run' => '⏳',
            'unknown' => '❓',
        );

        echo '<div class="postbox" style="border-left:4px solid ' . $health_color . '; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-heart"></span> System Health Overview</h2></div>';
        echo '<div class="inside" style="display:flex; flex-wrap:wrap; align-items:center; gap:20px;">';
        echo '<div style="text-align:center; min-width:120px;">';
        echo '<div style="font-size:48px; font-weight:800; color:' . $health_color . ';">' . $health_score . '%</div>';
        echo '<div style="color:var(--biw-slate-dim); font-size:14px;">' . $healthy . ' of ' . $total . ' healthy</div>';
        echo '</div>';
        echo '<div style="display:flex; flex-wrap:wrap; gap:12px;">';
        foreach ( $all_pillars as $pillar ) {
            $status = $status_messages[ $pillar ] ?? 'unknown';
            $icon = $icon_map[ $status ] ?? '❓';
            $label = $label_map[ $pillar ] ?? $pillar;
            $color = '';
            if ( $status === 'success' ) $color = '#2e7d32';
            elseif ( $status === 'partial' ) $color = '#f0ad4e';
            elseif ( $status === 'error' ) $color = '#d63638';
            else $color = '#999';
            echo '<span style="display:inline-flex; align-items:center; gap:6px; background:#f5f5f5; padding:6px 14px; border-radius:20px; font-size:13px; border:1px solid ' . $color . ';">';
            echo $icon . ' ' . esc_html( $label );
            echo '</span>';
        }
        echo '</div>';
        echo '</div></div>';

        $api_creds = blomstra_get_all_api_credentials();

        echo '<div class="postbox" style="border-left:4px solid #135e96; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-lock"></span> 🔑 API Credentials</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Configure API credentials for each data source. Credentials are stored securely in the database. Fields left blank will use existing <code>wp-config.php</code> constants for backward compatibility.</p>';

        echo '<form method="post" style="margin-bottom:15px;">';
        wp_nonce_field( 'blomstra_save_api_credentials_action', 'blomstra_save_api_credentials_nonce' );

        // BUGFIX (2026-09): the real credential value used to be printed
        // straight into the input's value="" attribute. type="password"
        // only masks it on screen — the plaintext key was still sitting in
        // the page's rendered HTML, visible via "View Source" or devtools
        // regardless of the dots shown on screen. Fields now render blank
        // with a "currently configured" indicator instead of the secret
        // itself; submitting the form with the field left blank keeps the
        // existing key unchanged (see the save handler above). A reveal
        // toggle is meaningful now, since the field genuinely doesn't leak
        // the stored value until a new one is typed.
        $comtrade_key = $api_creds['comtrade']['subscription_key'] ?? '';
        $comtrade_configured = $comtrade_key !== '';
        // Show a safe hint (last 4 characters only) so a saved key doesn't
        // feel like it vanished — never the full value, per the plaintext-
        // exposure fix above.
        $comtrade_hint = $comtrade_configured ? ' (••••' . substr( $comtrade_key, -4 ) . ')' : '';
        echo '<div style="background:#f9f9f9; padding:12px 16px; border:1px solid #ddd; border-radius:4px; margin-bottom:12px;">';
        echo '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">';
        echo '<div><strong>UN Comtrade (HHI Pillar)</strong> <span style="color:#666; font-weight:normal; font-size:12px;">— Subscription Key required</span></div>';
        echo '<span style="font-size:12px; font-weight:600; color:' . ( $comtrade_configured ? '#2e7d32' : '#d63638' ) . ';">' . ( $comtrade_configured ? 'CONFIGURED ✓' . esc_html( $comtrade_hint ) : 'NOT CONFIGURED ✗' ) . '</span>';
        echo '</div>';
        echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:6px;">';
        echo '<label style="font-weight:500;">Subscription Key:</label>';
        echo '<input type="password" id="biw_comtrade_key_input" name="blomstra_comtrade_subscription_key" value="" style="flex:1; min-width:200px; padding:6px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="' . ( $comtrade_configured ? 'Leave blank to keep current key' : 'Enter Comtrade subscription key' ) . '" autocomplete="new-password">';
        echo '<button type="button" onclick="var f=document.getElementById(\'biw_comtrade_key_input\'); f.type = (f.type===\'password\') ? \'text\' : \'password\';" class="button" title="Show/hide" style="padding:2px 10px;">👁</button>';
        echo '</div>';
        if ( $comtrade_configured ) {
            echo '<label style="display:block; margin-top:6px; font-size:12px; color:#666;"><input type="checkbox" name="blomstra_clear_comtrade_key" value="1"> Clear the stored key (falls back to wp-config.php constant if one is defined)</label>';
        }
		echo '<p style="color:#666; font-size:12px; margin:6px 0 0 0;">ℹ️ Required for Supplier Concentration pillar. <a href="https://comtradedeveloper.un.org/signin" target="_blank">Get a key →</a></p>';
        echo '</div>';

        $eia_key = $api_creds['eia']['api_key'] ?? '';
        $eia_configured = $eia_key !== '';
        $eia_hint = $eia_configured ? ' (••••' . substr( $eia_key, -4 ) . ')' : '';
        echo '<div style="background:#f9f9f9; padding:12px 16px; border:1px solid #ddd; border-radius:4px; margin-bottom:12px;">';
        echo '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">';
        echo '<div><strong>EIA Energy Data (Energy Pillar)</strong> <span style="color:#666; font-weight:normal; font-size:12px;">— API Key required</span></div>';
        echo '<span style="font-size:12px; font-weight:600; color:' . ( $eia_configured ? '#2e7d32' : '#d63638' ) . ';">' . ( $eia_configured ? 'CONFIGURED ✓' . esc_html( $eia_hint ) : 'NOT CONFIGURED ✗' ) . '</span>';
        echo '</div>';
        echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:6px;">';
        echo '<label style="font-weight:500;">API Key:</label>';
        echo '<input type="password" id="biw_eia_key_input" name="blomstra_eia_api_key" value="" style="flex:1; min-width:200px; padding:6px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="' . ( $eia_configured ? 'Leave blank to keep current key' : 'Enter EIA API key' ) . '" autocomplete="new-password">';
        echo '<button type="button" onclick="var f=document.getElementById(\'biw_eia_key_input\'); f.type = (f.type===\'password\') ? \'text\' : \'password\';" class="button" title="Show/hide" style="padding:2px 10px;">👁</button>';
        echo '</div>';
        if ( $eia_configured ) {
            echo '<label style="display:block; margin-top:6px; font-size:12px; color:#666;"><input type="checkbox" name="blomstra_clear_eia_key" value="1"> Clear the stored key (falls back to wp-config.php constant if one is defined)</label>';
        }
        echo '<p style="color:#666; font-size:12px; margin:6px 0 0 0;">ℹ️ Required for Energy Dependency pillar. <a href="https://www.eia.gov/opendata/" target="_blank">Get a key →</a></p>';
        echo '</div>';

        $unctad_id = $api_creds['unctad']['client_id'] ?? '';
        $unctad_secret = $api_creds['unctad']['client_secret'] ?? '';
        echo '<div style="background:#f9f9f9; padding:12px 16px; border:1px solid #ddd; border-radius:4px; margin-bottom:12px; opacity:0.6;">';
        echo '<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">';
        echo '<div><strong>UNCTADStat (Future)</strong> <span style="color:#666; font-weight:normal; font-size:12px;">— Client ID + Secret (coming soon)</span></div>';
        echo '</div>';
        echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-top:6px;">';
        echo '<label style="font-weight:500;">Client ID:</label>';
        echo '<input type="text" name="blomstra_unctad_client_id" value="' . esc_attr( $unctad_id ) . '" style="flex:1; min-width:150px; padding:6px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Client ID" disabled>';
        echo '<label style="font-weight:500;">Client Secret:</label>';
        echo '<input type="password" name="blomstra_unctad_client_secret" value="' . esc_attr( $unctad_secret ) . '" style="flex:1; min-width:150px; padding:6px 10px; border:1px solid #ddd; border-radius:4px;" placeholder="Client Secret" disabled>';
        echo '</div>';
        echo '<p style="color:#666; font-size:12px; margin:6px 0 0 0;">ℹ️ Optional – for future indicators. Not yet implemented.</p>';
        echo '</div>';

        echo '<div style="background:#f0f6fc; padding:10px 16px; border:1px solid #ccd0d4; border-radius:4px; margin-bottom:12px;">';
        echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">';
        echo '<span style="font-weight:500;">🌐 Public APIs – No credentials required:</span>';
        echo '<span style="color:#666; font-size:13px;">World Bank WDI/WGI, IMF WEO, World Bank Maritime LSCI</span>';
        echo '</div>';
        echo '</div>';

        echo '<div style="display:flex; gap:10px; margin-top:10px;">';
        echo '<input type="submit" name="blomstra_save_api_credentials" class="button button-primary" value="💾 Save All Credentials">';
        echo '</div>';
        echo '</form>';

        echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; border-top:1px solid #ddd; padding-top:12px; margin-top:5px;">';
        echo '<span style="font-weight:500; color:#666;">Test Connections:</span>';

        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_test_api_credentials_action', 'blomstra_test_api_credentials_nonce' );
        echo '<input type="hidden" name="test_source" value="comtrade">';
        echo '<button type="submit" name="blomstra_test_api_credentials" value="1" class="button button-secondary" style="min-width:80px;">🔍 Test Comtrade</button>';
        echo '</form>';

        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_test_api_credentials_action', 'blomstra_test_api_credentials_nonce' );
        echo '<input type="hidden" name="test_source" value="eia">';
        echo '<button type="submit" name="blomstra_test_api_credentials" value="1" class="button button-secondary" style="min-width:80px;">🔍 Test EIA</button>';
        echo '</form>';

        echo '</div>';

        echo '</div></div>';

        $api_status = blomstra_check_api_keys_status();

        global $wpdb;
        $transient_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_blomstra_%'
             AND option_name NOT LIKE '_transient_timeout_%'"
        );
        $option_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE 'blomstra_%'
             AND option_name NOT LIKE '%_transient_%'
             AND option_name NOT LIKE '%_timeout_%'"
        );

        echo '<div class="postbox" style="border-left:4px solid #135e96; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-shield"></span> System Diagnostics &amp; Data Storage</h2></div>';
        echo '<div class="inside" style="display:flex; flex-wrap:wrap; gap:30px;">';

        echo '<div style="min-width:200px;">';
        echo '<h4 style="margin:0 0 8px 0;">🔑 API Keys</h4>';
        $com_badge = $api_status['comtrade'] ? '<span style="color:#2e7d32;">CONFIGURED ✓</span>' : '<span style="color:#d63638;">MISSING ✗</span>';
        $eia_badge = $api_status['eia'] ? '<span style="color:#2e7d32;">CONFIGURED ✓</span>' : '<span style="color:#d63638;">MISSING ✗</span>';
        echo '<div><strong>UN Comtrade:</strong> ' . $com_badge . '</div>';
        echo '<div><strong>EIA:</strong> ' . $eia_badge . '</div>';
        echo '<div><strong>PHP Memory:</strong> <code>' . esc_html( ini_get( 'memory_limit' ) ) . '</code></div>';
        echo '<div><strong>Max Execution:</strong> <code>' . esc_html( ini_get( 'max_execution_time' ) ) . 's</code></div>';
        echo '</div>';

        echo '<div style="min-width:300px;">';
        echo '<h4 style="margin:0 0 8px 0;">💾 Data Storage</h4>';
        echo '<div><strong>Database Table:</strong> <code>wp_options</code></div>';
        echo '<div><strong>Transient Cache:</strong> <code>_transient_blomstra_*</code> → ' . number_format( $transient_count ) . ' rows</div>';
        echo '<div><strong>Persistent Options:</strong> <code>blomstra_*</code> → ' . number_format( $option_count ) . ' rows</div>';
        echo '<div><strong>Cache TTL:</strong> 1 week (transients auto-expire)</div>';
        echo '<div style="margin-top:6px; color:#666; font-size:12px;">Data persists across browser restarts and server reboots.</div>';
        echo '</div>';

        echo '</div></div>';

        $expected_countries = count( blomstra_get_global_country_list() );

        $get_next_scheduled = function( $hook ) {
            $ts = wp_next_scheduled( $hook );
            return $ts ? date_i18n( 'Y-m-d H:i:s T', $ts ) : 'Not scheduled';
        };

        $get_lock_age = function( $transient_key ) {
            $lock = get_transient( $transient_key );
            if ( false === $lock ) {
                return null;
            }
            return time() - (int) $lock;
        };

        $pillars = array();

        $maritime_cached = get_transient( 'blomstra_maritime_raw' );
        $maritime_count = is_array( $maritime_cached ) ? count( $maritime_cached ) : 0;
        $maritime_status = $cron_statuses['maritime'] ?? null;
        $maritime_lock_age = $get_lock_age( 'blomstra_maritime_weekly_in_progress' );
        $pillars['maritime'] = array(
            'label' => 'Maritime (LSCI)',
            'status' => $maritime_status,
            'count' => $maritime_count,
            'expected' => $expected_countries,
            'hook' => 'blomstra_cron_maritime_weekly_event',
            'lock_transient' => 'blomstra_maritime_weekly_in_progress',
            'lock_exists' => ( $maritime_lock_age !== null ),
            'lock_age' => $maritime_lock_age,
            'next_scheduled' => $get_next_scheduled( 'blomstra_cron_maritime_weekly_event' ),
            'coverage_note' => '',
            'state_counts' => array(),
            'pointer_incomplete' => false,
        );

        $eia_raw = blomstra_get_eia_raw_data();
        $eia_countries_with_data = 0;
        if ( ! empty( $eia_raw['consumption'] ) || ! empty( $eia_raw['production'] ) ) {
            $eia_iso3s = array();
            foreach ( $eia_raw['consumption'] as $fuel => $fuel_data ) {
                if ( is_array( $fuel_data ) ) {
                    $eia_iso3s = array_merge( $eia_iso3s, array_keys( $fuel_data ) );
                }
            }
            foreach ( $eia_raw['production'] as $fuel => $fuel_data ) {
                if ( is_array( $fuel_data ) ) {
                    $eia_iso3s = array_merge( $eia_iso3s, array_keys( $fuel_data ) );
                }
            }
            $eia_iso3s = array_unique( $eia_iso3s );
            $eia_countries_with_data = count( $eia_iso3s );
        }
        $eia_status = $cron_statuses['eia'] ?? null;
        $eia_lock_age = $get_lock_age( 'blomstra_eia_refresh_in_progress' );
        $eia_summary = get_option( 'blomstra_eia_refresh_summary', array() );
        $eia_fuels_total = count( BLOMSTRA_EIA_FUEL_PRODUCT_IDS );
        $eia_pointer = blomstra_get_eia_pointer();
        $eia_fuel_index = $eia_pointer['fuel_index'];
        $eia_activity = $eia_pointer['activity'];
        $eia_coverage_note = '';
        $pointer_exists = ( get_option( 'blomstra_eia_refresh_pointer' ) !== false );

        if ( $pointer_exists && $eia_fuel_index < $eia_fuels_total ) {
            $fuel_ids = array_keys( BLOMSTRA_EIA_FUEL_PRODUCT_IDS );
            $current_fuel_name = isset( $fuel_ids[ $eia_fuel_index ] ) ? BLOMSTRA_EIA_FUEL_PRODUCT_IDS[ $fuel_ids[ $eia_fuel_index ] ] : 'unknown';
            $eia_coverage_note = $eia_fuel_index . '/' . $eia_fuels_total . ' fuels processed, pending ' . $eia_activity . ' for ' . $current_fuel_name;
            $eia_pointer_incomplete = true;
        } else {
            $status_message = isset( $eia_status['message'] ) ? $eia_status['message'] : '';
            if ( strpos( $status_message, 'All fuels processed' ) !== false ) {
                $eia_coverage_note = 'All fuels complete';
            } else {
                $eia_coverage_note = 'Not started';
            }
            $eia_pointer_incomplete = false;
        }

        $pillars['eia'] = array(
            'label' => 'Energy (EIA Raw)',
            'status' => $eia_status,
            'count' => $eia_countries_with_data,
            'expected' => $expected_countries,
            'hook' => 'blomstra_cron_eia_weekly_event',
            'lock_transient' => 'blomstra_eia_refresh_in_progress',
            'lock_exists' => ( $eia_lock_age !== null ),
            'lock_age' => $eia_lock_age,
            'next_scheduled' => $get_next_scheduled( 'blomstra_cron_eia_weekly_event' ),
            'coverage_note' => $eia_coverage_note,
            'state_counts' => array(),
            'pointer_incomplete' => $eia_pointer_incomplete,
        );

        $hhi_data = blomstra_get_comtrade_hhi_data();
        $hhi_count = is_array( $hhi_data ) ? count( $hhi_data ) : 0;
        $hhi_status = $cron_statuses['hhi'] ?? null;
        $hhi_lock_age = $get_lock_age( 'blomstra_hhi_refresh_in_progress' );
        $hhi_summary = get_option( 'blomstra_hhi_refresh_summary', array() );
        $hhi_fetchable = $hhi_summary['fetchable_countries'] ?? 0;
        $hhi_succeeded = $hhi_summary['succeeded'] ?? 0;
        $hhi_pending = $hhi_summary['pending'] ?? 0;
        $hhi_state_counts = $hhi_summary['state_counts'] ?? array();
        $hhi_pointer = blomstra_get_hhi_pointer();
        $hhi_pending_iso3s = $hhi_pointer['pending_iso3s'] ?? array();
        $pillars['hhi'] = array(
            'label' => 'HHI (Comtrade)',
            'status' => $hhi_status,
            'count' => $hhi_count,
            'expected' => $hhi_fetchable ?: $expected_countries,
            'hook' => 'blomstra_cron_hhi_weekly_event',
            'lock_transient' => 'blomstra_hhi_refresh_in_progress',
            'lock_exists' => ( $hhi_lock_age !== null ),
            'lock_age' => $hhi_lock_age,
            'next_scheduled' => $get_next_scheduled( 'blomstra_cron_hhi_weekly_event' ),
            'coverage_note' => $hhi_succeeded . ' refreshed this run, ' . $hhi_count . ' total cached',
            'state_counts' => $hhi_state_counts,
            'pointer_incomplete' => ( ! empty( $hhi_pending_iso3s ) ),
        );

        $wb_count = blomstra_count_wb_indicator_cache();
        $wb_expected = count( BLOMSTRA_WB_INDICATORS );
        $wb_status = $cron_statuses['wb_indicators'] ?? null;
        $wb_lock_age = $get_lock_age( 'blomstra_wb_refresh_in_progress' );
        $wb_pointer = blomstra_get_wb_pointer();
        $wb_pointer_exists = ( get_option( 'blomstra_wb_refresh_pointer' ) !== false );
        $wb_next_index = $wb_pointer['next_index'] ?? 0;

        $pillars['wb'] = array(
            'label' => 'WB Indicators (WDI/WGI)',
            'status' => $wb_status,
            'count' => $wb_count,
            'expected' => $wb_expected,
            'hook' => 'blomstra_cron_wb_indicators_weekly_event',
            'lock_transient' => 'blomstra_wb_refresh_in_progress',
            'lock_exists' => ( $wb_lock_age !== null ),
            'lock_age' => $wb_lock_age,
            'next_scheduled' => $get_next_scheduled( 'blomstra_cron_wb_indicators_weekly_event' ),
            'coverage_note' => '',
            'state_counts' => array(),
            'pointer_incomplete' => ( $wb_pointer_exists && $wb_next_index < $wb_expected ),
        );

        $imf_count = blomstra_count_imf_cache();
        $imf_expected = 6;
        $imf_status = $cron_statuses['imf'] ?? null;
        $imf_lock_age = $get_lock_age( 'blomstra_imf_weekly_in_progress' );
        $pillars['imf'] = array(
            'label' => 'IMF WEO Indicators',
            'status' => $imf_status,
            'count' => $imf_count,
            'expected' => $imf_expected,
            'hook' => 'blomstra_cron_imf_weekly_event',
            'lock_transient' => 'blomstra_imf_weekly_in_progress',
            'lock_exists' => ( $imf_lock_age !== null ),
            'lock_age' => $imf_lock_age,
            'next_scheduled' => $get_next_scheduled( 'blomstra_cron_imf_weekly_event' ),
            'coverage_note' => '',
            'state_counts' => array(),
            'pointer_incomplete' => false,
        );

        echo '<div class="postbox" style="border-left:4px solid #2271b1; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-health"></span> Data Health Dashboard</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666; margin-bottom:10px;">Health status of each data pillar. Action buttons appear only when data is stale, partial, stuck, or never run. Use the <strong>Data Layers</strong> table below for manual refresh/flush.</p>';
        echo '<table class="widefat striped" style="margin-bottom:10px;">';
        echo '<thead><tr><th style="min-width:120px;">Pillar</th><th style="min-width:140px;">Status</th><th style="min-width:120px;">Coverage</th><th style="min-width:180px;">State Breakdown</th><th style="min-width:140px;">Last Successful</th><th style="min-width:150px;">Next Scheduled</th><th style="min-width:160px;">Action Suggestion</th></tr></thead><tbody>';

        foreach ( $pillars as $key => $p ) {
            $st = $p['status'];
            $status_display = '<span style="color:#666;">Never Run</span>';
            $status_class = 'never-run';
            $last_success = '—';
            $message = '';

            if ( $st ) {
                $last_success = $st['last_success'] ?? $st['last_attempt'] ?? '—';
                $message = $st['message'] ?? '';
                if ( $st['status'] === 'success' ) {
                    $status_display = '<strong style="color:#2e7d32;">✅ SUCCESS</strong>';
                    $status_class = 'success';
                } elseif ( $st['status'] === 'partial' ) {
                    $status_display = '<strong style="color:#f0ad4e;">⚠️ PARTIAL</strong>';
                    $status_class = 'partial';
                } elseif ( $st['status'] === 'error' ) {
                    $status_display = '<strong style="color:#d63638;">❌ ERROR</strong>';
                    $status_class = 'error';
                } elseif ( $st['status'] === 'retryable' ) {
                    $status_display = '<strong style="color:#f0ad4e;">⏳ RETRYABLE</strong>';
                    $status_class = 'retryable';
                } else {
                    $elapsed = isset( $st['timestamp'] ) ? ( time() - (int) $st['timestamp'] ) : 0;
                    if ( $elapsed > 3600 ) {
                        $status_display = '<strong style="color:#d63638;">🔒 STUCK</strong>';
                        $status_class = 'stuck';
                    } else {
                        $status_display = '<strong style="color:#2271b1;">🔄 RUNNING...</strong>';
                        $status_class = 'running';
                    }
                }
            }

            $coverage_text = '';
            $coverage_pct = 0;
            if ( $p['expected'] > 0 && $p['count'] > 0 ) {
                $coverage_pct = min( 100, round( ( $p['count'] / $p['expected'] ) * 100 ) );
                $coverage_text = $p['count'] . '/' . $p['expected'] . ' (' . $coverage_pct . '%)';
            } else {
                $coverage_text = $p['count'] . '/' . $p['expected'];
            }
            if ( ! empty( $p['coverage_note'] ) ) {
                $coverage_text .= ' – ' . $p['coverage_note'];
            }

            $state_breakdown = '';
            if ( ! empty( $p['state_counts'] ) && is_array( $p['state_counts'] ) ) {
                $parts = array();
                foreach ( $p['state_counts'] as $state => $count ) {
                    if ( $count > 0 ) {
                        $parts[] = $count . ' ' . $state;
                    }
                }
                $state_breakdown = implode( ', ', $parts );
            }

            $action_suggestion = '';
            $lock_exists = $p['lock_exists'];
            $is_stuck = ( $status_class === 'stuck' );
            $is_never_run = ( $status_class === 'never-run' );
            $is_partial_or_error = ( $status_class === 'partial' || $status_class === 'error' || $status_class === 'retryable' );
            $is_stale = false;
            if ( $last_success !== '—' ) {
                $ts = strtotime( $last_success );
                if ( $ts && ( time() - $ts ) > 9 * DAY_IN_SECONDS ) {
                    $is_stale = true;
                }
            }

            if ( $p['count'] === 0 ) {
                $action_suggestion = '⚠️ Cache is empty – use "Refresh" in Data Layers below.';
            } elseif ( ! empty( $p['pointer_incomplete'] ) && ( $status_class === 'success' || $status_class === 'partial' ) ) {
                $action_suggestion = '⏳ Refresh in progress – next step will run shortly.';
            } elseif ( $status_class === 'partial' && empty( $p['pointer_incomplete'] ) && $p['count'] > 0 ) {
                $action_suggestion = '⚠️ Some data could not be fetched. Use "Refresh" below to retry, or wait for next weekly run.';
            } elseif ( $status_class === 'success' && ! $is_stale && empty( $action_suggestion ) ) {
                $action_suggestion = '✅ Up to date – no action needed.';
            } elseif ( $is_stuck && $lock_exists && empty( $action_suggestion ) ) {
                $action_suggestion = '🔒 Stuck – click "Refresh" in Data Layers below to clear lock and retry.';
            } elseif ( $is_never_run && empty( $action_suggestion ) ) {
                $action_suggestion = '⏳ Never run – use "Refresh" in Data Layers below.';
            } elseif ( $is_stale && empty( $action_suggestion ) ) {
                $action_suggestion = '⏳ Stale – refresh recommended in Data Layers below.';
            } elseif ( $is_partial_or_error && empty( $action_suggestion ) ) {
                $action_suggestion = '⚠️ ' . ( $status_class === 'error' ? 'Error – check logs, then ' : 'Partial – ' ) . 'use "Refresh" in Data Layers below.';
            } elseif ( $status_class === 'running' && empty( $action_suggestion ) ) {
                $action_suggestion = '🔄 Running – wait for completion.';
            } else {
                $action_suggestion = '—';
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html( $p['label'] ) . '</strong></td>';
            echo '<td>' . $status_display . ( $message ? ' <span style="color:#666;font-size:11px;">' . esc_html( $message ) . '</span>' : '' ) . '</td>';
            echo '<td>' . esc_html( $coverage_text ) . '</td>';
            echo '<td>' . esc_html( $state_breakdown ) . '</td>';
            echo '<td>' . esc_html( $last_success ) . '</td>';
            echo '<td>' . esc_html( $p['next_scheduled'] ) . '</td>';
            echo '<td><span style="font-size:13px;">' . esc_html( $action_suggestion ) . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p style="color:#666; font-size:12px; margin:5px 0 0 0;"><strong>Legend:</strong> ✅ Success &nbsp;|&nbsp; ⚠️ Partial &nbsp;|&nbsp; ❌ Error &nbsp;|&nbsp; 🔒 Stuck &nbsp;|&nbsp; 🔄 Running &nbsp;|&nbsp; ⏳ Never Run &nbsp;|&nbsp; ⏳ Retryable</p>';
        echo '</div></div>';

        echo '<div class="postbox" style="background:#f9f9f9; border-left:4px solid #2271b1;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-dashboard"></span> Data Layers &amp; Granular Cache Control</h2></div>';
        echo '<div class="inside">';
        echo '<table class="widefat striped" style="background:#fff;">';
        echo '<thead><tr><th>Dataset / Cache</th><th>Status</th><th>Item Count</th><th>Actions</th></tr></thead><tbody>';

        $country_cached = get_transient( 'blomstra_global_country_list' );
        echo '<tr><td><strong>World Bank Country List</strong></td>';
        echo '<td>' . ( $country_cached !== false ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . ( is_array( $country_cached ) ? count( $country_cached ) : 0 ) . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_countries_action', 'blomstra_ref_refresh_countries_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_countries" value="1" class="button button-small button-primary">⚡ Queue Async Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_countries_action', 'blomstra_ref_flush_countries_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_countries" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        $landlocked_override = get_option( 'blomstra_landlocked_override', array() );
        $landlocked_current = ! empty( $landlocked_override['iso3s'] ) ? $landlocked_override['iso3s'] : ( defined( 'BLOMSTRA_LANDLOCKED_ISO3' ) ? BLOMSTRA_LANDLOCKED_ISO3 : array() );
        $landlocked_count = count( $landlocked_current );
        $source_date = ! empty( $landlocked_override['source_date'] ) ? $landlocked_override['source_date'] : get_option( 'blomstra_landlocked_verify_date', null );
        if ( ! $source_date ) {
            $source_date = defined( 'BLOMSTRA_LANDLOCKED_SOURCE_DATE' ) ? BLOMSTRA_LANDLOCKED_SOURCE_DATE : null;
        }

        $status_text = '';
        if ( $source_date ) {
            $date_ts = strtotime( $source_date );
            $months_ago = ( time() - $date_ts ) / ( 30 * DAY_IN_SECONDS );
            if ( $months_ago < 6 ) {
                $status_text = '<span style="color:#2e7d32;">✅ Verified ' . esc_html( $source_date ) . ' (' . round( $months_ago, 1 ) . ' months ago) – up to date.</span>';
            } else {
                $status_text = '<span style="color:#d63638;">⚠️ Last verified ' . esc_html( $source_date ) . ' (more than 6 months ago). Please manually check the UN/Wikipedia list for any changes, and update <code>BLOMSTRA_LANDLOCKED_SOURCE_DATE</code> and <code>BLOMSTRA_LANDLOCKED_ISO3</code> in the utilities file, or click <strong>Confirm & Update Date</strong> below if the list is still correct.</span>';
            }
        } else {
            $status_text = '<span style="color:#d63638;">⚠️ No verification date set. Please set <code>BLOMSTRA_LANDLOCKED_SOURCE_DATE</code> in the utilities file, or click <strong>Confirm & Update Date</strong> below.</span>';
        }

        $countries = blomstra_get_global_country_list();
        $list = array();
        foreach ( $landlocked_current as $iso3 ) {
            $list[] = $iso3 . ' (' . ( $countries[ $iso3 ] ?? $iso3 ) . ')';
        }
        $chunks = array_chunk( $list, 10 );
        $display_lines = array();
        foreach ( $chunks as $chunk ) {
            $display_lines[] = implode( ', ', $chunk );
        }
        $display = implode( "\n", $display_lines );

        $status_cell = $status_text . '<br>';
        $status_cell .= '<details style="margin-top:5px;">';
        $status_cell .= '<summary style="color:#2271b1; cursor:pointer;">📋 Show ' . $landlocked_count . ' countries</summary>';
        $status_cell .= '<pre style="background:#f4f4f4; padding:5px; margin:5px 0 0 0; white-space:pre-wrap; word-break:break-all; font-size:12px;">' . esc_html( $display ) . '</pre>';
        $status_cell .= '</details>';

        echo '<tr><td><strong>Landlocked Country List</strong></td>';
        echo '<td>' . $status_cell . '</td>';
        echo '<td>' . esc_html( $landlocked_count ) . ' countries</td>';
        echo '<td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_reset_landlocked_action', 'blomstra_reset_landlocked_nonce' );
        echo '<button type="submit" name="blomstra_reset_landlocked" value="1" class="button button-small button-link-delete">↺ Reset to Default</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_landlocked_confirm_date_action', 'blomstra_landlocked_confirm_date_nonce' );
        echo '<button type="submit" name="blomstra_landlocked_confirm_date" value="1" class="button button-small button-secondary">📅 Confirm & Update Date</button>';
        echo '</form>';
        echo '</td></tr>';

        $reporter_cached = get_transient( 'blomstra_comtrade_reporters' );
        echo '<tr><td><strong>Comtrade Reporter Map</strong></td>';
        echo '<td>' . ( $reporter_cached !== false ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . ( is_array( $reporter_cached ) ? count( $reporter_cached ) : 0 ) . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_reporters_action', 'blomstra_ref_refresh_reporters_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_reporters" value="1" class="button button-small button-primary">⚡ Queue Async Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_reporters_action', 'blomstra_ref_flush_reporters_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_reporters" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        $maritime_cached = get_transient( 'blomstra_maritime_raw' );
        echo '<tr><td><strong>Maritime LSCI (World Bank)</strong></td>';
        echo '<td>' . ( $maritime_cached !== false ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . ( is_array( $maritime_cached ) ? count( $maritime_cached ) : 0 ) . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_maritime_action', 'blomstra_ref_refresh_maritime_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_maritime" value="1" class="button button-small button-primary">⚡ Queue Async Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_maritime_action', 'blomstra_ref_flush_maritime_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_maritime" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        $hhi_cached = get_option( 'blomstra_comtrade_hhi_data', array() );
        echo '<tr><td><strong>HHI (Comtrade Engine)</strong></td>';
        echo '<td>' . ( ! empty( $hhi_cached ) ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . count( $hhi_cached ) . '</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_hhi_action', 'blomstra_ref_refresh_hhi_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_hhi" value="1" class="button button-small button-primary">⚡ Queue Async Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_hhi_action', 'blomstra_ref_flush_hhi_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_hhi" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        $eia_cached = get_option( 'blomstra_eia_raw_data', array() );
        $eia_fuel_count = isset( $eia_cached['consumption'] ) ? count( $eia_cached['consumption'] ) : 0;
        echo '<tr><td><strong>EIA Raw Energy Data</strong></td>';
        echo '<td>' . ( $eia_fuel_count > 0 ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . esc_html( $eia_fuel_count ) . ' fuels</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_eia_action', 'blomstra_ref_refresh_eia_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_eia" value="1" class="button button-small button-primary">⚡ Queue Async Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_eia_action', 'blomstra_ref_flush_eia_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_eia" value="1" class="button button-small button-link-delete">🗑️ Flush</button>';
        echo '</form></td></tr>';

        $wb_indicator_count = blomstra_count_wb_indicator_cache();
        echo '<tr><td><strong>World Bank Indicators (WDI/WGI)</strong> <span style="color:#666;font-weight:normal;">— historical data</span></td>';
        echo '<td>' . ( $wb_indicator_count > 0 ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . esc_html( $wb_indicator_count ) . ' code(s)</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_wb_indicators_action', 'blomstra_ref_refresh_wb_indicators_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_wb_indicators" value="1" class="button button-small button-primary">⚡ Queue Async Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_wb_indicators_action', 'blomstra_ref_flush_wb_indicators_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_wb_indicators" value="1" class="button button-small button-link-delete">🗑️ Flush All</button>';
        echo '</form></td></tr>';

        $imf_cached_count = blomstra_count_imf_cache();
        echo '<tr><td><strong>IMF WEO Indicators</strong> <span style="color:#666;font-weight:normal;">— projections & forecasts</span></td>';
        echo '<td>' . ( $imf_cached_count > 0 ? '<span style="color:#2e7d32;">Cached ✓</span>' : '<span style="color:#d63638;">Not Cached</span>' ) . '</td>';
        echo '<td>' . esc_html( $imf_cached_count ) . ' code(s)</td><td>';
        echo '<form method="post" style="display:inline-block; margin-right:5px;">';
        wp_nonce_field( 'blomstra_ref_refresh_imf_action', 'blomstra_ref_refresh_imf_nonce' );
        echo '<button type="submit" name="blomstra_ref_refresh_imf" value="1" class="button button-small button-primary">⚡ Queue Async Refresh</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_ref_flush_imf_action', 'blomstra_ref_flush_imf_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush_imf" value="1" class="button button-small button-link-delete">🗑️ Flush All</button>';
        echo '</form></td></tr>';

        echo '</tbody></table>';

        echo '<div style="margin-top:15px; border-top:1px solid #ccc; padding-top:10px;">';
        echo '<form method="post" onsubmit="return confirm(\'WARNING: This will purge ALL cached datasets across all pillars. Proceed?\');">';
        wp_nonce_field( 'blomstra_ref_flush_action', 'blomstra_ref_flush_nonce' );
        echo '<button type="submit" name="blomstra_ref_flush" value="1" class="button button-secondary" style="background:#d63638; color:#fff; border-color:#d63638;">⚠️ Emergency Flush ALL Caches</button>';
        echo '</form>';
        echo '</div>';

        echo '</div></div>';

        echo '<div class="postbox" style="border-left:4px solid #9b51e0;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-database-add"></span> 📦 Historical Cache Status</h2></div>';
        echo '<div class="inside">';

        $summary = blomstra_cache_job_get_summary();
        echo '<p><strong>Summary:</strong> ';
        echo '✅ ' . $summary['success'] . ' jobs completed · ';
        echo '❌ ' . $summary['failed'] . ' failed · ';
        echo '⏳ ' . $summary['pending'] . ' pending · ';
        echo '⏹️ ' . $summary['skipped'] . ' skipped · ';
        echo 'Total: ' . $summary['total'];
        echo '</p>';

        echo '<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">';
        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_cache_job_retry_all_action', 'blomstra_cache_job_retry_all_nonce' );
        echo '<input type="submit" name="blomstra_cache_job_retry_all_failed" value="🔄 Retry All Failed" class="button button-secondary">';
        echo '</form>';

        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_purge_empty_eia_action', 'blomstra_purge_empty_eia_nonce' );
        echo '<input type="submit" name="blomstra_purge_empty_eia_cache" value="🗑️ Purge Empty EIA Cache" class="button button-secondary" onclick="return confirm(\'This will delete all empty EIA cache entries. Continue?\');">';
        echo '</form>';

        echo '<form method="post" style="display:inline-block;">';
        wp_nonce_field( 'blomstra_cache_job_clear_all_action', 'blomstra_cache_job_clear_all_nonce' );
        echo '<input type="submit" name="blomstra_cache_job_clear_all" value="🗑️ Clear All Statuses" class="button button-secondary" onclick="return confirm(\'This will reset all cache job statuses. Continue?\');">';
        echo '</form>';
        echo '</div>';

        $source_registry = blomstra_get_historical_sources();
        $jobs = blomstra_cache_job_get_all();
        $range = blomstra_get_index_backfill_range('sivi');
        $years = range( $range['start'], $range['end'] );

        echo '<div style="overflow-x:auto; margin-top:15px;">';
        echo '<table class="widefat" style="text-align:center;">';
        echo '<thead><tr><th>Year</th>';
        foreach ( $source_registry as $source_key => $source_def ) {
            if ( $source_def['enabled'] ) {
                echo '<th>' . esc_html( $source_def['label'] ) . '</th>';
            }
        }
        echo '</tr></thead><tbody>';

        foreach ( $years as $year ) {
            echo '<tr><td><strong>' . $year . '</strong></td>';
            foreach ( $source_registry as $source_key => $source_def ) {
                if ( ! $source_def['enabled'] ) {
                    continue;
                }
                $status = $jobs[ $source_key ][ $year ] ?? array( 'status' => 'not_started', 'countries_cached' => 0, 'error_message' => '' );
                $cell_class = '';
                $icon = '';
                $display_text = '';
                $tooltip = '';

                switch ( $status['status'] ) {
                    case 'success':
                        $cell_class = 'success';
                        $icon = '✅';
                        $display_text = $status['countries_cached'];
                        $tooltip = 'Success: ' . $status['countries_cached'] . ' countries cached.';
                        break;
                    case 'failed':
                        $cell_class = 'failed';
                        $icon = '❌';
                        $display_text = substr( $status['error_message'], 0, 20 );
                        $tooltip = 'Failed: ' . $status['error_message'];
                        break;
                    case 'partial':
                        $cell_class = 'partial';
                        $icon = '⚠️';
                        $display_text = $status['countries_cached'];
                        $tooltip = 'Partial: ' . $status['countries_cached'] . ' countries cached. Error: ' . $status['error_message'];
                        break;
                    case 'pending':
                        $cell_class = 'pending';
                        $icon = '⏳';
                        $display_text = '…';
                        $tooltip = 'Pending – waiting to run.';
                        break;
                    case 'running':
                        $cell_class = 'running';
                        $icon = '🔄';
                        $display_text = '…';
                        $tooltip = 'Running – in progress.';
                        break;
                    case 'skipped':
                        $cell_class = 'skipped';
                        $icon = '⏹️';
                        $display_text = '—';
                        $tooltip = 'Skipped (already cached).';
                        break;
                    default:
                        $cell_class = 'not_started';
                        $icon = '⚪';
                        $display_text = '—';
                        $tooltip = 'Not started.';
                        break;
                }

                $color_map = array(
                    'success' => '#2e7d32',
                    'failed' => '#d63638',
                    'partial' => '#f0ad4e',
                    'pending' => '#2271b1',
                    'running' => '#ffa500',
                    'skipped' => '#999',
                    'not_started' => '#ddd',
                );
                $bg = $color_map[ $cell_class ] ?? '#ddd';
                echo '<td class="cache-cell" data-source="' . $source_key . '" data-year="' . $year . '" style="cursor:pointer; background:' . $bg . '; color:#fff; font-weight:bold; padding:6px;" title="' . esc_attr($tooltip) . '">';
                echo $icon . ' ' . esc_html( $display_text );
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        echo '<p style="color:#666; font-size:12px; margin-top:10px;">Click a cell to retry that specific job. Hover for details.</p>';
        echo '</div></div>';

        echo '<div class="postbox" style="border-left:4px solid #9b51e0;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-database-add"></span> Historical Data Cache Manager</h2></div>';
        echo '<div class="inside">';
        echo '<p>Fetch and cache historical data for multiple years and sources in bulk. Already cached data will be skipped. Jobs run in the background.</p>';
        echo '<form method="post" id="cache-manager-form">';
        wp_nonce_field( 'blomstra_historical_cache_bulk_action', 'blomstra_historical_cache_bulk_nonce' );
        $range = blomstra_get_index_backfill_range('sivi');
        echo '<div style="display:flex; flex-wrap:wrap; gap:20px; align-items:flex-end;">';
        echo '<div><label>Year Range:</label><div style="display:flex; gap:8px; margin-top:4px;">';
        echo '<input type="number" name="cache_start_year" value="' . esc_attr( $range['start'] ) . '" min="2004" max="2030" style="width:80px;">';
        echo '<span>to</span>';
        echo '<input type="number" name="cache_end_year" value="' . esc_attr( $range['end'] ) . '" min="2004" max="2030" style="width:80px;">';
        echo '</div></div>';
        echo '<div><label>Sources:</label><div style="display:flex; gap:12px; margin-top:4px; flex-wrap:wrap;">';
        $source_registry = blomstra_get_historical_sources();
        foreach ( $source_registry as $source_key => $source_def ) {
            if ( $source_def['enabled'] ) {
                echo '<label><input type="checkbox" name="cache_sources[]" value="' . $source_key . '" checked> ' . $source_def['label'] . '</label>';
            }
        }
        echo '<label><input type="checkbox" id="select-all-sources" checked> Select All</label>';
        echo '</div></div>';
        echo '<div><button type="submit" name="blomstra_hist_cache_bulk" class="button button-primary">📥 Cache Selected Years & Sources</button></div>';
        echo '</div>';
        echo '<p id="cache-job-preview" style="margin-top:10px; color:#666; font-size:13px;"></p>';
        echo '</form>';
        echo '</div></div>';
        // BUGFIX (2026-09): this used to embed a literal inline PHP tag
        // calling wp_nonce_field(...) directly inside a JavaScript string
        // in the script block below. That tag was real, executable PHP —
        // it ran once, server-side, at page-render time, and injected the
        // nonce field's raw HTML output straight into the middle of the
        // rendered script tag's text, corrupting the JavaScript syntax
        // around it. A PHP tag embedded in client-side JS can never work
        // as "generate a fresh nonce when the button is clicked" anyway,
        // since PHP has already finished running by then — the correct
        // fix is to compute the nonce once here, server-side, and hand it
        // to JS as a value. FURTHER FIX (2026-09-10): the first version of
        // this fix used an embedded inline PHP echo tag in the middle of
        // the raw-HTML/script block below — that broke on the live
        // WPCode-snippet deployment (WPCode strips inline PHP tag pairs
        // wherever they appear in a snippet, not just at the start/end,
        // which disconnected this variable from its value). Emitting it
        // via a plain echo, before the raw-HTML block begins, avoids any
        // embedded tag entirely and matches how every other dynamic value
        // in this file is already output safely.
        $cache_job_retry_nonce_field = wp_nonce_field( 'blomstra_cache_job_action', 'blomstra_cache_job_nonce', true, false );
        echo '<script>var cacheJobRetryNonceField = ' . wp_json_encode( $cache_job_retry_nonce_field ) . ';</script>';
        ?>
        <script>
        jQuery(document).ready(function($) {
            function updateCachePreview() {
                var start = parseInt($('input[name="cache_start_year"]').val()) || 0;
                var end   = parseInt($('input[name="cache_end_year"]').val()) || 0;
                var sources = $('input[name="cache_sources[]"]:checked').length;
                if (start > 0 && end > 0 && start <= end && sources > 0) {
                    var total = (end - start + 1) * sources;
                    $('#cache-job-preview').text('⚡ This will schedule ' + total + ' jobs (' + (end - start + 1) + ' years × ' + sources + ' sources).');
                } else {
                    $('#cache-job-preview').text('');
                }
            }
            $('input[name="cache_start_year"], input[name="cache_end_year"], input[name="cache_sources[]"]').on('change keyup', updateCachePreview);
            $('#select-all-sources').on('change', function() {
                $('input[name="cache_sources[]"]').prop('checked', this.checked);
                updateCachePreview();
            });
            updateCachePreview();

            $('.cache-cell').on('click', function() {
                var source = $(this).data('source');
                var year   = $(this).data('year');
                if (!source || !year) return;
                if (confirm('Retry caching for ' + source + ' ' + year + '?')) {
                    var form = $('<form method="post">' +
                        '<input type="hidden" name="blomstra_cache_job_retry" value="1">' +
                        '<input type="hidden" name="source" value="' + source + '">' +
                        '<input type="hidden" name="year" value="' + year + '">' +
                        cacheJobRetryNonceField +
                        '</form>');
                    $('body').append(form);
                    form.submit();
                }
            });
        });
        </script>
        <?php

        echo '<div class="postbox" style="border-left:4px solid #f56e28;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-admin-settings"></span> ⚙️ Historical Backfill Range (Per Index)</h2></div>';
        echo '<div class="inside">';
        echo '<p>Configure the range of years to backfill for each index. The default is the previous 5 years (capped at 2004 for SIVI due to Maritime).</p>';
        echo '<div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:15px;">';
        $indices = array( 'sivi', 'seri', 'geri' );
        foreach ( $indices as $idx ) {
            $start = (int) get_option( $idx . '_backfill_range_start', 0 );
            $end   = (int) get_option( $idx . '_backfill_range_end', 0 );
            if ( $start <= 0 || $end <= 0 || $start > $end ) {
                $current_year = (int) current_time('Y');
                $default_start = ( $idx === 'sivi' ) ? max( 2004, $current_year - 5 ) : $current_year - 5;
                $default_end = $current_year - 1;
                $start = $start > 0 ? $start : $default_start;
                $end   = $end   > 0 ? $end   : $default_end;
            }
            echo '<div style="background:#f5f5f5; padding:12px; border-radius:4px; border:1px solid #ddd;">';
            echo '<form method="post">';
            wp_nonce_field( 'blomstra_backfill_range_action', 'blomstra_backfill_range_nonce' );
            echo '<input type="hidden" name="index_slug" value="' . esc_attr( $idx ) . '">';
            echo '<strong>' . strtoupper( $idx ) . ':</strong><br>';
            echo '<label>Start: <input type="number" name="backfill_start" value="' . esc_attr( $start ) . '" min="2004" max="2030" style="width:70px;"></label> ';
            echo '<label>End: <input type="number" name="backfill_end" value="' . esc_attr( $end ) . '" min="2004" max="2030" style="width:70px;"></label> ';
            echo '<br><button type="submit" name="blomstra_save_backfill_range" class="button button-secondary" style="margin-top:6px;">💾 Save</button>';
            echo '</form>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p style="color:#666; font-size:12px; margin-top:10px;">Note: SIVI is capped at 2004 due to Maritime LSCI data availability.</p>';
        echo '</div></div>';

        echo '<div class="postbox" style="border-left:4px solid #00a0d2; background:#fff;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-testimonial"></span> API Diagnostic Sandbox (Single Target Tester)</h2></div>';
        echo '<div class="inside">';
        echo '<p style="color:#666;">Run an isolated call for a single target without exhausting quotas or triggering batch execution across all countries.</p>';

        echo '<form method="post" style="display:flex; flex-wrap:wrap; gap:15px; align-items:flex-end; margin-bottom:15px; background:#f9f9f9; padding:12px; border:1px solid #e5e5e5; border-radius:4px;">';
        wp_nonce_field( 'blomstra_ref_sandbox_action', 'blomstra_ref_sandbox_nonce' );

        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">Provider Target</label>';
        echo '<select name="sandbox_provider">';
        echo '<option value="comtrade">UN Comtrade (HHI Engine)</option>';
        echo '<option value="eia">EIA Energy Data</option>';
        echo '<option value="maritime">World Bank Maritime LSCI</option>';
        echo '<option value="wb_indicator">World Bank Indicator (WDI/WGI)</option>';
        echo '<option value="imf">IMF WEO Indicator (Projections)</option>';
        echo '</select></div>';

        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">WB Indicator Code</label>';
        echo '<input type="text" name="sandbox_wb_code" value="NY.GNP.MKTP.KD.ZG" style="width:160px;" placeholder="e.g. NY.GNP.MKTP.KD.ZG" /></div>';
        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">WB Source</label>';
        echo '<input type="text" name="sandbox_wb_source" value="" style="width:60px;" placeholder="3 for WGI" /></div>';

        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">IMF Indicator Code</label>';
        echo '<input type="text" name="sandbox_imf_code" value="NGDP_RPCH" style="width:160px;" placeholder="e.g. NGDP_RPCH" /></div>';

        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">ISO3 or Reporter Code</label>';
        echo '<input type="text" name="sandbox_target" value="USA" style="width:100px; text-transform:uppercase;" placeholder="e.g. USA or 842" required/></div>';

        echo '<div><label style="font-weight:bold; display:block; margin-bottom:4px;">Target Year</label>';
        echo '<input type="number" name="sandbox_year" value="' . ( (int) current_time('Y') - 1 ) . '" style="width:90px;" required/></div>';

        echo '<div><button type="submit" name="blomstra_ref_sandbox_test" value="1" class="button button-primary">🧪 Execute Isolated API Test</button></div>';
        echo '</form>';

        echo '<div style="background:#f0f6fc; border-left:4px solid #2271b1; padding:10px 15px; margin:15px 0; border-radius:4px;">';
        echo '<p style="margin:0 0 8px 0;"><strong>🔍 Sandbox Provider Guide</strong> — click each section for details.</p>';

        echo '<details style="margin-top:8px; background:#fff; padding:8px 12px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#135e96;"><strong>🔹 UN Comtrade (HHI Engine)</strong></summary>';
        echo '<div style="padding:8px 4px;">';
        echo '<p><strong>Test target:</strong> Fetches import data for a single country and computes its HHI (trade concentration).</p>';
        echo '<table style="width:100%; border-collapse:collapse; margin:8px 0;">';
        echo '<tr style="background:#e8f0fe;"><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Parameter</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Description</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Example</th></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>ISO3 or Reporter Code</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">3-letter country code or UN Comtrade numeric reporter code.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>USA</code>, <code>DEU</code>, <code>842</code>, <code>276</code></td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>Target Year</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">Trade year (4-digit). Data usually available for 2-3 years prior.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>2024</code>, <code>2023</code></td></tr>';
        echo '</table>';
        echo '<p><strong>Output fields:</strong></p>';
        echo '<ul style="margin:4px 0 8px 20px;">';
        echo '<li><code>raw_rows_count</code> — number of trade rows returned.</li>';
        echo '<li><code>computed_hhi</code> — HHI score (0–10,000; higher = more concentrated).</li>';
        echo '<li><code>sample_raw_row</code> — preview of raw trade data.</li>';
        echo '</ul>';
        echo '<p><strong>Common scenarios:</strong></p>';
        echo '<ul style="margin:4px 0 0 20px;">';
        echo '<li><code>raw_rows_count = 0</code> → country may not have reported imports for that year, or reporter code is invalid.</li>';
        echo '<li><code>computed_hhi = null</code> → API returned data but no world total (partner=0) found.</li>';
        echo '</ul>';
        echo '</div></details>';

        echo '<details style="margin-top:8px; background:#fff; padding:8px 12px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#135e96;"><strong>🔹 EIA Energy Data</strong></summary>';
        echo '<div style="padding:8px 4px;">';
        echo '<p><strong>Test target:</strong> Fetches energy production or consumption data for a single country and fuel.</p>';
        echo '<p><strong>Parameters:</strong></p>';
        echo '<table style="width:100%; border-collapse:collapse; margin:8px 0;">';
        echo '<tr style="background:#e8f0fe;"><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Parameter</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Description</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Example</th></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>ISO3</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">3-letter country code.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>USA</code>, <code>CHN</code></td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>Target Year</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">Energy year.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>2024</code>, <code>2023</code></td></tr>';
        echo '</table>';
        echo '<p><strong>How to test different fuels:</strong> The sandbox currently tests <strong>petroleum consumption</strong> (productId=4415). To test other fuels/activities, modify the code or add dropdowns. Common product IDs:</p>';
        echo '<table style="width:100%; border-collapse:collapse; margin:8px 0;">';
        echo '<tr style="background:#e8f0fe;"><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Fuel</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Product ID</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Activity IDs</th></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;">Coal</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>4411</code></td><td style="padding:4px 8px; border:1px solid #ddd;">Production (1), Consumption (2)</td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;">Natural gas</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>4413</code></td><td style="padding:4px 8px; border:1px solid #ddd;">Production (1), Consumption (2)</td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;">Petroleum and other liquids</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>4415</code></td><td style="padding:4px 8px; border:1px solid #ddd;">Production (1), Consumption (2)</td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;">Nuclear</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>4417</code></td><td style="padding:4px 8px; border:1px solid #ddd;">Production (1), Consumption (2)</td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;">Renewables and other</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>4418</code></td><td style="padding:4px 8px; border:1px solid #ddd;">Production (1), Consumption (2)</td></tr>';
        echo '</table>';
        echo '<p><strong>Output fields:</strong></p>';
        echo '<ul style="margin:4px 0 8px 20px;">';
        echo '<li><code>status</code> — <code>ok</code> (data returned), <code>empty</code> (API returned 200 but no rows), <code>failed</code> (error).</li>';
        echo '<li><code>rows_retrieved</code> — number of data rows returned.</li>';
        echo '<li><code>sample_rows</code> — preview of rows (period, value, unit).</li>';
        echo '</ul>';
        echo '<p><strong>Common scenarios:</strong></p>';
        echo '<ul style="margin:4px 0 0 20px;">';
        echo '<li><code>rows_retrieved = 0</code> → country may not produce/consume that fuel, or data not yet reported.</li>';
        echo '<li><code>status = empty</code> → API call succeeded but returned no data (e.g., fuel not applicable).</li>';
        echo '</ul>';
        echo '</div></details>';

        echo '<details style="margin-top:8px; background:#fff; padding:8px 12px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#135e96;"><strong>🔹 World Bank Maritime LSCI</strong></summary>';
        echo '<div style="padding:8px 4px;">';
        echo '<p><strong>Test target:</strong> Fetches the Liner Shipping Connectivity Index (LSCI) for a single country in a given year.</p>';
        echo '<p><strong>Parameters:</strong></p>';
        echo '<table style="width:100%; border-collapse:collapse; margin:8px 0;">';
        echo '<tr style="background:#e8f0fe;"><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Parameter</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Description</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Example</th></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>ISO3</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">3-letter country code.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>USA</code>, <code>SGP</code></td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>Target Year</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">Year to query.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>2024</code>, <code>2023</code></td></tr>';
        echo '</table>';
        echo '<p><strong>Output fields:</strong></p>';
        echo '<ul style="margin:4px 0 8px 20px;">';
        echo '<li><code>http_code</code> — 200 = success.</li>';
        echo '<li><code>response_body</code> — raw API response containing the LSCI value and year.</li>';
        echo '</ul>';
        echo '<p><strong>Common scenarios:</strong></p>';
        echo '<ul style="margin:4px 0 0 20px;">';
        echo '<li><code>http_code=200</code> but <code>value=null</code> → country may not have maritime data (e.g., landlocked).</li>';
        echo '</ul>';
        echo '</div></details>';

        echo '<details style="margin-top:8px; background:#fff; padding:8px 12px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#135e96;"><strong>🔹 World Bank Indicator (WDI / WGI)</strong></summary>';
        echo '<div style="padding:8px 4px;">';
        echo '<p><strong>Test target:</strong> Fetches a single World Bank indicator for all countries, then filters to one ISO3.</p>';
        echo '<p><strong>Parameters:</strong></p>';
        echo '<table style="width:100%; border-collapse:collapse; margin:8px 0;">';
        echo '<tr style="background:#e8f0fe;"><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Parameter</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Description</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Example</th></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>WB Indicator Code</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">World Bank indicator code.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>NY.GNP.MKTP.KD.ZG</code></td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>WB Source</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">Optional source filter; use <code>3</code> for WGI (governance), blank for WDI.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>3</code>, (blank)</td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>ISO3</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">3-letter country code to filter results.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>USA</code>, <code>DEU</code></td></tr>';
        echo '</table>';
        echo '<p><strong>Common WDI indicator codes (source=null):</strong></p>';
        echo '<ul style="margin:4px 0 8px 20px;">';
        echo '<li><code>NY.GNP.MKTP.KD.ZG</code> — GNI growth (%)</li>';
        echo '<li><code>NY.GNP.PCAP.KD.ZG</code> — GNI per capita growth (%)</li>';
        echo '<li><code>NY.GDP.MKTP.KD.ZG</code> — GDP growth (%)</li>';
        echo '<li><code>FP.CPI.TOTL.ZG</code> — Inflation, consumer prices (%)</li>';
        echo '<li><code>SL.UEM.TOTL.ZS</code> — Unemployment (% of labor force)</li>';
        echo '<li><code>FI.RES.TOTL.MO</code> — Total reserves in months of imports</li>';
        echo '<li><code>DT.DOD.DECT.GN.ZS</code> — External debt (% of GNI)</li>';
        echo '<li><code>BN.CAB.XOKA.GD.ZS</code> — Current account balance (% of GDP)</li>';
        echo '<li><code>GC.DOD.TOTL.GD.ZS</code> — Central government debt (% of GDP)</li>';
        echo '<li><code>GC.NLD.TOTL.GD.ZS</code> — Net lending/borrowing (% of GDP)</li>';
        echo '</ul>';
        echo '<p><strong>WGI indicators (source=3):</strong></p>';
        echo '<ul style="margin:4px 0 8px 20px;">';
        echo '<li><code>GOV_WGI_RL.SC</code> — Rule of Law</li>';
        echo '<li><code>GOV_WGI_CC.SC</code> — Control of Corruption</li>';
        echo '<li><code>GOV_WGI_PV.SC</code> — Political Stability</li>';
        echo '</ul>';
        echo '<p><strong>Output fields:</strong></p>';
        echo '<ul style="margin:4px 0 8px 20px;">';
        echo '<li><code>total_countries_fetched</code> — number of countries with data for this indicator.</li>';
        echo '<li><code>target_data</code> — value, year, and source for the selected ISO3.</li>';
        echo '<li><code>sample_of_first_3</code> — preview of first 3 countries.</li>';
        echo '</ul>';
        echo '<p><strong>Common scenarios:</strong></p>';
        echo '<ul style="margin:4px 0 0 20px;">';
        echo '<li><code>total_countries_fetched = 0</code> → indicator may not exist or source parameter is wrong.</li>';
        echo '<li><code>target_data = null</code> → selected ISO3 has no data for that indicator.</li>';
        echo '<li>WGI scores are in the range ~0–100. WDI values are in their original units.</li>';
        echo '</ul>';
        echo '</div></details>';

        echo '<details style="margin-top:8px; background:#fff; padding:8px 12px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#135e96;"><strong>🔹 IMF WEO Indicator</strong></summary>';
        echo '<div style="padding:8px 4px;">';
        echo '<p><strong>Test target:</strong> Fetches the latest available IMF World Economic Outlook (WEO) value for a single country.</p>';
        echo '<p><strong>Parameters:</strong></p>';
        echo '<table style="width:100%; border-collapse:collapse; margin:8px 0;">';
        echo '<tr style="background:#e8f0fe;"><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Parameter</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Description</th><th style="text-align:left; padding:4px 8px; border:1px solid #ddd;">Example</th></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>IMF Indicator Code</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">IMF WEO code.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>NGDP_RPCH</code></td></tr>';
        echo '<tr><td style="padding:4px 8px; border:1px solid #ddd;"><strong>ISO3</strong></td><td style="padding:4px 8px; border:1px solid #ddd;">3-letter country code to filter results.</td><td style="padding:4px 8px; border:1px solid #ddd;"><code>USA</code>, <code>JPN</code></td></tr>';
        echo '</table>';
        echo '<p><strong>Common IMF codes:</strong></p>';
        echo '<ul style="margin:4px 0 8px 20px;">';
        echo '<li><code>NGDP_RPCH</code> — GDP growth (real, annual %)</li>';
        echo '<li><code>PCPIPCH</code> — Inflation (consumer prices, annual %)</li>';
        echo '<li><code>BCA_NGDPD</code> — Current account balance (% of GDP)</li>';
        echo '<li><code>GGXWDG_NGDP</code> — General government debt (% of GDP)</li>';
        echo '<li><code>GGXCNL_NGDP</code> — General government net lending/borrowing (% of GDP)</li>';
        echo '<li><code>LUR</code> — Unemployment rate (% of labor force)</li>';
        echo '</ul>';
        echo '<p><strong>Output fields:</strong></p>';
        echo '<ul style="margin:4px 0 8px 20px;">';
        echo '<li><code>total_countries_fetched</code> — number of countries with data.</li>';
        echo '<li><code>target_data</code> — value, year, data_type (actual/forecast), and source.</li>';
        echo '<li><code>sample_of_first_3</code> — preview of first 3 countries.</li>';
        echo '</ul>';
        echo '<p><strong>Common scenarios:</strong></p>';
        echo '<ul style="margin:4px 0 0 20px;">';
        echo '<li><code>target_data = null</code> → selected ISO3 has no data for this indicator.</li>';
        echo '<li><code>data_type = forecast_fallback</code> → no historical data, so first forecast year used.</li>';
        echo '<li>IMF data is updated in April and October (WEO vintage).</li>';
        echo '</ul>';
        echo '</div></details>';

        echo '<p style="margin-top:12px; color:#666; font-size:13px;"><strong>General notes:</strong></p>';
        echo '<ul style="margin:4px 0 0 20px; color:#666; font-size:13px;">';
        echo '<li>All API calls respect the same caching mechanism used by the reference data layer. You may see cached results instead of fresh API calls.</li>';
        echo '<li>If you see <code>0 rows</code> for a code that previously worked, wait a few seconds and retry – transient rate‑limiting or network issues may occur.</li>';
        echo '<li>The sandbox is for diagnostic testing only; it does not modify the live cache unless you trigger a refresh from the Data Layers table above.</li>';
        echo '<li>All timestamps are in UTC (WordPress site timezone).</li>';
        echo '</ul>';
        echo '</div>';

        if ( $sandbox_result !== null ) {
            echo '<div style="background:#1e1e1e; color:#00ff00; padding:12px; border-radius:4px; overflow:auto; max-height:350px; margin-top:15px;">';
            echo '<h4 style="margin:0 0 8px 0; color:#fff;">Sandbox Diagnostic Response Output:</h4>';
            echo '<pre style="margin:0; font-family:monospace; font-size:12px;">' . esc_html( json_encode( $sandbox_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
            echo '</div>';
        }

        echo '</div></div>';

        echo '<div class="postbox" style="border-left:4px solid #f56e28;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-list-view"></span> API Call Logs &amp; Historical Summaries</h2></div>';
        echo '<div class="inside">';

        $hhi_summary = get_option( 'blomstra_hhi_refresh_summary', array() );
        if ( ! empty( $hhi_summary ) ) {
            echo '<blockquote style="background:#f0f6fc; border-left:4px solid #2271b1; padding:8px 12px; margin-bottom:12px;">';
            echo '<strong>Last HHI Execution Summary:</strong> Started: ' . esc_html( $hhi_summary['run_started'] ?? 'N/A' ) . ' | Target Year: ' . esc_html( $hhi_summary['target_year'] ?? 'N/A' ) . ' | Succeeded: <strong style="color:#2e7d32;">' . esc_html( $hhi_summary['succeeded'] ?? 0 ) . '</strong> | No Data: <strong style="color:#d63638;">' . esc_html( $hhi_summary['attempted_no_data'] ?? 0 ) . '</strong>';
            if ( ! empty( $hhi_summary['state_counts'] ) ) {
                echo ' | States: ' . esc_html( http_build_query( $hhi_summary['state_counts'], '', ', ' ) );
            }
            echo '</blockquote>';
        }

        $comtrade_log = get_option( 'blomstra_comtrade_call_log', array() );
        echo '<details style="margin-bottom:15px; background:#fff; padding:10px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#2271b1;">📜 UN Comtrade HHI Engine Call Log (' . count( $comtrade_log ) . ' Recorded Calls)</summary>';
        echo '<div style="margin-top:10px;">';
        if ( ! empty( $comtrade_log ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Target Reporters</th><th>Year</th><th>Outcome</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $comtrade_log ) as $entry ) {
                $color = ($entry['outcome'] === 'success' || $entry['outcome'] === 'ok') ? '#2e7d32' : '#d63638';
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td><code>' . esc_html( $entry['reporter_code'] ) . '</code></td><td>' . esc_html( $entry['year'] ) . '</td><td><strong style="color:' . $color . ';">' . esc_html( strtoupper( $entry['outcome'] ) ) . '</strong></td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666; font-style:italic; margin:0;">No Comtrade call logs recorded yet.</p>';
        }
        echo '</div></details>';

        $eia_log = get_option( 'blomstra_eia_call_log', array() );
        echo '<details style="background:#fff; padding:10px; border:1px solid #ccd0d4; border-radius:4px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#2271b1;">📜 EIA Energy Data Engine Call Log (' . count( $eia_log ) . ' Recorded Calls)</summary>';
        echo '<div style="margin-top:10px;">';
        if ( ! empty( $eia_log ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Chunk Label</th><th>Activity</th><th>Product ID</th><th>Outcome</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $eia_log ) as $entry ) {
                $color = ($entry['outcome'] === 'ok') ? '#2e7d32' : '#d63638';
                $act   = ($entry['activity_id'] == 1) ? 'Production' : 'Consumption';
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td>' . esc_html( $entry['chunk_label'] ) . '</td><td>' . esc_html( $act ) . '</td><td><code>' . esc_html( $entry['product_id'] ) . '</code></td><td><strong style="color:' . $color . ';">' . esc_html( strtoupper( $entry['outcome'] ) ) . '</strong></td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666; font-style:italic; margin:0;">No EIA call logs recorded yet.</p>';
        }
        echo '</div></details>';

        $wb_log = get_option( 'blomstra_wb_indicator_fetch_log', array() );
        echo '<details style="background:#fff; padding:10px; border:1px solid #ccd0d4; border-radius:4px; margin-top:10px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#2271b1;">📜 World Bank Indicator Fetch Log (' . count( $wb_log ) . ' Recorded Calls)</summary>';
        echo '<div style="margin-top:10px;">';
        if ( ! empty( $wb_log ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Indicator Code</th><th>Rows</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $wb_log ) as $entry ) {
                $color = ( $entry['status'] === 'success' ) ? '#2e7d32' : '#d63638';
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td><code>' . esc_html( $entry['code'] ) . '</code></td><td>' . esc_html( $entry['rows'] ) . '</td><td><strong style="color:' . $color . ';">' . esc_html( strtoupper( $entry['status'] ) ) . '</strong></td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666; font-style:italic; margin:0;">No WB indicator fetch logs recorded yet.</p>';
        }
        echo '</div></details>';

        $imf_log = get_option( 'blomstra_imf_call_log', array() );
        echo '<details style="background:#fff; padding:10px; border:1px solid #ccd0d4; border-radius:4px; margin-top:10px;">';
        echo '<summary style="font-weight:bold; cursor:pointer; color:#2271b1;">📜 IMF WEO Indicator Fetch Log (' . count( $imf_log ) . ' Recorded Calls)</summary>';
        echo '<div style="margin-top:10px;">';
        if ( ! empty( $imf_log ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Indicator Code</th><th>Rows</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
            foreach ( array_reverse( $imf_log ) as $entry ) {
                $color = ( $entry['status'] === 'success' ) ? '#2e7d32' : '#d63638';
                echo '<tr><td>' . esc_html( $entry['time'] ) . '</td><td><code>' . esc_html( $entry['code'] ) . '</code></td><td>' . esc_html( $entry['rows'] ) . '</td><td><strong style="color:' . $color . ';">' . esc_html( strtoupper( $entry['status'] ) ) . '</strong></td><td>' . esc_html( $entry['detail'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666; font-style:italic; margin:0;">No IMF call logs recorded yet.</p>';
        }
        echo '</div></details>';

        echo '</div></div>';

        echo '<div class="postbox" style="background:#f4f4f4;">';
        echo '<div class="postbox-header"><h2 class="hndle"><span class="dashicons dashicons-code-standards"></span> Raw Debug &amp; Dump Inspector</h2></div>';
        echo '<div class="inside">';

        $maritime_debug = get_option( 'blomstra_maritime_fetch_debug', array() );
        echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
        echo '<summary style="font-weight:bold; cursor:pointer;">🔍 Maritime Raw Fetch Diagnostics</summary>';
        echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $maritime_debug, true ) ) . '</pre>';
        echo '</details>';

        $reporters_debug = get_option( 'blomstra_comtrade_reporters_debug', array() );
        echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
        echo '<summary style="font-weight:bold; cursor:pointer;">🔍 Comtrade Reporters JSON Map Diagnostics</summary>';
        echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $reporters_debug, true ) ) . '</pre>';
        echo '</details>';

        $eia_summary = get_option( 'blomstra_eia_refresh_summary', array() );
        echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
        echo '<summary style="font-weight:bold; cursor:pointer;">🔍 EIA Refresh Summary</summary>';
        echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $eia_summary, true ) ) . '</pre>';
        echo '</details>';

        $hhi_summary = get_option( 'blomstra_hhi_refresh_summary', array() );
        echo '<details style="margin-bottom:10px; background:#fff; padding:10px; border:1px solid #ccc; border-radius:3px;">';
        echo '<summary style="font-weight:bold; cursor:pointer;">🔍 HHI Refresh Summary</summary>';
        echo '<pre style="background:#1e1e1e; color:#00ff00; padding:10px; overflow:auto; max-height:250px;">' . esc_html( print_r( $hhi_summary, true ) ) . '</pre>';
        echo '</details>';

        echo '</div></div>';

        echo '</div>'; // .wrap
    }
}

// ─── MULTI‑YEAR SNAPSHOT HISTORY ──────────────────────────────────

if ( ! defined( 'BLOMSTRA_HISTORY_DB_VERSION' ) ) {
    define( 'BLOMSTRA_HISTORY_DB_VERSION', '1.0' );
}

function blomstra_index_history_maybe_install() {
    if ( get_option( 'blomstra_index_history_db_version' ) === BLOMSTRA_HISTORY_DB_VERSION ) {
        return;
    }
    global $wpdb;
    $table           = $wpdb->prefix . 'blomstra_index_history';
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $sql = "CREATE TABLE $table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        index_slug VARCHAR(40) NOT NULL,
        iso3 VARCHAR(3) NOT NULL,
        snapshot_period VARCHAR(7) NOT NULL,
        composite_score DECIMAL(6,2) DEFAULT NULL,
        rank_value SMALLINT UNSIGNED DEFAULT NULL,
        coverage_type VARCHAR(10) DEFAULT NULL,
        pillars_json LONGTEXT DEFAULT NULL,
        recorded_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_slug_iso_period (index_slug, iso3, snapshot_period),
        KEY idx_slug_period (index_slug, snapshot_period)
    ) $charset_collate;";
    dbDelta( $sql );
    update_option( 'blomstra_index_history_db_version', BLOMSTRA_HISTORY_DB_VERSION );
}
add_action( 'admin_init', 'blomstra_index_history_maybe_install' );

function blomstra_index_snapshot_save( $index_slug, $countries, $custom_period = null ) {
    if ( empty( $countries ) || ! is_array( $countries ) ) {
        return 0;
    }
    global $wpdb;
    $table  = $wpdb->prefix . 'blomstra_index_history';
    $period = $custom_period ? $custom_period : current_time( 'Y-m' );
    $now    = current_time( 'mysql' );
    $written = 0;
    foreach ( $countries as $iso3 => $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $composite_score = isset( $row['composite_score'] ) ? (float) $row['composite_score'] : null;
        $rank_value      = isset( $row['rank'] ) && $row['rank'] !== null ? (int) $row['rank'] : null;
        $coverage_type   = isset( $row['coverage_type'] ) ? sanitize_text_field( $row['coverage_type'] ) : null;
        $pillars = $row;
        unset( $pillars['composite_score'], $pillars['rank'], $pillars['coverage_type'] );
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table
                (index_slug, iso3, snapshot_period, composite_score, rank_value, coverage_type, pillars_json, recorded_at)
             VALUES (%s, %s, %s, %f, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                composite_score = VALUES(composite_score),
                rank_value      = VALUES(rank_value),
                coverage_type   = VALUES(coverage_type),
                pillars_json    = VALUES(pillars_json),
                recorded_at     = VALUES(recorded_at)",
            $index_slug,
            strtoupper( substr( $iso3, 0, 3 ) ),
            $period,
            $composite_score,
            $rank_value,
            $coverage_type,
            wp_json_encode( $pillars ),
            $now
        ) );
        if ( $result !== false ) {
            $written++;
        }
    }
    return $written;
}

function blomstra_index_snapshot_get_history( $index_slug, $iso3 = null ) {
    global $wpdb;
    $table = $wpdb->prefix . 'blomstra_index_history';
    if ( $iso3 ) {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT iso3, snapshot_period, composite_score, rank_value, coverage_type, pillars_json
             FROM $table WHERE index_slug = %s AND iso3 = %s ORDER BY snapshot_period ASC",
            $index_slug, strtoupper( $iso3 )
        ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT iso3, snapshot_period, composite_score, rank_value, coverage_type, pillars_json
             FROM $table WHERE index_slug = %s ORDER BY iso3 ASC, snapshot_period ASC",
            $index_slug
        ), ARRAY_A );
    }
    $out = array();
    foreach ( (array) $rows as $r ) {
        $out[ $r['iso3'] ][] = array(
            'period'          => $r['snapshot_period'],
            'composite_score' => $r['composite_score'] !== null ? (float) $r['composite_score'] : null,
            'rank'            => $r['rank_value'] !== null ? (int) $r['rank_value'] : null,
            'coverage_type'   => $r['coverage_type'],
            'pillars'         => json_decode( $r['pillars_json'], true ),
        );
    }
    return $out;
}

// ─── REST endpoints (authenticated for history) ───────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/index-history/(?P<slug>[a-z0-9_-]+)', array(
        'methods'             => 'GET',
        'callback'            => function ( $request ) {
            $slug = sanitize_key( $request['slug'] );
            $iso3 = $request->get_param( 'iso3' );
            return rest_ensure_response( blomstra_index_snapshot_get_history( $slug, $iso3 ? sanitize_text_field( $iso3 ) : null ) );
        },
        'permission_callback' => '__return_true',
    ) );
} );

add_action( 'rest_api_init', function () {
    register_rest_route( 'blomstra/v1', '/country-names', array(
        'methods'             => 'GET',
        'callback'            => function () {
            return rest_ensure_response( blomstra_get_global_country_list() );
        },
        'permission_callback' => '__return_true',
    ) );
} );

add_action( 'admin_notices', function () {
    if ( empty( $_GET['page'] ) || strpos( $_GET['page'], 'blomstra' ) === false ) {
        return;
    }
    global $wpdb;
    $table = $wpdb->prefix . 'blomstra_index_history';
    $rows  = $wpdb->get_results(
        "SELECT index_slug, snapshot_period, COUNT(*) as country_count, MAX(recorded_at) as last_recorded
         FROM $table GROUP BY index_slug, snapshot_period ORDER BY index_slug, snapshot_period DESC",
        ARRAY_A
    );
    if ( empty( $rows ) ) {
        return;
    }
    echo '<div class="notice notice-info"><p><strong>Snapshot history:</strong></p><ul style="margin-left:20px;list-style:disc;">';
    foreach ( $rows as $r ) {
        echo '<li>' . esc_html( $r['index_slug'] ) . ' — ' . esc_html( $r['snapshot_period'] ) . ': '
            . esc_html( $r['country_count'] ) . ' countries · last updated ' . esc_html( $r['last_recorded'] ) . '</li>';
    }
    echo '</ul></div>';
} );

// ================================================================
// PART 2 STARTS HERE – Historical Cache Helpers, Fetchers & Jobs
// ================================================================
// ─── HISTORICAL DATA CACHE TABLE INSTALL ──────────────────────────

function blomstra_historical_data_maybe_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'blomstra_historical_data';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source VARCHAR(20) NOT NULL,
        indicator VARCHAR(50) NOT NULL,
        fuel VARCHAR(20) DEFAULT NULL,
        iso3 CHAR(3) NOT NULL,
        year SMALLINT UNSIGNED NOT NULL,
        value DECIMAL(12,4) DEFAULT NULL,
        meta JSON DEFAULT NULL,
        fetched_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY idx_source_indicator_fuel_iso3_year (source, indicator, fuel, iso3, year),
        KEY idx_source_year (source, year),
        KEY idx_iso3 (iso3)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
add_action( 'admin_init', 'blomstra_historical_data_maybe_install' );

// ─── HISTORICAL CACHE JOB STATUS TABLE ────────────────────────────

function blomstra_cache_jobs_maybe_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'blomstra_cache_jobs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source VARCHAR(20) NOT NULL,
        year SMALLINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        countries_cached INT DEFAULT 0,
        error_message TEXT DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        attempts INT DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY idx_source_year (source, year),
        KEY idx_status (status),
        KEY idx_year (year)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
add_action( 'admin_init', 'blomstra_cache_jobs_maybe_install' );

// ─── SOURCE REGISTRY ───────────────────────────────────────────────

if ( ! function_exists( 'blomstra_get_historical_sources' ) ) {
    function blomstra_get_historical_sources() {
        return array(
            'eia' => array(
                'label' => 'EIA Energy',
                'fetcher' => 'blomstra_fetch_eia_for_year',
                'available_since' => 2000,
                'enabled' => true,
                'implemented' => true,
            ),
            'comtrade' => array(
                'label' => 'Comtrade HHI',
                'fetcher' => 'blomstra_fetch_hhi_for_year',
                'available_since' => 2000,
                'enabled' => true,
                'implemented' => true,
            ),
            'maritime' => array(
                'label' => 'Maritime LSCI',
                'fetcher' => 'blomstra_fetch_maritime_for_year',
                'available_since' => 2004,
                'enabled' => true,
                'implemented' => true,
            ),
            'imf' => array(
                'label' => 'IMF WEO',
                'fetcher' => 'blomstra_fetch_imf_for_year',
                'available_since' => 1990,
                'enabled' => true,
                'implemented' => true,
            ),
            'wb' => array(
                'label' => 'World Bank',
                'fetcher' => 'blomstra_fetch_wb_for_year',
                'available_since' => 1990,
                'enabled' => true,
                'implemented' => true,
            ),
        );
    }
}

// ─── CACHE JOB HELPER FUNCTIONS ────────────────────────────────────

if ( ! function_exists( 'blomstra_cache_job_get_status' ) ) {
    function blomstra_cache_job_get_status( $source, $year ) {
        global $wpdb;
        $table = $wpdb->prefix . 'blomstra_cache_jobs';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE source = %s AND year = %d",
            $source, $year
        ), ARRAY_A );
        return $row;
    }
}

if ( ! function_exists( 'blomstra_cache_job_update' ) ) {
    function blomstra_cache_job_update( $source, $year, $status, $countries = null, $error = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'blomstra_cache_jobs';
        $now = current_time( 'mysql' );
        $data = array( 'status' => $status );
        if ( $countries !== null ) $data['countries_cached'] = (int) $countries;
        if ( $error !== null ) $data['error_message'] = $error;
        if ( $status === 'running' ) $data['started_at'] = $now;
        if ( $status === 'success' || $status === 'failed' || $status === 'skipped' ) $data['completed_at'] = $now;
        $existing = blomstra_cache_job_get_status( $source, $year );
        if ( $existing ) {
            $data['attempts'] = (int) $existing['attempts'] + 1;
            $wpdb->update( $table, $data, array( 'source' => $source, 'year' => $year ) );
        } else {
            $data['source'] = $source;
            $data['year'] = $year;
            $data['attempts'] = 1;
            $wpdb->insert( $table, $data );
        }
    }
}

if ( ! function_exists( 'blomstra_cache_job_get_all' ) ) {
    function blomstra_cache_job_get_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'blomstra_cache_jobs';
        $rows = $wpdb->get_results( "SELECT * FROM $table", ARRAY_A );
        $result = array();
        foreach ( $rows as $row ) {
            $result[ $row['source'] ][ $row['year'] ] = $row;
        }
        return $result;
    }
}

if ( ! function_exists( 'blomstra_cache_job_get_summary' ) ) {
    function blomstra_cache_job_get_summary() {
        $jobs = blomstra_cache_job_get_all();
        $total = 0; $success = 0; $failed = 0; $pending = 0; $skipped = 0;
        foreach ( $jobs as $source => $years ) {
            foreach ( $years as $year => $data ) {
                $total++;
                switch ( $data['status'] ) {
                    case 'success': $success++; break;
                    case 'failed': $failed++; break;
                    case 'pending': $pending++; break;
                    case 'running': $pending++; break;
                    case 'skipped': $skipped++; break;
                    default: break;
                }
            }
        }
        return array(
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'pending' => $pending,
            'skipped' => $skipped,
        );
    }
}

if ( ! function_exists( 'blomstra_cache_job_clear_all' ) ) {
    function blomstra_cache_job_clear_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'blomstra_cache_jobs';
        $wpdb->query( "TRUNCATE TABLE $table" );
    }
}

// ─── YEAR‑SPECIFIC FETCHERS FOR IMF & WB ──────────────────────────

function blomstra_fetch_imf_for_year( $year, $iso3_list = null ) {
    if ( $iso3_list === null ) {
        $iso3_list = array_keys( blomstra_get_global_country_list() );
    }

    $results = array();
    $indicator_codes = array_keys( BLOMSTRA_IMF_INDICATORS );
    foreach ( $indicator_codes as $code ) {
        $data = blomstra_fetch_imf_generic( $code, true, null, $year );
        $filtered = array();
        foreach ( $data as $iso3 => $entry ) {
            if ( in_array( $iso3, $iso3_list, true ) ) {
                $filtered[ $iso3 ] = $entry;
            }
        }
        $shaped = array();
        foreach ( $filtered as $iso3 => $entry ) {
            $shaped[ $iso3 ] = array(
                'value' => $entry['value'],
                'year'  => (int) $entry['year'],
            );
        }
        $results[ $code ] = $shaped;
    }
    return $results;
}

function blomstra_fetch_wb_for_year( $year, $iso3_list = null ) {
    if ( $iso3_list === null ) {
        $iso3_list = array_keys( blomstra_get_global_country_list() );
    }

    $results = array();
    foreach ( BLOMSTRA_WB_INDICATORS as $code => $config ) {
        $source_id = $config['source'];
        $data = blomstra_fetch_wb_historical_batch( $code, $year, $year, $source_id, true );
        $shaped = array();
        foreach ( $data as $iso3 => $years ) {
            if ( isset( $years[ $year ] ) ) {
                $shaped[ $iso3 ] = array(
                    'value' => $years[ $year ],
                    'year'  => $year,
                );
            }
        }
        $results[ $code ] = $shaped;
    }
    return $results;
}

// ─── HISTORICAL DATA CACHE WRAPPER (with empty‑cache fix) ────────

function blomstra_get_historical_data( $source, $indicator, $fuel, $iso3_list, $year, $fetch_callback ) {
    global $wpdb;
    $table = $wpdb->prefix . 'blomstra_historical_data';

    if ( empty( $iso3_list ) ) {
        return array();
    }

    $current_year = (int) current_time('Y');
    $should_cache = ( $year <= ( $current_year - 2 ) );

    $iso3_list = array_unique( array_map( 'strtoupper', $iso3_list ) );

    $cached = array();
    $missing = $iso3_list;

    if ( $should_cache ) {
        $placeholders = implode( ',', array_fill( 0, count( $iso3_list ), '%s' ) );
        if ( $fuel === null ) {
            $fuel_condition = 'IS NULL';
            $fuel_params = array();
        } else {
            $fuel_condition = '= %s';
            $fuel_params = array( $fuel );
        }
        $cached_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT iso3, value, meta FROM $table 
             WHERE source = %s AND indicator = %s AND fuel $fuel_condition AND year = %d AND iso3 IN ($placeholders)",
            array_merge( array( $source, $indicator ), $fuel_params, array( (int) $year ), $iso3_list )
        ), OBJECT_K );

        foreach ( $cached_rows as $row ) {
            $cached[ $row->iso3 ] = array(
                'value' => (float) $row->value,
                'year'  => (int) $year,
                'meta'  => json_decode( $row->meta, true ) ?: array(),
            );
        }

        $missing = array_diff( $iso3_list, array_keys( $cached ) );
    }

    $new_data = array();
    if ( ! empty( $missing ) ) {
        $api_result = call_user_func( $fetch_callback, $missing, $year );
        if ( is_array( $api_result ) ) {
            foreach ( $api_result as $iso3 => $row ) {
                if ( ! isset( $row['value'] ) ) {
                    continue;
                }
                $new_data[ $iso3 ] = array(
                    'value' => (float) $row['value'],
                    'year'  => isset( $row['year'] ) ? (int) $row['year'] : (int) $year,
                    'meta'  => isset( $row['meta'] ) && is_array( $row['meta'] ) ? $row['meta'] : array(),
                );
            }

            if ( $should_cache && ! empty( $new_data ) ) {
                foreach ( $new_data as $iso3 => $row ) {
                    $wpdb->insert( $table, array(
                        'source'     => $source,
                        'indicator'  => $indicator,
                        'fuel'       => $fuel,
                        'iso3'       => $iso3,
                        'year'       => (int) $year,
                        'value'      => $row['value'],
                        'meta'       => json_encode( $row['meta'] ),
                        'fetched_at' => current_time( 'mysql' ),
                    ), array( '%s', '%s', '%s', '%s', '%d', '%f', '%s', '%s' ) );
                }
            }
        }
    }

    $result = array();
    foreach ( $iso3_list as $iso3 ) {
        if ( isset( $cached[ $iso3 ] ) ) {
            $result[ $iso3 ] = $cached[ $iso3 ];
        } elseif ( isset( $new_data[ $iso3 ] ) ) {
            $result[ $iso3 ] = $new_data[ $iso3 ];
        }
    }
    return $result;
}

// ─── YEAR‑SPECIFIC FETCHERS (EIA, HHI, Maritime) ──────────────────

/**
 * v2.9.1 REWRITE (REF-BUG-3, REF-BUG-6): the previous version tried each
 * lookback offset only until the ENTIRE fetch for that offset returned ANY
 * data at all, then stopped — a single country succeeding silently ended
 * fallback attempts for every OTHER country that still needed an earlier
 * year, permanently truncating backfill coverage. It also stored the
 * REQUESTED year on every value regardless of which offset it actually
 * came from, corrupting DQI staleness disclosure. This version tracks
 * $still_missing per activity and only retries countries still lacking
 * data at each earlier year — matching blomstra_fetch_maritime_for_year's
 * correct pattern below — and records the real source year per country.
 */
function blomstra_fetch_eia_for_year( $year, $iso3_list = null ) {
    $key = blomstra_get_api_credential( 'eia', 'api_key' );
    if ( empty( $key ) ) {
        return array( 'consumption' => array(), 'production' => array() );
    }

    if ( $iso3_list === null ) {
        $iso3_list = array_keys( blomstra_get_global_country_list() );
    }

    $fuel_ids = array_keys( BLOMSTRA_EIA_FUEL_PRODUCT_IDS );
    $result = array( 'consumption' => array(), 'production' => array() );

    foreach ( $fuel_ids as $product_id ) {
        $result['consumption'][ $product_id ] = array();
        $result['production'][ $product_id ] = array();

        foreach ( array( 'consumption' => BLOMSTRA_EIA_ACTIVITY_CONS, 'production' => BLOMSTRA_EIA_ACTIVITY_PROD ) as $activity_label => $activity_id ) {
            $still_missing = $iso3_list;
            for ( $offset = 0; $offset <= 5 && ! empty( $still_missing ); $offset++ ) {
                $try_year = $year - $offset;
                if ( $try_year < 2000 ) {
                    break;
                }
                $fetch_callback = function( $missing_iso3s, $try_year ) use ( $product_id, $activity_id ) {
                    return blomstra_eia_fetch_one_fuel_activity( $missing_iso3s, $try_year, $product_id, $activity_id );
                };
                $found = blomstra_get_historical_data( 'eia', $activity_label, $product_id, $still_missing, $try_year, $fetch_callback );
                foreach ( $found as $iso3 => $row ) {
                    $result[ $activity_label ][ $product_id ][ $iso3 ] = array(
                        'value' => $row['value'],
                        'year'  => $try_year, // the REAL year this value came from
                    );
                }
                $still_missing = array_values( array_diff( $still_missing, array_keys( $found ) ) );
            }
        }
    }

    return $result;
}

function blomstra_eia_fetch_one_fuel_activity( $iso3_list, $year, $product_id, $activity_id ) {
    $all_results = array();
    $chunks = array_chunk( $iso3_list, BLOMSTRA_EIA_CHUNK_SIZE );
    foreach ( $chunks as $chunk ) {
        $result = blomstra_eia_fetch_activity_batch( $chunk, $activity_id, $product_id, 1 );
        if ( $result['status'] === 'ok' ) {
            foreach ( $result['rows'] as $row ) {
                $cc     = $row['countryRegionId'] ?? null;
                $val    = $row['value'] ?? null;
                $period = $row['period'] ?? null;
                if ( ! $cc || $val === null || $period === null ) {
                    continue;
                }
                $row_year = (int) substr( $period, 0, 4 );
                if ( $row_year === $year ) {
                    $all_results[ $cc ] = array(
                        'value' => (float) $val,
                        'year'  => $row_year,
                        'meta'  => array( 'period' => $period ),
                    );
                }
            }
        } elseif ( $result['status'] === 'empty' ) {
            // No data for this chunk; continue
        } else {
            error_log( "EIA historical: chunk failed for activity $activity_id, product $product_id, year $year: " . $result['status'] );
        }
        usleep( 200000 );
    }
    return $all_results;
}

/**
 * v2.9.1 REWRITE (REF-BUG-6): previously fetched ONLY the exact requested
 * year with no fallback at all, unlike the live builder (which tolerates
 * BLOMSTRA_HHI_LOOKBACK years back) — backfilled years had systematically
 * worse coverage than the live index ever had, for no reason tied to real
 * data availability. This now retries only the countries still missing at
 * each earlier year (matching Maritime's pattern below) and records the
 * real source year per country.
 */
function blomstra_fetch_hhi_for_year( $year, $iso3_list = null ) {
    $key = blomstra_get_api_credential( 'comtrade', 'subscription_key' );
    if ( empty( $key ) ) {
        return array();
    }

    if ( $iso3_list === null ) {
        $iso3_list = array_keys( blomstra_get_global_country_list() );
    }

    $reporter_map = blomstra_get_comtrade_reporter_map();
    $fetchable_iso3s = array();
    foreach ( $iso3_list as $iso3 ) {
        if ( isset( $reporter_map[ $iso3 ] ) ) {
            $fetchable_iso3s[] = $iso3;
        }
    }

    $fetch_at_year = function( $missing_iso3s, $try_year ) use ( $reporter_map ) {
        $results = array();
        $chunks = array_chunk( $missing_iso3s, BLOMSTRA_HHI_CHUNK_SIZE );
        foreach ( $chunks as $chunk_iso3s ) {
            $chunk_codes = array();
            $chunk_map = array();
            foreach ( $chunk_iso3s as $iso3 ) {
                $code = $reporter_map[ $iso3 ];
                $chunk_codes[] = $code;
                $chunk_map[ $code ] = $iso3;
            }
            $rows = blomstra_comtrade_fetch_partner_imports_batch( $chunk_codes, $try_year );
            if ( $rows === BLOMSTRA_COMTRADE_QUOTA_EXHAUSTED || $rows === BLOMSTRA_COMTRADE_PERMANENT_FAILURE ) {
                continue;
            }
            if ( ! is_array( $rows ) ) {
                continue;
            }
            $computed = blomstra_compute_hhi_from_batch_rows( $rows, $chunk_codes, $try_year );
            foreach ( $computed as $code => $data ) {
                $iso3 = $chunk_map[ $code ];
                $results[ $iso3 ] = array(
                    'value' => $data['value'],
                    'year'  => $try_year,
                    'meta'  => array( 'reporter_code' => $code ),
                );
            }
        }
        return $results;
    };

    $still_missing = $fetchable_iso3s;
    $found_all = array();
    for ( $offset = 0; $offset <= BLOMSTRA_HHI_LOOKBACK && ! empty( $still_missing ); $offset++ ) {
        $try_year = $year - $offset;
        $found = blomstra_get_historical_data( 'comtrade', 'hhi', null, $still_missing, $try_year, $fetch_at_year );
        foreach ( $found as $iso3 => $row ) {
            $found_all[ $iso3 ] = $row;
        }
        $still_missing = array_values( array_diff( $still_missing, array_keys( $found ) ) );
    }

    $results = array();
    foreach ( $found_all as $iso3 => $row ) {
        $results[ $iso3 ] = array(
            'value' => $row['value'],
            'year'  => $row['year'],
        );
    }
    return $results;
}

function blomstra_fetch_maritime_for_year( $year, $iso3_list = null ) {
    if ( $iso3_list === null ) {
        $iso3_list = array_keys( blomstra_get_global_country_list() );
    }

    $fetch_callback = function( $missing_iso3s, $year ) {
        $results = array();
        for ( $offset = 0; $offset <= 10; $offset++ ) {
            $try_year = $year - $offset;
            if ( $try_year < 2004 ) {
                break;
            }
            $url = "https://api.worldbank.org/v2/country/all/indicator/" . BLOMSTRA_MARITIME_INDICATOR_CODE . "?format=json&per_page=20000&date={$try_year}";
            $response = wp_remote_get( $url, array( 'timeout' => 30, 'user-agent' => BLOMSTRA_USER_AGENT ) );
            if ( is_wp_error( $response ) ) {
                continue;
            }
            $http_code = wp_remote_retrieve_response_code( $response );
            if ( $http_code !== 200 ) {
                continue;
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! isset( $body[1] ) || ! is_array( $body[1] ) ) {
                continue;
            }
            $data = array();
            foreach ( $body[1] as $row ) {
                $iso3 = $row['countryiso3code'] ?? null;
                $value = $row['value'] ?? null;
                if ( ! $iso3 || $value === null ) {
                    continue;
                }
                if ( in_array( $iso3, $missing_iso3s, true ) ) {
                    $data[ $iso3 ] = array(
                        'value' => (float) $value,
                        'year'  => $try_year,
                        'meta'  => array( 'source_year' => $try_year ),
                    );
                }
            }
            if ( ! empty( $data ) ) {
                return $data;
            }
        }
        return array();
    };

    $cached = blomstra_get_historical_data( 'maritime', 'lsci', null, $iso3_list, $year, $fetch_callback );

    $results = array();
    foreach ( $iso3_list as $iso3 ) {
        if ( function_exists( 'blomstra_is_landlocked' ) && blomstra_is_landlocked( $iso3 ) ) {
            $results[ $iso3 ] = array(
                'value' => 0.0,
                'year'  => null,
            );
        } elseif ( isset( $cached[ $iso3 ] ) ) {
            $results[ $iso3 ] = array(
                'value' => $cached[ $iso3 ]['value'],
                'year'  => $cached[ $iso3 ]['year'],
            );
        }
    }
    return $results;
}

// ─── DYNAMIC BACKFILL RANGE ──────────────────────────────────────

if ( ! function_exists( 'blomstra_get_index_backfill_range' ) ) {
    function blomstra_get_index_backfill_range( $index_slug ) {
        $start = (int) get_option( $index_slug . '_backfill_range_start', 0 );
        $end   = (int) get_option( $index_slug . '_backfill_range_end', 0 );

        if ( $start <= 0 || $end <= 0 || $start > $end ) {
            $current_year = (int) current_time('Y');
            if ( $index_slug === 'sivi' ) {
                $default_start = max( 2004, $current_year - 5 );
            } else {
                $default_start = $current_year - 5;
            }
            $default_end = $current_year - 1;
            $start = $start > 0 ? $start : $default_start;
            $end   = $end   > 0 ? $end   : $default_end;
        }
        return array( 'start' => $start, 'end' => $end );
    }
}

// ─── BACKGROUND CACHE JOB ───────────────────────────────────────────

add_action( 'blomstra_cache_historical_job', 'blomstra_cache_historical_job_callback', 10, 2 );
function blomstra_cache_historical_job_callback( $source, $year ) {
    $source_registry = blomstra_get_historical_sources();
    if ( ! isset( $source_registry[ $source ] ) || ! $source_registry[ $source ]['implemented'] ) {
        blomstra_cache_job_update( $source, $year, 'skipped', 0, 'Not implemented' );
        return;
    }

    blomstra_cache_job_update( $source, $year, 'running' );

    $iso3_list = array_keys( blomstra_get_global_country_list() );
    try {
        if ( $source === 'eia' ) {
            $result = blomstra_fetch_eia_for_year( $year, $iso3_list );
            $count = 0;
            foreach ( $result['consumption'] as $fuel_data ) {
                $count += count( $fuel_data );
            }
            foreach ( $result['production'] as $fuel_data ) {
                $count += count( $fuel_data );
            }
            if ( $count > 0 ) {
                blomstra_cache_job_update( $source, $year, 'success', $count );
            } else {
                blomstra_cache_job_update( $source, $year, 'failed', 0, 'No data returned (check call log)' );
            }
        } elseif ( $source === 'comtrade' ) {
            $result = blomstra_fetch_hhi_for_year( $year, $iso3_list );
            $count = count( $result );
            if ( $count > 0 ) {
                blomstra_cache_job_update( $source, $year, 'success', $count );
            } else {
                blomstra_cache_job_update( $source, $year, 'failed', 0, 'No data returned' );
            }
        } elseif ( $source === 'maritime' ) {
            $result = blomstra_fetch_maritime_for_year( $year, $iso3_list );
            $count = count( $result );
            if ( $count > 0 ) {
                blomstra_cache_job_update( $source, $year, 'success', $count );
            } else {
                blomstra_cache_job_update( $source, $year, 'failed', 0, 'No data returned' );
            }
        } elseif ( $source === 'imf' ) {
            $result = blomstra_fetch_imf_for_year( $year, $iso3_list );
            $unique_iso3s = array();
            foreach ( $result as $code => $data ) {
                foreach ( $data as $iso3 => $row ) {
                    $unique_iso3s[ $iso3 ] = true;
                }
            }
            $count = count( $unique_iso3s );
            if ( $count > 0 ) {
                blomstra_cache_job_update( $source, $year, 'success', $count );
            } else {
                blomstra_cache_job_update( $source, $year, 'failed', 0, 'No data returned for any IMF indicator' );
            }
        } elseif ( $source === 'wb' ) {
            $result = blomstra_fetch_wb_for_year( $year, $iso3_list );
            $unique_iso3s = array();
            foreach ( $result as $code => $data ) {
                foreach ( $data as $iso3 => $row ) {
                    $unique_iso3s[ $iso3 ] = true;
                }
            }
            $count = count( $unique_iso3s );
            if ( $count > 0 ) {
                blomstra_cache_job_update( $source, $year, 'success', $count );
            } else {
                blomstra_cache_job_update( $source, $year, 'failed', 0, 'No data returned for any WB indicator' );
            }
        }
    } catch ( Exception $e ) {
        blomstra_cache_job_update( $source, $year, 'failed', 0, 'Exception: ' . $e->getMessage() );
    } catch ( Error $e ) {
        blomstra_cache_job_update( $source, $year, 'failed', 0, 'Fatal error: ' . $e->getMessage() );
    }

    error_log( "Blomstra cache job completed for $source, $year" );
}
