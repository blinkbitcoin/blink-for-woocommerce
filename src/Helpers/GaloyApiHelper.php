<?php

declare(strict_types=1);

namespace Blink\WC\Helpers;

use Blink\WC\Admin\Notice;
use Blink\WC\Helpers\GaloyApiClient;

class GaloyApiHelper {
  public $configured = false;
	public $env;
	public $apiKey;

	public function __construct() {
		if ($config = self::getConfig()) {
			$this->url = $config['env'];
			$this->apiKey = $config['api_key'];
			$this->configured = true;
		}
	}

  public static function getUrl(string $env = null): string {
    $urlMapping = [
      'blink' => 'https://api.blink.sv/graphql',
      'staging' => 'https://api.staging.galoy.io/graphql',
    ];
    if ($env && isset($urlMapping[$env])) {
      return $urlMapping[$env];
    }
    return $urlMapping['blink'];
  }

  public static function getConfig(): array {
    $env = get_option('galoy_blink_env');
    $key = get_option('galoy_blink_api_key');
    $url = self::getUrl($env);

    if ($env && $key && $url) {
      return [
        'env' => $env,
        'api_key' => $key,
        'url' => $url,
      ];
    }

    return [];
  }


  public static function verifyApiKey(string $env = null, string $apiKey = null): bool {
    Logger::debug( 'Start verifyApiKey' );

		$config = self::getConfig();
		$url = self::getUrl($env);

		if ($env && $apiKey && $url) {
      $config['env'] = $env;
			$config['url'] = $url;
			$config['api_key'] = $apiKey;
		}

		if (!$config) {
      Logger::debug( 'Invalid config', true );
      return false;
		}

    try {
      $client = new GaloyApiClient( $config['url'], $config['api_key'] );
      $info = $client->getUserInfo();
      Logger::debug( 'User info: ' . print_r( $info, true ) );
      Logger::debug( 'End verifyApiKey with ' . !!$info );
      return !!$info;
    } catch (\Throwable $e) {
      Logger::debug('Error fetching user info: ' . $e->getMessage(), true);
      return false;
    }
	}
}
