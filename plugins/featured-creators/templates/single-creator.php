<?php
/**
 * Featured Creators — creator page for classic themes (v1.0.0).
 *
 * The theme's own header and footer stay, so the page sits in the theme's
 * container and spacing (Astra, Storefront and others). A theme can
 * replace this file with its own single-nox_creator.php and print the page
 * with CRT_Front::render_page( get_the_ID() ).
 */

defined( 'ABSPATH' ) || exit;

get_header();

// The usual classic-theme wrappers (Storefront, Astra, Underscores themes),
// so the theme's own product grid and spacing rules apply. No sidebar: the
// page uses the full content width (front.css, .crt-creator-page).
echo '<div id="primary" class="content-area"><main id="main" class="site-main">';

while ( have_posts() ) {
	the_post();
	try {
		$crt_html = CRT_Front::render_page( (int) get_the_ID() );
	} catch ( \Throwable $crt_e ) {
		$crt_html = ''; // Bible §14.6: an error never breaks the page.
	}
	echo '<div class="crt-template">' . $crt_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_page().
}

echo '</main></div>';

get_footer();
