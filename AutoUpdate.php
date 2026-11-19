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
	 * 更新情報（update.json の中身）を保存するキャッシュのキー
	 *
	 * uninstall.php でも同じキーを削除しているため、変える場合は両方を揃えること。
	 *
	 * @var string
	 */
	const UPDATE_INFO_CACHE_KEY = 'omakase_sync_plugin_check';

	/**
	 * WordPress の更新確認にこのプラグインの更新情報を渡す
	 *
	 * プラグインヘッダーの Update URI のホスト名ごとに WordPress が呼ぶ
	 * update_plugins_{hostname} フィルターのコールバック。
	 * 返した情報を WordPress が現在のバージョンと比べ、新しければ「更新あり」、
	 * そうでなければ「最新」として記録する。WP-Cron の自動更新からも呼ばれる。
	 *
	 * @param array|false $update      他の処理が返した更新情報。未設定なら false。
	 * @param array       $plugin_data プラグインヘッダーの情報（Version・UpdateURI など）。
	 * @param string      $plugin_file プラグインのパス（plugins ディレクトリからの相対パス）。
	 * @return array|false このプラグインの更新情報。取得できなければ $update をそのまま返す。
	 */
	public static function filter_update_info( $update, $plugin_data, $plugin_file ) {
		// 同じホストを Update URI に使う別のプラグインには手を出さない
		if ( OMAKASE_SYNC_BASENAME !== $plugin_file ) {
			return $update;
		}

		$api_url = isset( $plugin_data['UpdateURI'] ) ? $plugin_data['UpdateURI'] : '';
		if ( ! \is_string( $api_url ) || '' === $api_url ) {
			return $update;
		}

		// update.json を取得し、必要な項目がそろっているかを確認する
		$update_info = self::fetch_update_info( $api_url );
		if ( ! \is_array( $update_info )
			|| empty( $update_info['version'] ) || ! \is_string( $update_info['version'] )
			|| empty( $update_info['package'] ) ) {
			return $update;
		}

		// update.json が改ざんされても任意のZIPを入れないよう、配布元を検証する
		if ( ! self::is_trusted_package_url( $update_info['package'] ) ) {
			return $update;
		}

		// WordPress に渡す更新情報を組み立てる
		$result = array(
			'slug'    => \dirname( OMAKASE_SYNC_BASENAME ),
			'version' => $update_info['version'],
			'package' => $update_info['package'],
		);
		if ( ! empty( $update_info['url'] ) && \is_string( $update_info['url'] ) ) {
			$result['url'] = \esc_url_raw( $update_info['url'] );
		}

		return $result;
	}

	/**
	 * update.json を取得する（結果はキャッシュする）
	 *
	 * @param string $api_url update.json のURL。
	 * @return array|null デコードした update.json の中身。取得・解析に失敗した場合は空配列か null。
	 */
	private static function fetch_update_info( $api_url ) {
		// キャッシュがあればそれを使う
		$update_info = \get_site_transient( self::UPDATE_INFO_CACHE_KEY );
		if ( false !== $update_info ) {
			return $update_info;
		}

		// update.json を取得する
		$response = \wp_remote_get(
			$api_url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
			)
		);

		if ( ! \is_wp_error( $response ) && 200 === \wp_remote_retrieve_response_code( $response ) ) {
			// 成功時は6時間キャッシュする
			$update_info = \json_decode( \wp_remote_retrieve_body( $response ), true );
			\set_site_transient( self::UPDATE_INFO_CACHE_KEY, $update_info, 6 * HOUR_IN_SECONDS );
		} else {
			// 失敗時も短時間キャッシュし、更新確認のたびに再試行しないようにする
			$update_info = array();
			\set_site_transient( self::UPDATE_INFO_CACHE_KEY, $update_info, 15 * MINUTE_IN_SECONDS );
		}

		return $update_info;
	}

	/**
	 * 更新時の展開ディレクトリ名を現在のプラグインディレクトリ名に揃える
	 *
	 * WordPressはZIP直下に単一フォルダがない場合、一時展開ディレクトリ名
	 * （例: omakase-sync-31HQlffLKfPSWo5k）をそのままプラグインディレクトリ名に使う。
	 * その結果、更新のたびにディレクトリ名が変わり有効化状態も外れるため、
	 * このプラグインの更新に限り展開元を現在のディレクトリ名へ移し替える。
	 *
	 * @param string|\WP_Error $source        展開されたソースのパス。
	 * @param string           $remote_source 一時展開ディレクトリのパス。
	 * @param \WP_Upgrader     $upgrader      アップグレーダーインスタンス。
	 * @param array            $hook_extra    追加情報。
	 * @return string|\WP_Error 修正後のソースパス、またはエラー。
	 */
	public static function fix_source_directory( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( \is_wp_error( $source ) ) {
			return $source;
		}

		// このプラグインの更新以外は対象外
		if ( ! \is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || OMAKASE_SYNC_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		if ( ! \is_object( $wp_filesystem ) ) {
			return $source;
		}

		$expected_dir_name = \dirname( OMAKASE_SYNC_BASENAME );
		// 単一ファイルプラグイン扱い（'.'）や不正値の場合は何もしない
		if ( '.' === $expected_dir_name || '' === $expected_dir_name ) {
			return $source;
		}

		$remote_source = \untrailingslashit( $remote_source );
		$source_path   = \untrailingslashit( $source );
		$expected_path = $remote_source . '/' . $expected_dir_name;

		// 既に期待するディレクトリ名なら何もしない
		if ( $source_path === $expected_path ) {
			return \trailingslashit( $expected_path );
		}

		if ( $wp_filesystem->exists( $expected_path ) ) {
			return new \WP_Error(
				'omakase_sync_source_exists',
				\sprintf( 'Omakase Sync: 展開先ディレクトリ %s が既に存在するため更新を中止しました。', $expected_dir_name )
			);
		}

		if ( $source_path === $remote_source ) {
			// ZIP直下にファイルが直接置かれている場合: サブディレクトリを作って中身を移す
			$entries = $wp_filesystem->dirlist( $remote_source );
			if ( ! \is_array( $entries ) || ! $wp_filesystem->mkdir( $expected_path, FS_CHMOD_DIR ) ) {
				return new \WP_Error(
					'omakase_sync_source_mkdir_failed',
					'Omakase Sync: 更新ファイルの展開ディレクトリを作成できませんでした。'
				);
			}
			foreach ( \array_keys( $entries ) as $entry ) {
				if ( ! $wp_filesystem->move( $remote_source . '/' . $entry, $expected_path . '/' . $entry ) ) {
					return new \WP_Error(
						'omakase_sync_source_move_failed',
						\sprintf( 'Omakase Sync: 更新ファイル %s を移動できませんでした。', $entry )
					);
				}
			}
		} elseif ( ! $wp_filesystem->move( $source_path, $expected_path ) ) {
			// ZIP直下のフォルダ名が異なる場合: フォルダ名を変更する
			return new \WP_Error(
				'omakase_sync_source_rename_failed',
				'Omakase Sync: 更新ファイルの展開ディレクトリ名を変更できませんでした。'
			);
		}

		return \trailingslashit( $expected_path );
	}

	/**
	 * 配布パッケージURLが信頼できるホストかを判定する
	 *
	 * update.json が改ざんされた場合、任意のZIPがプラグインディレクトリに
	 * 展開・実行されるため、スキームとホストを検証する。
	 *
	 * @param mixed $package_url 検証対象のURL。
	 * @return bool 信頼できる場合 true。
	 */
	private static function is_trusted_package_url( $package_url ) {
		if ( ! is_string( $package_url ) || '' === $package_url ) {
			return false;
		}

		$parts = wp_parse_url( $package_url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		// 平文HTTPやfile://などを拒否する
		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}

		// GitHubのリリース配信に使われるホストのみを許可する
		$allowed_hosts = array(
			'github.com',
			'objects.githubusercontent.com',
			'codeload.github.com',
		);

		return in_array( strtolower( $parts['host'] ), $allowed_hosts, true );
	}
}
