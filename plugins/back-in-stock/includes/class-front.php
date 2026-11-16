<?php
/**
 * BIS_Front — the visitor side.
 *
 *  - form()        «Ειδοποίησέ με όταν ξαναέρθει» on the product page,
 *                  after the add-to-cart area (woocommerce_single_product_summary
 *                  31, or after the woocommerce/add-to-cart-form block in
 *                  block themes), once per product. Simple products: only
 *                  when the stock status is "outofstock". Variable products:
 *                  a list of the out-of-stock variations; with JavaScript
 *                  the box shows only when the chosen variation is one of
 *                  them. The same HTML for every guest (page caches work);
 *                  a logged-in customer gets the address filled in and a
 *                  nonce.
 *  - handle_post() wp_loaded: the request. Honeypot, a time check (JS),
 *                  nonce for logged-in visitors, rate limit per IP (hash),
 *                  then PRG back to the product with a result code.
 *  - pages()       template_redirect: ?bis=c (confirm) and ?bis=u
 *                  (unsubscribe) from the e-mails open a small page with a
 *                  POST button; mail scanners open links first, so a GET
 *                  changes nothing. ?bis=m shows the result.
 *
 * Nothing on the product page in mode "off", with BIS_DISABLE, when the
 * back-in-stock e-mail is off, or for guests when the confirm e-mail is
 * off. Test mode: only shop managers see the form and may send it.
 * Confirming needs a mode other than "off"; unsubscribing always works
 * (an address can always leave).
 */

defined( 'ABSPATH' ) || exit;

final class BIS_Front {

	/** @var int[] Products whose form was printed in this request. */
	private static $done = array();

	/** @var bool Set after an error: the plugin stays out of the rest of the request. */
	private static $broken = false;

	/** Result codes of the PRG redirect => text key. */
	const RESULTS = array(
		'm' => 'ok_member',
		'g' => 'ok_guest',
		'e' => 'bad_email',
		'n' => 'no_ok',
		'l' => 'limit',
		'x' => 'error',
	);

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'pages' ), 1 );
		if ( 'off' === BIS_Settings::mode() ) {
			return;
		}
		add_action( 'wp_loaded', array( __CLASS__, 'handle_post' ), 20 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'form_hook' ), 31 );
		add_filter( 'render_block', array( __CLASS__, 'form_block' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	private static function fail( \Throwable $e ): void {
		self::$broken = true;
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $e->getMessage(), array( 'source' => 'back-in-stock' ) );
		}
	}

	/* =====================================================================
	 * Who sees the form, on which product
	 * =================================================================== */

	/** The form may show to this visitor at all. */
	public static function visible(): bool {
		if ( self::$broken || ! BIS_Emails::enabled( BIS_Emails::RESTOCK ) ) {
			return false;
		}
		$mode = BIS_Settings::mode();
		if ( 'off' === $mode || ( 'test' === $mode && ! current_user_can( Back_In_Stock::cap() ) ) ) {
			return false;
		}
		return is_user_logged_in() || BIS_Emails::enabled( BIS_Emails::CONFIRM );
	}

	/** The product may get requests (published, not hidden, not a pre-order, not gone from Book Feed). */
	public static function applies( WC_Product $product ): bool {
		$ok = 'publish' === $product->get_status() && ! $product->is_type( array( 'external', 'grouped' ) );
		if ( $ok && function_exists( 'por_get_release' ) ) {
			$r  = por_get_release( $product );
			$ok = ! ( is_array( $r ) && ! empty( $r['active'] ) );
		}
		if ( $ok && function_exists( 'hic_hidden_terms' ) ) {
			$hidden = array_map( 'intval', (array) hic_hidden_terms( 'shop' ) );
			$ok     = ! ( $hidden && array_intersect( $hidden, array_map( 'intval', $product->get_category_ids() ) ) );
		}
		if ( $ok && BIS_Settings::get()['hide_gone'] && '1' === (string) get_post_meta( $product->get_id(), '_bkf_gone', true ) ) {
			$ok = false;
		}
		/**
		 * Whether a product gets the back-in-stock form.
		 *
		 * @param bool       $ok      Default decision.
		 * @param WC_Product $product The product.
		 */
		return (bool) apply_filters( 'bis_show_form', $ok, $product );
	}

	/**
	 * Out-of-stock variations of a variable product: id => label.
	 *
	 * @param WC_Product $product A variable product.
	 */
	public static function out_variations( WC_Product $product ): array {
		$out = array();
		if ( ! $product->is_type( 'variable' ) ) {
			return $out;
		}
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || 'publish' !== $v->get_status() || 'outofstock' !== $v->get_stock_status() ) {
				continue;
			}
			$label = wc_get_formatted_variation( $v, true, false, false );
			$out[ (int) $vid ] = '' !== $label ? wp_strip_all_tags( $label ) : '#' . (int) $vid;
		}
		return $out;
	}

	/** The pair a POST asks for is valid and out of stock. */
	private static function valid_pair( int $product_id, int $variation_id ): bool {
		$p = $product_id > 0 ? wc_get_product( $product_id ) : null;
		if ( ! $p || $p->is_type( 'variation' ) || ! self::applies( $p ) ) {
			return false;
		}
		if ( $p->is_type( 'variable' ) ) {
			return $variation_id > 0 && isset( self::out_variations( $p )[ $variation_id ] );
		}
		return 0 === $variation_id && 'outofstock' === $p->get_stock_status();
	}

	/* =====================================================================
	 * The form
	 * =================================================================== */

	public static function form_hook(): void {
		global $product;
		if ( $product instanceof WC_Product ) {
			echo self::form( $product ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in form().
		}
	}

	public static function form_block( $html, $block ) {
		if ( ! is_array( $block ) || 'woocommerce/add-to-cart-form' !== ( $block['blockName'] ?? '' ) || ! is_singular( 'product' ) ) {
			return $html;
		}
		$product = wc_get_product( get_queried_object_id() );
		return $product ? $html . self::form( $product ) : $html;
	}

	/** The form's HTML, or '' (Bible §14.28: once per product). */
	public static function form( WC_Product $product ): string {
		try {
			$pid = (int) $product->get_id();
			if ( in_array( $pid, self::$done, true ) || ! self::visible() || ! self::applies( $product ) ) {
				return '';
			}
			$vars = array();
			if ( $product->is_type( 'variable' ) ) {
				$vars = self::out_variations( $product );
				if ( ! $vars ) {
					return '';
				}
			} elseif ( 'outofstock' !== $product->get_stock_status() ) {
				return '';
			}
			self::$done[] = $pid;
			return self::markup( $product, $vars );
		} catch ( \Throwable $e ) {
			self::fail( $e );
			return '';
		}
	}

	private static function markup( WC_Product $product, array $vars ): string {
		$pid    = (int) $product->get_id();
		$user   = wp_get_current_user();
		$email  = $user && $user->ID ? (string) $user->user_email : '';
		$s      = BIS_Settings::get();
		$btn    = 'button bis-button' . ( function_exists( 'wc_wp_theme_get_element_class_name' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' );
		$policy = $s['privacy'] && function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result code.
		$res  = isset( $_GET['bis_r'] ) ? sanitize_key( wp_unslash( (string) $_GET['bis_r'] ) ) : '';
		$msg  = isset( self::RESULTS[ $res ] ) ? BIS_Settings::text( self::RESULTS[ $res ] ) : '';
		$good = in_array( $res, array( 'm', 'g' ), true );

		ob_start();
		?>
		<div class="bis-box" id="bis-form" data-bis-variations="<?php echo esc_attr( (string) wp_json_encode( array_map( 'intval', array_keys( $vars ) ) ) ); ?>"<?php echo $vars ? ' data-bis-variable="1"' : ''; ?>>
			<p class="bis-title"><?php echo esc_html( BIS_Settings::text( 'title' ) ); ?></p>
			<?php if ( '' !== $msg ) : ?>
				<p class="bis-msg <?php echo $good ? 'bis-msg--ok' : 'bis-msg--err'; ?>" role="status"><?php echo esc_html( $msg ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( get_permalink( $pid ) ); ?>#bis-form" class="bis-form">
				<p class="bis-hint"><?php echo esc_html( BIS_Settings::text( 'hint' ) ); ?></p>
				<?php if ( $vars ) : ?>
					<p class="bis-pick"><?php echo esc_html( BIS_Settings::text( 'pick' ) ); ?></p>
					<select name="bis_variation" class="bis-variation" aria-label="<?php echo esc_attr( BIS_Settings::text( 'title' ) ); ?>">
						<?php foreach ( $vars as $vid => $label ) : ?>
							<option value="<?php echo (int) $vid; ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="hidden" name="bis_variation" value="0" />
				<?php endif; ?>
				<label class="bis-label" for="bis-email-<?php echo (int) $pid; ?>"><?php echo esc_html( BIS_Settings::text( 'email' ) ); ?></label>
				<input type="email" id="bis-email-<?php echo (int) $pid; ?>" name="bis_email" class="bis-email input-text" value="<?php echo esc_attr( $email ); ?>" required="required" autocomplete="email" maxlength="190" />
				<label class="bis-consent"><input type="checkbox" name="bis_ok" value="1" required="required" /> <?php echo esc_html( BIS_Settings::text( 'consent' ) ); ?>
					<?php if ( '' !== $policy ) : ?>
						<a href="<?php echo esc_url( $policy ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Πολιτική απορρήτου', 'back-in-stock' ); ?></a>
					<?php endif; ?>
				</label>
				<span class="bis-hp" aria-hidden="true"><label><?php esc_html_e( 'Αφήστε το κενό', 'back-in-stock' ); ?> <input type="text" name="bis_website" value="" tabindex="-1" autocomplete="off" /></label></span>
				<input type="hidden" name="bis_product" value="<?php echo (int) $pid; ?>" />
				<input type="hidden" name="bis_e" value="" class="bis-elapsed" />
				<?php
				if ( is_user_logged_in() ) {
					wp_nonce_field( 'bis_request', 'bis_nonce', false );
				}
				?>
				<button type="submit" name="bis_request" value="1" class="<?php echo esc_attr( trim( $btn ) ); ?>"><?php echo esc_html( BIS_Settings::text( 'button' ) ); ?></button>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public static function assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! self::visible() ) {
			return;
		}
		wp_enqueue_style( 'bis-front', BIS_URL . 'assets/front.css', array(), BIS_VERSION );
		wp_enqueue_script( 'bis-front', BIS_URL . 'assets/front.js', array( 'jquery' ), BIS_VERSION, true );
	}

	/* =====================================================================
	 * The request (POST, PRG)
	 * =================================================================== */

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in handle_post() for logged-in visitors; guests: honeypot + rate limit + double opt-in.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	public static function handle_post(): void {
		if ( ! isset( $_POST['bis_request'] ) || self::$broken ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see post().
			return;
		}
		try {
			$pid  = absint( self::post( 'bis_product' ) );
			$vid  = absint( self::post( 'bis_variation' ) );
			$back = $pid > 0 ? (string) get_permalink( $pid ) : home_url( '/' );
			if ( '' === $back ) {
				$back = home_url( '/' );
			}
			self::redirect( $back, self::process( $pid, $vid ) );
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	/** Result code of a request (a key of RESULTS). */
	private static function process( int $pid, int $vid ): string {
		if ( ! self::visible() ) {
			return 'x';
		}
		// Bots: a filled honeypot or a form sent in under 1.5 s gets the
		// guest's success message and nothing is stored or sent.
		$elapsed = self::post( 'bis_e' );
		if ( '' !== trim( self::post( 'bis_website' ) ) || ( '' !== $elapsed && (int) $elapsed < 1500 ) ) {
			return 'g';
		}
		$logged = is_user_logged_in();
		if ( $logged && ! wp_verify_nonce( sanitize_key( self::post( 'bis_nonce' ) ), 'bis_request' ) ) {
			return 'x';
		}
		if ( ! self::valid_pair( $pid, $vid ) ) {
			return 'x';
		}
		if ( '1' !== self::post( 'bis_ok' ) ) {
			return 'n';
		}
		$email = BIS_Store::norm_email( self::post( 'bis_email' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			return 'e';
		}
		if ( BIS_Store::over_ip_limit() ) {
			return 'l';
		}
		$user    = wp_get_current_user();
		$own     = $logged && $user && strtolower( (string) $user->user_email ) === $email;
		$confirm = ! $own;
		if ( $confirm && ! BIS_Emails::enabled( BIS_Emails::CONFIRM ) ) {
			return 'x';
		}
		$r = BIS_Store::add( $email, $pid, $vid, $own ? (int) $user->ID : 0, BIS_Settings::visitor_lang(), $confirm );

		switch ( $r['result'] ) {
			case 'active':
				return 'm';
			case 'exists':
				// The same answer whether a request existed or not.
				return $confirm ? 'g' : 'm';
			case 'limit':
				return $confirm ? 'g' : 'l';
			case 'pending':
				$row = BIS_Store::get( (int) $r['id'] );
				if ( $row && BIS_Emails::send_confirm( $row, (string) $r['token'] ) ) {
					return 'g';
				}
				return 'x';
		}
		return 'x';
	}

	private static function redirect( string $url, string $code ): void {
		$url = add_query_arg( 'bis_r', $code, remove_query_arg( 'bis_r', $url ) ) . '#bis-form';
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/* =====================================================================
	 * Confirm / unsubscribe pages
	 * =================================================================== */

	private static function q( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; the action is a POST checked below.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
	}

	public static function pages(): void {
		$what = self::q( 'bis' );
		if ( ! in_array( $what, array( 'c', 'u', 'm' ), true ) ) {
			return;
		}
		try {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
			if ( 'c' === $what ) {
				self::page_confirm();
			} elseif ( 'u' === $what ) {
				self::page_unsub();
			} else {
				self::page_message();
			}
		} catch ( \Throwable $e ) {
			self::fail( $e );
		}
	}

	private static function is_post(): bool {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) );
	}

	private static function product_name( int $pid, int $vid ): string {
		$p = wc_get_product( $vid > 0 ? $vid : $pid );
		return $p ? wp_strip_all_tags( $p->get_name() ) : '';
	}

	private static function page_confirm(): void {
		$id    = absint( self::q( 'r' ) );
		$token = preg_replace( '/[^a-f0-9]/', '', strtolower( self::q( 'k' ) ) );
		$off   = 'off' === BIS_Settings::mode();
		if ( self::is_post() ) {
			$row = $off ? null : BIS_Store::confirm( $id, (string) $token );
			if ( ! $row ) {
				self::to_message( 'invalid' );
			}
			self::to_message( 'confirmed', (int) $row['product_id'], (int) $row['variation_id'], (string) $row['lang'] );
		}
		$row = $off ? null : BIS_Store::peek( $id, (string) $token );
		if ( ! $row ) {
			self::render_page( BIS_Settings::text( 'invalid' ), '', home_url( '/' ) );
		}
		$lang = (string) $row['lang'];
		$name = self::product_name( (int) $row['product_id'], (int) $row['variation_id'] );
		self::render_page( BIS_Settings::text( 'confirm_q', array( 'product' => $name ), $lang ), BIS_Settings::text( 'confirm_b', array(), $lang ), '', $lang );
	}

	private static function page_unsub(): void {
		$hash = preg_replace( '/[^a-f0-9]/', '', strtolower( self::q( 'e' ) ) );
		$key  = preg_replace( '/[^a-f0-9]/', '', strtolower( self::q( 'k' ) ) );
		$ok   = BIS_Store::check_unsub( (string) $hash, (string) $key );
		if ( self::is_post() ) {
			if ( ! $ok ) {
				self::to_message( 'invalid' );
			}
			BIS_Store::unsubscribe( (string) $hash );
			self::to_message( 'unsubbed' );
		}
		if ( ! $ok ) {
			self::render_page( BIS_Settings::text( 'invalid' ), '', home_url( '/' ) );
		}
		$email = BIS_Store::email_of( (string) $hash );
		$shown = '' !== $email ? self::mask( $email ) : '—';
		self::render_page( BIS_Settings::text( 'unsub_q', array( 'email' => $shown ) ), BIS_Settings::text( 'unsub_b' ), '' );
	}

	/** k***@example.gr */
	public static function mask( string $email ): string {
		$at = strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '***';
		}
		return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/** PRG after a POST: ?bis=m&m=<key>&p=<product>&v=<variation>&l=<lang>. */
	private static function to_message( string $key, int $pid = 0, int $vid = 0, string $lang = '' ): void {
		$args = array(
			'bis' => 'm',
			'm'   => $key,
		);
		if ( $pid > 0 ) {
			$args['p'] = $pid;
			$args['v'] = $vid;
		}
		if ( 'el' === $lang || 'en' === $lang ) {
			$args['l'] = $lang;
		}
		wp_safe_redirect( add_query_arg( $args, home_url( '/' ) ), 303 );
		exit;
	}

	private static function page_message(): void {
		$key  = sanitize_key( self::q( 'm' ) );
		$pid  = absint( self::q( 'p' ) );
		$vid  = absint( self::q( 'v' ) );
		$lang = in_array( self::q( 'l' ), array( 'el', 'en' ), true ) ? self::q( 'l' ) : '';
		if ( ! in_array( $key, array( 'confirmed', 'unsubbed', 'invalid' ), true ) ) {
			return;
		}
		$name = $pid ? self::product_name( $pid, $vid ) : '';
		$back = $pid ? (string) get_permalink( $pid ) : home_url( '/' );
		self::render_page( BIS_Settings::text( $key, array( 'product' => $name ), $lang ), '', '' !== $back ? $back : home_url( '/' ), $lang );
	}

	/**
	 * A small self-contained page (no theme: these pages must work with
	 * every theme and must never be cached).
	 *
	 * @param string $text   Message.
	 * @param string $button Label of the POST button ('' = none).
	 * @param string $back   Link under the message ('' = none).
	 * @param string $lang   'el' | 'en' | ''.
	 */
	private static function render_page( string $text, string $button, string $back, string $lang = '' ): void {
		$lang  = '' !== $lang ? $lang : BIS_Settings::visitor_lang();
		$site  = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$color = (string) get_option( 'woocommerce_email_base_color', '#7f54b3' );
		$color = preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ? $color : '#7f54b3';
		$label = BIS_Lang::with_lang(
			$lang,
			static function () {
				return __( 'Επιστροφή στο κατάστημα', 'back-in-stock' );
			}
		);
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?php echo esc_html( $site ); ?></title>
<style>
body{margin:0;background:#f6f6f6;color:#1d2327;font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.bis-page{max-width:520px;margin:12vh auto 0;padding:0 16px}
.bis-card{background:#fff;border-radius:8px;padding:28px 24px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.bis-site{margin:0 0 16px;font-weight:600;font-size:15px;color:#50575e}
.bis-site a{color:inherit;text-decoration:none}
.bis-text{margin:0 0 20px}
.bis-go{display:inline-block;padding:12px 28px;border:0;border-radius:4px;background:<?php echo esc_attr( $color ); ?>;color:#fff;font-weight:600;font-size:16px;cursor:pointer}
.bis-go:focus-visible{outline:3px solid #1d2327;outline-offset:2px}
.bis-back{display:inline-block;margin-top:8px;color:#1d2327}
@media (prefers-color-scheme:dark){body{background:#1d2327;color:#f0f0f1}.bis-card{background:#2c3338;box-shadow:none}.bis-site{color:#c3c4c7}.bis-back{color:#f0f0f1}}
</style>
</head>
<body>
<main class="bis-page">
	<div class="bis-card">
		<p class="bis-site"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $site ); ?></a></p>
		<p class="bis-text"><?php echo esc_html( $text ); ?></p>
		<?php if ( '' !== $button ) : ?>
			<form method="post"><button type="submit" class="bis-go"><?php echo esc_html( $button ); ?></button></form>
		<?php endif; ?>
		<?php if ( '' !== $back ) : ?>
			<a class="bis-back" href="<?php echo esc_url( $back ); ?>"><?php echo esc_html( (string) $label ); ?></a>
		<?php endif; ?>
	</div>
</main>
</body>
</html>
		<?php
		exit;
	}
}
