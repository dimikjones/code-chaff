<?php
/**
 * CodeChaff Settings for AI Provider selection.
 *
 * @package CodeChaff
 */

namespace CodeChaff;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CodeChaff Settings class.
 */
class CodeChaff_Settings {

	/**
	 * Option name for storing the selected AI provider.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'code_chaff_ai_provider';

	/**
	 * Register admin menu and settings.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Add the CodeChaff admin menu.
	 *
	 * @return void
	 */
	public static function add_admin_menu() {
		add_menu_page(
			__( 'CodeChaff', 'code-chaff' ),
			__( 'CodeChaff', 'code-chaff' ),
			'manage_options',
			'code-chaff',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-shield',
			80
		);

		add_submenu_page(
			'code-chaff',
			__( 'Settings', 'code-chaff' ),
			__( 'Settings', 'code-chaff' ),
			'manage_options',
			'code-chaff',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'code_chaff_settings',
			self::OPTION_NAME,
			array(
				'type'              => 'string',
				'description'       => __( 'Selected AI provider for CodeChaff audits.', 'code-chaff' ),
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$selected_provider = get_option( self::OPTION_NAME, '' );
		$providers         = CodeChaff::get_available_providers();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'code_chaff_settings' );
				do_settings_sections( 'code_chaff_settings' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_NAME ); ?>">
								<?php esc_html_e( 'AI Provider', 'code-chaff' ); ?>
							</label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>" id="<?php echo esc_attr( self::OPTION_NAME ); ?>">
								<option value=""><?php esc_html_e( '— Select a provider —', 'code-chaff' ); ?></option>
								<?php foreach ( $providers as $provider_id => $provider_name ) : ?>
									<option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $selected_provider, $provider_id ); ?>>
										<?php echo esc_html( $provider_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php
								if ( empty( $providers ) ) {
									esc_html_e( 'No AI providers are available. Please install and activate an AI provider plugin (e.g., AI Provider for DeepSeek).', 'code-chaff' );
								} else {
									esc_html_e( 'Choose the AI provider that will be used for update audits.', 'code-chaff' );
								}
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
