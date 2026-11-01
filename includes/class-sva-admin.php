<?php
/**
 * Admin: analysis dashboard, settings, conversation log.
 */
defined( 'ABSPATH' ) || exit;

class SVA_Admin {

	const SLUG = 'smart-visitor-assistant';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_sva_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_sva_test_ai', array( __CLASS__, 'ajax_test_ai' ) );
		add_action( 'admin_post_sva_clear_logs', array( __CLASS__, 'clear_logs' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SVA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( 'دستیار هوشمند', 'دستیار هوشمند', 'manage_options', self::SLUG, array( __CLASS__, 'page' ), 'dashicons-format-chat', 58 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'تنظیمات', 'smart-visitor-assistant' ) . '</a>' );
		return $links;
	}

	public static function register() {
		register_setting( 'sva_settings_group', SVA_Plugin::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'sva-admin', SVA_URL . 'assets/css/admin.css', array(), SVA_VERSION );
		wp_enqueue_script( 'sva-admin', SVA_URL . 'assets/js/admin.js', array(), SVA_VERSION, true );
		wp_localize_script(
			'sva-admin',
			'SVA_ADMIN',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'sva_admin' ),
				'rest'  => esc_url_raw( rest_url( 'sva/v1/chat' ) ),
				'wpnon' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) || ! get_transient( 'sva_activated' ) ) {
			return;
		}
		$p = get_option( SVA_Plugin::PROFILE );
		if ( ! empty( $p['scanned_at'] ) ) {
			delete_transient( 'sva_activated' );
			return;
		}
		echo '<div class="notice notice-info is-dismissible"><p><strong>دستیار هوشمند بازدیدکننده</strong> نصب شد و در پس‌زمینه سایت را تحلیل می‌کند. برای تحلیل فوری <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">اینجا کلیک کنید</a>.</p></div>';
	}

	/* ---------------------------------------------------------------- */

	public static function sanitize( $in ) {
		$o    = SVA_Plugin::settings();
		$in   = is_array( $in ) ? $in : array();
		$form = isset( $in['_form'] ) ? $in['_form'] : '';

		if ( 'settings' === $form ) {
			$o['enabled']          = empty( $in['enabled'] ) ? 0 : 1;
			$o['logging']          = empty( $in['logging'] ) ? 0 : 1;
			$o['assistant_name']   = sanitize_text_field( $in['assistant_name'] ?? '' );
			$o['welcome']          = sanitize_textarea_field( $in['welcome'] ?? '' );
			$color                 = sanitize_hex_color( $in['color'] ?? '' );
			$o['color']            = $color ? $color : '#2563eb';
			$o['position']         = ( 'left' === ( $in['position'] ?? '' ) ) ? 'left' : 'right';
			$o['show_prices']      = in_array( $in['show_prices'] ?? '', array( 'yes', 'no' ), true ) ? $in['show_prices'] : 'auto';
			$o['mode']             = ( 'ai' === ( $in['mode'] ?? '' ) ) ? 'ai' : 'local';
			$o['provider']         = ( 'openai' === ( $in['provider'] ?? '' ) ) ? 'openai' : 'anthropic';
			$o['api_base']         = esc_url_raw( trim( $in['api_base'] ?? '' ) );
			$o['model']            = sanitize_text_field( $in['model'] ?? '' );
			$o['max_tokens']       = max( 100, min( 4000, (int) ( $in['max_tokens'] ?? 600 ) ) );
			$o['rate_limit']       = max( 1, min( 100, (int) ( $in['rate_limit'] ?? 15 ) ) );
			$o['ai_instructions']  = sanitize_textarea_field( $in['ai_instructions'] ?? '' );
			$key                   = trim( (string) ( $in['api_key'] ?? '' ) );
			if ( ! empty( $in['api_key_clear'] ) ) {
				$o['api_key'] = '';
			} elseif ( '' !== $key ) {
				$o['api_key'] = sanitize_text_field( $key );
			}
		} elseif ( 'knowledge' === $form ) {
			$o['custom_knowledge'] = sanitize_textarea_field( $in['custom_knowledge'] ?? '' );
			$o['faq']              = sanitize_textarea_field( $in['faq'] ?? '' );
		} elseif ( 'profile' === $form ) {
			$types                   = SVA_I18n::types();
			$t                       = $in['site_type_override'] ?? '';
			$o['site_type_override'] = isset( $types[ $t ] ) ? $t : '';
			$o['key_overrides']      = array();
			foreach ( (array) ( $in['key_overrides'] ?? array() ) as $role => $url ) {
				$url = trim( (string) $url );
				if ( '-' === $url ) {
					$o['key_overrides'][ sanitize_key( $role ) ] = '-';
				} elseif ( '' !== $url ) {
					$o['key_overrides'][ sanitize_key( $role ) ] = esc_url_raw( $url );
				}
			}
			$o['contact_phone']   = sanitize_text_field( $in['contact_phone'] ?? '' );
			$o['contact_email']   = sanitize_text_field( $in['contact_email'] ?? '' );
			$o['contact_address'] = sanitize_text_field( $in['contact_address'] ?? '' );
			$o['contact_hours']   = sanitize_text_field( $in['contact_hours'] ?? '' );
		}
		return $o;
	}

	/* ---------------------------------------------------------------- */

	public static function ajax_scan() {
		check_ajax_referer( 'sva_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		if ( 'start' === $step ) {
			$s = SVA_Analyzer::start();
			wp_send_json_success( array( 'total' => $s['total'], 'pos' => 0, 'status' => $s['status'] ) );
		}
		if ( 'batch' === $step ) {
			$t0 = microtime( true );
			do {
				$s = SVA_Analyzer::run_batch();
			} while ( isset( $s['status'] ) && 'running' === $s['status'] && microtime( true ) - $t0 < 8 );
			wp_send_json_success( array( 'total' => $s['total'], 'pos' => $s['pos'], 'status' => $s['status'] ) );
		}
		if ( 'finalize' === $step ) {
			$p = SVA_Analyzer::finalize();
			wp_send_json_success( array( 'status' => 'done', 'type' => $p['site_type'] ) );
		}
		wp_send_json_error( 'bad step' );
	}

	public static function ajax_test_ai() {
		check_ajax_referer( 'sva_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$r = SVA_AI::chat( 'You are a test.', array( array( 'role' => 'user', 'content' => 'Reply with exactly: OK' ) ), 20 );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( $r->get_error_message() );
		}
		wp_send_json_success( 'مدل ' . SVA_AI::model() . ' پاسخ داد: ' . $r );
	}

	public static function clear_logs() {
		check_admin_referer( 'sva_clear_logs' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . SVA_DB::t( 'logs' ) ); // phpcs:ignore
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=logs' ) );
		exit;
	}

	/* ---------------------------------------------------------------- */

	public static function page() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'analysis'; // phpcs:ignore
		$tabs = array( 'analysis' => 'تحلیل سایت', 'settings' => 'تنظیمات', 'knowledge' => 'دانش و سؤالات متداول', 'logs' => 'گفتگوها' );
		echo '<div class="wrap sva-wrap"><h1>دستیار هوشمند بازدیدکننده</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $k ) ), $tab === $k ? 'nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		settings_errors();
		if ( 'settings' === $tab ) {
			self::tab_settings();
		} elseif ( 'knowledge' === $tab ) {
			self::tab_knowledge();
		} elseif ( 'logs' === $tab ) {
			self::tab_logs();
		} else {
			self::tab_analysis();
		}
		echo '</div>';
	}

	private static function opt_name( $k ) {
		return esc_attr( SVA_Plugin::OPT . '[' . $k . ']' );
	}

	private static function tab_analysis() {
		$p     = SVA_Plugin::profile();
		$raw   = get_option( SVA_Plugin::PROFILE, array() );
		$s     = SVA_Plugin::settings();
		$st    = SVA_Analyzer::state();
		$types = SVA_I18n::types();
		$c     = isset( $p['counts'] ) ? $p['counts'] : array();
		?>
		<div class="sva-card">
			<h2>تحلیل خودکار سایت</h2>
			<p>دستیار، برگه‌ها، نوشته‌ها، محصولات، منوها و صفحه اصلی سایت را بررسی می‌کند تا نوع سایت، بخش‌های مهم (مثل نوبت‌دهی، تماس، سبد خرید) و اطلاعات تماس را پیدا کند. تغییرات محتوا بعد از هر ذخیره خودکار به‌روز می‌شوند و هر هفته هم تحلیل کامل تکرار می‌شود.</p>
			<?php if ( ! empty( $p['scanned_at'] ) ) : ?>
				<p class="sva-muted">آخرین تحلیل: <?php echo esc_html( wp_date( 'Y/m/d H:i', (int) $p['scanned_at'] ) ); ?></p>
			<?php elseif ( ! empty( $st['status'] ) && 'running' === $st['status'] ) : ?>
				<p class="sva-muted">تحلیل در پس‌زمینه در حال انجام است (<?php echo (int) $st['pos']; ?> از <?php echo (int) $st['total']; ?>).</p>
			<?php else : ?>
				<p class="sva-warn">سایت هنوز تحلیل نشده است.</p>
			<?php endif; ?>
			<p><button type="button" class="button button-primary button-hero" id="sva-scan">🔍 <?php echo empty( $p['scanned_at'] ) ? 'شروع تحلیل سایت' : 'تحلیل دوباره'; ?></button></p>
			<div id="sva-progress" class="sva-progress" hidden><div class="sva-bar"><span></span></div><p class="sva-progress-text"></p></div>
		</div>

		<?php if ( ! empty( $p['scanned_at'] ) ) : ?>
		<div class="sva-grid">
			<div class="sva-card sva-stat"><span>نوع سایت</span><strong><?php echo esc_html( $types[ $p['site_type'] ] ?? $p['site_type'] ); ?></strong></div>
			<div class="sva-card sva-stat"><span>برگه‌ها</span><strong><?php echo (int) ( $c['page'] ?? 0 ); ?></strong></div>
			<div class="sva-card sva-stat"><span>نوشته‌ها</span><strong><?php echo (int) ( $c['post'] ?? 0 ); ?></strong></div>
			<div class="sva-card sva-stat"><span>محصولات</span><strong><?php echo (int) ( $c['product'] ?? 0 ); ?></strong></div>
			<div class="sva-card sva-stat"><span>بخش‌های دانش</span><strong><?php echo (int) ( $c['chunks'] ?? 0 ); ?></strong></div>
		</div>
		<?php if ( ! empty( $p['ai']['summary'] ) ) : ?>
			<div class="sva-card"><h3>خلاصه سایت (هوش مصنوعی)</h3><p><?php echo esc_html( $p['ai']['summary'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! empty( $raw['ai_error'] ) ) : ?>
			<div class="notice notice-warning inline"><p>تحلیل هوش مصنوعی انجام نشد: <?php echo esc_html( $raw['ai_error'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( empty( $raw['home_ok'] ) ) : ?>
			<div class="notice notice-warning inline"><p>صفحه اصلی سایت از داخل سرور قابل دریافت نبود (loopback). اطلاعات فوتر/تماس ممکن است ناقص باشد؛ آن‌ها را پایین همین صفحه وارد کنید.</p></div>
		<?php endif; ?>

		<form method="post" action="options.php" class="sva-card">
			<?php settings_fields( 'sva_settings_group' ); ?>
			<input type="hidden" name="<?php echo self::opt_name( '_form' ); ?>" value="profile">
			<h2>نتیجه تحلیل (قابل اصلاح)</h2>
			<p class="sva-muted">اگر چیزی اشتباه تشخیص داده شده، اینجا اصلاح کنید. برای حذف یک بخش، در فیلد آدرس فقط <code>-</code> بنویسید.</p>
			<table class="form-table">
				<tr><th>نوع سایت</th><td>
					<select name="<?php echo self::opt_name( 'site_type_override' ); ?>">
						<option value="">خودکار (<?php echo esc_html( $types[ $raw['site_type'] ?? 'general' ] ?? '' ); ?>)</option>
						<?php foreach ( $types as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['site_type_override'], $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
			</table>
			<h3>بخش‌های کلیدی سایت</h3>
			<table class="widefat striped sva-keys">
				<thead><tr><th>بخش</th><th>تشخیص خودکار</th><th>آدرس دستی (اختیاری)</th></tr></thead>
				<tbody>
				<?php
				foreach ( SVA_I18n::roles() as $role => $labels ) :
					$d = $raw['key_pages'][ $role ] ?? null;
					?>
					<tr>
						<td><strong><?php echo esc_html( $labels['fa'] ); ?></strong></td>
						<td><?php echo $d ? '<a href="' . esc_url( $d['url'] ) . '" target="_blank">' . esc_html( $d['title'] ) . '</a> <span class="sva-muted">(' . esc_html( $d['source'] ) . ')</span>' : '<span class="sva-muted">—</span>'; ?></td>
						<td><input type="text" class="regular-text" dir="ltr" name="<?php echo self::opt_name( 'key_overrides' ); ?>[<?php echo esc_attr( $role ); ?>]" value="<?php echo esc_attr( $s['key_overrides'][ $role ] ?? '' ); ?>" placeholder="https://..."></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3>اطلاعات تماس</h3>
			<?php $dc = $raw['contact'] ?? array(); ?>
			<table class="form-table">
				<tr><th>تلفن</th><td><input type="text" class="regular-text" name="<?php echo self::opt_name( 'contact_phone' ); ?>" value="<?php echo esc_attr( $s['contact_phone'] ); ?>" placeholder="<?php echo esc_attr( implode( ' , ', $dc['phones'] ?? array() ) ); ?>"><p class="description">تشخیص خودکار: <?php echo esc_html( implode( ' ، ', $dc['phones'] ?? array() ) ?: '—' ); ?></p></td></tr>
				<tr><th>ایمیل</th><td><input type="text" class="regular-text" dir="ltr" name="<?php echo self::opt_name( 'contact_email' ); ?>" value="<?php echo esc_attr( $s['contact_email'] ); ?>"><p class="description">تشخیص خودکار: <?php echo esc_html( implode( ' ، ', $dc['emails'] ?? array() ) ?: '—' ); ?></p></td></tr>
				<tr><th>آدرس</th><td><input type="text" class="large-text" name="<?php echo self::opt_name( 'contact_address' ); ?>" value="<?php echo esc_attr( $s['contact_address'] ); ?>"><p class="description">تشخیص خودکار: <?php echo esc_html( ( $dc['address'] ?? '' ) ?: '—' ); ?></p></td></tr>
				<tr><th>ساعات کاری</th><td><input type="text" class="large-text" name="<?php echo self::opt_name( 'contact_hours' ); ?>" value="<?php echo esc_attr( $s['contact_hours'] ); ?>"><p class="description">تشخیص خودکار: <?php echo esc_html( ( $dc['hours'] ?? '' ) ?: '—' ); ?></p></td></tr>
			</table>
			<?php if ( ! empty( $p['services'] ) ) : ?>
				<h3>خدمات/بخش‌های شناسایی‌شده</h3>
				<p><?php foreach ( $p['services'] as $sv ) { echo '<a class="sva-tag" href="' . esc_url( $sv['url'] ) . '" target="_blank">' . esc_html( $sv['title'] ) . '</a> '; } ?></p>
			<?php endif; ?>
			<?php submit_button( 'ذخیره اصلاحات' ); ?>
		</form>
		<?php endif; ?>

		<div class="sva-card">
			<h2>آزمایش دستیار</h2>
			<p class="sva-muted">یک سؤال مثل بازدیدکننده بپرسید (مثلاً «چطور نوبت بگیرم؟» یا «قیمت گوشی سامسونگ»).</p>
			<div class="sva-test"><input type="text" id="sva-test-q" class="regular-text" placeholder="سؤال…"> <button type="button" class="button" id="sva-test-btn">بپرس</button></div>
			<div id="sva-test-out" class="sva-test-out"></div>
		</div>
		<?php
	}

	private static function tab_settings() {
		$s = SVA_Plugin::settings();
		?>
		<form method="post" action="options.php" class="sva-card">
			<?php settings_fields( 'sva_settings_group' ); ?>
			<input type="hidden" name="<?php echo self::opt_name( '_form' ); ?>" value="settings">
			<h2>نمایش</h2>
			<table class="form-table">
				<tr><th>فعال</th><td><label><input type="checkbox" name="<?php echo self::opt_name( 'enabled' ); ?>" value="1" <?php checked( $s['enabled'] ); ?>> نمایش دستیار در سایت</label></td></tr>
				<tr><th>نام دستیار</th><td><input type="text" class="regular-text" name="<?php echo self::opt_name( 'assistant_name' ); ?>" value="<?php echo esc_attr( $s['assistant_name'] ); ?>" placeholder="دستیار هوشمند"></td></tr>
				<tr><th>پیام خوش‌آمد</th><td><textarea class="large-text" rows="3" name="<?php echo self::opt_name( 'welcome' ); ?>" placeholder="خالی = پیام خودکار بر اساس نوع سایت"><?php echo esc_textarea( $s['welcome'] ); ?></textarea></td></tr>
				<tr><th>رنگ</th><td><input type="color" name="<?php echo self::opt_name( 'color' ); ?>" value="<?php echo esc_attr( $s['color'] ); ?>"></td></tr>
				<tr><th>نمایش قیمت محصولات در چت</th><td>
					<select name="<?php echo self::opt_name( 'show_prices' ); ?>">
						<option value="auto" <?php selected( $s['show_prices'], 'auto' ); ?>>خودکار — مثل صفحه محصول</option>
						<option value="no" <?php selected( $s['show_prices'], 'no' ); ?>>نمایش داده نشود (استعلام از فروش)</option>
						<option value="yes" <?php selected( $s['show_prices'], 'yes' ); ?>>نمایش داده شود</option>
					</select>
					<?php $pv = get_option( SVA_Plugin::PROFILE, array() )['prices_visible'] ?? null; ?>
					<p class="description">آخرین تحلیل: <?php echo null === $pv ? 'قابل تشخیص نبود' : ( $pv ? 'قیمت در صفحه محصول دیده می‌شود' : 'قیمت در صفحه محصول مخفی است' ); ?>. اگر قیمت را با CSS مخفی کرده‌اید، تشخیص خودکار ممکن نیست؛ «نمایش داده نشود» را انتخاب کنید. وضعیت فعلی: <strong><?php echo SVA_Plugin::show_prices() ? 'قیمت نمایش داده می‌شود' : 'قیمت مخفی است'; ?></strong></p>
				</td></tr>
				<tr><th>موقعیت</th><td><select name="<?php echo self::opt_name( 'position' ); ?>"><option value="right" <?php selected( $s['position'], 'right' ); ?>>پایین راست</option><option value="left" <?php selected( $s['position'], 'left' ); ?>>پایین چپ</option></select></td></tr>
			</table>

			<h2>موتور پاسخ‌گویی</h2>
			<table class="form-table">
				<tr><th>حالت</th><td>
					<label><input type="radio" name="<?php echo self::opt_name( 'mode' ); ?>" value="local" <?php checked( $s['mode'], 'local' ); ?>> <strong>محلی (رایگان)</strong> — تشخیص نیت + جستجو در محتوای سایت، بدون نیاز به API</label><br>
					<label><input type="radio" name="<?php echo self::opt_name( 'mode' ); ?>" value="ai" <?php checked( $s['mode'], 'ai' ); ?>> <strong>هوش مصنوعی</strong> — پاسخ‌های طبیعی‌تر بر اساس محتوای سایت (در صورت خطا به حالت محلی برمی‌گردد)</label>
				</td></tr>
				<tr><th>سرویس‌دهنده</th><td><select name="<?php echo self::opt_name( 'provider' ); ?>" id="sva-provider"><option value="anthropic" <?php selected( $s['provider'], 'anthropic' ); ?>>Anthropic Claude</option><option value="openai" <?php selected( $s['provider'], 'openai' ); ?>>سازگار با OpenAI (OpenAI، OpenRouter، Ollama و …)</option></select></td></tr>
				<tr><th>کلید API</th><td>
					<input type="password" class="regular-text" dir="ltr" autocomplete="off" name="<?php echo self::opt_name( 'api_key' ); ?>" value="" placeholder="<?php echo SVA_AI::api_key() ? '•••••••• (ذخیره شده)' : 'sk-…'; ?>">
					<?php if ( $s['api_key'] ) : ?><label><input type="checkbox" name="<?php echo self::opt_name( 'api_key_clear' ); ?>" value="1"> حذف کلید</label><?php endif; ?>
					<p class="description">کلید فقط روی سرور استفاده می‌شود. می‌توانید به‌جای اینجا، در wp-config.php بنویسید: <code dir="ltr">define('SVA_API_KEY','...');</code></p>
				</td></tr>
				<tr class="sva-openai-only"><th>آدرس API</th><td><input type="url" class="regular-text" dir="ltr" name="<?php echo self::opt_name( 'api_base' ); ?>" value="<?php echo esc_attr( $s['api_base'] ); ?>"><p class="description">مثال: https://api.openai.com/v1 — https://openrouter.ai/api/v1 — http://localhost:11434/v1</p></td></tr>
				<tr><th>مدل</th><td><input type="text" class="regular-text" dir="ltr" name="<?php echo self::opt_name( 'model' ); ?>" value="<?php echo esc_attr( $s['model'] ); ?>" placeholder="<?php echo esc_attr( SVA_AI::model() ); ?>"><p class="description">خالی = پیش‌فرض. نام دقیق مدل را از پنل سرویس‌دهنده بررسی کنید.</p></td></tr>
				<tr><th>حداکثر طول پاسخ (توکن)</th><td><input type="number" min="100" max="4000" name="<?php echo self::opt_name( 'max_tokens' ); ?>" value="<?php echo (int) $s['max_tokens']; ?>"></td></tr>
				<tr><th>دستورالعمل اختصاصی</th><td><textarea class="large-text" rows="3" name="<?php echo self::opt_name( 'ai_instructions' ); ?>" placeholder="مثلاً: همیشه مؤدب و رسمی صحبت کن. از قیمت دقیق حرف نزن و به تماس ارجاع بده."><?php echo esc_textarea( $s['ai_instructions'] ); ?></textarea></td></tr>
				<tr><th></th><td><button type="button" class="button" id="sva-test-ai">تست اتصال هوش مصنوعی</button> <span id="sva-test-ai-out"></span><p class="description">ابتدا تنظیمات را ذخیره کنید.</p></td></tr>
			</table>

			<h2>امنیت و حریم خصوصی</h2>
			<table class="form-table">
				<tr><th>محدودیت پیام</th><td><input type="number" min="1" max="100" name="<?php echo self::opt_name( 'rate_limit' ); ?>" value="<?php echo (int) $s['rate_limit']; ?>"> پیام در دقیقه برای هر بازدیدکننده</td></tr>
				<tr><th>ذخیره گفتگوها</th><td><label><input type="checkbox" name="<?php echo self::opt_name( 'logging' ); ?>" value="1" <?php checked( $s['logging'] ); ?>> سؤالات بازدیدکنندگان (بدون IP) ذخیره شود تا ببینید مردم چه می‌پرسند</label><p class="description">گفتگوها بعد از ۹۰ روز خودکار پاک می‌شوند. اگر سایت شما مشمول GDPR است، این مورد را در سیاست حریم خصوصی ذکر کنید. در حالت هوش مصنوعی، متن سؤال برای سرویس‌دهنده انتخابی ارسال می‌شود.</p></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function tab_knowledge() {
		$s = SVA_Plugin::settings();
		?>
		<form method="post" action="options.php" class="sva-card">
			<?php settings_fields( 'sva_settings_group' ); ?>
			<input type="hidden" name="<?php echo self::opt_name( '_form' ); ?>" value="knowledge">
			<h2>دانش اضافه</h2>
			<p class="sva-muted">هر اطلاعاتی که در سایت نیست ولی می‌خواهید دستیار بداند (ساعات کاری دقیق، بیمه‌های طرف قرارداد، شرایط ارسال، …).</p>
			<textarea class="large-text" rows="8" name="<?php echo self::opt_name( 'custom_knowledge' ); ?>"><?php echo esc_textarea( $s['custom_knowledge'] ); ?></textarea>

			<h2>سؤالات متداول (پاسخ قطعی)</h2>
			<p class="sva-muted">هر خط یک سؤال و جواب، جدا شده با <code>|</code>. اگر سؤال بازدیدکننده شبیه یکی از این‌ها باشد، دقیقاً همین جواب داده می‌شود.</p>
			<textarea class="large-text code" rows="8" name="<?php echo self::opt_name( 'faq' ); ?>" placeholder="آیا بیمه تکمیلی قبول می‌کنید؟ | بله، با بیمه‌های دی، آسیا و ایران قرارداد داریم.&#10;هزینه ارسال چقدر است؟ | برای خریدهای بالای ۵۰۰ هزار تومان ارسال رایگان است."><?php echo esc_textarea( $s['faq'] ); ?></textarea>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function tab_logs() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . SVA_DB::t( 'logs' ) . ' ORDER BY id DESC LIMIT 200' ); // phpcs:ignore
		$top  = $wpdb->get_results( 'SELECT intent, COUNT(*) AS n FROM ' . SVA_DB::t( 'logs' ) . ' GROUP BY intent ORDER BY n DESC LIMIT 10' ); // phpcs:ignore
		?>
		<div class="sva-card">
			<h2>آخرین سؤالات بازدیدکنندگان</h2>
			<?php if ( $top ) : ?>
				<p><?php foreach ( $top as $t ) { echo '<span class="sva-tag">' . esc_html( $t->intent ? $t->intent : '—' ) . ': ' . (int) $t->n . '</span> '; } ?></p>
				<p class="sva-muted">سؤال‌هایی با نیت <code>none</code> یعنی دستیار جوابی پیدا نکرده؛ پاسخ آن‌ها را در تب «دانش و سؤالات متداول» اضافه کنید.</p>
			<?php endif; ?>
			<table class="widefat striped">
				<thead><tr><th style="width:130px">زمان</th><th>سؤال</th><th>پاسخ</th><th style="width:90px">نیت</th><th style="width:60px">حالت</th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5">هنوز گفتگویی ثبت نشده است.</td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $r->created . ' UTC' ) ) ); ?></td>
						<td><?php echo esc_html( $r->question ); ?></td>
						<td class="sva-muted"><?php echo esc_html( mb_substr( $r->answer, 0, 220 ) ); ?></td>
						<td><code><?php echo esc_html( $r->intent ); ?></code></td>
						<td><?php echo esc_html( $r->mode ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="sva_clear_logs">
				<?php wp_nonce_field( 'sva_clear_logs' ); ?>
				<button class="button" onclick="return confirm('همه گفتگوها پاک شوند؟');">پاک کردن همه</button>
			</form>
		</div>
		<?php
	}
}
