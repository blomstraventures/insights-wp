if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'blomstra_sivi_index', function () {
    $pillars = array(
        array( 'key' => 'energy_dependency_percentile', 'raw_key' => 'energy_dependency_raw', 'label' => 'Energy Dependency', 'color' => '#60a5fa' ),
        array( 'key' => 'supplier_concentration_percentile', 'raw_key' => 'supplier_concentration_raw', 'label' => 'Supplier Concentration', 'color' => '#f87171' ),
        array( 'key' => 'maritime_vulnerability_percentile', 'raw_key' => 'maritime_connectivity_raw', 'label' => 'Maritime Exposure', 'color' => '#fb923c' ),
    );
    $methodology = 'The Sovereign Infrastructure Vulnerability Index (SIVI) combines three pillars — ' .
        '<strong>Energy Dependency</strong> (EIA, consumption-share-weighted across five fuels), ' .
        '<strong>Supplier Concentration</strong> (UN Comtrade import HHI), and ' .
        '<strong>Maritime Exposure</strong> (World Bank LSCI, inverted to a vulnerability orientation). ' .
        '<strong>Higher scores indicate higher vulnerability (rank #1 is the most vulnerable country).</strong> ' .
        'Data Quality Index (DQI) shows the freshness of underlying data per pillar, disclosed as a confidence metric only — it does not affect the score.' .
        '<a href="https://blomstrainsights.com/methodology/sivi" target="_blank" rel="noopener">Full methodology →</a>';
    $pillars_json = wp_json_encode( $pillars );

    // ─── Dynamic year range from snapshot history ──────────────────
    global $wpdb;
    $history_table = $wpdb->prefix . 'blomstra_index_history';
    $default_min = 2004;
    $default_max = (int) date('Y');

    $year_min = $default_min;
    $year_max = $default_max;

    // Query the history table for SIVI snapshots
    $result = $wpdb->get_row( $wpdb->prepare(
        "SELECT 
            MIN(CAST(SUBSTRING(snapshot_period, 1, 4) AS UNSIGNED)) as min_year,
            MAX(CAST(SUBSTRING(snapshot_period, 1, 4) AS UNSIGNED)) as max_year
         FROM $history_table
         WHERE index_slug = %s",
        'sivi'
    ) );

    if ( $result && $result->min_year !== null && $result->max_year !== null ) {
        $year_min = (int) $result->min_year;
        $year_max = (int) $result->max_year;
    }
    // If no history, we keep the defaults (2004 and current year)

    ob_start();
    ?>
    <div class="biw"
         data-biw-slug="sivi"
         data-biw-endpoint="/wp-json/blomstra/v1/sovereign-infrastructure-vulnerability-index"
         data-biw-names-endpoint="/wp-json/blomstra/v1/country-names"
         data-biw-title="Sovereign Infrastructure Vulnerability Index"
         data-biw-subtitle="A country-level assessment of exposure, dependency, and systemic weakness"
         data-biw-eyebrow="Strategic Intelligence"
         data-biw-score-key="sivi_structural"
         data-biw-score-label="Vulnerability Score"
         data-biw-coverage-key="coverage"
         data-biw-band-thresholds="25,50,75"
         data-biw-band-labels="Very Good,Good,Poor,Very Poor"
         data-biw-band-select-label="All Vulnerability Levels"
         data-biw-pillars='<?php echo esc_attr( $pillars_json ); ?>'
         data-biw-methodology="<?php echo esc_attr( $methodology ); ?>"
         data-biw-view="dashboard"
         data-biw-year-min="<?php echo esc_attr( $year_min ); ?>"
         data-biw-year-max="<?php echo esc_attr( $year_max ); ?>">
    </div>
    <?php
    return ob_get_clean();
} );
