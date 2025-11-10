<?php
/*
Plugin Name: Omakase Sync
Plugin URI:  https://www.m-g-n.me
Description: 親サーバへWP情報を定期送信
Version:     0.2.20
Author:      megane9988
License:     GPLv2 or later
Text Domain: omakase-sync
Update URI: https://m-g-n.github.io/omakase-sync/update.json
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 定数の宣言
define( 'OMAKASE_SYNC_PATH', untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/' ); //このプラグインのパス.
define('OMAKASE_SYNC_BASENAME', plugin_basename(__FILE__));

/**
 * プラグインのアップデートを管理するクラス
 * GitHubページのjsonから最新のリリース情報を取得し、WordPressのアップデート画面に表示する
 */
require_once OMAKASE_SYNC_PATH . 'AutoUpdate.php';
// 管理画面内のみAutoUpdateを実行
add_action(
	'admin_init',
	function () {
		new OmakaseSync\AutoUpdate();
	}
);

/**
 * CRONスケジュール・イベントの自動修復を設定
 */
add_action( 'plugins_loaded', 'omakase_setup_cron_recovery' );
function omakase_setup_cron_recovery() {
	// admin_init は管理画面でのみ実行されるが、頻度も適度で最も確実に実行される
	add_action( 'admin_init', 'omakase_verify_cron_setup' );
	
	// フロントエンドでも機会的に確認する (毎回ではなく低頻度で)
	if ( ! is_admin() && mt_rand( 1, 100 ) <= 5 ) { // 5% の確率で実行
		add_action( 'wp_loaded', 'omakase_verify_cron_setup' );
	}
}

/**
 * 5分ごとのCronスケジュールを追加
 */
add_filter( 'cron_schedules', 'omakase_add_five_minutes_cron' );
function omakase_add_five_minutes_cron( $schedules ) {
	// 'every_five_minutes' というキーで5分ごとのスケジュールを登録
	$schedules['every_five_minutes'] = array(
		'interval' => 300,            // 5分 (秒)
		'display'  => __( 'Every Five Minutes' ),
	);
	return $schedules;
}

/**
 * CRONの設定を確認・修復する
 * - 'every_five_minutes' スケジュールが存在するか確認
 * - 'omakase_hourly_sync_event' イベントが予約されているか確認
 * - 問題があれば再登録する
 */
function omakase_verify_cron_setup() {
	// プラグインが有効かどうか確認するため、必要な関数を読み込む
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	
	// プラグインが有効でない場合は何もしない
	if ( ! is_plugin_active( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	$recovery_needed = false;
	
	// 既存のCRONスケジュールを取得
	$schedules = wp_get_schedules();
	
	// 'every_five_minutes'スケジュールが存在しない場合、追加する
	if ( ! isset( $schedules['every_five_minutes'] ) ) {
		// filter_hooksでcron_schedulesに登録されたフィルターを取得
		global $wp_filter;
		if ( ! isset( $wp_filter['cron_schedules'] ) || ! has_filter( 'cron_schedules', 'omakase_add_five_minutes_cron' ) ) {
			// フィルターが存在しない場合は再登録
			add_filter( 'cron_schedules', 'omakase_add_five_minutes_cron' );
			$recovery_needed = true;
			error_log( 'Omakase Sync: Cron schedule "every_five_minutes" was missing and has been re-registered.' );
		}
	}

	// 'omakase_hourly_sync_event'イベントが予約されていない場合、登録する
	if ( ! wp_next_scheduled( 'omakase_hourly_sync_event' ) ) {
		wp_schedule_event( time(), 'every_five_minutes', 'omakase_hourly_sync_event' );
		$recovery_needed = true;
		error_log( 'Omakase Sync: Cron event "omakase_hourly_sync_event" was missing and has been re-scheduled.' );
	}
	
	// 修復が必要だった場合、WP-Cronのスケジュールを更新する
	if ( $recovery_needed ) {
		wp_clear_scheduled_hook( 'wp_cron_events_clean' ); // 念のため清掃イベントを再設定
	}
}

/**
 * プラグイン有効化時の処理
 *  - 5分ごとのWP-Cronスケジュールイベントを登録
 */
register_activation_hook( __FILE__, 'omakase_register_cron_event' );
function omakase_register_cron_event() {
	if ( ! wp_next_scheduled( 'omakase_hourly_sync_event' ) ) {
		// スケジュールの間隔を 'every_five_minutes' に変更
		wp_schedule_event( time(), 'every_five_minutes', 'omakase_hourly_sync_event' );
	}
}

/**
 * プラグイン無効化時の処理
 *  - スケジュールイベントを削除
 */
register_deactivation_hook( __FILE__, 'omakase_clear_cron_event' );
function omakase_clear_cron_event() {
	$timestamp = wp_next_scheduled( 'omakase_hourly_sync_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'omakase_hourly_sync_event' );
	}
}

/**
 * 5分ごとに呼ばれる関数
 *  - 親サーバへWordPressバージョン、プラグイン一覧を送信
 */
add_action( 'omakase_hourly_sync_event', 'omakase_send_site_data_to_parent' );
function omakase_send_site_data_to_parent() {
	// 設定したSite IDとAPI Keyを取得
	$site_id = get_option( 'omakase_site_id' );
	$api_key = get_option( 'omakase_api_key' );

	// 未設定の場合は送信しない
	if ( empty( $site_id ) || empty( $api_key ) ) {
		return;
	}

	// WordPressバージョン
	$wordpress_version = get_bloginfo( 'version' );

	// プラグイン情報を取得
	if ( ! function_exists( 'get_plugins' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$all_plugins  = get_plugins();
	$plugins_data = array();

	foreach ( $all_plugins as $plugin_file => $plugin_info ) {
		// slugをディレクトリ名から簡易的に推定
		$slug_parts = explode( '/', $plugin_file );
		$slug       = isset( $slug_parts[0] ) ? $slug_parts[0] : '';

		// プラグインが有効かどうか
		$is_active = is_plugin_active( $plugin_file );

		$plugins_data[] = array(
			'name'      => isset( $plugin_info['Name'] ) ? $plugin_info['Name'] : '',
			'slug'      => $slug,
			'version'   => isset( $plugin_info['Version'] ) ? $plugin_info['Version'] : '',
			'is_active' => $is_active,
		);
	}

	// 送信データ組み立て
	$body = array(
		'wordpress_version' => $wordpress_version,
		'plugins'           => $plugins_data,
	);

	// 親サーバのエンドポイント (例)
	$url = 'https://manage.megane9988.com/api/v1/sites/' . intval( $site_id ) . '/sync';

	// リクエストヘッダ
	$headers = array(
		'Content-Type' => 'application/json; charset=UTF-8',
		'X-TOKEN'      => $api_key,
	);

	// WordPress組み込みのHTTP APIでPOST送信
	$response = wp_remote_post(
		$url,
		array(
			'headers' => $headers,
			'body'    => json_encode( $body ),
			'timeout' => 30,
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( 'Omakase Sync Error: ' . $response->get_error_message() );
	} else {
		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			error_log( 'Omakase Sync Error: Unexpected response code: ' . $status_code );
			error_log( 'Omakase Sync Error: Response Body: ' . wp_remote_retrieve_body( $response ) );
		}
	}
}

/**
 * 管理画面メニューの追加
 */
add_action( 'admin_menu', 'omakase_add_admin_menu' );
function omakase_add_admin_menu() {
	add_options_page(
		'Omakase Sync設定',
		'Omakase Sync設定',
		'manage_options',
		'omakase-sync-settings',
		'omakase_settings_page'
	);
}

/**
 * 管理画面にフォームを表示するコールバック
 */
function omakase_settings_page() {
	// フォームから送信された場合は保存処理を行う
	if ( isset( $_POST['omakase_save_settings'] ) ) {
		check_admin_referer( 'omakase_settings_nonce' );

		$site_id = sanitize_text_field( $_POST['omakase_site_id'] );
		$api_key = sanitize_text_field( $_POST['omakase_api_key'] );

		update_option( 'omakase_site_id', $site_id );
		update_option( 'omakase_api_key', $api_key );

		echo '<div class="updated"><p>設定を保存しました。</p></div>';
	}

	$current_site_id = get_option( 'omakase_site_id', '' );
	$current_api_key = get_option( 'omakase_api_key', '' );

	?>
	<div class="wrap">
		<h1>Omakase Sync 設定</h1>
		<form method="post" action="">
			<?php wp_nonce_field( 'omakase_settings_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="omakase_site_id">Site ID</label>
					</th>
					<td>
						<input type="text" name="omakase_site_id" id="omakase_site_id"
							value="<?php echo esc_attr( $current_site_id ); ?>"
							class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="omakase_api_key">API Key</label>
					</th>
					<td>
						<input type="text" name="omakase_api_key" id="omakase_api_key"
							value="<?php echo esc_attr( $current_api_key ); ?>"
							class="regular-text" />
					</td>
				</tr>
			</table>
			<?php submit_button( '保存', 'primary', 'omakase_save_settings' ); ?>
		</form>
	</div>
	<?php
}
