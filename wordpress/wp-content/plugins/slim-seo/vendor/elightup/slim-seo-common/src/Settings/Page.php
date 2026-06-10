<?php
namespace eLightUp\SlimSEO\Common\Settings;

use eLightUp\SlimSEO\Common\Assets;

class Page {
	public static function setup(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
	}

	public static function add_menu(): void {
		$page_hook = add_options_page(
			__( 'Slim SEO', 'slim-seo' ),
			__( 'Slim SEO', 'slim-seo' ),
			'manage_options',
			'slim-seo',
			[ __CLASS__, 'render' ]
		);
		add_action( "admin_print_styles-{$page_hook}", [ __CLASS__, 'enqueue' ] );
		add_action( "load-{$page_hook}", [ __CLASS__, 'save' ] );
	}

	public static function render(): void {
		?>
		<div class="wrap">
			<h1 class="ss-title">
				<svg fill="none" width="151" height="36" viewBox="0 0 151 36" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><linearGradient id="a" gradientUnits="userSpaceOnUse" x1="0" x2="36" y1="0" y2="36"><stop offset="0" stop-color="#c21500"/><stop offset="1" stop-color="#ffc500"/></linearGradient><path d="m55.008 26.168c-1.104 0-2.08-.192-2.928-.576-.832-.384-1.488-.92-1.968-1.608s-.736-1.456-.768-2.304h1.776c.08.816.44 1.536 1.08 2.16.64.608 1.576.912 2.808.912 1.136 0 2.032-.288 2.688-.864.672-.592 1.008-1.336 1.008-2.232 0-.72-.184-1.296-.552-1.728-.368-.448-.824-.776-1.368-.984-.544-.224-1.296-.464-2.256-.72-1.12-.304-2.008-.6-2.664-.888s-1.216-.736-1.68-1.344-.696-1.432-.696-2.472c0-.864.224-1.632.672-2.304.448-.688 1.08-1.224 1.896-1.608s1.752-.576 2.808-.576c1.552 0 2.8.384 3.744 1.152.96.752 1.512 1.728 1.656 2.928h-1.824c-.112-.688-.488-1.296-1.128-1.824-.64-.544-1.504-.816-2.592-.816-1.008 0-1.848.272-2.52.816-.672.528-1.008 1.256-1.008 2.184 0 .704.184 1.272.552 1.704s.824.76 1.368.984c.56.224 1.312.464 2.256.72 1.088.304 1.968.608 2.64.912.672.288 1.24.736 1.704 1.344s.696 1.424.696 2.448c0 .784-.208 1.528-.624 2.232s-1.032 1.272-1.848 1.704-1.792.648-2.928.648zm10.3264-17.928v17.76h-1.68v-17.76zm4.6444 2.184c-.336 0-.624-.12-.864-.36s-.36-.536-.36-.888.12-.64.36-.864c.24-.24.528-.36.864-.36s.624.12.864.36c.24.224.36.512.36.864s-.12.648-.36.888-.528.36-.864.36zm.84 2.472v13.104h-1.68v-13.104zm19.4043-.24c1.536 0 2.784.488 3.744 1.464.976.96 1.464 2.36 1.464 4.2v7.68h-1.656v-7.488c0-1.424-.344-2.512-1.032-3.264s-1.624-1.128-2.808-1.128c-1.232 0-2.216.408-2.952 1.224s-1.104 2-1.104 3.552v7.104h-1.656v-7.488c0-1.424-.344-2.512-1.032-3.264s-1.632-1.128-2.832-1.128c-1.232 0-2.216.408-2.952 1.224s-1.104 2-1.104 3.552v7.104h-1.68v-13.104h1.68v2.256c.416-.816 1.008-1.432 1.776-1.848.768-.432 1.624-.648 2.568-.648 1.136 0 2.128.272 2.976.816.864.544 1.488 1.344 1.872 2.4.352-1.04.952-1.832 1.8-2.376.864-.56 1.84-.84 2.928-.84zm21.1889 13.512c-1.232 0-2.336-.2-3.312-.6s-1.76-.992-2.352-1.776c-.576-.784-.88-1.728-.912-2.832h4.368c.064.624.28 1.104.648 1.44.368.32.848.48 1.44.48.608 0 1.088-.136 1.44-.408.352-.288.528-.68.528-1.176 0-.416-.144-.76-.432-1.032-.272-.272-.616-.496-1.032-.672-.4-.176-.976-.376-1.728-.6-1.088-.336-1.976-.672-2.664-1.008s-1.28-.832-1.776-1.488-.744-1.512-.744-2.568c0-1.568.568-2.792 1.704-3.672 1.136-.896 2.616-1.344 4.44-1.344 1.856 0 3.352.448 4.488 1.344 1.136.88 1.744 2.112 1.824 3.696h-4.44c-.032-.544-.232-.968-.6-1.272-.368-.32-.84-.48-1.416-.48-.496 0-.896.136-1.2.408-.304.256-.456.632-.456 1.128 0 .544.256.968.768 1.272s1.312.632 2.4.984c1.088.368 1.968.72 2.64 1.056.688.336 1.28.824 1.776 1.464s.744 1.464.744 2.472c0 .96-.248 1.832-.744 2.616-.48.784-1.184 1.408-2.112 1.872s-2.024.696-3.288.696zm12.774-13.728v3.408h5.496v3.168h-5.496v3.696h6.216v3.288h-10.32v-16.848h10.32v3.288zm16.848 13.728c-1.584 0-3.04-.368-4.368-1.104-1.312-.736-2.36-1.76-3.144-3.072-.768-1.328-1.152-2.816-1.152-4.464s.384-3.128 1.152-4.44c.784-1.312 1.832-2.336 3.144-3.072 1.328-.736 2.784-1.104 4.368-1.104s3.032.368 4.344 1.104c1.328.736 2.368 1.76 3.12 3.072.768 1.312 1.152 2.792 1.152 4.44s-.384 3.136-1.152 4.464c-.768 1.312-1.808 2.336-3.12 3.072s-2.76 1.104-4.344 1.104zm0-3.744c1.344 0 2.416-.448 3.216-1.344.816-.896 1.224-2.08 1.224-3.552 0-1.488-.408-2.672-1.224-3.552-.8-.896-1.872-1.344-3.216-1.344-1.36 0-2.448.44-3.264 1.32-.8.88-1.2 2.072-1.2 3.576 0 1.488.4 2.68 1.2 3.576.816.88 1.904 1.32 3.264 1.32z" fill="#131820"/><path d="m33.1875 0h-30.375c-1.5533 0-2.8125 1.2592-2.8125 2.8125v30.375c0 1.5533 1.2592 2.8125 2.8125 2.8125h30.375c1.5533 0 2.8125-1.2592 2.8125-2.8125v-30.375c0-1.5533-1.2592-2.8125-2.8125-2.8125z" fill="url(#a)"/><path d="m18.2012 26.168c-1.232 0-2.336-.2-3.312-.6s-1.76-.992-2.352-1.776c-.576-.784-.88-1.728-.912-2.832h4.368c.064.624.28 1.104.648 1.44.368.32.848.48 1.44.48.608 0 1.088-.136 1.44-.408.352-.288.528-.68.528-1.176 0-.416-.144-.76-.432-1.032-.272-.272-.616-.496-1.032-.672-.4-.176-.976-.376-1.728-.6-1.088-.336-1.976-.672-2.664-1.008s-1.28-.832-1.776-1.488-.744-1.512-.744-2.568c0-1.568.568-2.792 1.704-3.672 1.136-.896 2.616-1.344 4.44-1.344 1.856 0 3.352.448 4.488 1.344 1.136.88 1.744 2.112 1.824 3.696h-4.44c-.032-.544-.232-.968-.6-1.272-.368-.32-.84-.48-1.416-.48-.496 0-.896.136-1.2.408-.304.256-.456.632-.456 1.128 0 .544.256.968.768 1.272s1.312.632 2.4.984c1.088.368 1.968.72 2.64 1.056.688.336 1.28.824 1.776 1.464s.744 1.464.744 2.472c0 .96-.248 1.832-.744 2.616-.48.784-1.184 1.408-2.112 1.872s-2.024.696-3.288.696z" fill="#fff"/></svg>

				<?php if ( ! defined( 'SLIM_SEO_PRO_VER' ) ) : ?>
					<a href="https://elu.to/ssp" target="_blank" class="ss-title__upgrade">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" color="currentColor" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
							<path d="M4.5 9.5C4.5 13.6421 7.85786 17 12 17C16.1421 17 19.5 13.6421 19.5 9.5C19.5 5.35786 16.1421 2 12 2C7.85786 2 4.5 5.35786 4.5 9.5Z" />
							<path d="M9 10.1667C9 10.1667 9.75 10.1667 10.5 11.5C10.5 11.5 12.8824 8.16667 15 7.5" />
							<path d="M16.8825 15L17.5527 18.2099C17.9833 20.2723 18.1986 21.3035 17.7563 21.7923C17.3141 22.281 16.546 21.8606 15.0099 21.0198L12.7364 19.7753C12.3734 19.5766 12.1919 19.4773 12 19.4773C11.8081 19.4773 11.6266 19.5766 11.2636 19.7753L8.99008 21.0198C7.45397 21.8606 6.68592 22.281 6.24365 21.7923C5.80139 21.3035 6.01669 20.2723 6.44731 18.2099L7.11752 15" />
						</svg>
						<strong><?php esc_html_e( 'Upgrade to Pro', 'slim-seo' ); ?></strong>
					</a>
				<?php endif ?>

				<a href="https://elu.to/ec" target="_blank">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
						<circle cx="12" cy="12" r="10" />
						<circle cx="12" cy="12" r="4" />
						<path d="M8.53448 14L4.0332 6" />
						<path d="M11.5 21.5L15.5 14" />
						<path d="M12 8H21" />
					</svg>
					<?php esc_html_e( 'Browser extensions', 'slim-seo' ); ?>
				</a>

				<a href="https://elu.to/ssd" target="_blank">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
						<path d="M5.33333 3.00001C7.79379 2.99657 10.1685 3.88709 12 5.5V21C10.1685 19.3871 7.79379 18.4966 5.33333 18.5C3.77132 18.5 2.99032 18.5 2.64526 18.2792C2.4381 18.1466 2.35346 18.0619 2.22086 17.8547C2 17.5097 2 16.8941 2 15.6629V6.40322C2 4.97543 2 4.26154 2.54874 3.68286C3.09748 3.10418 3.65923 3.07432 4.78272 3.0146C4.965 3.00491 5.14858 3.00001 5.33333 3.00001Z" />
						<path d="M18.6667 3.00001C16.2062 2.99657 13.8315 3.88709 12 5.5V21C13.8315 19.3871 16.2062 18.4966 18.6667 18.5C20.2287 18.5 21.0097 18.5 21.3547 18.2792C21.5619 18.1466 21.6465 18.0619 21.7791 17.8547C22 17.5097 22 16.8941 22 15.6629V6.40322C22 4.97543 22 4.26154 21.4513 3.68286C20.9025 3.10418 20.3408 3.07432 19.2173 3.0146C19.035 3.00491 18.8514 3.00001 18.6667 3.00001Z" />
					</svg>
					<?php esc_html_e( 'Docs', 'slim-seo' ); ?>
				</a>
				<a href="https://elu.to/ssfb" target="_blank">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
						<path d="M15 8C15 9.65685 13.6569 11 12 11C10.3431 11 9 9.65685 9 8C9 6.34315 10.3431 5 12 5C13.6569 5 15 6.34315 15 8Z" />
						<path d="M16 4C17.6569 4 19 5.34315 19 7C19 8.22309 18.2681 9.27523 17.2183 9.7423" />
						<path d="M13.7143 14H10.2857C7.91876 14 5.99998 15.9188 5.99998 18.2857C5.99998 19.2325 6.76749 20 7.71426 20H16.2857C17.2325 20 18 19.2325 18 18.2857C18 15.9188 16.0812 14 13.7143 14Z" />
						<path d="M17.7143 13C20.0812 13 22 14.9188 22 17.2857C22 18.2325 21.2325 19 20.2857 19" />
						<path d="M8 4C6.34315 4 5 5.34315 5 7C5 8.22309 5.73193 9.27523 6.78168 9.7423" />
						<path d="M3.71429 19C2.76751 19 2 18.2325 2 17.2857C2 14.9188 3.91878 13 6.28571 13" />
					</svg>
					<?php esc_html_e( 'Community', 'slim-seo' ); ?>
				</a>
			</h1>

			<div class="ss-content" id="poststuff">

				<form action="" method="post" class="ss-tabs">
					<nav class="ss-tab-list">
						<?php
						$tabs = apply_filters( 'slim_seo_settings_tabs', [] );
						foreach ( $tabs as $key => $label ) {
							printf( '<a href="#%s" class="ss-tab">%s</a>', esc_attr( $key ), esc_html( $label ) );
						}
						?>
					</nav>
					<?php
					wp_nonce_field( 'save' );
					$panes = apply_filters( 'slim_seo_settings_panes', [] );
					echo implode( '', $panes ); // @codingStandardsIgnoreLine.
					?>
				</form>

				<aside class="ss-sidebar">
					<?php if ( ! defined( 'SLIM_SEO_PRO_VER' ) ) : ?>
						<div class="ss-upgrade postbox">
							<h3 class="hndle"><?php esc_html_e( 'Advanced SEO features', 'slim-seo' ); ?></h3>
							<div class="inside">
								<p>
									<?php
									// Translators: %1$s - plugin URL, %2$s - plugin name.
									echo wp_kses_post( sprintf( __( 'Wanna advanced SEO features without complexity? Check out <a href="%1$s"><strong>%2$s</strong></a>, our powerful & lightweight pro version that has:', 'slim-seo' ), 'https://elu.to/ssp', 'Slim SEO Pro' ) );
									?>
								</p>
								<ul>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Visual schema builder', 'slim-seo' ) ?></li>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( '30+ pre-built schema types', 'slim-seo' ) ?></li>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Custom schema with JSON-LD', 'slim-seo' ) ?></li>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Contextual link suggestions', 'slim-seo' ) ?></li>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Real-time link health monitoring', 'slim-seo' ) ?></li>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Broken link repair', 'slim-seo' ) ?></li>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Link updater', 'slim-seo' ) ?></li>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Google Search Console integration', 'slim-seo' ) ?></li>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Writing assistant', 'slim-seo' ) ?></li>
									<li><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'And more...', 'slim-seo' ) ?></li>
								</ul>
								<a class="button button-primary" href="https://elu.to/ssp" target="_blank">
									<?php // Translators: %s - plugin name ?>
									<?php echo esc_html( sprintf( __( 'Get %s', 'slim-seo' ), 'Slim SEO Pro' ) ); ?> &rarr;
								</a>
							</div>
						</div>
					<?php endif ?>

					<div class="postbox">
						<h3 class="hndle"><?php esc_html_e( 'Our WordPress products', 'slim-seo' ) ?></h3>
						<div class="inside">
							<p><?php esc_html_e( 'Like this plugin? Check out our other WordPress products:', 'slim-seo' ) ?></p>
							<p><a href="https://elu.to/ssm" target="_blank"><strong>Meta Box</strong></a>: <?php esc_html_e( 'A framework for dynamic WordPress websites', 'slim-seo' ) ?></p>
							<p><a href="https://elu.to/ssf" target="_blank"><strong>Falcon</strong></a>: <?php esc_html_e( 'WordPress optimization & tweaks', 'slim-seo' ) ?></p>
						</div>
					</div>

					<div class="postbox">
						<h3 class="hndle">
							<span><?php esc_html_e( 'Write a review for Slim SEO', 'slim-seo' ) ?></span>
						</h3>
						<div class="inside">
							<p><?php esc_html_e( 'If you like Slim SEO, please write a review on WordPress.org to help us spread the word. We really appreciate that!', 'slim-seo' ) ?></p>
							<p><a href="https://elu.to/ssr" target="_blank"><?php esc_html_e( 'Write a review', 'slim-seo' ) ?> &rarr;</a></p>
						</div>
					</div>
				</aside>
			</div>

		</div>
		<?php
	}

	public static function enqueue(): void {
		Assets::enqueue_css( 'components' );
		Assets::enqueue_css( 'settings' );
		Assets::enqueue_js( 'settings' );
	}

	public static function save(): void {
		if ( empty( $_POST['submit'] ) || ! check_ajax_referer( 'save', false, false ) ) {
			return;
		}

		do_action( 'slim_seo_save' );

		add_settings_error( null, 'slim-seo', __( 'Settings updated.', 'slim-seo' ), 'success' );
	}
}
