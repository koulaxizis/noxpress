<?php
/**
 * NM_Page — the standalone pages (runner.php and the installer): one
 * self-contained HTML page with the suite's dark palette, a progress bar
 * and a small script that calls the same URL step by step (POST, JSON)
 * until the job ends. No external files, no CDN.
 */

defined( 'ABSPATH' ) || defined( 'NM_STANDALONE' ) || exit;

final class NM_Page {

	public static function h( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	public static function headers(): void {
		if ( headers_sent() ) {
			return;
		}
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Frame-Options: DENY' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
	}

	public static function json( array $data, int $code = 200 ): void {
		self::headers();
		if ( ! headers_sent() ) {
			http_response_code( $code );
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo NM_Util::json( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
	}

	public static function open( string $title ): void {
		self::headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=utf-8' );
		}
		$lang = 'en' === NM_Util::$lang ? 'en' : 'el';
		echo '<!doctype html><html lang="' . self::h( $lang ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>' . self::h( $title ) . '</title><style>' . self::css() . '</style></head><body><main class="nm-wrap"><h1>' . self::h( $title ) . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}

	public static function close(): void {
		echo '<p class="nm-footer">Data Migrator · <a href="https://noxpress.tech" target="_blank" rel="noopener noreferrer">noxpress.tech</a></p></main></body></html>';
	}

	public static function css(): string {
		return ':root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#0b0c10;color:#eae8fa;font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:24px 16px}'
			. '.nm-wrap{max-width:860px;margin:0 auto;background:#101218;border:1px solid #2b2e36;border-radius:8px;padding:32px 36px 28px;box-shadow:0 1px 3px rgba(0,0,0,.35)}'
			. 'h1{color:#f6f4ff;font-size:22px;margin:0 0 16px}h2{color:#b7b2d6;font-size:15px;text-transform:uppercase;border-bottom:1px solid #2e3240;padding-bottom:6px;margin:24px 0 12px}'
			. 'a{color:#beb1ff}a:hover{color:#d4c9ff}p.desc,.muted{color:#b7b2d6}'
			. '.zone{background:#14161d;border:1px solid #2e3240;border-radius:6px;padding:16px 18px;margin:12px 0}'
			. 'label{display:block;margin:10px 0 4px;color:#b7b2d6}input[type=text],input[type=password],input[type=url]{width:100%;max-width:520px;background:#1a1d26;color:#eae8fa;border:1px solid #3a3f52;border-radius:4px;padding:8px 10px;font:inherit}'
			. 'button,.button{display:inline-block;background:#1e2130;color:#eae8fa;border:1px solid #4a4f66;border-radius:4px;padding:8px 16px;font:inherit;cursor:pointer;text-decoration:none;margin:6px 6px 0 0}'
			. 'button.primary,.button.primary{background:#6d4aff;border-color:#6d4aff;color:#fff}button.primary:hover{background:#8263ff}button.danger{border-color:#ff9a9a;color:#ff9a9a}button[disabled]{opacity:.5;cursor:default}'
			. '.bar{height:10px;background:#1d2029;border-radius:5px;overflow:hidden;max-width:620px;margin:12px 0}.bar span{display:block;height:100%;width:0;background:#6d4aff;transition:width .3s}'
			. '.ok{color:#9be3b0}.warn{color:#f5c97e}.fail,.err{color:#ff9a9a}.info{color:#b7b2d6}ul{padding-left:20px}code{background:#1d2029;padding:1px 5px;border-radius:3px}'
			. '.code{font:20px/1.4 ui-monospace,Menlo,monospace;letter-spacing:1px;background:#1d2029;border:1px solid #3a3f52;padding:10px 14px;border-radius:6px;display:inline-block;word-break:break-all}'
			. '.nm-footer{margin-top:28px;border-top:1px solid #23262f;padding-top:12px;color:#b7b2d6;font-size:13px}'
			. '@media(max-width:600px){.nm-wrap{padding:20px 16px}}';
	}

	/**
	 * The stepping script. POSTs {do: step, job, token} to $url until the
	 * answer's status is final, then reloads the page to show the report.
	 */
	public static function stepper( string $url, array $fields ): void {
		$cfg = array(
			'url'    => $url,
			'fields' => $fields,
			'err'    => NM_Util::t( 'Σφάλμα σύνδεσης. Νέα προσπάθεια σε λίγα δευτερόλεπτα…' ),
			'stop'   => NM_Util::t( 'Η σύνδεση απέτυχε πολλές φορές. Άνοιξε ξανά τη σελίδα με τον κωδικό ανάκτησης για να συνεχίσεις.' ),
		);
		echo '<script>(function(c){var bar=document.getElementById("nm-bar"),txt=document.getElementById("nm-text"),fails=0;'
			. 'function body(){var f=new FormData();for(var k in c.fields){f.append(k,c.fields[k]);}f.append("do","step");return f;}'
			. 'function done(){var f=document.getElementById("nm-reload");if(f){f.submit();}}'
			. 'function go(){fetch(c.url,{method:"POST",body:body(),credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json();}).then(function(d){fails=0;'
			. 'if(bar&&d.pct!==undefined){bar.style.width=d.pct+"%";}if(txt&&d.text){txt.textContent=d.text;}'
			. 'if(d.status==="running"||d.status==="rolling-back"||d.status==="busy"){setTimeout(go,d.status==="busy"?3000:300);}else{done();}})'
			. '.catch(function(){fails++;if(txt){txt.textContent=fails>8?c.stop:c.err;}if(fails<=8){setTimeout(go,3000*fails);}});}go();})(' . NM_Util::json( $cfg ) . ');</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a script.
	}

	/** Hidden form that reloads the page (POST, so the token stays out of URLs). */
	public static function reload_form( string $url, array $fields ): void {
		echo '<form id="nm-reload" method="post" action="' . self::h( $url ) . '">';
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . self::h( $k ) . '" value="' . self::h( $v ) . '">';
		}
		echo '<input type="hidden" name="do" value="page"></form>';
	}

	/** The final report of a restore job. */
	public static function report( array $job ): void {
		$r = (array) ( $job['report'] ?? array() );
		echo '<div class="zone"><ul>';
		if ( isset( $r['tables'] ) ) {
			echo '<li>' . self::h( NM_Util::tf( 'Πίνακες: %1$d, γραμμές: %2$d.', (int) $r['tables'], (int) ( $r['rows'] ?? 0 ) ) ) . '</li>';
		}
		if ( isset( $r['replaced'] ) ) {
			echo '<li>' . self::h( NM_Util::tf( 'Αντικαταστάσεις διευθύνσεων και διαδρομών: %d.', (int) $r['replaced'] ) ) . '</li>';
		}
		if ( isset( $r['files'] ) ) {
			echo '<li>' . self::h( NM_Util::tf( 'Αρχεία που γράφτηκαν: %d.', (int) $r['files'] ) ) . '</li>';
		}
		if ( ! empty( $r['files_skip'] ) ) {
			echo '<li class="warn">' . self::h( NM_Util::tf( 'Αρχεία που δεν γράφτηκαν (υπάρχει φάκελος με το ίδιο όνομα): %s', implode( ', ', array_slice( (array) $r['files_skip'], 0, 20 ) ) ) ) . '</li>';
		}
		if ( ! empty( $r['collation'] ) ) {
			echo '<li class="info">' . self::h( NM_Util::tf( 'Πίνακες με collation που άλλαξε για αυτόν τον server: %s', implode( ', ', (array) $r['collation'] ) ) ) . '</li>';
		}
		if ( ! empty( $r['fk_warnings'] ) ) {
			echo '<li class="warn">' . self::h( NM_Util::tf( 'Foreign keys που δεν μπήκαν ξανά: %s', implode( '; ', (array) $r['fk_warnings'] ) ) ) . '</li>';
		}
		if ( ! empty( $r['tables_kept'] ) ) {
			echo '<li class="info">' . self::h( NM_Util::tf( 'Πίνακες αυτού του site που δεν υπήρχαν στο αντίγραφο και έμειναν ως είχαν: %s', implode( ', ', (array) $r['tables_kept'] ) ) ) . '</li>';
		}
		if ( isset( $r['as_pending'] ) ) {
			echo '<li class="info">' . self::h( NM_Util::tf( 'Εκκρεμείς εργασίες του Action Scheduler: %d (θα τρέξουν σε αυτόν τον server).', (int) $r['as_pending'] ) ) . '</li>';
		}
		if ( isset( $r['salts'] ) ) {
			echo '<li class="' . ( $r['salts'] ? 'ok' : 'warn' ) . '">' . self::h( $r['salts'] ? NM_Util::t( 'Νέα salts στο wp-config.php: όλοι οι χρήστες αποσυνδέθηκαν.' ) : NM_Util::t( 'Τα salts δεν άλλαξαν: το wp-config.php δεν έχει την αναμενόμενη μορφή ή δεν γράφεται.' ) ) . '</li>';
		}
		if ( ! empty( $job['manifest']['admins'] ) ) {
			echo '<li>' . self::h( NM_Util::tf( 'Συνδέσου με λογαριασμό του αντιγράφου, π.χ.: %s', implode( ', ', (array) $job['manifest']['admins'] ) ) ) . '</li>';
		}
		echo '</ul></div>';
	}
}
