<?php
/**
 * Blank landing page for classic themes: the page alone, without the
 * theme's header or footer. Chosen by WPMark for custom-designed pages with
 * the "blank" layout, or by anyone under Page → Template.
 *
 * @package WPMark
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'wpmark-blank' ); ?>>
<?php
wp_body_open();

while ( have_posts() ) {
	the_post();
	the_content();
}

wp_footer();
?>
</body>
</html>
