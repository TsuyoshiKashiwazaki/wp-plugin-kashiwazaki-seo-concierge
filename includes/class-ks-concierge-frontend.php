<?php
/**
 * Front-end rendering for Kashiwazaki SEO Concierge: the floating tab/panel,
 * asset enqueue (lazy), display conditions and GA4 wiring.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Frontend
 */
class Ks_Concierge_Frontend {

	/**
	 * Register front-end hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_widget' ) );
	}

	/**
	 * Whether the widget should display on the current request.
	 *
	 * @return bool
	 */
	protected function should_display() {
		if ( is_admin() ) {
			return false;
		}
		$condition = (string) Ks_Concierge_Settings::get( 'display_condition', 'all' );
		$show      = true;
		if ( 'front_page' === $condition ) {
			$show = is_front_page();
		} elseif ( 'singular' === $condition ) {
			$show = is_singular();
		}
		/**
		 * Filter whether the concierge widget is displayed.
		 *
		 * @param bool $show Whether to display.
		 */
		return (bool) apply_filters( 'ks_concierge_should_display', $show );
	}

	/**
	 * Enqueue the front-end CSS and JS (script kept small; chat loads on demand).
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->should_display() ) {
			return;
		}
		// Bust caches on every asset change (filemtime), since some speed plugins
		// strip the ?ver query string and would otherwise serve a stale widget.
		$css_path = KS_CONCIERGE_DIR . 'assets/css/ks-concierge-front.css';
		$js_path  = KS_CONCIERGE_DIR . 'assets/js/ks-concierge-front.js';
		$css_ver  = file_exists( $css_path ) ? KS_CONCIERGE_VERSION . '.' . filemtime( $css_path ) : KS_CONCIERGE_VERSION;
		$js_ver   = file_exists( $js_path ) ? KS_CONCIERGE_VERSION . '.' . filemtime( $js_path ) : KS_CONCIERGE_VERSION;
		wp_enqueue_style(
			'ks-concierge-front',
			KS_CONCIERGE_URL . 'assets/css/ks-concierge-front.css',
			array(),
			$css_ver
		);
		wp_enqueue_script(
			'ks-concierge-front',
			KS_CONCIERGE_URL . 'assets/js/ks-concierge-front.js',
			array(),
			$js_ver,
			true
		);

		$tab_label = (string) Ks_Concierge_Settings::get( 'tab_label', '' );
		if ( '' === $tab_label ) {
			$tab_label = __( 'なにかお探しですか？', 'kashiwazaki-seo-concierge' );
		}
		$initial = (string) Ks_Concierge_Settings::get( 'initial_message', '' );
		if ( '' === $initial ) {
			$initial = __( 'ご質問をどうぞ。サイト内の最適なページをご案内します。', 'kashiwazaki-seo-concierge' );
		}
		$widget_title = (string) Ks_Concierge_Settings::get( 'widget_title', '' );
		if ( '' === $widget_title ) {
			$widget_title = 'Kashiwazaki SEO Concierge';
		}
		$bot_avatar = (string) Ks_Concierge_Settings::get( 'bot_avatar', '' );

		wp_localize_script(
			'ks-concierge-front',
			'ksConcierge',
			array(
				'restAsk'   => esc_url_raw( rest_url( Ks_Concierge_REST::NAMESPACE . '/ask' ) ),
				'restClick' => esc_url_raw( rest_url( Ks_Concierge_REST::NAMESPACE . '/click' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'tabLabel'  => $tab_label,
				'title'     => $widget_title,
				'avatar'    => '' !== $bot_avatar ? esc_url( $bot_avatar ) : '',
				'initial'   => $initial,
				'accent'    => (string) Ks_Concierge_Settings::get( 'accent_color', '#1e73be' ),
				'chips'     => array_values( (array) Ks_Concierge_Settings::get( 'suggest_chips', array() ) ),
				'ga4'       => (string) Ks_Concierge_Settings::get( 'ga4_measurement_id', '' ),
				'consent'   => (bool) Ks_Concierge_Settings::get( 'consent_required', false ),
				'i18n'      => array(
					'send'        => __( '送信', 'kashiwazaki-seo-concierge' ),
					'placeholder' => __( '質問を入力…', 'kashiwazaki-seo-concierge' ),
					'thinking'    => __( '考えています…', 'kashiwazaki-seo-concierge' ),
					'close'       => __( '閉じる', 'kashiwazaki-seo-concierge' ),
					'error'       => __( '通信エラーが発生しました。時間をおいて再度お試しください。', 'kashiwazaki-seo-concierge' ),
					'consentText' => __( '質問はAI（OpenAI）に送信されます。同意して続行しますか？', 'kashiwazaki-seo-concierge' ),
				),
			)
		);
	}

	/**
	 * Render the floating widget root element.
	 *
	 * @return void
	 */
	public function render_widget() {
		if ( ! $this->should_display() ) {
			return;
		}
		$accent = (string) Ks_Concierge_Settings::get( 'accent_color', '#1e73be' );
		?>
		<div id="ks-concierge-root" class="ks-concierge" data-accent="<?php echo esc_attr( $accent ); ?>" hidden></div>
		<?php
	}
}
