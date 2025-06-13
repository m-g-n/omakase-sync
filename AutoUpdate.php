<?php
/**
 * Auto Update （use Inc2734/WP_GitHub_Plugin_Updater）
 *
 * @package Omakase_Sync
 * @license GPL‑2.0-or-later
 */

namespace OmakaseSync;

use Inc2734\WP_GitHub_Plugin_Updater\Bootstrap as Updater;

/**
 * アップデートの有無の検知及び実施
 */
class AutoUpdate {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'activate_autoupdate' ) );
	}

	/**
	 * Activate auto update using GitHub,
	 *
	 * @return void
	 */
	public function activate_autoupdate() {
		new Updater(
			plugin_basename(__FILE__),
			'm-g-n',
			'omakase-sync',
			[
				'description_url' => 'https://www.m-g-n.me',
				'faq_url' => 'https://www.m-g-n.me',
				'changelog_url' => 'https://github.com/m-g-n/omakase-sync/',
				'tested' => '6.8.1', // Tested up WordPress version
				'requires_php' => '8.0.0', // Requires PHP version
				'requires' => '6.3', // Requires WordPress version
			]
		);
	}
}
