<?php

declare(strict_types=1);

namespace Blink\WC\Helpers;

class GaloyApiClient {
  private $apiUrl;
  private $token;

  public function __construct($apiUrl, $token) {
    $this->apiUrl = $apiUrl;
    $this->token = $token;
  }

  private function sendRequest($query, $variables) {
    // Prepare HTTP headers
    $headers = [
      'X-API-KEY' => $this->token,
      'Content-Type' => 'application/json'
    ];

    // Prepare request body
    $body = json_encode([
      'query' => $query,
      'variables' => $variables
    ]);

    // Make the HTTP POST request
    $client = new \GuzzleHttp\Client();
    $response = $client->request('POST', $this->apiUrl, [
      'headers' => $headers,
      'body' => $body
    ]);

    // Parse response
    $responseBody = $response->getBody()->getContents();
    return json_decode($responseBody, true);
  }

  public function getAuthorizationScopes() {
    // Prepare GraphQL query for authorization scopes
    $query = 'query Authorization {
      authorization {
        scopes
      }
    }';

    // Send GraphQL request for authorization scopes
    $response = $this->sendRequest($query, null);

    // Check for errors during authorization scopes retrieval
    if (!empty($response['errors'])) {
      $errorMessage = implode(', ', array_column($response['errors'], 'message'));
      throw new Exception('Authorization scopes retrieval failed: ' . $errorMessage);
    }

    // Return authorization scopes
    return $response['data']['authorization']['scopes'];
  }

  public function createInvoice($amount, $expiresIn, $memo, $walletId) {
    // Prepare GraphQL query
    $query = 'mutation lnInvoiceCreate($input: LnInvoiceCreateInput!) {
      lnInvoiceCreate(input: $input) {
        errors {
          message
        }
        invoice {
          createdAt
          paymentHash
          paymentRequest
          paymentSecret
          paymentStatus
          satoshis
        }
      }
    }';

    // Prepare variables for the GraphQL query
    $variables = [
      'input' => [
        'amount' => $amount,
        'expiresIn' => $expiresIn,
        'memo' => $memo,
        'walletId' => $walletId
      ]
    ];

    // Send GraphQL request
    $response = $this->sendRequest($query, $variables);

    // Check for errors
    if (!empty($response['errors'])) {
      $errorMessage = implode(', ', array_column($response['errors'], 'message'));
      throw new Exception('GraphQL query failed: ' . $errorMessage);
    }

    // Return invoice details
    return $response['data']['lnInvoiceCreate'];
  }

  public function getInvoiceStatus($paymentHash) {
      // Prepare GraphQL query for invoice payment status by hash
      $query = 'query lnInvoicePaymentStatusByHash($input: LnInvoicePaymentStatusByHashInput!) {
        lnInvoicePaymentStatusByHash(input: $input) {
          paymentHash
          paymentRequest
          status
        }
      }';

      // Prepare variables for the invoice payment status by hash GraphQL query
      $variables = [
        'input' => [
          'paymentHash' => $paymentHash
        ]
      ];

      // Send GraphQL request for invoice payment status by hash
      $response = $this->sendRequest($query, $variables);

      // Check for errors during invoice payment status by hash
      if (!empty($response['errors'])) {
        $errorMessage = implode(', ', array_column($response['errors'], 'message'));
        throw new Exception('Invoice payment status retrieval failed: ' . $errorMessage);
      }

      // Return payment status
      return $response['data']['lnInvoicePaymentStatusByHash'];
  }
}
