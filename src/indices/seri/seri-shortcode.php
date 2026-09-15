/**
 * SERI Frontend — Shortcode (PHP)
 * WPCode PHP Snippet.
 *
 * Registers [blomstra_seri_index] which renders the Sovereign Economic Resilience Index
 * using the shared Blomstra Index Frontend engine/styles.
 *
 * Usage: [blomstra_seri_index]
 */
if ( ! function_exists( 'seri_render_index_shortcode' ) ) {
    function seri_render_index_shortcode( $atts ) {

        // METHODOLOGY CHANGE (2026-09, SERI v5.0.0 / BMS-1.2.0): pillar
        // labels updated from risk-framed ("Governance Risk", "Fiscal
        // Stress", etc.) to strength-framed, matching the polarity flip in
        // seri_build_composite() — a high pillar percentile now means
        // strong/resilient in that dimension, not at-risk.
        $pillars = array(
            array(
                'key'     => 'governance_percentile',
                'raw_key' => null,
                'label'   => 'Governance Strength',
                'color'   => '#60a5fa',
            ),
            array(
                'key'     => 'macro_percentile',
                'raw_key' => null,
                'label'   => 'Macro Stability',
                'color'   => '#34d399',
            ),
            array(
                'key'     => 'external_percentile',
                'raw_key' => null,
                'label'   => 'External Resilience',
                'color'   => '#fb923c',
            ),
            array(
                'key'     => 'fiscal_percentile',
                'raw_key' => null,
                'label'   => 'Fiscal Strength',
                'color'   => '#f87171',
            ),
        );

        $methodology = 'The Sovereign Economic Resilience Index (SERI) combines four pillars — '
            . '<strong>Governance</strong> (World Bank WGI: rule of law, control of corruption, political stability), '
            . '<strong>Macro Stability</strong> (GNI growth, inflation, unemployment, GDP volatility, inflation volatility), '
            . '<strong>External Resilience</strong> (reserve months, external debt, current account, GNI-GDP divergence), and '
            . '<strong>Fiscal Strength</strong> (government debt, government balance, debt trajectory). '
            . 'Countries with data for all four pillars receive a definitive rank (Full Index). '
            . 'Countries missing one pillar receive a projected rank range using global median injection (Partial Index). '
            . 'Countries with fewer than three pillars are excluded. '
            . 'Higher scores indicate greater resilience — the most resilient country is ranked #1. '
            . '<a href="' . esc_url( 'https://blomstrainsights.com/methodology/seri' ) . '" target="_blank" rel="noopener">Full methodology →</a>';

        $missing_pillar_notes = array(
            'governance' => 'insufficient WGI coverage',
            'macro'      => 'missing macro data (GNI growth, inflation, unemployment, or volatility)',
            'external'   => 'missing external data (reserves, debt, current account, or divergence)',
            'fiscal'     => 'missing fiscal data (debt, balance, or trajectory)',
        );

        ob_start();
        ?>

        <div class="biw"
            data-biw-slug="seri"
            data-biw-endpoint="/wp-json/blomstra/v1/sovereign-economic-resilience-index"
            data-biw-names-endpoint="/wp-json/blomstra/v1/country-names"
            data-biw-title="Sovereign Economic Resilience Index"
            data-biw-subtitle="A composite measure of governance, macro stability, external resilience, and fiscal strength — higher score = more resilient"
            data-biw-eyebrow="Strategic Intelligence"
            data-biw-score-key="seri_structural"
            data-biw-score-label="Resilience Score"
            data-biw-metric-name="Resilience"
            data-biw-orientation="higher_is_better"
            data-biw-coverage-key="coverage"
            data-biw-missing-key="pillars_missing"
            data-biw-missing-notes='<?php echo esc_attr( wp_json_encode( $missing_pillar_notes ) ); ?>'
            data-biw-band-thresholds="25,50,75"
            data-biw-band-labels="Very Good,Good,Poor,Very Poor"
            data-biw-pillars='<?php echo esc_attr( wp_json_encode( $pillars ) ); ?>'
            data-biw-methodology="<?php echo esc_attr( $methodology ); ?>">
        </div>

        <?php
        return ob_get_clean();
    }
}
add_shortcode( 'blomstra_seri_index', 'seri_render_index_shortcode' );
