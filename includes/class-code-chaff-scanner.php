<?php
/**
 * CodeChaff Security Scanner — Lightweight pre-scan before AI audit.
 *
 * Runs regex-based checks for common WordPress security patterns
 * and extracts code windows (±15 lines) around each finding.
 *
 * @package CodeChaff
 */

namespace CodeChaff;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CodeChaff Scanner class.
 */
class CodeChaff_Scanner {

	/**
	 * Scan a file's content for security issues.
	 *
	 * @param string $content  File content.
	 * @param string $rel_path Relative file path.
	 * @return array List of findings with code windows.
	 */
	public static function scan( $content, $rel_path ) {
		$lines    = explode( "\n", $content );
		$findings = array();

		self::scan_unescaped_output( $lines, $rel_path, $findings );
		self::scan_unsanitized_input( $lines, $rel_path, $findings );
		self::scan_direct_db_queries( $lines, $rel_path, $findings );
		self::scan_missing_nonce( $lines, $rel_path, $findings );
		self::scan_eval_exec( $lines, $rel_path, $findings );
		self::scan_file_inclusion( $lines, $rel_path, $findings );

		return $findings;
	}

	/**
	 * Check for unescaped output (echo/print without esc_*).
	 *
	 * @param array  $lines    File lines.
	 * @param string $rel_path File path.
	 * @param array  $findings Output findings array (modified in place).
	 * @return void
	 */
	private static function scan_unescaped_output( $lines, $rel_path, &$findings ) {
		foreach ( $lines as $i => $line ) {
			$trimmed = trim( $line );

			// Skip comments and empty lines.
			if ( '' === $trimmed || 0 === strpos( $trimmed, '//' ) || 0 === strpos( $trimmed, '#' ) || 0 === strpos( $trimmed, '*' ) ) {
				continue;
			}

			// echo/print without escaping on the same line.
			if ( preg_match( '/(?:^|[;{]\s*)(echo|print)\b/i', $trimmed, $m ) ) {
				// Check if esc_* or wp_kses appears on the same line (not before echo).
				$after_echo = substr( $trimmed, strpos( $trimmed, $m[0] ) + strlen( $m[0] ) );
				if (
					! preg_match( '/\b(esc_|wp_kses|wp_json_encode|wp_remote_retrieve_body)\b/', $after_echo ) &&
					! preg_match( '/\b__\(/', $trimmed ) && // translation functions are safe
					! preg_match( '/^\s*echo\s+(?:esc_|__\(|_e\(|_x\()/i', $trimmed ) // echo esc_*() or echo __()
				) {
					$findings[] = array(
						'file'     => $rel_path,
						'line'     => $i + 1,
						'rule'     => 'UnescapedOutput',
						'message'  => 'Output statement without visible escaping function on the same line.',
						'code'     => self::code_window( $lines, $i ),
					);
				}
			}
		}
	}

	/**
	 * Check for unsanitized superglobal access.
	 *
	 * @param array  $lines    File lines.
	 * @param string $rel_path File path.
	 * @param array  $findings Output findings array (modified in place).
	 * @return void
	 */
	private static function scan_unsanitized_input( $lines, $rel_path, &$findings ) {
		$superglobals = array( '$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_COOKIE', '$_FILES' );

		foreach ( $lines as $i => $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed || 0 === strpos( $trimmed, '//' ) || 0 === strpos( $trimmed, '#' ) || 0 === strpos( $trimmed, '*' ) ) {
				continue;
			}

			foreach ( $superglobals as $sg ) {
				if ( false !== strpos( $trimmed, $sg ) ) {
					// Skip if sanitization/validation happens on this or next line.
					$window_lines = array_slice( $lines, $i, 3 );
					$window_text  = implode( ' ', $window_lines );
					if (
						preg_match( '/\b(sanitize_|wp_unslash|absint|intval|filter_var|wp_verify_nonce|check_admin_referer|wp_kses)\b/', $window_text )
					) {
						continue;
					}
					$findings[] = array(
						'file'     => $rel_path,
						'line'     => $i + 1,
						'rule'     => 'UnsanitizedInput',
						'message'  => "{$sg} accessed without visible sanitization within a 3-line window.",
						'code'     => self::code_window( $lines, $i ),
					);
					break; // One finding per line.
				}
			}
		}
	}

	/**
	 * Check for direct database queries without prepare().
	 *
	 * @param array  $lines    File lines.
	 * @param string $rel_path File path.
	 * @param array  $findings Output findings array (modified in place).
	 * @return void
	 */
	private static function scan_direct_db_queries( $lines, $rel_path, &$findings ) {
		$methods = array( 'get_var', 'get_row', 'get_col', 'get_results', 'query' );

		$text = implode( "\n", $lines );

		foreach ( $methods as $method ) {
			if ( ! preg_match_all( '/\$wpdb->' . $method . '\s*\(/', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				$offset     = $match[1];
				$pre_offset = substr( $text, 0, $offset );
				$line_num   = substr_count( $pre_offset, "\n" );

				// Look ahead ~200 chars for prepare().
				$ahead = substr( $text, $offset, 200 );

				if ( ! preg_match( '/\$wpdb->prepare\s*\(/', $ahead ) && false === strpos( $ahead, '%s' ) && false === strpos( $ahead, '%d' ) && false === strpos( $ahead, '%i' ) ) {
					$findings[] = array(
						'file'     => $rel_path,
						'line'     => $line_num + 1,
						'rule'     => 'DirectDBQuery',
						'message'  => "\$wpdb->{$method}() called without visible \$wpdb->prepare() nearby.",
						'code'     => self::code_window( $lines, $line_num ),
					);
				}
			}
		}
	}

	/**
	 * Check for form/AJAX handlers missing nonce verification.
	 *
	 * @param array  $lines    File lines.
	 * @param string $rel_path File path.
	 * @param array  $findings Output findings array (modified in place).
	 * @return void
	 */
	private static function scan_missing_nonce( $lines, $rel_path, &$findings ) {
		$text = implode( "\n", $lines );

		// Find function definitions that look like handlers.
		if ( ! preg_match_all( '/function\s+(\w+)\s*\(/', $text, $func_matches, PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		$handler_names = array( 'save', 'update', 'delete', 'handle', 'process', 'import', 'export', 'upload' );

		foreach ( $func_matches[1] as $idx => $func_match ) {
			$func_name = $func_match[0];
			$is_handler = false;

			foreach ( $handler_names as $keyword ) {
				if ( false !== stripos( $func_name, $keyword ) ) {
					$is_handler = true;
					break;
				}
			}

			if ( ! $is_handler ) {
				continue;
			}

			// Get the function body (up to the matching closing brace — approximate).
			$func_start = $func_matches[0][ $idx ][1];
			$func_text  = substr( $text, $func_start, 2000 ); // First ~2000 chars of function.
			$line_num   = substr_count( substr( $text, 0, $func_start ), "\n" );

			// Skip if nonce check found within the function body.
			if ( preg_match( '/\b(wp_verify_nonce|check_ajax_referer|check_admin_referer|wp_create_nonce|wp_nonce_field)\b/', $func_text ) ) {
				continue;
			}

			// Skip if the function only reads data (no POST/GET/REQUEST access).
			if ( ! preg_match( '/\b\$_(?:POST|GET|REQUEST)\b/', $func_text ) ) {
				continue;
			}

			$findings[] = array(
				'file'     => $rel_path,
				'line'     => $line_num + 1,
				'rule'     => 'MissingNonce',
				'message'  => "Handler '{$func_name}()' processes input but has no visible nonce verification.",
				'code'     => self::code_window( $lines, $line_num ),
			);
		}
	}

	/**
	 * Check for eval() / exec() / system() calls.
	 *
	 * @param array  $lines    File lines.
	 * @param string $rel_path File path.
	 * @param array  $findings Output findings array (modified in place).
	 * @return void
	 */
	private static function scan_eval_exec( $lines, $rel_path, &$findings ) {
		$dangerous = array( 'eval(', 'exec(', 'system(', 'passthru(', 'shell_exec(', 'popen(' );

		foreach ( $lines as $i => $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '//' ) || 0 === strpos( $trimmed, '#' ) ) {
				continue;
			}
			foreach ( $dangerous as $fn ) {
				if ( false !== stripos( $trimmed, $fn ) ) {
					$findings[] = array(
						'file'     => $rel_path,
						'line'     => $i + 1,
						'rule'     => 'DangerousFunction',
						'message'  => "Potentially dangerous function '{$fn}' used.",
						'code'     => self::code_window( $lines, $i ),
					);
					break;
				}
			}
		}
	}

	/**
	 * Check for file inclusion using variables.
	 *
	 * @param array  $lines    File lines.
	 * @param string $rel_path File path.
	 * @param array  $findings Output findings array (modified in place).
	 * @return void
	 */
	private static function scan_file_inclusion( $lines, $rel_path, &$findings ) {
		$includes = array( 'include', 'include_once', 'require', 'require_once' );

		foreach ( $lines as $i => $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '//' ) || 0 === strpos( $trimmed, '#' ) || 0 === strpos( $trimmed, '*' ) ) {
				continue;
			}
			foreach ( $includes as $fn ) {
				if ( preg_match( '/\b' . $fn . '\s*\(\s*\$/', $trimmed ) ) {
					// Check if path is wrapped in plugin_dir_path or similar safe constructs.
					if ( preg_match( '/\b(plugin_dir_path|get_template_directory|ABSPATH|__DIR__|trailingslashit)\b/', $trimmed ) ) {
						continue;
					}
					$findings[] = array(
						'file'     => $rel_path,
						'line'     => $i + 1,
						'rule'     => 'VariableInclusion',
						'message'  => "File inclusion using a variable — verify the path cannot be user-controlled.",
						'code'     => self::code_window( $lines, $i ),
					);
					break;
				}
			}
		}
	}

	/**
	 * Extract a code window of ±15 lines around a flagged line.
	 *
	 * @param array $lines     All file lines.
	 * @param int   $line_idx  Zero-based index of the flagged line.
	 * @return string Code window with line numbers.
	 */
	public static function code_window( $lines, $line_idx ) {
		$start = max( 0, $line_idx - 15 );
		$end   = min( count( $lines ), $line_idx + 16 );
		$window = array();

		for ( $i = $start; $i < $end; $i++ ) {
			$marker   = ( $i === $line_idx ) ? '>>' : '  ';
			$line_num = $i + 1;
			$window[] = "{$marker} {$line_num}: " . $lines[ $i ];
		}

		return implode( "\n", $window );
	}
}