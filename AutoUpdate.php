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
 *
 * 注意: このクラスは名前空間 OmakaseSync 内にあるため、
 * WordPress関数やPHP組み込み関数を呼び出す際は、
 * グローバル名前空間から明示的に呼び出すため \ プレフィックスを使用する。
 */
class AutoUpdate {

	/**
	 * アップデートAPI URL
	 *
	 * @var string
	 */
	private $api_url;

	/**
	 * プラグインスラッグ
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * 現在のプラグインバージョン
	 *
	 * @var string
	 */
	private $version;

	/**
	 * コンストラクタ
	 *
	 * プラグイン情報を取得しアップデートフィルターを設定
	 */
	public function __construct() {
		// プラグインデータ取得関数の存在確認
		if ( ! function_exists( 'get_plugin_data' ) ) {
			return;
		}
		// プラグインディレクトリ名を取得
		$plugin_dir_name = dirname( OMAKASE_SYNC_BASENAME );
		// プラグインデータを取得
		$plugin_data = get_plugin_data( OMAKASE_SYNC_PATH . $plugin_dir_name . '.php' );

		// プロパティに値を設定
		$this->plugin_slug = $plugin_dir_name;
		$this->version     = $plugin_data['Version'];
		$this->api_url     = $plugin_data['UpdateURI'];

		// アップデート確認フィルターを追加
		add_filter( 'site_transient_update_plugins', array( $this, 'check_for_plugin_update' ) );
	}

	/**
	 * プラグインアップデート確認処理
	 *
	 * @param object $transient アップデート情報オブジェクト
	 * @return object 更新されたアップデート情報オブジェクト
	 */
	public function check_for_plugin_update( $transient ) {
		// チェック対象が空の場合は処理終了
		if ( empty( $transient->checked ) ) {
			return $transient;
		}
		// キャッシュ確認
		$cache_key    = 'omakase_sync_plugin_check';
		$api_response = get_site_transient( $cache_key );

		// キャッシュが存在しない場合はAPI呼び出し
		if ( false === $api_response ) {
			// APIからアップデート情報を取得
			$response = wp_remote_get( $this->api_url );
			// レスポンス正常確認
			if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				// JSONをデコード
				$api_response = json_decode( wp_remote_retrieve_body( $response ), true );
				// 6時間キャッシュ保存
				set_site_transient( $cache_key, $api_response, 6 * HOUR_IN_SECONDS );
			}
		}

		// API応答が有効かつ配列形式の場合のアップデート情報確認
		if ( $api_response && is_array( $api_response ) && isset( $api_response['version'] ) && isset( $api_response['package'] ) ) {
			// バージョン比較：現在より新しい場合
			if ( version_compare( $this->version, $api_response['version'], '<' ) ) {
				// アップデート情報を作成
				$plugin_data = array(
					'slug'        => $this->plugin_slug,
					'new_version' => $api_response['version'],
					'package'     => $api_response['package'],
				);
				// トランジェントにアップデート情報を設定
				$transient->response[ OMAKASE_SYNC_BASENAME ] = (object) $plugin_data;
			}
		}
		return $transient;
	}
}
