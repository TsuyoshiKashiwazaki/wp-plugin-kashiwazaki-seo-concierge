<?php
/**
 * Admin UI for Kashiwazaki SEO Concierge: menu, settings tabs, plugin action
 * link, analytics view and the pre-publish test sandbox.
 *
 * @package Kashiwazaki_SEO_Concierge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Ks_Concierge_Admin
 */
class Ks_Concierge_Admin {

	const MENU_SLUG  = 'kashiwazaki-seo-concierge';
	const NONCE_NAME = 'ks_concierge_settings_nonce';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . KS_CONCIERGE_BASENAME, array( $this, 'action_links' ) );
		add_action( 'wp_ajax_ks_concierge_sandbox', array( $this, 'ajax_sandbox' ) );
	}

	/**
	 * Add the top-level admin menu at position 81.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			'Kashiwazaki SEO Concierge',
			'Kashiwazaki SEO Concierge',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-format-chat',
			81
		);
	}

	/**
	 * Add a "設定" link on the plugins.php row.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url      = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( '設定', 'kashiwazaki-seo-concierge' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Enqueue admin assets on the settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		// Use the file modification time as a cache-busting version so asset
		// updates are picked up by browsers without bumping the plugin version.
		$css_path = KS_CONCIERGE_DIR . 'assets/css/ks-concierge-admin.css';
		$js_path  = KS_CONCIERGE_DIR . 'assets/js/ks-concierge-admin.js';
		$css_ver  = file_exists( $css_path ) ? KS_CONCIERGE_VERSION . '.' . filemtime( $css_path ) : KS_CONCIERGE_VERSION;
		$js_ver   = file_exists( $js_path ) ? KS_CONCIERGE_VERSION . '.' . filemtime( $js_path ) : KS_CONCIERGE_VERSION;
		wp_enqueue_style( 'ks-concierge-admin', KS_CONCIERGE_URL . 'assets/css/ks-concierge-admin.css', array(), $css_ver );
		wp_enqueue_script( 'ks-concierge-admin', KS_CONCIERGE_URL . 'assets/js/ks-concierge-admin.js', array(), $js_ver, true );
		// WordPress media library picker, used by the chat reply icon field.
		wp_enqueue_media();
		wp_localize_script(
			'ks-concierge-admin',
			'ksConciergeAdmin',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'ks_concierge_sandbox' ),
				// Per-provider default base URL + suggested model, so switching the
				// provider dropdown repopulates the base/model fields client-side.
				'providerDefaults' => array(
					'embed' => array(
						'openai' => array( 'base' => Ks_Concierge_Settings::provider_default_base( 'openai', 'embed' ), 'model' => 'text-embedding-3-small' ),
						'custom' => array( 'base' => '', 'model' => '' ),
					),
					'chat'  => array(
						'openai' => array( 'base' => Ks_Concierge_Settings::provider_default_base( 'openai', 'chat' ), 'model' => 'gpt-4o-mini' ),
						'zai'    => array( 'base' => Ks_Concierge_Settings::provider_default_base( 'zai', 'chat' ), 'model' => 'glm-4.6' ),
						'ollama' => array( 'base' => Ks_Concierge_Settings::provider_default_base( 'ollama', 'chat' ), 'model' => 'qwen3-coder:480b' ),
						'custom' => array( 'base' => '', 'model' => '' ),
					),
				),
			)
		);
	}

	/**
	 * Persist settings when the form is submitted.
	 *
	 * @return void
	 */
	public function maybe_save_settings() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'ks_concierge_save_settings', self::NONCE_NAME );

		$current = Ks_Concierge_Settings::all();
		$in      = wp_unslash( $_POST );

		// Each tab submits only its own fields, so start from the stored values
		// and update only the fields belonging to the submitted tab. Rebuilding
		// the whole array from $_POST would wipe other tabs' settings.
		$values = $current;
		$tab    = isset( $in['ks_active_tab'] ) ? sanitize_key( $in['ks_active_tab'] ) : '';

		switch ( $tab ) {
			case 'general':
				$values['sitemap_url']      = isset( $in['sitemap_url'] ) ? esc_url_raw( $in['sitemap_url'] ) : '';
				$values['llms_txt_url']     = isset( $in['llms_txt_url'] ) ? esc_url_raw( $in['llms_txt_url'] ) : '';
				$values['llms_txt_enabled'] = ! empty( $in['llms_txt_enabled'] );
				break;
			case 'ai':
				$chat_providers             = array( 'openai', 'zai', 'ollama', 'custom' );
				// Embeddings only accepts providers that serve an embeddings endpoint.
				$embed_providers            = array( 'openai', 'custom' );
				$values['embed_provider']   = isset( $in['embed_provider'] ) && in_array( $in['embed_provider'], $embed_providers, true ) ? $in['embed_provider'] : 'openai';
				$values['chat_provider']    = isset( $in['chat_provider'] ) && in_array( $in['chat_provider'], $chat_providers, true ) ? $in['chat_provider'] : 'openai';
				$values['embed_api_base']   = isset( $in['embed_api_base'] ) ? esc_url_raw( trim( (string) $in['embed_api_base'] ) ) : '';
				$values['chat_api_base']    = isset( $in['chat_api_base'] ) ? esc_url_raw( trim( (string) $in['chat_api_base'] ) ) : '';
				$values['chat_structured_mode'] = isset( $in['chat_structured_mode'] ) && in_array( $in['chat_structured_mode'], array( 'auto', 'json_schema', 'json_object', 'none' ), true ) ? $in['chat_structured_mode'] : 'auto';
				$values['chat_model']       = isset( $in['chat_model'] ) ? sanitize_text_field( $in['chat_model'] ) : 'gpt-4o-mini';
				$values['embeddings_model'] = isset( $in['embeddings_model'] ) ? sanitize_text_field( $in['embeddings_model'] ) : 'text-embedding-3-small';
				$values['embeddings_dims']  = isset( $in['embeddings_dims'] ) ? absint( $in['embeddings_dims'] ) : 1536;
				$values['candidate_count']  = isset( $in['candidate_count'] ) ? min( 20, max( 1, absint( $in['candidate_count'] ) ) ) : 10;
				$values['system_prompt']    = isset( $in['system_prompt'] ) ? sanitize_textarea_field( $in['system_prompt'] ) : '';
				$values['custom_embed_price_in']  = isset( $in['custom_embed_price_in'] ) ? max( 0, (float) $in['custom_embed_price_in'] ) : 0;
				$values['custom_chat_price_in']   = isset( $in['custom_chat_price_in'] ) ? max( 0, (float) $in['custom_chat_price_in'] ) : 0;
				$values['custom_chat_price_out']  = isset( $in['custom_chat_price_out'] ) ? max( 0, (float) $in['custom_chat_price_out'] ) : 0;
				// Per-role keys; updated only when a new value is entered so an
				// existing cipher is preserved on save.
				if ( isset( $in['embed_api_key'] ) && '' !== trim( (string) $in['embed_api_key'] ) ) {
					$values['embed_api_key_cipher'] = Ks_Concierge_Settings::encrypt( sanitize_text_field( $in['embed_api_key'] ) );
				}
				if ( isset( $in['chat_api_key'] ) && '' !== trim( (string) $in['chat_api_key'] ) ) {
					$values['chat_api_key_cipher'] = Ks_Concierge_Settings::encrypt( sanitize_text_field( $in['chat_api_key'] ) );
				}
				break;
			case 'index':
				$values['reindex_interval']   = isset( $in['reindex_interval'] ) ? sanitize_key( $in['reindex_interval'] ) : 'daily';
				$values['exclude_rules']      = isset( $in['exclude_rules'] ) ? sanitize_textarea_field( $in['exclude_rules'] ) : '';
				$values['priority_rules']     = isset( $in['priority_rules'] ) ? sanitize_textarea_field( $in['priority_rules'] ) : '';
				$values['reachability_check'] = ! empty( $in['reachability_check'] );
				break;
			case 'display':
				$values['tab_label']          = isset( $in['tab_label'] ) ? sanitize_text_field( $in['tab_label'] ) : '';
				$values['widget_title']       = isset( $in['widget_title'] ) ? sanitize_text_field( $in['widget_title'] ) : '';
				$values['bot_avatar']         = isset( $in['bot_avatar'] ) ? esc_url_raw( trim( (string) $in['bot_avatar'] ) ) : '';
				$values['accent_color']       = isset( $in['accent_color'] ) ? ( sanitize_hex_color( $in['accent_color'] ) ? sanitize_hex_color( $in['accent_color'] ) : '#1e73be' ) : '#1e73be';
				$values['initial_message']    = isset( $in['initial_message'] ) ? sanitize_textarea_field( $in['initial_message'] ) : '';
				$values['suggest_chips']      = isset( $in['suggest_chips'] ) ? $this->sanitize_lines( $in['suggest_chips'] ) : array();
				$values['display_condition']  = isset( $in['display_condition'] ) ? sanitize_key( $in['display_condition'] ) : 'all';
				$values['ga4_measurement_id'] = isset( $in['ga4_measurement_id'] ) ? sanitize_text_field( $in['ga4_measurement_id'] ) : '';
				break;
			case 'privacy':
				$values['rate_limit']         = isset( $in['rate_limit'] ) ? absint( $in['rate_limit'] ) : 20;
				$values['rate_window']        = isset( $in['rate_window'] ) ? absint( $in['rate_window'] ) : 60;
				$values['max_question_len']   = isset( $in['max_question_len'] ) ? absint( $in['max_question_len'] ) : 500;
				$values['blocklist']          = isset( $in['blocklist'] ) ? sanitize_textarea_field( $in['blocklist'] ) : '';
				$values['pii_mode']           = isset( $in['pii_mode'] ) && in_array( $in['pii_mode'], array( 'mask', 'block' ), true ) ? $in['pii_mode'] : 'mask';
				$values['log_retention_days'] = isset( $in['log_retention_days'] ) ? absint( $in['log_retention_days'] ) : 90;
				$values['consent_required']   = ! empty( $in['consent_required'] );
				$values['log_ip']             = ! empty( $in['log_ip'] );
				$values['trust_cloudflare']   = ! empty( $in['trust_cloudflare'] );
				$values['trusted_proxies']    = isset( $in['trusted_proxies'] ) ? sanitize_textarea_field( $in['trusted_proxies'] ) : '';
				$values['cost_limit_daily']   = isset( $in['cost_limit_daily'] ) ? max( 0, (float) $in['cost_limit_daily'] ) : 0;
				$values['cost_limit_monthly'] = isset( $in['cost_limit_monthly'] ) ? max( 0, (float) $in['cost_limit_monthly'] ) : 0;
				$values['token_limit_daily']   = isset( $in['token_limit_daily'] ) ? absint( $in['token_limit_daily'] ) : 0;
				$values['token_limit_monthly'] = isset( $in['token_limit_monthly'] ) ? absint( $in['token_limit_monthly'] ) : 0;
				$values['request_limit_daily']   = isset( $in['request_limit_daily'] ) ? absint( $in['request_limit_daily'] ) : 0;
				$values['request_limit_monthly'] = isset( $in['request_limit_monthly'] ) ? absint( $in['request_limit_monthly'] ) : 0;
				break;
			default:
				// Unknown tab: nothing to update.
				return;
		}

		$old_sig = Ks_Concierge_Embeddings::current_embed_sig();
		Ks_Concierge_Settings::update( $values );
		$new_sig = Ks_Concierge_Embeddings::current_embed_sig();

		// Embedding configuration changed: start a fresh reindex drain so the new
		// signature's index builds. Existing vectors stay until overwritten (no
		// wipe); search already filters to the current signature.
		if ( $old_sig !== $new_sig ) {
			delete_option( Ks_Concierge_Cache::STATE_KEY );
			if ( ! wp_next_scheduled( Ks_Concierge_Cache::CRON_HOOK ) ) {
				wp_schedule_single_event( time() + 5, Ks_Concierge_Cache::CRON_HOOK );
			}
		}

		// Reschedule cron if the interval changed.
		if ( $values['reindex_interval'] !== $current['reindex_interval'] ) {
			wp_clear_scheduled_hook( Ks_Concierge_Cache::CRON_HOOK );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $values['reindex_interval'], Ks_Concierge_Cache::CRON_HOOK );
		}

		// Exclusion rules changed: re-apply to already-indexed pages and refresh the
		// search index immediately, so adding/removing a path rule takes effect
		// without waiting for the next full reindex.
		if ( $values['exclude_rules'] !== $current['exclude_rules'] ) {
			( new Ks_Concierge_Cache() )->apply_exclude_rules();
		}

		// Reachability check turned off: restore pages demoted to 'unreachable' to
		// 'active' so they re-enter search (the recovery probe no longer runs).
		$reach_was_on = ! isset( $current['reachability_check'] ) || ! empty( $current['reachability_check'] );
		if ( isset( $values['reachability_check'] ) && $reach_was_on && empty( $values['reachability_check'] ) ) {
			( new Ks_Concierge_Cache() )->restore_unreachable();
		}

		add_settings_error( 'ks_concierge', 'saved', __( '設定を保存しました。', 'kashiwazaki-seo-concierge' ), 'updated' );
		set_transient( 'ks_concierge_settings_saved', 1, 30 );
	}

	/**
	 * Sanitize a textarea into an array of trimmed lines.
	 *
	 * @param string $raw Raw textarea content.
	 * @return string[]
	 */
	protected function sanitize_lines( $raw ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = sanitize_text_field( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Render the settings page with tabs.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = array(
			'general'   => __( '一般', 'kashiwazaki-seo-concierge' ),
			'ai'        => __( 'AI・モデル', 'kashiwazaki-seo-concierge' ),
			'index'     => __( 'インデックス', 'kashiwazaki-seo-concierge' ),
			'display'   => __( '表示', 'kashiwazaki-seo-concierge' ),
			'privacy'   => __( 'プライバシー・安全', 'kashiwazaki-seo-concierge' ),
			'analytics' => __( '分析', 'kashiwazaki-seo-concierge' ),
			'sandbox'   => __( 'サンドボックス', 'kashiwazaki-seo-concierge' ),
			'manual'    => __( '説明書', 'kashiwazaki-seo-concierge' ),
		);
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'general';
		}
		$s = Ks_Concierge_Settings::all();
		?>
		<div class="wrap ks-concierge-admin">
			<style>
				.ks-concierge-admin .ks-help{position:relative;display:inline-block;margin-left:6px;vertical-align:middle;cursor:help}
				.ks-concierge-admin .ks-help-mark{display:inline-flex;align-items:center;justify-content:center;width:17px;height:17px;border-radius:50%;background:#2271b1;color:#fff;font-size:11px;font-weight:700;line-height:1}
				.ks-concierge-admin .ks-help-bubble{position:absolute;left:calc(100% + 12px);top:50%;transform:translateY(-50%);width:300px;max-width:min(50vw,420px);background:#1d2327;color:#fff;font-size:12px;font-weight:400;line-height:1.6;padding:9px 12px;border-radius:6px;box-shadow:0 4px 18px rgba(0,0,0,.3);opacity:0;visibility:hidden;transition:opacity .12s;z-index:100001;white-space:normal;text-align:left}
				.ks-concierge-admin .ks-help-bubble:after{content:"";position:absolute;right:100%;top:50%;transform:translateY(-50%);border:6px solid transparent;border-right-color:#1d2327}
				.ks-concierge-admin .ks-help:hover .ks-help-bubble,.ks-concierge-admin .ks-help:focus .ks-help-bubble{opacity:1;visibility:visible}
				.ks-concierge-admin .form-table th{position:relative}
			</style>
			<h1>Kashiwazaki SEO Concierge</h1>
			<?php settings_errors( 'ks_concierge' ); ?>
			<?php if ( ! Ks_Concierge_OpenAI::has_key( 'embed' ) || ! Ks_Concierge_OpenAI::has_key( 'chat' ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: AI/model settings tab link. */
						esc_html__( 'ページ検索用AI または 回答生成用AI の APIキーが未設定です。%s で設定するまで回答を生成できません（Ollama ローカルはキー不要）。', 'kashiwazaki-seo-concierge' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=ai' ) ) . '">' . esc_html__( 'AI・モデル', 'kashiwazaki-seo-concierge' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>
			<?php
			$ks_last_index = (int) get_option( 'ks_concierge_last_reindex', 0 );
			if ( ! $ks_last_index ) :
				?>
				<div class="notice notice-info"><p>
					<?php
					printf(
						/* translators: %s: index tab link. */
						esc_html__( 'インデックスがまだ構築されていません。%s で「今すぐ再構築」を実行してください。', 'kashiwazaki-seo-concierge' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=index' ) ) . '">' . esc_html__( 'インデックス', 'kashiwazaki-seo-concierge' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( get_transient( 'ks_concierge_settings_saved' ) ) : delete_transient( 'ks_concierge_settings_saved' ); ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '設定を保存しました。', 'kashiwazaki-seo-concierge' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['reindexed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'インデックスを再構築しました。', 'kashiwazaki-seo-concierge' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['linkcheck'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'リンク到達性チェックを開始しました（バックグラウンドで順次実行します）。', 'kashiwazaki-seo-concierge' ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $key ) ); ?>" class="nav-tab <?php echo $active === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'manual' === $active ) : ?>
				<?php $this->render_manual(); ?>
			<?php elseif ( 'analytics' === $active ) : ?>
				<?php $this->render_analytics(); ?>
			<?php elseif ( 'sandbox' === $active ) : ?>
				<?php $this->render_sandbox(); ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=' . $active ) ); ?>">
					<?php wp_nonce_field( 'ks_concierge_save_settings', self::NONCE_NAME ); ?>
					<input type="hidden" name="ks_active_tab" value="<?php echo esc_attr( $active ); ?>" />
					<?php $this->render_tab( $active, $s ); ?>
					<?php submit_button( __( '設定を保存', 'kashiwazaki-seo-concierge' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the beginner-friendly manual tab.
	 *
	 * @return void
	 */
	protected function render_manual() {
		$ai_url    = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=ai' );
		$index_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=index' );
		?>
		<style>
			.ks-manual{max-width:880px}
			.ks-manual .ks-sec{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:4px 22px 18px;margin:0 0 18px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
			.ks-manual .ks-sec>h2{font-size:15px;margin:16px 0 10px;padding-bottom:8px;border-bottom:2px solid #f0f0f1;display:flex;align-items:center;gap:8px}
			.ks-manual .ks-sec>h2 .ks-ico{font-size:18px}
			.ks-manual p{line-height:1.85;margin:.4em 0}
			.ks-manual .ks-flow{display:flex;gap:10px;flex-wrap:wrap;align-items:stretch;margin:6px 0}
			.ks-manual .ks-flow .ks-box{flex:1;min-width:240px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:12px 14px}
			.ks-manual .ks-flow .ks-arrow{align-self:center;font-size:22px;color:#8c8f94}
			.ks-manual .ks-box b{display:block;margin-bottom:4px;color:#1d2327}
			.ks-manual ol.ks-steps{counter-reset:step;list-style:none;margin:6px 0;padding:0}
			.ks-manual ol.ks-steps>li{position:relative;padding:12px 14px 12px 52px;margin:0 0 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;line-height:1.8}
			.ks-manual ol.ks-steps>li:before{counter-increment:step;content:counter(step);position:absolute;left:14px;top:12px;width:26px;height:26px;border-radius:50%;background:#2271b1;color:#fff;font-weight:600;text-align:center;line-height:26px}
			.ks-manual table.ks-tbl{border-collapse:collapse;width:100%;margin:6px 0}
			.ks-manual table.ks-tbl th,.ks-manual table.ks-tbl td{border:1px solid #dcdcde;padding:8px 12px;text-align:left;vertical-align:top;font-size:13px}
			.ks-manual table.ks-tbl th{background:#f6f7f7;white-space:nowrap;width:150px}
			.ks-manual .ks-note{border-left:4px solid #72aee6;background:#f0f6fc;padding:10px 14px;border-radius:0 4px 4px 0;margin:6px 0}
			.ks-manual .ks-warn{border-left:4px solid #dba617;background:#fcf9e8;padding:10px 14px;border-radius:0 4px 4px 0;margin:6px 0}
			.ks-manual dl.ks-ts{margin:4px 0}
			.ks-manual dl.ks-ts dt{font-weight:600;margin:10px 0 2px}
			.ks-manual dl.ks-ts dd{margin:0 0 0 1.2em;color:#50575e}
		</style>
		<div class="ks-manual">
			<div class="ks-sec">
				<h2><span class="ks-ico">📘</span><?php esc_html_e( 'このプラグインは何ができる？', 'kashiwazaki-seo-concierge' ); ?></h2>
				<p><?php esc_html_e( 'サイト訪問者が入力した質問に対して、あなたのサイトの内容をもとにAIが自動で回答するチャットボットです。「サイト内検索＋AI回答」を組み合わせた案内役（コンシェルジュ）と考えてください。', 'kashiwazaki-seo-concierge' ); ?></p>
			</div>

			<div class="ks-sec">
				<h2><span class="ks-ico">⚙️</span><?php esc_html_e( '仕組み（2つのAIが連携）', 'kashiwazaki-seo-concierge' ); ?></h2>
				<div class="ks-flow">
					<div class="ks-box"><b><?php esc_html_e( '① ページ検索用AI', 'kashiwazaki-seo-concierge' ); ?></b><?php esc_html_e( '質問に関係するページをサイト内から探します。事前に全ページを「検索データ（索引）」に変換しておきます。', 'kashiwazaki-seo-concierge' ); ?></div>
					<div class="ks-arrow">→</div>
					<div class="ks-box"><b><?php esc_html_e( '② 回答生成用AI', 'kashiwazaki-seo-concierge' ); ?></b><?php esc_html_e( '見つかったページの内容をもとに、人間向けの回答文を作ります。', 'kashiwazaki-seo-concierge' ); ?></div>
				</div>
				<div class="ks-note"><?php esc_html_e( 'この2つは別々のAIサービスを指定できます（例：検索＝OpenAI、回答＝GLM や Ollama Cloud）。', 'kashiwazaki-seo-concierge' ); ?></div>
			</div>

			<div class="ks-sec">
				<h2><span class="ks-ico">🚀</span><?php esc_html_e( '使い始める3ステップ', 'kashiwazaki-seo-concierge' ); ?></h2>
				<ol class="ks-steps">
					<li><?php printf( wp_kses( /* translators: %s: AI tab link */ __( '<a href="%s">AI・モデル</a>タブで、使うAIサービスとAPIキーを設定します（Ollamaローカルはキー不要）。', 'kashiwazaki-seo-concierge' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( $ai_url ) ); ?></li>
					<li><?php esc_html_e( '一般タブで、サイトマップのURL（索引する対象ページの一覧）を設定します。', 'kashiwazaki-seo-concierge' ); ?></li>
					<li><?php printf( wp_kses( /* translators: %s: index tab link */ __( '<a href="%s">インデックス</a>タブで「今すぐ再構築」を1回押します。これで全ページの検索データが作られます（最初の1回だけ。以後は自動更新）。', 'kashiwazaki-seo-concierge' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( $index_url ) ); ?></li>
				</ol>
			</div>

			<div class="ks-sec">
				<h2><span class="ks-ico">🗂️</span><?php esc_html_e( '各タブの役割', 'kashiwazaki-seo-concierge' ); ?></h2>
				<table class="ks-tbl">
					<tr><th><?php esc_html_e( '一般', 'kashiwazaki-seo-concierge' ); ?></th><td><?php esc_html_e( '基本動作とサイトマップなど索引対象の設定。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'AI・モデル', 'kashiwazaki-seo-concierge' ); ?></th><td><?php esc_html_e( '検索AI・回答AIのプロバイダ／APIキー／モデルの設定。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'インデックス', 'kashiwazaki-seo-concierge' ); ?></th><td><?php esc_html_e( '検索データの作成・更新（今すぐ再構築／自動更新間隔／除外ルール）。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
					<tr><th><?php esc_html_e( '表示', 'kashiwazaki-seo-concierge' ); ?></th><td><?php esc_html_e( 'チャットボットの見た目・文言・表示位置。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'プライバシー・安全', 'kashiwazaki-seo-concierge' ); ?></th><td><?php esc_html_e( '個人情報の扱いと、1日の上限金額・問合せ回数（暴走課金の防止）。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
					<tr><th><?php esc_html_e( '分析', 'kashiwazaki-seo-concierge' ); ?></th><td><?php esc_html_e( 'よく聞かれた質問・回答できなかった質問などの確認。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'サンドボックス', 'kashiwazaki-seo-concierge' ); ?></th><td><?php esc_html_e( '公開せず管理画面上で質問を試して動作確認。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
				</table>
			</div>

			<div class="ks-sec">
				<h2><span class="ks-ico">🤖</span><?php esc_html_e( 'どのAIを選べばいい？', 'kashiwazaki-seo-concierge' ); ?></h2>
				<table class="ks-tbl">
					<tr><th><?php esc_html_e( '検索（埋め込み）', 'kashiwazaki-seo-concierge' ); ?></th><td><?php esc_html_e( 'OpenAI が安価で確実（例 text-embedding-3-small。大量ページでも数円程度）。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
					<tr><th><?php esc_html_e( '回答（チャット）', 'kashiwazaki-seo-concierge' ); ?></th><td><?php esc_html_e( 'OpenAI / GLM(Z.AI) / Ollama Cloud から選択（各自のアカウントのAPIキー）。応答の速さはサービスにより異なります。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
				</table>
				<div class="ks-warn"><?php esc_html_e( '注意：GLMのコーディングプランと Ollama Cloud は「回答」専用で、埋め込み（検索）には使えません。検索はOpenAIなどの埋め込み対応サービスが必要です。', 'kashiwazaki-seo-concierge' ); ?></div>
			</div>

			<div class="ks-sec">
				<h2><span class="ks-ico">💰</span><?php esc_html_e( '料金が心配なときは', 'kashiwazaki-seo-concierge' ); ?></h2>
				<p><?php esc_html_e( '「プライバシー・安全」タブで、1日・1か月の上限金額（USD）と、API問合せ回数の上限を設定できます。上限に達すると、それ以上はAIへ送信されず自動で停止するので、想定外の高額請求を防げます。', 'kashiwazaki-seo-concierge' ); ?></p>
			</div>

			<div class="ks-sec">
				<h2><span class="ks-ico">🔧</span><?php esc_html_e( 'うまく動かないとき', 'kashiwazaki-seo-concierge' ); ?></h2>
				<dl class="ks-ts">
					<dt><?php esc_html_e( '「該当するページが見つかりませんでした」と出る', 'kashiwazaki-seo-concierge' ); ?></dt>
					<dd><?php esc_html_e( '① 索引がまだ作られていない（インデックスタブで再構築）／② 回答AIのキー未設定や接続不可（AI・モデルタブを確認）。', 'kashiwazaki-seo-concierge' ); ?></dd>
					<dt><?php esc_html_e( 'APIキー未設定の警告が出る', 'kashiwazaki-seo-concierge' ); ?></dt>
					<dd><?php esc_html_e( 'AI・モデルタブで検索AI・回答AIそれぞれにキーを入れてください（Ollamaローカルは不要）。', 'kashiwazaki-seo-concierge' ); ?></dd>
					<dt><?php esc_html_e( '設定後の動作確認', 'kashiwazaki-seo-concierge' ); ?></dt>
					<dd><?php esc_html_e( 'サンドボックスタブで実際に質問して、回答が出るか確認してください。', 'kashiwazaki-seo-concierge' ); ?></dd>
				</dl>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a settings tab body.
	 *
	 * @param string $tab Tab key.
	 * @param array  $s   Settings.
	 * @return void
	 */
	protected function render_tab( $tab, $s ) {
		echo '<table class="form-table" role="presentation">';
		switch ( $tab ) {
			case 'general':
				$this->field_text( 'sitemap_url', __( 'サイトマップのURL', 'kashiwazaki-seo-concierge' ), $s['sitemap_url'], '', __( '索引する対象ページの一覧（sitemap.xml）のURL。WordPressなら通常 https://サイト名/wp-sitemap.xml です。ここに載っているページがチャットの検索対象になります。', 'kashiwazaki-seo-concierge' ) );
				$this->field_text( 'llms_txt_url', __( 'llms.txt のURL（任意）', 'kashiwazaki-seo-concierge' ), $s['llms_txt_url'], '', __( 'AI向けのページ一覧ファイル「llms.txt」がある場合のURL。無ければ空欄でOKです。', 'kashiwazaki-seo-concierge' ) );
				$this->field_checkbox( 'llms_txt_enabled', __( 'llms.txt も読み込む', 'kashiwazaki-seo-concierge' ), $s['llms_txt_enabled'], __( '上のllms.txtも索引対象に含めるか。通常はサイトマップだけで十分なので、無効のままで構いません。', 'kashiwazaki-seo-concierge' ) );
				break;
			case 'ai':
				$chat_provider_opts  = array(
					'openai' => 'OpenAI',
					'zai'    => 'GLM (Z.AI)',
					'ollama' => 'Ollama Cloud',
					'custom' => __( 'カスタム (OpenAI互換)', 'kashiwazaki-seo-concierge' ),
				);
				// Embeddings dropdown only lists providers that actually serve an
				// embeddings endpoint. GLM (coding plan) and Ollama Cloud do not.
				$embed_provider_opts = array(
					'openai' => 'OpenAI',
					'custom' => __( 'カスタム (OpenAI互換)', 'kashiwazaki-seo-concierge' ),
				);

				echo '<tr><td colspan="2"><p class="description">' . esc_html__( '「検索AI」が質問に関係するページを探し、「回答AI」がその内容から回答文を作ります。2つは別々のサービスを指定できます。各項目の「?」マークにカーソルを合わせると説明が出ます。', 'kashiwazaki-seo-concierge' ) . '</p></td></tr>';

				echo '<tr><td colspan="2"><hr><strong>' . esc_html__( '① 検索AI（質問に関係するページを探す）', 'kashiwazaki-seo-concierge' ) . '</strong></td></tr>';
				$this->field_select( 'embed_provider', __( '検索AI：使うサービス', 'kashiwazaki-seo-concierge' ), $s['embed_provider'], $embed_provider_opts, __( '関連ページを探す検索AIに使うサービス。OpenAIが安価で確実です（GLMやOllama Cloudは検索には使えません）。', 'kashiwazaki-seo-concierge' ) );
				$this->field_text( 'embed_api_base', __( '検索AI：接続先URL', 'kashiwazaki-seo-concierge' ), '' !== (string) $s['embed_api_base'] ? $s['embed_api_base'] : Ks_Concierge_Settings::provider_default_base( $s['embed_provider'], 'embed' ), '', __( '検索AIの接続先。通常は空欄でOK（選んだサービスの既定URLが自動で使われます）。独自サーバーを使う時だけ入力します。', 'kashiwazaki-seo-concierge' ) );
				$this->field_password( 'embed_api_key', __( '検索AI：APIキー', 'kashiwazaki-seo-concierge' ), '' !== (string) $s['embed_api_key_cipher'], __( '検索AIサービスのAPIキー。各自のアカウントで取得して貼り付けます。安全のため保存後は表示されません（入力した時だけ更新されます）。', 'kashiwazaki-seo-concierge' ) );
				$this->field_text( 'embeddings_model', __( '検索AI：モデル名', 'kashiwazaki-seo-concierge' ), $s['embeddings_model'], '', __( '使う埋め込みモデルの名前。OpenAIなら text-embedding-3-small（安価・推奨）または text-embedding-3-large（高精度）。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'embeddings_dims', __( '検索AI：次元数', 'kashiwazaki-seo-concierge' ), $s['embeddings_dims'], __( '検索データの精細さ（ベクトルの次元数）。OpenAIの3系のみ変更可。通常は既定のままでOKです。', 'kashiwazaki-seo-concierge' ) );

				echo '<tr><td colspan="2"><hr><strong>' . esc_html__( '② 回答AI（見つけたページから回答文を作る）', 'kashiwazaki-seo-concierge' ) . '</strong></td></tr>';
				$this->field_select( 'chat_provider', __( '回答AI：使うサービス', 'kashiwazaki-seo-concierge' ), $s['chat_provider'], $chat_provider_opts, __( '回答文を作る回答AIに使うサービス。OpenAI・GLM・Ollama Cloud などから選べます。', 'kashiwazaki-seo-concierge' ) );
				$this->field_text( 'chat_api_base', __( '回答AI：接続先URL', 'kashiwazaki-seo-concierge' ), '' !== (string) $s['chat_api_base'] ? $s['chat_api_base'] : Ks_Concierge_Settings::provider_default_base( $s['chat_provider'], 'chat' ), '', __( '回答AIの接続先。通常は空欄でOK（既定URLが自動）。独自サーバーを使う時だけ入力します。', 'kashiwazaki-seo-concierge' ) );
				$this->field_password( 'chat_api_key', __( '回答AI：APIキー', 'kashiwazaki-seo-concierge' ), '' !== (string) $s['chat_api_key_cipher'], __( '回答AIサービスのAPIキー。ローカルOllama以外は必須です。保存後は表示されません（入力した時だけ更新）。', 'kashiwazaki-seo-concierge' ) );
				$this->field_text( 'chat_model', __( '回答AI：モデル名', 'kashiwazaki-seo-concierge' ), $s['chat_model'], '', __( '使うモデルの名前。OpenAIなら gpt-4o-mini（最安・高速・推奨）。GLMは glm-4.6、Ollama Cloudは qwen3-coder:480b など。', 'kashiwazaki-seo-concierge' ) );
				$this->field_select( 'chat_structured_mode', __( '回答の出力形式（上級者向け）', 'kashiwazaki-seo-concierge' ), $s['chat_structured_mode'], array(
					'auto'        => __( '自動（おすすめ）', 'kashiwazaki-seo-concierge' ),
					'json_schema' => 'json_schema (strict)',
					'json_object' => 'json_object',
					'none'        => __( 'なし（プロンプト整形のみ）', 'kashiwazaki-seo-concierge' ),
				), __( 'AIにJSON形式で回答させる方式。通常は「自動」のままでOKです。特殊なAPIで不具合が出る時だけ変更します。', 'kashiwazaki-seo-concierge' ) );

				echo '<tr><td colspan="2"><hr><strong>' . esc_html__( '③ 詳細設定（通常は変更不要）', 'kashiwazaki-seo-concierge' ) . '</strong></td></tr>';
				$this->field_number( 'candidate_count', __( '検索する候補ページ数', 'kashiwazaki-seo-concierge' ), $s['candidate_count'], __( '1回の質問で探す関連ページの最大数（1〜20）。多いほど精度が上がる可能性がありますが、コストと時間も少し増えます。既定は10。', 'kashiwazaki-seo-concierge' ) );
				$this->field_textarea( 'system_prompt', __( 'AIへの指示文（任意）', 'kashiwazaki-seo-concierge' ), $s['system_prompt'], __( '回答AIの口調や方針を指定する文。空欄なら、丁寧な案内役として既定の動きをします。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'custom_embed_price_in', __( 'カスタム検索AIの入力単価（上級者）', 'kashiwazaki-seo-concierge' ), $s['custom_embed_price_in'], __( '「カスタム」プロバイダを使う場合の、検索AI入力料金（100万トークンあたりUSD）。コスト計算用。OpenAI等を使うなら0のままでOK。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'custom_chat_price_in', __( 'カスタム回答AIの入力単価（上級者）', 'kashiwazaki-seo-concierge' ), $s['custom_chat_price_in'], __( '「カスタム」プロバイダの回答AI入力料金（100万トークンあたりUSD）。OpenAI等を使うなら0のままでOK。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'custom_chat_price_out', __( 'カスタム回答AIの出力単価（上級者）', 'kashiwazaki-seo-concierge' ), $s['custom_chat_price_out'], __( '「カスタム」プロバイダの回答AI出力料金（100万トークンあたりUSD）。OpenAI等を使うなら0のままでOK。', 'kashiwazaki-seo-concierge' ) );
				echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'カスタムプロバイダで単価が0（未設定）の場合は、プライバシー・安全タブのトークン上限が課金の歯止めとして適用されます。', 'kashiwazaki-seo-concierge' ) . '</p></td></tr>';
				break;
			case 'index':
				// render_tab() opened a form-table before the switch. Close it (the
				// "状態" block below is not a form-table) and reopen one for "設定".
				echo '</table>';

				$cache        = new Ks_Concierge_Cache();
				$counts       = $cache->status_counts();
				$broken_total = (int) $counts['unreachable'] + (int) $counts['broken'];

				// ── 状態 ──
				echo '<h2 class="title" style="font-size:1.1em;margin:1em 0 .3em;">' . esc_html__( 'インデックスの状態', 'kashiwazaki-seo-concierge' ) . '</h2>';
				$last = (int) get_option( 'ks_concierge_last_reindex', 0 );
				echo '<p>' . esc_html__( '最終更新:', 'kashiwazaki-seo-concierge' ) . ' ' . ( $last ? esc_html( gmdate( 'Y-m-d H:i', $last ) . ' UTC' ) : '—' ) . '</p>';
				echo '<p style="font-size:1.05em;">' . sprintf(
					/* translators: 1: searchable, 2: excluded, 3: link-broken (unreachable+broken), 4: total. */
					esc_html__( '検索対象 %1$s ・ 除外 %2$s ・ リンク切れ %3$s （全 %4$s）', 'kashiwazaki-seo-concierge' ),
					'<strong>' . esc_html( number_format_i18n( $counts['active'] ) ) . '</strong>',
					esc_html( number_format_i18n( $counts['excluded'] ) ),
					'<strong>' . esc_html( number_format_i18n( $broken_total ) ) . '</strong>',
					esc_html( number_format_i18n( $counts['total'] ) )
				) . '</p>';
				echo '<p class="description">' . esc_html__( '「検索対象」だけが回答に使われます。', 'kashiwazaki-seo-concierge' ) . '</p>';

				// 操作（nonce 付きリンク。入れ子 <form> は不可なので <a> で）
				$reindex_url   = wp_nonce_url( admin_url( 'admin-post.php?action=ks_concierge_reindex_now' ), 'ks_concierge_reindex_now' );
				$linkcheck_url = wp_nonce_url( admin_url( 'admin-post.php?action=ks_concierge_check_links_now' ), 'ks_concierge_check_links_now' );
				// アクションは縦に1行ずつ。各ボタンの直後に「?」を置くことで、どちらの
				// 説明かが一目で分かる（横並びだと「?」がボタン間で曖昧になる）。
				echo '<p style="margin:.4em 0;">';
				echo '<a href="' . esc_url( $reindex_url ) . '" class="button button-secondary">' . esc_html__( '今すぐ再構築', 'kashiwazaki-seo-concierge' ) . '</a>';
				echo $this->help_icon( __( 'サイトマップ／llms.txt から全ページを読み込み直し、検索データ（索引）を作り直します。設定変更や記事更新をすぐ反映したいときに押します。', 'kashiwazaki-seo-concierge' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</p>';
				echo '<p style="margin:.4em 0;">';
				echo '<a href="' . esc_url( $linkcheck_url ) . '" class="button button-secondary">' . esc_html__( 'リンクを今すぐチェック', 'kashiwazaki-seo-concierge' ) . '</a>';
				echo $this->help_icon( __( '全ページの到達性を順次確認します。分割実行のため完了まで数分かかる場合があります。', 'kashiwazaki-seo-concierge' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo '</p>';

				// リンク切れ一覧（unreachable + broken、最新50件）
				$unavailable = $cache->get_unavailable_pages( 50 );
				if ( ! empty( $unavailable ) ) {
					$caption = ( $broken_total > count( $unavailable ) )
						/* translators: 1: total link-broken, 2: shown count. */
						? sprintf( esc_html__( 'リンク切れ %1$s 件（最新 %2$s 件を表示）', 'kashiwazaki-seo-concierge' ), esc_html( number_format_i18n( $broken_total ) ), esc_html( number_format_i18n( count( $unavailable ) ) ) )
						/* translators: %s: total link-broken. */
						: sprintf( esc_html__( 'リンク切れ（全 %s 件）', 'kashiwazaki-seo-concierge' ), esc_html( number_format_i18n( $broken_total ) ) );
					echo '<p class="description" style="margin:.5em 0 .2em;">' . $caption . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo '<table class="widefat striped" style="max-width:900px;margin:0 0 8px;"><thead><tr>';
					echo '<th>' . esc_html__( 'ページURL', 'kashiwazaki-seo-concierge' ) . '</th>';
					echo '<th style="width:140px">' . esc_html__( '種別・状態', 'kashiwazaki-seo-concierge' ) . '</th>';
					echo '<th style="width:160px">' . esc_html__( '確認日時', 'kashiwazaki-seo-concierge' ) . '</th></tr></thead><tbody>';
					foreach ( $unavailable as $row ) {
						if ( 'broken' === (string) $row->status ) {
							$kind = esc_html__( 'ソースから消失', 'kashiwazaki-seo-concierge' );
						} else {
							$kind = ( null !== $row->http_status ) ? esc_html( (string) (int) $row->http_status ) : esc_html__( 'エラー', 'kashiwazaki-seo-concierge' );
						}
						echo '<tr><td><a href="' . esc_url( $row->url ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr( $row->url ) . '">' . esc_html( $this->short_url( $row->url ) ) . '</a></td>';
						echo '<td>' . $kind . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo '<td>' . esc_html( $row->http_checked_at ? $row->http_checked_at . ' UTC' : '—' ) . '</td></tr>';
					}
					echo '</tbody></table>';
				}

				// ── 設定 ──（form-table 一本化。長い説明はツールチップへ）
				echo '<h2 class="title" style="font-size:1.1em;margin:1.5em 0 .3em;">' . esc_html__( '設定', 'kashiwazaki-seo-concierge' ) . '</h2>';
				echo '<table class="form-table" role="presentation">';
				$this->field_select( 'reindex_interval', __( '自動更新の間隔', 'kashiwazaki-seo-concierge' ), $s['reindex_interval'], array(
					'hourly'     => __( '毎時', 'kashiwazaki-seo-concierge' ),
					'twicedaily' => __( '1日2回', 'kashiwazaki-seo-concierge' ),
					'daily'      => __( '毎日', 'kashiwazaki-seo-concierge' ),
					'weekly'     => __( '毎週', 'kashiwazaki-seo-concierge' ),
				), __( 'サイト内容を取り込み直す頻度。通常は毎日でOK。即時反映は「今すぐ再構築」。低トラフィックのサイトはサーバー実cron（DISABLE_WP_CRON）を推奨。', 'kashiwazaki-seo-concierge' ) );
				$this->field_textarea( 'exclude_rules', __( '検索から除外するURL', 'kashiwazaki-seo-concierge' ), $s['exclude_rules'], __( '検索から外したいURLの一部を1行ずつ。例：/tag/ や /category/。保存で即反映。', 'kashiwazaki-seo-concierge' ) );
				$this->field_textarea( 'priority_rules', __( '優先して表示するURL', 'kashiwazaki-seo-concierge' ), $s['priority_rules'], __( '上位に出したいURLの一部を1行ずつ。重要ページを入れると回答に出やすくなります。', 'kashiwazaki-seo-concierge' ) );
				$this->field_checkbox( 'reachability_check', __( 'リンク到達性チェック', 'kashiwazaki-seo-concierge' ), ! empty( $s['reachability_check'] ), __( '見つからない（404）・エラー（403・500・タイムアウト等）のページを回答候補から自動で外します。直れば自動で戻ります。', 'kashiwazaki-seo-concierge' ) );
				break;
			case 'display':
				$this->field_text( 'tab_label', __( 'チャットを開くボタンの文言', 'kashiwazaki-seo-concierge' ), $s['tab_label'], '', __( 'サイト右下に出る開閉ボタンの文字。空欄なら「なにかお探しですか？」が表示されます。', 'kashiwazaki-seo-concierge' ) );
				$this->field_text( 'widget_title', __( 'チャット画面のタイトル', 'kashiwazaki-seo-concierge' ), $s['widget_title'], '', __( 'チャットを開いた時に上部（タイトルバー）に表示される見出し。空欄なら「Kashiwazaki SEO Concierge」が表示されます。', 'kashiwazaki-seo-concierge' ) );
				$this->field_image( 'bot_avatar', __( '回答アイコン画像', 'kashiwazaki-seo-concierge' ), $s['bot_avatar'], __( 'ボットの回答の横に表示するアイコン画像。ロボットのイラストでも、ご自身の顔写真でも自由に設定できます。空欄なら表示されません。', 'kashiwazaki-seo-concierge' ) );
				$this->field_text( 'accent_color', __( 'テーマカラー', 'kashiwazaki-seo-concierge' ), $s['accent_color'], '', __( 'チャットの見た目の色。#1e73be のような「#」付きのカラーコードで指定します。', 'kashiwazaki-seo-concierge' ) );
				$this->field_textarea( 'initial_message', __( '最初に表示するあいさつ', 'kashiwazaki-seo-concierge' ), $s['initial_message'], __( 'チャットを開いた時に、ボットが最初に表示するメッセージです。', 'kashiwazaki-seo-concierge' ) );
				$this->field_textarea( 'suggest_chips', __( '質問の例（クリックで送信・1行に1つ）', 'kashiwazaki-seo-concierge' ), implode( "\n", (array) $s['suggest_chips'] ), __( '入力欄の上に表示する質問例。訪問者がクリックするとその質問が送られます。1行に1つ書きます。', 'kashiwazaki-seo-concierge' ) );
				$this->field_select( 'display_condition', __( 'チャットを表示するページ', 'kashiwazaki-seo-concierge' ), $s['display_condition'], array(
					'all'        => __( '全ページ', 'kashiwazaki-seo-concierge' ),
					'front_page' => __( 'トップページのみ', 'kashiwazaki-seo-concierge' ),
					'singular'   => __( '個別の投稿・固定ページのみ', 'kashiwazaki-seo-concierge' ),
				), __( 'チャットウィジェットを表示するページの範囲。通常は「全ページ」でOKです。', 'kashiwazaki-seo-concierge' ) );
				$this->field_text( 'ga4_measurement_id', __( 'GA4 測定ID（任意）', 'kashiwazaki-seo-concierge' ), $s['ga4_measurement_id'], '', __( 'Googleアナリティクス4で利用状況を計測する場合の測定ID（G-XXXXXXX）。不要なら空欄でOKです。', 'kashiwazaki-seo-concierge' ) );
				break;
			case 'privacy':
				echo '<tr><td colspan="2"><hr><strong>' . esc_html__( '💰 料金の上限（暴走課金の防止）', 'kashiwazaki-seo-concierge' ) . '</strong></td></tr>';
				$this->field_number( 'cost_limit_daily', __( '1日の上限金額（USD）', 'kashiwazaki-seo-concierge' ), $s['cost_limit_daily'], __( '1日にAIへ使う金額の上限（米ドル）。これを超えると自動で停止し、それ以上は課金されません。既定は$5。0にすると無制限（非推奨）。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'cost_limit_monthly', __( '1か月の上限金額（USD）', 'kashiwazaki-seo-concierge' ), $s['cost_limit_monthly'], __( '1か月にAIへ使う金額の上限（米ドル）。超えると自動停止。既定は$50。0で無制限（非推奨）。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'request_limit_daily', __( '1日の問い合わせ回数の上限', 'kashiwazaki-seo-concierge' ), $s['request_limit_daily'], __( '1日にAIへ送る回数の上限（検索＋回答の合計）。無限ループなどの暴走を防ぐ保険です。既定は10000。0で無効。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'request_limit_monthly', __( '1か月の問い合わせ回数の上限', 'kashiwazaki-seo-concierge' ), $s['request_limit_monthly'], __( '1か月にAIへ送る回数の上限。既定は200000。0で無効。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'token_limit_daily', __( '1日のトークン上限（上級者）', 'kashiwazaki-seo-concierge' ), $s['token_limit_daily'], __( '料金を自動計算できないプロバイダ向けの保険。1日のトークン数の上限。OpenAI等を使うなら0（無効）でOK。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'token_limit_monthly', __( '1か月のトークン上限（上級者）', 'kashiwazaki-seo-concierge' ), $s['token_limit_monthly'], __( '同上の1か月版。OpenAI等を使うなら0（無効）でOK。', 'kashiwazaki-seo-concierge' ) );

				echo '<tr><td colspan="2"><hr><strong>' . esc_html__( '🛡️ 不正利用・個人情報の対策', 'kashiwazaki-seo-concierge' ) . '</strong></td></tr>';
				$this->field_number( 'rate_limit', __( '連投制限：回数', 'kashiwazaki-seo-concierge' ), $s['rate_limit'], __( '同じ訪問者が短時間に送れる質問の回数上限。いたずらや過剰アクセスを防ぎます。既定は20。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'rate_window', __( '連投制限：時間（秒）', 'kashiwazaki-seo-concierge' ), $s['rate_window'], __( '上の「回数」を数える時間の長さ（秒）。例：60秒のあいだに20回まで、という意味になります。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'max_question_len', __( '質問の最大文字数', 'kashiwazaki-seo-concierge' ), $s['max_question_len'], __( '1回の質問で受け付ける最大の文字数。極端に長い入力を防ぎます。', 'kashiwazaki-seo-concierge' ) );
				$this->field_textarea( 'blocklist', __( '禁止ワード（1行に1語）', 'kashiwazaki-seo-concierge' ), $s['blocklist'], __( 'この語を含む質問には回答しません。NGワードを1行ずつ書きます。', 'kashiwazaki-seo-concierge' ) );
				$this->field_select( 'pii_mode', __( '個人情報が含まれた時の動作', 'kashiwazaki-seo-concierge' ), $s['pii_mode'], array(
					'mask'  => __( '伏字にして送信（完全ではありません）', 'kashiwazaki-seo-concierge' ),
					'block' => __( '送信せず定型文で応答（機微な業種に推奨）', 'kashiwazaki-seo-concierge' ),
				), __( '電話番号やメール等の個人情報が質問に含まれた場合の扱い。伏字＝マスクして送信、送信せず＝AIに送らず定型応答を返します。', 'kashiwazaki-seo-concierge' ) );
				$this->field_number( 'log_retention_days', __( 'ログの保存日数', 'kashiwazaki-seo-concierge' ), $s['log_retention_days'], __( '質問ログを何日間保存するか。過ぎたものは自動で削除されます。', 'kashiwazaki-seo-concierge' ) );
				$this->field_checkbox( 'consent_required', __( '送信前に同意を求める', 'kashiwazaki-seo-concierge' ), $s['consent_required'], __( '質問をAIに送る前に、訪問者の同意を必須にします。プライバシーを重視するサイト向けです。', 'kashiwazaki-seo-concierge' ) );
				echo '<tr><td colspan="2"><hr><strong>' . esc_html__( '🛡 スパム対策（IP・ブラウザの記録）', 'kashiwazaki-seo-concierge' ) . '</strong></td></tr>';
				$this->field_checkbox( 'log_ip', __( '訪問者のIPアドレスとブラウザを履歴に記録する', 'kashiwazaki-seo-concierge' ), ! empty( $s['log_ip'] ), __( 'スパム特定用にIPとブラウザを記録します。保存日数で自動削除。IPは個人情報のためプライバシーポリシー記載を推奨。', 'kashiwazaki-seo-concierge' ) );
				$this->field_checkbox( 'trust_cloudflare', __( 'Cloudflare 経由のサイト', 'kashiwazaki-seo-concierge' ), ! empty( $s['trust_cloudflare'] ), __( 'Cloudflare 経由のサイトでオン。本当の訪問者IPを記録でき、連投制限も本人単位で効きます。使っていなければオフのまま。', 'kashiwazaki-seo-concierge' ) );
				$this->field_textarea( 'trusted_proxies', __( '信頼するプロキシ（上級者向け・1行に1つ・CIDR可）', 'kashiwazaki-seo-concierge' ), $s['trusted_proxies'], __( 'Cloudflare 以外のプロキシ経由時、その送信元IP/CIDRを記入（例：10.0.0.0/8）。不明なら空欄でOK。', 'kashiwazaki-seo-concierge' ) );
				echo '</table>';
				if ( (float) $s['cost_limit_daily'] <= 0 && (float) $s['cost_limit_monthly'] <= 0
					&& (int) $s['token_limit_daily'] <= 0 && (int) $s['token_limit_monthly'] <= 0
					&& (int) $s['request_limit_daily'] <= 0 && (int) $s['request_limit_monthly'] <= 0 ) {
					echo '<div class="notice notice-warning inline"><p>' . esc_html__( '課金上限がすべて 0（無制限）です。有料プロバイダ利用時は青天井課金のリスクがあります。USD 上限またはトークン上限の設定を推奨します。', 'kashiwazaki-seo-concierge' ) . '</p></div>';
				}
				echo '<p class="description">' . esc_html__( '質問は選択した AI プロバイダへ送信されます。PIIマスクは正規表現ベースの best-effort で完全ではありません。', 'kashiwazaki-seo-concierge' ) . '</p><table class="form-table" role="presentation">';
				break;
		}
		echo '</table>';
	}

	/**
	 * Render the analytics tab.
	 *
	 * @return void
	 */
	protected function render_analytics() {
		$analytics = new Ks_Concierge_Analytics();
		$popular   = $analytics->popular_questions( 10 );
		$gaps      = $analytics->content_gaps( 10 );

		// History controls (read-only navigation; the page itself is capability-gated).
		$per_page    = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$scope       = isset( $_GET['log_scope'] ) ? sanitize_key( wp_unslash( $_GET['log_scope'] ) ) : 'all';
		if ( ! in_array( $scope, array( 'all', 'visitor', 'admin' ), true ) ) {
			$scope = 'all';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page        = isset( $_GET['log_page'] ) ? max( 1, (int) $_GET['log_page'] ) : 1;
		$total       = $analytics->count_logs( $scope );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $page > $total_pages ) {
			$page = $total_pages;
		}
		$offset       = ( $page - 1 ) * $per_page;
		$logs         = $analytics->recent_logs( $per_page, $offset, $scope );
		$count_visitor = $analytics->count_logs( 'visitor' );
		$count_admin   = $analytics->count_logs( 'admin' );

		$scope_labels = array(
			'all'     => __( 'すべて', 'kashiwazaki-seo-concierge' ),
			'visitor' => __( '訪問者のみ', 'kashiwazaki-seo-concierge' ),
			'admin'   => __( '管理者テストのみ', 'kashiwazaki-seo-concierge' ),
		);
		?>
		<style>
			.ks-analytics .ks-cards{display:flex;gap:14px;flex-wrap:wrap;margin:6px 0 4px}
			.ks-analytics .ks-card{flex:1;min-width:160px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 16px}
			.ks-analytics .ks-card b{display:block;font-size:24px;line-height:1.2}
			.ks-analytics .ks-card span{color:#646970;font-size:12px}
			.ks-analytics .ks-subnav{margin:14px 0 6px}
			.ks-analytics .ks-subnav a{display:inline-block;padding:5px 12px;margin-right:6px;border:1px solid #c3c4c7;border-radius:999px;text-decoration:none;color:#1d2327;background:#f6f7f7}
			.ks-analytics .ks-subnav a.active{background:#2271b1;border-color:#2271b1;color:#fff}
			.ks-analytics .ks-badge{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
			.ks-analytics .ks-badge--visitor{background:#e6f4ea;color:#1e7e34}
			.ks-analytics .ks-badge--admin{background:#fff4e5;color:#b26a00}
			.ks-analytics .ks-badge--ok{background:#e6f4ea;color:#1e7e34}
			.ks-analytics .ks-badge--ng{background:#fde7e9;color:#b32d2e}
			.ks-analytics table.ks-history td{vertical-align:top}
			.ks-analytics .ks-q{font-weight:600}
			.ks-analytics .ks-a{color:#50575e;font-size:12px;margin-top:3px;max-width:520px}
			.ks-analytics .ks-urls{margin-top:5px;display:flex;flex-wrap:wrap;gap:4px 10px}
			.ks-analytics .ks-url{font-size:11px;color:#2271b1;text-decoration:none;background:#f0f6fc;border:1px solid #d2e4f3;border-radius:4px;padding:1px 7px}
			.ks-analytics .ks-url:hover{background:#e5f0fa}
			.ks-analytics .ks-url--clicked{background:#e6f4ea;border-color:#acdcb8;color:#1e7e34;font-weight:600}
			.ks-analytics .ks-time{white-space:nowrap;color:#646970;font-size:12px}
			.ks-analytics .ks-pager{margin:12px 0;display:flex;align-items:center;gap:10px}
		</style>
		<div class="ks-analytics">
			<div class="ks-cards">
				<div class="ks-card"><b><?php echo esc_html( number_format_i18n( $count_visitor ) ); ?></b><span><?php esc_html_e( '訪問者の質問（実データ）', 'kashiwazaki-seo-concierge' ); ?></span></div>
				<div class="ks-card"><b><?php echo esc_html( number_format_i18n( $count_admin ) ); ?></b><span><?php esc_html_e( '管理者テスト（ログイン中の質問）', 'kashiwazaki-seo-concierge' ); ?></span></div>
			</div>
			<p class="description"><?php esc_html_e( 'ログインした状態でチャットした質問は「管理者テスト」として別枠で集計し、下の集計（よく聞かれる質問・コンテンツギャップ）には含めません。', 'kashiwazaki-seo-concierge' ); ?></p>

			<h2><?php esc_html_e( 'よく聞かれる質問', 'kashiwazaki-seo-concierge' ); ?> <span class="description">（<?php esc_html_e( '訪問者のみ', 'kashiwazaki-seo-concierge' ); ?>）</span></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( '質問', 'kashiwazaki-seo-concierge' ); ?></th><th style="width:80px"><?php esc_html_e( '件数', 'kashiwazaki-seo-concierge' ); ?></th></tr></thead><tbody>
			<?php if ( $popular ) : foreach ( $popular as $row ) : ?>
				<tr><td><?php echo esc_html( $row->question ); ?></td><td><?php echo (int) $row->cnt; ?></td></tr>
			<?php endforeach; else : ?>
				<tr><td colspan="2"><?php esc_html_e( 'データがありません。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
			<?php endif; ?>
			</tbody></table>

			<h2><?php esc_html_e( 'ヒットしなかった質問（コンテンツギャップ）', 'kashiwazaki-seo-concierge' ); ?> <span class="description">（<?php esc_html_e( '訪問者のみ', 'kashiwazaki-seo-concierge' ); ?>）</span></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( '質問', 'kashiwazaki-seo-concierge' ); ?></th><th style="width:80px"><?php esc_html_e( '件数', 'kashiwazaki-seo-concierge' ); ?></th></tr></thead><tbody>
			<?php if ( $gaps ) : foreach ( $gaps as $row ) : ?>
				<tr><td><?php echo esc_html( $row->question ); ?></td><td><?php echo (int) $row->cnt; ?></td></tr>
			<?php endforeach; else : ?>
				<tr><td colspan="2"><?php esc_html_e( 'データがありません。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
			<?php endif; ?>
			</tbody></table>

			<h2><?php esc_html_e( '質問と回答の履歴', 'kashiwazaki-seo-concierge' ); ?></h2>
			<div class="ks-subnav">
				<?php foreach ( $scope_labels as $key => $label ) : ?>
					<a class="<?php echo $scope === $key ? 'active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'log_scope' => $key, 'log_page' => 1 ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</div>
			<table class="widefat striped ks-history">
				<thead><tr>
					<th style="width:130px"><?php esc_html_e( '日時', 'kashiwazaki-seo-concierge' ); ?></th>
					<th style="width:90px"><?php esc_html_e( '種別', 'kashiwazaki-seo-concierge' ); ?></th>
					<th><?php esc_html_e( '質問・回答・案内したURL', 'kashiwazaki-seo-concierge' ); ?></th>
					<th style="width:80px"><?php esc_html_e( '結果', 'kashiwazaki-seo-concierge' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( $logs ) : foreach ( $logs as $row ) : ?>
					<tr>
						<td class="ks-time"><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i' ) ); ?></td>
						<td>
							<?php if ( (int) $row->is_admin ) : ?>
								<span class="ks-badge ks-badge--admin"><?php esc_html_e( '管理者テスト', 'kashiwazaki-seo-concierge' ); ?></span>
							<?php else : ?>
								<span class="ks-badge ks-badge--visitor"><?php esc_html_e( '訪問者', 'kashiwazaki-seo-concierge' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $row->ip ) ) : ?>
								<div class="ks-ip" style="font-size:11px;color:#555;margin-top:4px;word-break:break-all;"><?php echo esc_html( (string) $row->ip ); ?></div>
							<?php endif; ?>
							<?php if ( ! empty( $row->user_agent ) ) : ?>
								<div class="ks-ua" style="font-size:11px;color:#888;margin-top:2px;" title="<?php echo esc_attr( (string) $row->user_agent ); ?>"><?php echo esc_html( mb_strimwidth( (string) $row->user_agent, 0, 38, '…' ) ); ?></div>
							<?php endif; ?>
						</td>
						<td>
							<div class="ks-q"><?php echo esc_html( $row->question ); ?></div>
							<?php if ( '' !== (string) $row->answer ) : ?>
								<div class="ks-a"><?php echo esc_html( mb_strimwidth( (string) $row->answer, 0, 160, '…' ) ); ?></div>
							<?php endif; ?>
							<?php
							$urls    = json_decode( (string) $row->matched_urls, true );
							$clicked = (string) $row->clicked_url;
							if ( is_array( $urls ) && $urls ) :
								?>
								<div class="ks-urls">
									<?php
									foreach ( array_slice( $urls, 0, 5 ) as $u ) :
										if ( ! is_string( $u ) || '' === $u ) {
											continue;
										}
										// Normalize both sides through esc_url_raw (clicked_url was
										// stored that way) so the highlight is not missed on
										// trivial encoding/normalization differences.
										$is_clicked = ( '' !== $clicked && esc_url_raw( $u ) === $clicked );
										?>
										<a href="<?php echo esc_url( $u ); ?>" target="_blank" rel="noopener noreferrer" class="ks-url<?php echo $is_clicked ? ' ks-url--clicked' : ''; ?>"><?php echo esc_html( $this->short_url( $u ) ); ?><?php echo $is_clicked ? ' ✓' : ''; ?></a>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( (int) $row->answered ) : ?>
								<span class="ks-badge ks-badge--ok"><?php esc_html_e( '回答', 'kashiwazaki-seo-concierge' ); ?></span>
							<?php else : ?>
								<span class="ks-badge ks-badge--ng"><?php esc_html_e( '未ヒット', 'kashiwazaki-seo-concierge' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; else : ?>
					<tr><td colspan="4"><?php esc_html_e( 'まだ履歴がありません。', 'kashiwazaki-seo-concierge' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="ks-pager">
					<?php if ( $page > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'log_scope' => $scope, 'log_page' => $page - 1 ) ) ); ?>">&laquo; <?php esc_html_e( '前へ', 'kashiwazaki-seo-concierge' ); ?></a>
					<?php endif; ?>
					<span class="description"><?php
						/* translators: 1: current page, 2: total pages, 3: total rows. */
						echo esc_html( sprintf( __( '%1$d / %2$d ページ（全 %3$s 件）', 'kashiwazaki-seo-concierge' ), $page, $total_pages, number_format_i18n( $total ) ) );
					?></span>
					<?php if ( $page < $total_pages ) : ?>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'log_scope' => $scope, 'log_page' => $page + 1 ) ) ); ?>"><?php esc_html_e( '次へ', 'kashiwazaki-seo-concierge' ); ?> &raquo;</a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<p class="description"><?php echo esc_html( sprintf( /* translators: %s: total rows. */ __( '全 %s 件', 'kashiwazaki-seo-concierge' ), number_format_i18n( $total ) ) ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
				<?php wp_nonce_field( 'ks_concierge_export_logs' ); ?>
				<input type="hidden" name="action" value="ks_concierge_export_logs" />
				<?php submit_button( __( 'すべてのログをCSVエクスポート', 'kashiwazaki-seo-concierge' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="description"><?php
				/* translators: %d: retention days. */
				echo esc_html( sprintf( __( '履歴は「プライバシー・安全」で設定した保存日数（現在 %d 日）を過ぎると自動的に削除されます。古いものから消えるため、件数が無制限に増え続けることはありません。', 'kashiwazaki-seo-concierge' ), (int) Ks_Concierge_Settings::get( 'log_retention_days', 90 ) ) );
			?></p>
		</div>
		<?php
	}

	/**
	 * Shorten a URL for compact display in the history table: drop the scheme and
	 * host, show a decoded path (so Japanese slugs are readable), truncated.
	 *
	 * @param string $url Full URL.
	 * @return string
	 */
	protected function short_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path || '/' === $path ) {
			$path = (string) wp_parse_url( $url, PHP_URL_HOST );
		} else {
			$path = rawurldecode( $path );
		}
		return mb_strimwidth( $path, 0, 48, '…' );
	}

	/**
	 * Render the sandbox tab.
	 *
	 * @return void
	 */
	protected function render_sandbox() {
		?>
		<h2><?php esc_html_e( '品質テスト用サンドボックス', 'kashiwazaki-seo-concierge' ); ?></h2>
		<p class="description"><?php esc_html_e( '公開前に想定質問を入力して、回答・候補ページ・スコアを確認できます。', 'kashiwazaki-seo-concierge' ); ?></p>
		<p>
			<input type="text" id="ks-sandbox-q" class="regular-text" placeholder="<?php esc_attr_e( '質問を入力…', 'kashiwazaki-seo-concierge' ); ?>" />
			<button type="button" class="button button-primary" id="ks-sandbox-run"><?php esc_html_e( 'テスト実行', 'kashiwazaki-seo-concierge' ); ?></button>
		</p>
		<div id="ks-sandbox-result" class="ks-sandbox-result"></div>
		<?php
	}

	/**
	 * AJAX handler for the sandbox.
	 *
	 * @return void
	 */
	public function ajax_sandbox() {
		check_ajax_referer( 'ks_concierge_sandbox', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'kashiwazaki-seo-concierge' ) ), 403 );
		}
		$question = Ks_Concierge_Security::sanitize_question( (string) ( isset( $_POST['question'] ) ? wp_unslash( $_POST['question'] ) : '' ) );
		if ( '' === $question ) {
			wp_send_json_error( array( 'message' => __( '質問を入力してください。', 'kashiwazaki-seo-concierge' ) ) );
		}
		$query    = new Ks_Concierge_Query();
		$response = $query->answer( Ks_Concierge_Security::mask_pii( $question ), 'sandbox' );
		wp_send_json_success( $response );
	}

	/**
	 * Help icon with a hover/focus tooltip bubble explaining a field in plain
	 * language. Returns an empty string when no help text is given.
	 *
	 * @param string $text Plain-language explanation.
	 * @return string HTML.
	 */
	protected function help_icon( $text ) {
		if ( '' === trim( (string) $text ) ) {
			return '';
		}
		return ' <span class="ks-help" tabindex="0" role="note" aria-label="' . esc_attr( $text ) . '">'
			. '<span class="ks-help-mark" aria-hidden="true">?</span>'
			. '<span class="ks-help-bubble">' . esc_html( $text ) . '</span></span>';
	}

	/**
	 * Render a text input field row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $placeholder Placeholder / default hint.
	 * @param string $help  Plain-language tooltip text.
	 * @return void
	 */
	protected function field_text( $name, $label, $value, $placeholder = '', $help = '' ) {
		echo '<tr><th><label for="ks_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>' . $this->help_icon( $help ) . '</th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<input type="text" id="ks_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" />';
		if ( '' !== $placeholder ) {
			echo '<p class="description">' . sprintf( /* translators: %s: default URL used when the field is left empty. */ esc_html__( '空欄のときの既定値: %s', 'kashiwazaki-seo-concierge' ), '<code>' . esc_html( $placeholder ) . '</code>' ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Render an image picker field row: a URL input plus a button that opens the
	 * WordPress media library, with a live thumbnail preview.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Current image URL.
	 * @param string $help  Plain-language tooltip text.
	 * @return void
	 */
	protected function field_image( $name, $label, $value, $help = '' ) {
		$id = 'ks_' . $name;
		echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>' . $this->help_icon( $help ) . '</th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="ks-image-field" data-ks-image-field="1">';
		echo '<img class="ks-image-field__preview" src="' . esc_url( $value ) . '" alt="" style="' . ( '' === $value ? 'display:none;' : '' ) . '" />';
		echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" class="regular-text ks-image-field__url" value="' . esc_attr( $value ) . '" placeholder="https://…/icon.png" />';
		echo '<button type="button" class="button ks-image-field__select">' . esc_html__( 'メディアから選択', 'kashiwazaki-seo-concierge' ) . '</button>';
		echo '<button type="button" class="button-link ks-image-field__clear">' . esc_html__( '削除', 'kashiwazaki-seo-concierge' ) . '</button>';
		echo '<p class="description">' . esc_html__( '回答チャットの横に表示するアイコン画像。あなたの写真でもロボット画像でもOKです。空欄なら画像は表示されません。正方形の画像を推奨します。', 'kashiwazaki-seo-concierge' ) . '</p>';
		echo '</div></td></tr>';
	}

	/**
	 * Render a write-only password field row (value never echoed back).
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param bool   $is_set Whether a value is already stored.
	 * @param string $help  Plain-language tooltip text.
	 * @return void
	 */
	protected function field_password( $name, $label, $is_set, $help = '' ) {
		echo '<tr><th><label for="ks_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>' . $this->help_icon( $help ) . '</th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<input type="password" id="ks_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" class="regular-text" autocomplete="new-password" placeholder="' . ( $is_set ? '********' : '' ) . '" /></td></tr>';
	}

	/**
	 * Render a number input field row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param string $help  Plain-language tooltip text.
	 * @return void
	 */
	protected function field_number( $name, $label, $value, $help = '' ) {
		echo '<tr><th><label for="ks_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>' . $this->help_icon( $help ) . '</th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<input type="number" step="any" id="ks_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" /></td></tr>';
	}

	/**
	 * Render a textarea field row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $help  Plain-language tooltip text.
	 * @return void
	 */
	protected function field_textarea( $name, $label, $value, $help = '' ) {
		echo '<tr><th><label for="ks_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>' . $this->help_icon( $help ) . '</th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<textarea id="ks_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" rows="4" class="large-text">' . esc_textarea( $value ) . '</textarea></td></tr>';
	}

	/**
	 * Render a checkbox field row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param bool   $value Value.
	 * @param string $help  Plain-language tooltip text.
	 * @return void
	 */
	protected function field_checkbox( $name, $label, $value, $help = '' ) {
		echo '<tr><th>' . esc_html( $label ) . $this->help_icon( $help ) . '</th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $value, true, false ) . ' /> ' . esc_html__( '有効', 'kashiwazaki-seo-concierge' ) . '</label></td></tr>';
	}

	/**
	 * Render a select field row.
	 *
	 * @param string               $name    Field name.
	 * @param string               $label   Label.
	 * @param string               $value   Current value.
	 * @param array<string,string> $options Options.
	 * @param string                $help   Plain-language tooltip text.
	 * @return void
	 */
	protected function field_select( $name, $label, $value, $options, $help = '' ) {
		echo '<tr><th><label for="ks_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label>' . $this->help_icon( $help ) . '</th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<select id="ks_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $text ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}
}
