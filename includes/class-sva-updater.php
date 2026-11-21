<?php
/**
 * Self-update from GitHub Releases.
 *
 * Every version is published as a GitHub Release (tag vX.Y.Z) with the installable
 * smart-visitor-assistant.zip attached (built automatically by .github/workflows/release.yml).
 * WordPress shows the normal "update available" notice, one-click update, auto-updates,
 * and the release notes as changelog.
 *
 * The repository is public, so no token is needed. If it is ever made private again, set
 * define( 'SVA_GITHUB_TOKEN', 'github_pat_…' ) in wp-config.php or enter it on the "به‌روزرسانی" page.
 */
defined( 'ABSPATH' ) || exit;

class SVA_Updater {

	private $file;
	private $basename;
	private $slug;
	private $repo;
	private $version;
	private $parent;
	private $page;
	private $opt;
	private $cache;

	/**
	 * @param string $file    Main plugin file.
	 * @param string $repo    "owner/repo".
	 * @param string $version Installed version.
	 * @param string $parent  Admin menu slug to attach the "Updates" page to.
	 */
	public function __construct( $file, $repo, $version, $parent ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
		$this->repo     = $repo;
		$this->version  = $version;
		$this->parent   = $parent;
		$this->page     = $this->slug . '-updates';
		$this->opt      = str_replace( '-', '_', $this->slug ) . '_github_token';
		$this->cache    = str_replace( '-', '_', $this->slug ) . '_gh_release';

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'http_request_args', array( $this, 'auth_download' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'action_link' ) );
		add_action( 'admin_menu', array( $this, 'menu' ), 99 );
		add_action( 'admin_post_' . $this->page, array( $this, 'handle_form' ) );
		add_action( 'load-update-core.php', array( $this, 'maybe_force' ) );
	}

	/* ---------------- GitHub ---------------- */

	public function token() {
		if ( defined( 'SVA_GITHUB_TOKEN' ) && SVA_GITHUB_TOKEN ) {
			return (string) SVA_GITHUB_TOKEN;
		}
		return (string) get_option( $this->opt, '' );
	}

	private function api( $path, $raw = false ) {
		$headers = array(
			'Accept'               => $raw ? 'application/vnd.github.raw' : 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . $this->slug,
		);
		if ( $this->token() ) {
			$headers['Authorization'] = 'Bearer ' . $this->token();
		}
		$url = ( '/../../user' === $path ) ? 'https://api.github.com/user' : 'https://api.github.com/repos/' . $this->repo . $path;
		$res = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );
		if ( 200 !== $code ) {
			$msg = isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $code;
			if ( 404 === $code ) {
				$msg = 'not_found';
			} elseif ( 401 === $code ) {
				$msg = 'توکن گیت‌هاب نامعتبر یا منقضی است.';
			} elseif ( 403 === $code ) {
				$msg = 'محدودیت درخواست گیت‌هاب؛ کمی بعد دوباره امتحان کنید (با توکن این محدودیت بسیار بیشتر است).';
			}
			return new WP_Error( 'gh_http', $msg );
		}
		return $raw ? $body : $data;
	}

	/**
	 * Latest GitHub Release. Cached for 6 hours (errors for 30 minutes).
	 *
	 * @return array|WP_Error { version, zip, notes, url }
	 */
	public function latest( $force = false ) {
		$c = get_site_transient( $this->cache );
		if ( ! $force && is_array( $c ) ) {
			return isset( $c['error'] ) ? new WP_Error( 'gh_cached', $c['error'] ) : $c;
		}
		$rel = $this->api( '/releases/latest' );
		if ( is_wp_error( $rel ) || empty( $rel['tag_name'] ) || ! preg_match( '/^v?(\d+(?:\.\d+){0,3})/', $rel['tag_name'], $m ) ) {
			$err = is_wp_error( $rel ) ? $this->diagnose( $rel ) : 'آخرین ریلیز شماره نسخه معتبری ندارد.';
			set_site_transient( $this->cache, array( 'error' => $err ), 30 * MINUTE_IN_SECONDS );
			return new WP_Error( 'gh_none', $err );
		}
		$zip = '';
		foreach ( (array) ( isset( $rel['assets'] ) ? $rel['assets'] : array() ) as $as ) {
			if ( isset( $as['name'] ) && preg_match( '/\.zip$/i', $as['name'] ) ) {
				// Private repo: download through the API with the token; public: direct link.
				$zip = $this->token() ? $as['url'] : $as['browser_download_url'];
				if ( $this->slug . '.zip' === $as['name'] ) {
					break;
				}
			}
		}
		if ( '' === $zip ) {
			$zip = 'https://api.github.com/repos/' . $this->repo . '/zipball/' . rawurlencode( $rel['tag_name'] );
		}
		$notes = array();
		foreach ( preg_split( '/\r?\n/', (string) ( isset( $rel['body'] ) ? $rel['body'] : '' ) ) as $line ) {
			$line = trim( preg_replace( '/^\s*[-*•]\s*/u', '', $line ) );
			if ( '' !== $line && ! preg_match( '/^(#|\*\*Full Changelog|Full Changelog)/i', $line ) ) {
				$notes[] = wp_strip_all_tags( $line );
			}
		}
		$best = array(
			'version' => $m[1],
			'zip'     => $zip,
			'notes'   => array_slice( $notes, 0, 30 ),
			'url'     => isset( $rel['html_url'] ) ? $rel['html_url'] : 'https://github.com/' . $this->repo . '/releases',
		);
		set_site_transient( $this->cache, $best, 6 * HOUR_IN_SECONDS );
		return $best;
	}

	/** Turn a failed request into a precise, actionable message. */
	private function diagnose( $error ) {
		$msg = $error->get_error_message();
		if ( 'not_found' !== $msg ) {
			return $msg;
		}
		$repo = $this->api( '' );
		if ( ! is_wp_error( $repo ) ) {
			return 'هنوز هیچ ریلیزی در مخزن منتشر نشده است.';
		}
		if ( ! $this->token() ) {
			return 'مخزن پیدا نشد یا خصوصی است. اگر مخزن خصوصی است، توکن گیت‌هاب را وارد کنید.';
		}
		$tok = $this->token();
		if ( preg_match( '/\s/', $tok ) || strlen( $tok ) < 30 ) {
			return 'توکن ذخیره‌شده ناقص است یا فاصله دارد؛ آن را دوباره کامل کپی و ذخیره کنید.';
		}
		$user = $this->api( '/../../user' );
		if ( is_wp_error( $user ) && false !== strpos( $user->get_error_message(), 'نامعتبر' ) ) {
			return 'توکن نامعتبر یا منقضی است. یک توکن جدید بسازید.';
		}
		return 'توکن به مخزن ' . $this->repo . ' دسترسی ندارد. در تنظیمات توکن، این مخزن را در Repository access انتخاب کنید و Contents را روی Read-only بگذارید.';
	}

	/* ---------------- WordPress update hooks ---------------- */

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$r = $this->latest();
		if ( is_wp_error( $r ) ) {
			return $transient;
		}
		$item = (object) array(
			'id'            => 'github.com/' . $this->repo,
			'slug'          => $this->slug,
			'plugin'        => $this->basename,
			'new_version'   => $r['version'],
			'url'           => 'https://github.com/' . $this->repo,
			'package'       => $r['zip'],
			'tested'        => get_bloginfo( 'version' ),
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);
		if ( version_compare( $r['version'], $this->version, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
		} else {
			unset( $transient->response[ $this->basename ] );
			$transient->no_update[ $this->basename ] = $item;
		}
		return $transient;
	}

	public function plugin_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $res;
		}
		$r = $this->latest();
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$d     = get_plugin_data( $this->file, false, false );
		$notes = ( ! is_wp_error( $r ) && $r['notes'] ) ? '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $r['notes'] ) ) . '</li></ul>' : '<p>—</p>';
		return (object) array(
			'name'          => $d['Name'],
			'slug'          => $this->slug,
			'version'       => is_wp_error( $r ) ? $this->version : $r['version'],
			'author'        => $d['Author'],
			'homepage'      => 'https://github.com/' . $this->repo,
			'requires'      => $d['RequiresWP'],
			'requires_php'  => $d['RequiresPHP'],
			'tested'        => get_bloginfo( 'version' ),
			'download_link' => is_wp_error( $r ) ? '' : $r['zip'],
			'sections'      => array(
				'description' => wpautop( esc_html( $d['Description'] ) ),
				'changelog'   => $notes,
			),
		);
	}

	/** Send the token with the zip download (GitHub API → codeload redirect). */
	public function auth_download( $args, $url ) {
		$base = 'https://api.github.com/repos/' . $this->repo . '/';
		if ( $this->token() && ( 0 === strpos( $url, $base . 'zipball/' ) || 0 === strpos( $url, $base . 'releases/assets/' ) ) ) {
			$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . $this->token();
			$args['headers']['Accept']        = ( false !== strpos( $url, '/releases/assets/' ) ) ? 'application/octet-stream' : 'application/vnd.github+json';
		}
		return $args;
	}

	/** GitHub zips extract to "owner-repo-sha/"; rename to the plugin folder. */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		$ours = ( isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->basename )
			|| false !== stripos( basename( $source ), str_replace( '/', '-', $this->repo ) );
		if ( ! $ours || ! $wp_filesystem ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $target ) ) {
			return $source;
		}
		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}
		return $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) )
			? $target
			: new WP_Error( 'gh_rename', 'تغییر نام پوشه افزونه پس از دانلود ممکن نشد.' );
	}

	public function purge( $upgrader = null, $options = array() ) {
		delete_site_transient( $this->cache );
	}

	/** "Check again" on Dashboard → Updates also refreshes our cache. */
	public function maybe_force() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->purge();
		}
	}

	/* ---------------- Admin page ---------------- */

	public function action_link( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . $this->page ) ) . '">به‌روزرسانی</a>';
		return $links;
	}

	public function menu() {
		add_submenu_page( $this->parent, 'به‌روزرسانی افزونه', 'به‌روزرسانی', 'update_plugins', $this->page, array( $this, 'render' ) );
	}

	public function handle_form() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( $this->page );
		$msg = 'checked';
		if ( isset( $_POST['save_token'] ) && ! defined( 'SVA_GITHUB_TOKEN' ) ) {
			$tok = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ), " \t\n\r\0\x0B'\"" ) : '';
			if ( ! empty( $_POST['clear_token'] ) ) {
				delete_option( $this->opt );
			} elseif ( '' !== $tok ) {
				update_option( $this->opt, $tok, false );
			}
			$msg = 'saved';
		}
		$this->purge();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->page . '&msg=' . $msg ) );
		exit;
	}

	public function render() {
		$r       = $this->latest();
		$has_new = ! is_wp_error( $r ) && version_compare( $r['version'], $this->version, '>' );
		$const   = defined( 'SVA_GITHUB_TOKEN' );
		$msg     = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$auto    = in_array( $this->basename, (array) get_site_option( 'auto_update_plugins', array() ), true );
		?>
		<div class="wrap" dir="rtl">
			<h1>به‌روزرسانی افزونه</h1>
			<?php if ( 'saved' === $msg ) : ?><div class="notice notice-success is-dismissible"><p>ذخیره شد و دوباره بررسی شد.</p></div><?php endif; ?>
			<?php if ( 'checked' === $msg ) : ?><div class="notice notice-info is-dismissible"><p>دوباره بررسی شد.</p></div><?php endif; ?>
			<table class="form-table" role="presentation">
				<tr><th>نسخه نصب‌شده</th><td><code><?php echo esc_html( $this->version ); ?></code></td></tr>
				<tr><th>آخرین نسخه در گیت‌هاب</th><td>
					<?php if ( is_wp_error( $r ) ) : ?>
						<span style="color:#b32d2e">بررسی ممکن نشد: <?php echo esc_html( $r->get_error_message() ); ?></span>
					<?php else : ?>
						<code><?php echo esc_html( $r['version'] ); ?></code>
						<?php echo $has_new ? ' <strong style="color:#00701a">— نسخه جدید موجود است</strong>' : ' — افزونه به‌روز است ✔'; ?>
					<?php endif; ?>
				</td></tr>
				<tr><th>مخزن</th><td><a href="<?php echo esc_url( 'https://github.com/' . $this->repo ); ?>" target="_blank" dir="ltr"><?php echo esc_html( $this->repo ); ?></a> — <a href="<?php echo esc_url( 'https://github.com/' . $this->repo . '/releases' ); ?>" target="_blank">همه نسخه‌ها (Releases)</a></td></tr>
				<tr><th>به‌روزرسانی خودکار</th><td><?php echo $auto ? 'فعال است' : 'غیرفعال'; ?> <span class="description">(از صفحه افزونه‌ها، ستون «به‌روزرسانی‌های خودکار» قابل تغییر است)</span></td></tr>
			</table>

			<?php if ( $has_new ) : ?>
				<?php if ( $r['notes'] ) : ?>
					<h2>تغییرات نسخه <?php echo esc_html( $r['version'] ); ?></h2>
					<ul style="list-style:disc;padding-right:20px"><?php foreach ( $r['notes'] as $n ) { echo '<li>' . esc_html( $n ) . '</li>'; } ?></ul>
				<?php endif; ?>
				<p><a class="button button-primary button-hero" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $this->basename ) ), 'upgrade-plugin_' . $this->basename ) ); ?>">به‌روزرسانی به نسخه <?php echo esc_html( $r['version'] ); ?></a></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $this->page ); ?>">
				<?php wp_nonce_field( $this->page ); ?>
				<p><button class="button" name="check" value="1">بررسی دوباره</button></p>
				<details<?php echo $this->token() ? ' open' : ''; ?>>
				<summary><strong>توکن گیت‌هاب (اختیاری — فقط اگر مخزن خصوصی باشد)</strong></summary>
				<p class="description">مخزن عمومی است و برای به‌روزرسانی به توکن نیازی نیست. اگر روزی مخزن خصوصی شد، یک توکن «فقط‌خواندنی» بسازید: در گیت‌هاب Settings ← Developer settings ← Fine-grained tokens ← Generate new token؛ در Repository access فقط <code dir="ltr"><?php echo esc_html( $this->repo ); ?></code> را انتخاب کنید و در Permissions فقط <code dir="ltr">Contents: Read-only</code> را بدهید.</p>
				<?php if ( $const ) : ?>
					<p>✔ توکن در <code>wp-config.php</code> تعریف شده است (<code dir="ltr">SVA_GITHUB_TOKEN</code>).</p>
				<?php else : ?>
					<p>
						<input type="password" name="token" class="regular-text" dir="ltr" autocomplete="off" placeholder="<?php echo $this->token() ? '•••••••• (ذخیره شده)' : 'github_pat_…'; ?>">
						<?php if ( $this->token() ) : ?><label><input type="checkbox" name="clear_token" value="1"> حذف توکن</label><?php endif; ?>
					</p>
					<p class="description">امن‌تر: به‌جای این‌جا در wp-config.php بنویسید <code dir="ltr">define( 'SVA_GITHUB_TOKEN', 'github_pat_…' );</code></p>
					<p><button class="button button-primary" name="save_token" value="1">ذخیره توکن و بررسی</button></p>
				<?php endif; ?>
				</details>
			</form>
		</div>
		<?php
	}
}
