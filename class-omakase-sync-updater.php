<?php
/**
 * GitHub Updater for the Omakase Sync plugin
 *
 * Public repo edition
 * Simple implementation for checking updates from public GitHub repositories
 *
 * @package Omakase_Sync
 * @license GPL‑2.0-or-later
 */

if ( ! class_exists( 'Omakase_Sync_Updater' ) ) {

	class Omakase_Sync_Updater {

		private string $plugin_slug;
		private array $plugin_data;
		private string $username = 'megane9988';
		private string $repo     = 'omakase-sync';
		private string $plugin_file;
		private ?object $github_api_result = null;

		public function __construct( string $plugin_file ) {
			$this->plugin_file = $plugin_file;

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'set_transient' ) );
			add_filter( 'plugins_api', array( $this, 'set_plugin_info' ), 10, 3 );
			add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				WP_CLI::add_command( 'omakase-sync check-update', array( $this, 'cli_check_update' ) );
				WP_CLI::add_command( 'omakase-sync debug', array( $this, 'cli_debug' ) );
			}
		}

		public function refresh(): bool {
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
			$transient = get_site_transient( 'update_plugins' );
			return isset( $transient->response[ $this->plugin_slug ] );
		}

		public function is_update_available(): bool {
			$this->init_plugin_data();
			$this->get_repository_info();
			return ( ! empty( $this->github_api_result ) && version_compare( $this->github_api_result->tag_name, $this->plugin_data['Version'], '>' ) );
		}

		public function cli_check_update(): void {
			WP_CLI::log( $this->is_update_available() ? 'Update available.' : 'No update.' );
		}

		public function cli_debug(): void {
			$this->init_plugin_data();
			$this->get_repository_info( true );

			if ( empty( $this->github_api_result ) ) {
				WP_CLI::error( 'Cannot retrieve release info. Check repository access.' );
			}

			$package = $this->get_package_download_url();

			WP_CLI::log( sprintf( 'Repo         : %s/%s', $this->username, $this->repo ) );
			WP_CLI::log( sprintf( 'Local ver.   : %s', $this->plugin_data['Version'] ) );
			WP_CLI::log( sprintf( 'Latest tag   : %s', $this->github_api_result->tag_name ?? 'n/a' ) );
			WP_CLI::log( sprintf( 'Package URL  : %s', $package ?: '(not found)' ) );
			WP_CLI::log( sprintf( 'Assets found : %d', count( $this->github_api_result->assets ?? array() ) ) );
			WP_CLI::success( $this->is_update_available() ? 'Update available' : 'Up‑to‑date' );
		}

		private function init_plugin_data(): void {
			if ( empty( $this->plugin_data ) ) {
				$this->plugin_slug = plugin_basename( $this->plugin_file );
				$this->plugin_data = get_plugin_data( $this->plugin_file );
			}
		}

		private function get_repository_info( bool $force = false ): void {
			if ( ! $force && $this->github_api_result ) {
				return;
			}
			$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases', $this->username, $this->repo );
			$response = wp_remote_get(
				$url,
				array(
					'headers' => array( 'User-Agent' => 'WordPress' ),
					'timeout' => 20,
				)
			);

			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$releases                = json_decode( wp_remote_retrieve_body( $response ) );
				$this->github_api_result = $releases[0] ?? null;
			}
		}

		private function get_package_download_url(): string {
			if ( ! $this->github_api_result ) {
				return '';
			}
			// まず 'omakase-sync.zip' という固定名のファイルを探す
			foreach ( $this->github_api_result->assets ?? array() as $asset ) {
				if ( isset( $asset->browser_download_url ) && $asset->name === 'omakase-sync.zip' ) {
					return $asset->browser_download_url;
				}
			}
			// 固定名がなければ、拡張子が .zip のファイルを探す（既存の挙動）
			foreach ( $this->github_api_result->assets ?? array() as $asset ) {
				if ( isset( $asset->browser_download_url ) && str_ends_with( $asset->name, '.zip' ) ) {
					return $asset->browser_download_url;
				}
			}
			return $this->github_api_result->zipball_url ?? '';
		}

		public function set_transient( $transient ) {
			if ( empty( $transient->checked ) ) {
				return $transient;
			}
			$this->init_plugin_data();
			$this->get_repository_info();

			if ( $this->is_update_available() ) {
				$package = $this->get_package_download_url();
				if ( ! $package ) {
					return $transient; // no file
				}
				$info                                      = new stdClass();
				$info->slug                                = $this->plugin_slug;
				$info->new_version                         = $this->github_api_result->tag_name;
				$info->url                                 = $this->plugin_data['PluginURI'];
				$info->package                             = $package;
				$transient->response[ $this->plugin_slug ] = $info;
			}
			return $transient;
		}

		public function set_plugin_info( $false, $action, $response ) {
			if ( 'plugin_information' !== $action ) {
				return $false;
			}
			$this->init_plugin_data();
			$this->get_repository_info();

			if ( empty( $response->slug ) || $response->slug !== $this->plugin_slug ) {
				return $false;
			}

			$response->name          = $this->plugin_data['Name'];
			$response->version       = $this->github_api_result->tag_name ?? $this->plugin_data['Version'];
			$response->last_updated  = $this->github_api_result->published_at ?? current_time( 'mysql' );
			$response->homepage      = $this->plugin_data['PluginURI'];
			$response->sections      = array( 'description' => $this->plugin_data['Description'] );
			$response->download_link = $this->get_package_download_url();
			return $response;
		}

		public function post_install( $true, $hook_extra, $result ) {
			// Ensure slug/data are available even in fresh context after unzip.
			$this->init_plugin_data();

			global $wp_filesystem;
			$plugin_folder = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );

			// Move unpacked directory to final destination.
			$wp_filesystem->move( $result['destination'], $plugin_folder );
			$result['destination'] = $plugin_folder;

			// アップデート通知を消すためにトランジェントをクリア
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();

			// Reactivate plugin if it was active.
			if ( is_plugin_active( $this->plugin_slug ) ) {
				activate_plugin( $this->plugin_slug );
			}

			return $result;
		}
	}
}
