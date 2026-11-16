<?php
/**
 * Maintenance Mode — the visitor page. Plain HTML with inline CSS, no
 * theme, no external request. $mmd comes from MMD_Page::data().
 *
 * To replace it, copy this file to your (child) theme as
 * maintenance-mode.php: it gets the same $mmd.
 *
 * @var array $mmd
 */

defined( 'ABSPATH' ) || exit;

$mmd_bg_css = '' !== $mmd['bg_image'] ? 'background-image:linear-gradient(rgba(0,0,0,.45),rgba(0,0,0,.45)),url("' . esc_url( $mmd['bg_image'] ) . '");background-size:cover;background-position:center;' : '';
?><!doctype html>
<html lang="<?php echo esc_attr( $mmd['lang'] ); ?>">
<head>
<meta charset="<?php echo esc_attr( $mmd['charset'] ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ( 'coming_soon' === $mmd['mode'] || $mmd['preview'] ) : ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<title><?php echo esc_html( $mmd['title'] . ( '' !== $mmd['site'] ? ' · ' . $mmd['site'] : '' ) ); ?></title>
<style>
:root{--mmd-bg:<?php echo esc_html( $mmd['bg'] ); ?>;--mmd-fg:<?php echo esc_html( $mmd['fg'] ); ?>;--mmd-accent:<?php echo esc_html( $mmd['accent'] ); ?>}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:32px 16px;background:var(--mmd-bg);color:var(--mmd-fg);font:17px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans","Helvetica Neue",Arial,sans-serif;text-align:center;<?php echo $mmd_bg_css; // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_url above. ?>}
main{max-width:640px;width:100%}
.mmd-logo{max-width:180px;max-height:120px;width:auto;height:auto;margin:0 auto 28px;display:block}
h1{font-size:clamp(28px,6vw,44px);line-height:1.15;margin:0 0 16px;font-weight:700;letter-spacing:-.01em}
.mmd-text{opacity:.9;margin:0 auto 28px}
.mmd-text p{margin:0 0 12px}
a{color:var(--mmd-accent)}
.mmd-bar{width:56px;height:3px;background:var(--mmd-accent);border-radius:2px;margin:0 auto 28px}
.mmd-count{display:flex;gap:12px;justify-content:center;margin:0 0 28px;flex-wrap:wrap}
.mmd-count div{min-width:72px;padding:10px 8px;border:1px solid color-mix(in srgb,var(--mmd-fg) 25%,transparent);border-radius:10px}
.mmd-count b{display:block;font-size:28px;line-height:1.1;font-variant-numeric:tabular-nums}
.mmd-count span{font-size:12px;text-transform:uppercase;letter-spacing:.06em;opacity:.75}
.mmd-contact{font-size:15px;opacity:.9}
.mmd-contact a{margin:0 8px;white-space:nowrap}
.mmd-preview{position:fixed;top:0;left:0;right:0;padding:6px 12px;background:#b32d2e;color:#fff;font-size:13px}
</style>
</head>
<body class="mmd-<?php echo esc_attr( str_replace( '_', '-', $mmd['mode'] ) ); ?>">
<?php if ( $mmd['preview'] ) : ?>
<div class="mmd-preview"><?php echo esc_html( $mmd['labels']['preview'] ); ?></div>
<?php endif; ?>
<main>
<?php if ( '' !== $mmd['logo'] ) : ?>
<img class="mmd-logo" src="<?php echo esc_url( $mmd['logo'] ); ?>" alt="<?php echo esc_attr( $mmd['site'] ); ?>">
<?php endif; ?>
<h1><?php echo esc_html( $mmd['title'] ); ?></h1>
<div class="mmd-bar"></div>
<div class="mmd-text"><?php echo wpautop( wp_kses_post( $mmd['text'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- wp_kses_post. ?></div>
<?php if ( $mmd['countdown'] ) : ?>
<div class="mmd-count" id="mmd-count" data-end="<?php echo (int) $mmd['countdown']; ?>">
	<div><b data-u="d">0</b><span><?php echo esc_html( $mmd['labels']['days'] ); ?></span></div>
	<div><b data-u="h">0</b><span><?php echo esc_html( $mmd['labels']['hours'] ); ?></span></div>
	<div><b data-u="m">0</b><span><?php echo esc_html( $mmd['labels']['minutes'] ); ?></span></div>
	<div><b data-u="s">0</b><span><?php echo esc_html( $mmd['labels']['seconds'] ); ?></span></div>
</div>
<script>
(function(){var el=document.getElementById('mmd-count');if(!el){return;}var end=parseInt(el.getAttribute('data-end'),10)*1000;function t(){var r=Math.max(0,end-Date.now()),s=Math.floor(r/1000),v={d:Math.floor(s/86400),h:Math.floor(s%86400/3600),m:Math.floor(s%3600/60),s:s%60};for(var k in v){var b=el.querySelector('[data-u="'+k+'"]');if(b){b.textContent=v[k];}}if(r<=0){clearInterval(i);setTimeout(function(){location.reload();},3000);}}var i=setInterval(t,1000);t();})();
</script>
<?php endif; ?>
<?php if ( '' !== $mmd['email'] || '' !== $mmd['phone'] || $mmd['links'] ) : ?>
<p class="mmd-contact">
	<?php if ( '' !== $mmd['email'] ) : ?>
	<a href="<?php echo esc_url( 'mailto:' . $mmd['email'] ); ?>"><?php echo esc_html( antispambot( $mmd['email'] ) ); ?></a>
	<?php endif; ?>
	<?php if ( '' !== $mmd['phone'] ) : ?>
	<a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $mmd['phone'] ) ); ?>"><?php echo esc_html( $mmd['phone'] ); ?></a>
	<?php endif; ?>
	<?php foreach ( $mmd['links'] as $mmd_link ) : ?>
	<a href="<?php echo esc_url( $mmd_link['url'] ); ?>" rel="noopener noreferrer"><?php echo esc_html( $mmd_link['label'] ); ?></a>
	<?php endforeach; ?>
</p>
<?php endif; ?>
</main>
</body>
</html>
