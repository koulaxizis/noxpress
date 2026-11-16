<?php
/**
 * GFC_Emails — the WooCommerce emails of Gift Cards (Bible §14.29), and
 * plain admin alerts.
 *
 *  - gfc_card    to the recipient (or the buyer, "for me"): amount, message,
 *                the code, how to use it, expiry; the printable PDF attached
 *                (E15). The only place the code ever exists.
 *  - gfc_sent    to the buyer of a gift: "sent to …", WITHOUT the code (E9).
 *  - gfc_review  to the shop: cards waiting for approval (E10).
 *  - gfc_remind  to the recipient, remind_days before expiry.
 * Subject, heading and on/off: WooCommerce › Settings › Emails. The card
 * email cannot be switched off while cards exist (its code would be lost),
 * so it ignores "enabled" and always sends.
 *
 * Admin alerts (lockout, a locked card, a failed delivery) are plain text
 * with a link to the admin, never data (Bible §11).
 */

defined( 'ABSPATH' ) || exit;

final class GFC_Emails {

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	public static function register( $emails ): array {
		$emails = is_array( $emails ) ? $emails : array();
		require_once GFC_PATH . 'includes/class-email-classes.php';
		$emails['GFC_Email_Card']   = new GFC_Email_Card();
		$emails['GFC_Email_Sent']   = new GFC_Email_Sent();
		$emails['GFC_Email_Review'] = new GFC_Email_Review();
		$emails['GFC_Email_Remind'] = new GFC_Email_Remind();
		return $emails;
	}

	private static function email( string $class ) {
		$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
		$emails = $mailer ? $mailer->get_emails() : array();
		return $emails[ $class ] ?? null;
	}

	/** The card email with its code. False when it could not be sent. */
	public static function card( array $card, string $code ): bool {
		try {
			$e = self::email( 'GFC_Email_Card' );
			return $e ? (bool) $e->trigger( $card, $code ) : false;
		} catch ( \Throwable $ex ) {
			return false;
		}
	}

	public static function sent( array $card ): void {
		try {
			$e = self::email( 'GFC_Email_Sent' );
			if ( $e ) {
				$e->trigger( $card );
			}
		} catch ( \Throwable $ex ) {
			return;
		}
	}

	public static function review( WC_Order $order, array $ids ): void {
		try {
			$e = self::email( 'GFC_Email_Review' );
			if ( $e ) {
				$e->trigger( $order, $ids );
			}
		} catch ( \Throwable $ex ) {
			return;
		}
	}

	public static function remind( array $card ): void {
		try {
			$e = self::email( 'GFC_Email_Remind' );
			if ( $e ) {
				$e->trigger( $card );
			}
		} catch ( \Throwable $ex ) {
			return;
		}
	}

	/** Plain-text alert to the site admin, at most one per subject per hour. */
	public static function admin_alert( string $subject, string $text, string $url ): void {
		$key = 'gfc_alert_' . md5( $subject . '|' . $text );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );
		wp_mail( (string) get_option( 'admin_email' ), $subject, $text . "\n\n" . $url );
	}

	/**
	 * The PDF of a card, written to a private temporary file. The caller
	 * deletes it right after sending. Null when the PDF is off or fails.
	 */
	public static function pdf_file( array $card, string $code ): ?string {
		if ( ! GFC_Settings::get()['pdf'] || ! GFC_PDF::available() ) {
			return null;
		}
		try {
			$bin  = self::pdf( $card, $code );
			$dir  = trailingslashit( get_temp_dir() );
			$name = $dir . 'gift-card-' . bin2hex( random_bytes( 12 ) ) . '.pdf';
			// Created as 0600 from the first byte: the file holds the code.
			$old = umask( 0077 );
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- private temp file, deleted after sending.
				$fh = @fopen( $name, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} finally {
				umask( $old );
			}
			if ( false === $fh ) {
				return null;
			}
			// Where umask is not honoured: still 0600 before the first byte.
			@chmod( $name, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$ok = false !== fwrite( $fh, $bin );
			fclose( $fh );
			// phpcs:enable
			if ( ! $ok ) {
				wp_delete_file( $name );
				return null;
			}
			return $name;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** The printable card (binary PDF). */
	public static function pdf( array $card, string $code ): string {
		$lang = 'en' === $card['lang'] ? 'en' : 'el';
		$size = GFC_Settings::get()['pdf_size'];
		$pdf  = new GFC_PDF( GFC_Settings::text( 'pdf_title', $lang ), $size );
		$pdf->add_page();
		$w    = $pdf->w;
		$h    = $pdf->h;
		$dark = array( 0.11, 0.11, 0.14 );
		$mute = array( 0.42, 0.41, 0.5 );
		$acc  = self::accent();
		$l    = 40.0;
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$pdf->rect( 0, 0, $w, 70, $acc );
		$pdf->text( $l, 44, $site, 18, true, 'L', array( 1, 1, 1 ) );
		$pdf->text( $w - $l, 44, GFC_Settings::text( 'pdf_title', $lang ), 13, false, 'R', array( 1, 1, 1 ) );

		$img_w = 0.0;
		$img   = self::image_bin( $card );
		if ( '' !== $img && $pdf->image( $img, $w - $l - 170, 96, 170, 170 ) ) {
			$img_w = 190.0;
		}

		$y = 120.0;
		$pdf->text( $l, $y, GFC_Settings::money( $card['initial'], $card['currency'] ), 40, true, 'L', $dark );
		$y += 40;
		if ( '' !== $card['recipient_name'] ) {
			$pdf->text( $l, $y, GFC_Settings::text( 'pdf_to', $lang ) . ': ' . $card['recipient_name'], 12, false, 'L', $dark );
			$y += 18;
		}
		if ( '' !== $card['sender_name'] ) {
			$pdf->text( $l, $y, GFC_Settings::text( 'pdf_from', $lang ) . ': ' . $card['sender_name'], 12, false, 'L', $dark );
			$y += 18;
		}
		if ( '' !== (string) $card['message'] ) {
			$y += 4;
			foreach ( array_slice( $pdf->wrap( '«' . $card['message'] . '»', 11, $w - 2 * $l - $img_w ), 0, 6 ) as $line ) {
				$pdf->text( $l, $y, $line, 11, false, 'L', $mute );
				$y += 15;
			}
		}

		$box_y = max( $y + 16, 'a4' === $size ? 330.0 : 290.0 );
		$pdf->rect( $l, $box_y, $w - 2 * $l, 56, array( 0.95, 0.94, 0.99 ) );
		$pdf->text( $l + 16, $box_y + 20, GFC_Settings::text( 'pdf_code', $lang ), 9, false, 'L', $mute );
		$pdf->text( $w / 2, $box_y + 44, GFC_Codes::format( $code ), 22, true, 'C', $dark );

		$y = $box_y + 76;
		foreach ( array_slice( $pdf->wrap( GFC_Settings::text( 'email_how', $lang ), 9, $w - 2 * $l ), 0, 3 ) as $line ) {
			$pdf->text( $l, $y, $line, 9, false, 'L', $mute );
			$y += 12;
		}
		$foot = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $card['expires_at'] ) {
			$foot .= '   ·   ' . GFC_Settings::fill_plain( 'email_expires', array( 'date' => wp_date( get_option( 'date_format' ), (int) strtotime( $card['expires_at'] . ' UTC' ) ) ), $lang );
		}
		$pdf->text( $l, $h - 18, (string) $foot, 8.5, false, 'L', $mute );
		return $pdf->output();
	}

	/** The WooCommerce email base colour as 0–1 floats. */
	private static function accent(): array {
		$hex = ltrim( (string) get_option( 'woocommerce_email_base_color', '#6d4aff' ), '#' );
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			$hex = '6d4aff';
		}
		return array( hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 );
	}

	/** Product image of the card (or the site logo), as bytes from the uploads folder. */
	private static function image_bin( array $card ): string {
		$att = 0;
		if ( $card['item_id'] ) {
			$item = WC_Order_Factory::get_order_item( $card['item_id'] );
			if ( $item instanceof WC_Order_Item_Product ) {
				$p   = $item->get_product();
				$att = $p ? (int) $p->get_image_id() : 0;
			}
		}
		if ( ! $att ) {
			$att = (int) get_theme_mod( 'custom_logo' );
		}
		if ( ! $att ) {
			return '';
		}
		$file = get_attached_file( $att );
		$meta = wp_get_attachment_metadata( $att );
		if ( is_array( $meta ) && ! empty( $meta['sizes']['medium']['file'] ) && $file ) {
			$alt = path_join( dirname( $file ), $meta['sizes']['medium']['file'] );
			if ( is_readable( $alt ) ) {
				$file = $alt;
			}
		}
		$uploads = wp_get_upload_dir();
		$real    = $file ? realpath( $file ) : false;
		if ( ! $real || 0 !== strpos( $real, (string) realpath( $uploads['basedir'] ) ) || filesize( $real ) > 3 * MB_IN_BYTES ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		return (string) file_get_contents( $real );
	}
}
