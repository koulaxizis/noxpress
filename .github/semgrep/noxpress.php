<?php
// Test cases for noxpress.yml: semgrep --test .github/semgrep

// ruleid: nox-rest-route-without-permission-callback
register_rest_route( 'x/v1', '/a', array( 'methods' => 'GET', 'callback' => 'f' ) );
// ok: nox-rest-route-without-permission-callback
register_rest_route( 'x/v1', '/b', array( 'methods' => 'GET', 'callback' => 'f', 'permission_callback' => 'g' ) );
// ok: nox-rest-route-without-permission-callback
register_rest_route( 'x/v1', '/c', array( array( 'methods' => 'GET', 'callback' => 'f', 'permission_callback' => 'g' ) ) );
// ok: nox-rest-route-without-permission-callback
register_rest_route( 'x/v1', '/d', $open + array( 'methods' => 'GET', 'callback' => 'f' ) );

function q( $request ) {
	global $wpdb;
	// ruleid: nox-wpdb-query-without-prepare
	$wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE post_title = '" . $_GET['t'] . "'" );
	// ruleid: nox-wpdb-query-without-prepare
	$wpdb->query( "DELETE FROM x WHERE id = {$request['id']}" );
	// ok: nox-wpdb-query-without-prepare
	$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $_GET['id'] ) );
	// ok: nox-wpdb-query-without-prepare
	$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}nox_log" );
}

// ruleid: nox-unserialize-user-data
$a = unserialize( $_POST['x'] );
// ok: nox-unserialize-user-data
$b = unserialize( $raw, array( 'allowed_classes' => false ) );

// ruleid: nox-rest-route-public
register_rest_route( 'x/v1', '/e', array( 'methods' => 'GET', 'callback' => 'f', 'permission_callback' => '__return_true' ) );

// ruleid: nox-preg-replace-dynamic-replacement
$h = preg_replace( '/x/', $text, $h );
// ok: nox-preg-replace-dynamic-replacement
$h = preg_replace( '/x/', 'y', $h );

// ruleid: nox-fputcsv-escape
fputcsv( $fh, $row, ',', '"', '\\' );
// ok: nox-fputcsv-escape
fputcsv( $fh, $row, ',', '"', '' );
// ruleid: nox-fputcsv-escape
$row = fgetcsv( $fh, 0, ',', '"', '\\' );
// ruleid: nox-fputcsv-escape
$row = fgetcsv( $fh );
// ok: nox-fputcsv-escape
$row = fgetcsv( $fh, 0, ',', '"', '' );

// ruleid: nox-remote-request-unchecked
wp_remote_get( $url );
// ok: nox-remote-request-unchecked
wp_remote_get( 'https://noxpress.tech/updates.json' );

// ruleid: nox-token-in-transient-name
set_transient( 'rs_tok_' . $token, 1, 60 );
// ok: nox-token-in-transient-name
set_transient( 'rs_tok_' . hash_hmac( 'sha256', $token, 'k' ), 1, 60 );
