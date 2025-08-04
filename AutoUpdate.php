<?php
/**
 * Auto Update
 *
 * @package Omakase_Sync
 * @license GPL‑2.0-or-later
 */

namespace OmakaseSync;

/**
 * アップデートの有無の検知及び実施
 */
class AutoUpdate {
	private $api_url;
    private $plugin_slug;
    private $version;

    public function __construct() {
        $plugin_dir_name =  dirname(OMAKASE_SYNC_BASENAME);
		$plugin_data = \get_plugin_data(OMAKASE_SYNC_PATH . $plugin_dir_name . '.php');

        $this->plugin_slug = $plugin_dir_name;
        $this->version = $plugin_data['Version'];
        $this->api_url = $plugin_data['UpdateURI'];

        \add_filter('site_transient_update_plugins', [$this, 'check_for_plugin_update']);
    }

    public function check_for_plugin_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        // Check cache
        $cache_key = 'omakase_sync_plugin_check';
        $api_response = \get_site_transient($cache_key);

        if ($api_response === false) {
            // Only request API if cache does not exist
            $response = \wp_remote_get($this->api_url);
            if (!\is_wp_error($response) && \wp_remote_retrieve_response_code($response) === 200) {
                $api_response = \json_decode(\wp_remote_retrieve_body($response), true);
                // Save cache for 6 hours
                \set_site_transient($cache_key, $api_response, 6 * HOUR_IN_SECONDS);
            }
        }

        // Check update information if API response is valid
        if ($api_response) {
            if (\version_compare($this->version, $api_response['version'], '<')) {
                $plugin_data = [
                    'slug'        => $this->plugin_slug,
                    'new_version' => $api_response['version'],
                    'package'     => $api_response['package'],
                ];
                $transient->response[OMAKASE_SYNC_BASENAME] = (object) $plugin_data;
            }
        }
        return $transient;
    }
}
