<?php

declare(strict_types=1);

namespace Blink\WC\Helpers;

use Blink\WC\Admin\Notice;
use Blink\WC\Helpers\BlinkApiClient;

class BlinkApiHelper {
  public $configured = false;
  public $env;
  public $apiKey;
  public $walletType;

  public function __construct() {
    if ($config = self::getConfig()) {
      $this->env = $config['env'];
      $this->apiKey = $config['api_key'];
      $this->walletType = $config['wallet_type'];
      $this->configured = true;
    }
  }

  public static function getApiUrl(string $env = null): string {
    $urlMapping = [
      'blink' => 'https://api.blink.sv/graphql',
      'staging' => 'https://api.staging.galoy.io/graphql',
    ];
    if ($env && isset($urlMapping[$env])) {
      return $urlMapping[$env];
    }
    return $urlMapping['blink'];
  }

  public static function getPayUrl(string $env = null): string {
    $urlMapping = [
      'blink' => 'https://pay.blink.sv',
      'staging' => 'https://pay.staging.galoy.io',
    ];
    if ($env && isset($urlMapping[$env])) {
      return $urlMapping[$env];
    }
    return $urlMapping['blink'];
  }

  public static function getConfig(): array {
    $env = get_option('blink_env');
    $key = get_option('blink_api_key');
    $walletType = get_option('blink_wallet_type');
    if (!$env || !$key || !$walletType) {
      return [];
    }

    $url = self::getApiUrl($env);
    if ($url) {
      return [
        'env' => $env,
        'api_key' => $key,
        'wallet_type' => $walletType,
        'url' => $url,
      ];
    }

    return [];
  }

  public static function verifyApiKey(string $env = null, string $apiKey = null): bool {
    Logger::debug('Start verifyApiKey');
    if (!$env || !$apiKey) {
      Logger::debug('Invalid env or api key');
      return false;
    }

    $config = self::getConfig();
    $url = self::getApiUrl($env);

    if ($url) {
      $config['env'] = $env;
      $config['url'] = $url;
      $config['api_key'] = $apiKey;
    }

    if (!$config) {
      Logger::debug('Invalid config', true);
      return false;
    }

    try {
      $client = new BlinkApiClient($config['url'], $config['api_key']);
      $scopes = $client->getAuthorizationScopes();
      $hasReceive = in_array('RECEIVE', $scopes);
      $hasWrite = in_array('WRITE', $scopes);
      Logger::debug('API key scopes: ' . print_r($scopes, true));
      Logger::debug('End verifyApiKey with ' . ($hasReceive || $hasWrite));
      return $hasReceive || $hasWrite;
    } catch (\Throwable $e) {
      Logger::debug('Error fetching user info: ' . $e->getMessage(), true);
      return false;
    }
  }

  public function getInvoice(string $paymentHash) {
    Logger::debug('Start getInvoice for' . $paymentHash);
    if (!$paymentHash) {
      Logger::debug('Invalid invoice hash');
      return false;
    }

    if (!$this->configured) {
      Logger::debug('Invalid config', true);
      return false;
    }

    try {
      $config = self::getConfig();
      $client = new BlinkApiClient($config['url'], $config['api_key']);
      $invoice = $client->getInvoiceStatus($paymentHash);
      Logger::debug('End getInvoice for ' . $paymentHash);
      return $invoice;
    } catch (\Throwable $e) {
      Logger::debug('Error fetching invoice: ' . $e->getMessage(), true);
      return null;
    }
  }

  public function createInvoice($amount, $currency, $orderNumber) {
    Logger::debug('Start createInvoice for order ' . $orderNumber);
    if (!$amount || !$currency || !$orderNumber) {
      Logger::debug('Invalid createInvoice data');
      return null;
    }

    if (!$this->configured) {
      Logger::debug('Invalid config', true);
      return null;
    }

    try {
      $config = self::getConfig();
      $walletType = $config['wallet_type'];

      $client = new BlinkApiClient($config['url'], $config['api_key']);
      $walletsAmounts = $client->currencyConversionEstimation($amount, $currency);

      $walletCurrency = 'BTC';
      $walletAmount = $walletsAmounts['btcSatAmount'];
      $createInvoice = [$client, 'createInvoice'];
      if ($walletType == 'stablesats') {
        $walletCurrency = 'USD';
        $walletAmount = $walletsAmounts['usdCentAmount'];
        $createInvoice = [$client, 'createStablesatsInvoice'];
      }

      Logger::debug('CreateInvoice with wallet amount: ' . $walletAmount);
      Logger::debug('CreateInvoice with wallet currency: ' . $walletCurrency);

      $wallets = $client->getWallets();
      $walletId = $wallets[$walletCurrency];

      //TODO: add expiresIn and memo prefix in global config
      $expiresIn = 5;
      $memo = 'GW-' . $orderNumber;
      $invoice = $createInvoice($walletAmount, $expiresIn, $memo, $walletId);
      $redirectUrl = self::getInvoiceRedirectUrl($invoice['paymentHash']);
      $invoice['redirectUrl'] = $redirectUrl;
      Logger::debug('End createInvoice for ' . $orderNumber);
      return $invoice;
    } catch (\Throwable $e) {
      Logger::debug('Error creating invoice: ' . $e->getMessage(), true);
      return null;
    }
  }

  /**
   * Returns Blink invoice url.
   */
  public function getInvoiceRedirectUrl($invoiceId): ?string {
    if ($this->configured) {
      $payUrl = self::getPayUrl($this->env);
      return $payUrl . '/checkout/' . urlencode($invoiceId);
    }
    return null;
  }
}
