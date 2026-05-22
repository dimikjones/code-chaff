<?php
/**
 * Plugin Name:       CodeChaff
 * Description:       Separate the wheat from the chaff. This says you are stripping away the garbage from the update.
 * Plugin URI:        https://github.com/dimikjones/code-chaff
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Marko Dimitrijević
 * Author URI:        https://markocodes.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       code-chaff
 *
 * @package CodeChaff
 */

namespace CodeChaff;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// --- CONSTANTS ---
define( 'CODE_CHAFF_SETUP_DIR', __DIR__ );
define( 'CODE_CHAFF_SETUP_ROOT', __FILE__ );
define( 'CODE_CHAFF_SETUP_URL', plugin_dir_url( __FILE__ ) );
define( 'CODE_CHAFF_SETUP_CACHE_TIME_DAY', DAY_IN_SECONDS );
define( 'CODE_CHAFF_SETUP_VERSION', '0.1.0' );
