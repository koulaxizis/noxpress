<?php
/**
 * NM_Installer — builds nm-installer.php for one backup.
 *
 * The admin asks for an installer (POST): a fresh one-time key is made,
 * its sha256 and expiry go to the backup's metadata, and the key is shown
 * once. The download (privileged GET) builds the file from
 * engine/installer.tpl with the engine classes inlined, so the installer
 * is one self-contained PHP file. Asking again replaces the key: older
 * installers of that backup stop accepting theirs.
 */

defined( 'ABSPATH' ) || exit;

final class NM_Installer {

	const DAYS = 7;

	/** Engine files inlined into the installer, in order. */
	const PARTS = array( 'engine/class-nm-util.php', 'engine/class-nm-crypt.php', 'engine/class-nm-zip-reader.php', 'engine/class-nm-db.php', 'engine/class-nm-dumper.php', 'engine/class-nm-replacer.php', 'engine/class-nm-importer.php', 'engine/class-nm-restorer.php', 'engine/class-nm-page.php', 'includes/class-lang.php' );

	/** New key for a backup. @return string|WP_Error The key (shown once). */
	public static function new_key( string $id ) {
		$meta = NM_Storage::meta( $id );
		if ( ! $meta || 'done' !== ( $meta['status'] ?? '' ) ) {
			return new WP_Error( 'none', NM_Util::t( 'Το αντίγραφο δεν βρέθηκε ή δεν ολοκληρώθηκε.' ) );
		}
		if ( empty( $meta['db'] ) || ! in_array( 'core', (array) ( $meta['groups'] ?? array() ), true ) ) {
			return new WP_Error( 'scope', NM_Util::t( 'Ο installer χρειάζεται πλήρες αντίγραφο (βάση και αρχεία του WordPress).' ) );
		}
		$key                     = NM_Util::hex( 10 );
		$meta['installer']       = hash( 'sha256', $key );
		$meta['installer_until'] = time() + self::DAYS * DAY_IN_SECONDS;
		NM_Storage::save_meta( $meta );
		return implode( '-', str_split( $key, 5 ) );
	}

	/** The installer's source for a backup, or '' when it has no key. */
	public static function build( string $id ): string {
		$meta = NM_Storage::meta( $id );
		if ( ! $meta || empty( $meta['installer'] ) || (int) $meta['installer_until'] < time() ) {
			return '';
		}
		$engine = '';
		foreach ( self::PARTS as $rel ) {
			$src = (string) file_get_contents( NM_PATH . $rel ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$src = preg_replace( '/^<\?php\s*/', '', $src );
			$src = str_replace( "defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;", '', $src );
			$engine .= "\n// ---- " . $rel . " ----\n" . $src . "\n";
		}
		$tpl = (string) file_get_contents( NM_PATH . 'engine/installer.tpl' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return strtr(
			$tpl,
			array(
				'__NM_KEYHASH__'   => (string) $meta['installer'],
				'__NM_BACKUP__'    => $id,
				'__NM_EXPIRES__'   => (string) (int) $meta['installer_until'],
				'__NM_VERSION__'   => NM_VERSION,
				'// __NM_ENGINE__' => $engine,
			)
		);
	}
}
