<?php

declare(strict_types=1);

namespace Blink\WC\Helpers;

use Blink\WC\Admin\Notice;
use Blink\WC\Helpers\BlinkApiClient;
use Blink\WC\Helpers\BlinkLnurlClient;

class BlinkApiHelper {
  /** Default non-custodial invoice expiry window, in seconds (60 minutes). */
  const NON_CUSTODIAL_EXPIRY_SECONDS = 3600;

  public $configured = false;
  public $env;
  public $apiKey;
  public $walletType;
  public $accountType = 'custodial';
  public $lnAddress;

  public function __construct() {
    if ($config = self::getConfig()) {
      $this->env = $config['env'];
      $this->apiKey = $config['api_key'];
      $this->walletType = $config['wallet_type'];
      $this->accountType = $config['account_type'];
      $this->lnAddress = $config['ln_address'] ?? null;
      $this->configured = true;
    }
  }

  /**
   * Whether the currently configured account uses the non-custodial
   * (lightning address / LNURL) flow.
   */
  public function isNonCustodial(): bool {
    return $this->accountType === 'non_custodial';
  }

  /**
   * Returns the domain part of a lightning address (identifier@domain), or ''.
   */
  public static function lnAddressDomain(?string $lnAddress): string {
    if (!$lnAddress || !str_contains($lnAddress, '@')) {
      return '';
    }
    return trim(strtolower(explode('@', $lnAddress, 2)[1]));
  }

  public static function getApiUrl(string $env = null): string {
    $urlMapping = [
      'blink' => 'https://api.blink.sv/graphql',
      'staging' => 'https://api.staging.galoy.io/graphql',
    ];
    return $urlMapping[$env] ?? $urlMapping['blink'];
  }

  public static function getPayUrl(string $env = null): string {
    $urlMapping = [
      'blink' => 'https://pay.blink.sv',
      'staging' => 'https://pay.staging.galoy.io',
    ];
    return $urlMapping[$env] ?? $urlMapping['blink'];
  }

  public static function getConfig(): array {
    $accountType = get_option('blink_account_type', 'custodial');
    // Defense in depth: never trust an out-of-range stored value.
    if (!in_array($accountType, ['custodial', 'non_custodial'], true)) {
      $accountType = 'custodial';
    }
    $env = get_option('blink_env') ?: 'blink';
    $url = self::getApiUrl($env);

    if ($accountType === 'non_custodial') {
      $lnAddress = get_option('blink_ln_address');
      if (!$lnAddress) {
        return [];
      }

      return [
        'account_type' => 'non_custodial',
        'ln_address' => $lnAddress,
        'env' => $env,
        // Kept for currency conversion (public GraphQL query, no auth needed).
        'api_key' => get_option('blink_api_key') ?: '',
        // Non-custodial is BTC only for now.
        'wallet_type' => 'bitcoin',
        'url' => $url,
      ];
    }

    $key = get_option('blink_api_key');
    $walletType = get_option('blink_wallet_type');
    if (!$env || !$key || !$walletType) {
      return [];
    }

    return [
      'account_type' => 'custodial',
      'env' => $env,
      'api_key' => $key,
      'wallet_type' => $walletType,
      'url' => $url,
    ];
  }

  /**
   * Verifies a non-custodial Blink lightning address by resolving its
   * public LNURL-pay metadata.
   */
  public static function verifyLnAddress(string $lnAddress = null): bool {
    Logger::debug('Start verifyLnAddress');
    if (!$lnAddress) {
      Logger::debug('Invalid lightning address');
      return false;
    }

    $metadata = BlinkLnurlClient::fetchPayMetadata($lnAddress);
    $ok = $metadata !== null;
    Logger::debug('End verifyLnAddress with ' . ($ok ? 'true' : 'false'));
    return $ok;
  }

  public static function verifyApiKey(string $env = null, string $apiKey = null): bool {
    Logger::debug('Start verifyApiKey');
    if (!$env || !$apiKey) {
      Logger::debug('Invalid env or api key');
      return false;
    }

    $url = self::getApiUrl($env);

    try {
      $client = new BlinkApiClient($url, $apiKey);
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

  /**
   * Fetches the current status of a CUSTODIAL invoice.
   *
   * Non-custodial settlement no longer comes through here: it is driven by
   * SettlementService against the order's own stored verify URL and lightning
   * address. Branching on the global account-type setting, as this method used
   * to, meant that changing that setting stranded every order already in
   * flight -- in both directions.
   *
   * @return array<string,mixed>|null null when the status could not be read.
   */
  public function getInvoiceCustodial(string $paymentHash): ?array {
    Logger::debug('Start getInvoice for ' . $paymentHash);

    if (!$paymentHash || !$this->configured) {
      Logger::debug('Invalid invoice hash or configuration', true);

      return null;
    }

    try {
      $config = self::getConfig();
      $client = new BlinkApiClient($config['url'], $config['api_key']);
      $invoice = $client->getInvoiceStatus($paymentHash);
      Logger::debug('End getInvoice for ' . $paymentHash);

      return is_array($invoice) ? $invoice : null;
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

    if ($this->isNonCustodial()) {
      return $this->createInvoiceNonCustodial($amount, $currency, $orderNumber);
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
   * Creates an invoice for a non-custodial account via the LNURL-pay flow.
   *
   * Converts the order's fiat total to satoshis, resolves the merchant's
   * lightning-address LNURL metadata, then requests a fixed-amount BOLT11
   * invoice. The order reference is attached as an LUD-12 comment.
   *
   * @return array{paymentHash:string,paymentRequest:string,verifyUrl:string,satoshis:int,redirectUrl:null}|null
   */
  private function createInvoiceNonCustodial($amount, $currency, $orderNumber) {
    try {
      $config = self::getConfig();
      $lnAddress = $config['ln_address'];

      // Convert the order total to satoshis. This public query needs no auth.
      $client = new BlinkApiClient($config['url'], $config['api_key']);
      $conversion = $client->currencyConversionEstimation($amount, $currency);
      $satoshis = (int) $conversion['btcSatAmount'];
      if ($satoshis <= 0) {
        Logger::debug('Non-custodial invoice: non-positive sat amount.');
        return null;
      }

      $metadata = BlinkLnurlClient::fetchPayMetadata($lnAddress);
      if (!$metadata) {
        Logger::debug('Non-custodial invoice: could not fetch LNURL metadata.');
        return null;
      }

      $amountMsat = $satoshis * 1000;
      $minSendable = (int) $metadata['minSendable'];
      $maxSendable = (int) $metadata['maxSendable'];
      if ($minSendable > 0 && $amountMsat < $minSendable) {
        Logger::debug(
          'Non-custodial invoice: amount below minSendable (' .
            $amountMsat .
            ' < ' .
            $minSendable .
            ').'
        );
        return null;
      }
      if ($maxSendable > 0 && $amountMsat > $maxSendable) {
        Logger::debug(
          'Non-custodial invoice: amount above maxSendable (' .
            $amountMsat .
            ' > ' .
            $maxSendable .
            ').'
        );
        return null;
      }

      $addressDomain = self::lnAddressDomain($lnAddress);
      $comment = 'GW-' . $orderNumber;
      $expiry = self::NON_CUSTODIAL_EXPIRY_SECONDS;
      $createdAt = time();
      $invoice = BlinkLnurlClient::requestInvoice(
        $metadata['callback'],
        $amountMsat,
        $addressDomain,
        $comment,
        (int) $metadata['commentAllowed'],
        $expiry
      );
      if (!$invoice) {
        Logger::debug('Non-custodial invoice: LNURL callback did not return an invoice.');
        return null;
      }

      Logger::debug('End createInvoice (non-custodial) for ' . $orderNumber);

      return [
        'paymentHash' => $invoice['paymentHash'],
        'paymentRequest' => $invoice['paymentRequest'],
        'verifyUrl' => $invoice['verifyUrl'],
        'satoshis' => $satoshis,
        'createdAt' => $createdAt,
        'expiresAt' => $createdAt + $expiry,
        // Non-custodial payments are shown on our own on-site pay page,
        // so there is no external redirect URL here.
        'redirectUrl' => null,
      ];
    } catch (\Throwable $e) {
      Logger::debug('Error creating non-custodial invoice: ' . $e->getMessage(), true);
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
