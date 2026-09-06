<?php
/**
 * Uninstall
 *
 * プラグイン削除時に保存済みのクレデンシャルとキャッシュを消去する。
 *
 * @package Omakase_Sync
 * @license GPL-2.0-or-later
 */

// WordPressのアンインストール処理以外からの直接アクセスを拒否する.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * 単一サイト分のオプションを削除する
 *
 * @return void
 */
function omakase_sync_delete_site_options() {
	delete_option( 'omakase_site_id' );
	delete_option( 'omakase_api_key' );
}

// スケジュール済みイベントを解除する.
$omakase_sync_timestamp = wp_next_scheduled( 'omakase_hourly_sync_event' );
if ( $omakase_sync_timestamp ) {
	wp_unschedule_event( $omakase_sync_timestamp, 'omakase_hourly_sync_event' );
}
wp_clear_scheduled_hook( 'omakase_hourly_sync_event' );

// 自動更新のキャッシュを削除する（サイト単位ではなくネットワーク単位で保存されている）.
delete_site_transient( 'omakase_sync_plugin_check' );

if ( is_multisite() ) {
	// マルチサイトでは各サイトのオプションを個別に削除する必要がある.
	$omakase_sync_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $omakase_sync_site_ids as $omakase_sync_blog_id ) {
		switch_to_blog( $omakase_sync_blog_id );
		omakase_sync_delete_site_options();
		restore_current_blog();
	}
} else {
	omakase_sync_delete_site_options();
}
