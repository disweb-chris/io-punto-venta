<?php
/**
 * Plantilla de la pantalla del mostrador.
 *
 * Se puede sobrescribir copiándola al tema en io-punto-venta/terminal.php
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

$io_pos_can_use   = IO_POS_Terminal::can_use();
$io_pos_logged_in = is_user_logged_in();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( IO_POS_Settings::get( 'terminal_title' ) . ' · ' . get_bloginfo( 'name' ) ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="io-pos-body<?php echo $io_pos_can_use ? '' : ' io-pos-body--login'; ?>">

<?php if ( $io_pos_can_use ) : ?>

	<div id="io-pos-app" class="io-pos-app">
		<div class="io-pos-loading"><?php esc_html_e( 'Abriendo el mostrador…', 'io-punto-venta' ); ?></div>
	</div>
	<div id="io-pos-receipt" class="io-pos-receipt io-pos-receipt--<?php echo esc_attr( IO_POS_Settings::get( 'receipt_width' ) ); ?>"></div>

<?php else : ?>

	<div class="io-pos-login">
		<h1><?php echo esc_html( IO_POS_Settings::get( 'terminal_title' ) ); ?></h1>

		<?php if ( $io_pos_logged_in ) : ?>

			<p class="io-pos-login__error">
				<?php esc_html_e( 'Tu usuario no tiene permiso para usar el mostrador. Pedile a un administrador que te dé el permiso «Usar el mostrador».', 'io-punto-venta' ); ?>
			</p>
			<p>
				<a class="io-pos-login__link" href="<?php echo esc_url( wp_logout_url( io_pos_get_terminal_url() ) ); ?>">
					<?php esc_html_e( 'Salir y entrar con otro usuario', 'io-punto-venta' ); ?>
				</a>
			</p>

		<?php else : ?>

			<?php
			wp_login_form(
				array(
					'redirect'       => io_pos_get_terminal_url(),
					'label_username' => __( 'Usuario', 'io-punto-venta' ),
					'label_password' => __( 'Contraseña', 'io-punto-venta' ),
					'label_log_in'   => __( 'Entrar', 'io-punto-venta' ),
					'label_remember' => __( 'No cerrar la sesión', 'io-punto-venta' ),
					'remember'       => true,
				)
			);
			?>

		<?php endif; ?>
	</div>

<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
