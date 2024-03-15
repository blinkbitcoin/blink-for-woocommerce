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
    if (!$env || !$key) {
      return [];
    }

    $url = self::getUrl($env);
    if ($url) {
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
    if (!$env || !$apiKey) {
      Logger::debug( 'Invalid env or api key' );
      return false;
    }

    $config = self::getConfig();
    $url = self::getUrl($env);

    if ($url) {
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
      $scopes = $client->getAuthorizationScopes();
      $hasReceive = in_array('RECEIVE', $scopes);
      $hasWrite = in_array('WRITE', $scopes);
      Logger::debug( 'API key scopes: ' . print_r( $scopes, true ) );
      Logger::debug( 'End verifyApiKey with ' . $hasReceive || $hasWrite );
      return $hasReceive || $hasWrite;
    } catch (\Throwable $e) {
      Logger::debug('Error fetching user info: ' . $e->getMessage(), true);
      return false;
    }
  }
}
