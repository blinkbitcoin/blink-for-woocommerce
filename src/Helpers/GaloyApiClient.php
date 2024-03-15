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

    echo "body: " . implode(" ", $headers) . "\n";

    // Make the HTTP POST request
    $client = new \GuzzleHttp\Client();
    $response = $client->request('POST', $this->apiUrl, [
        'headers' => $headers,
        'body' => $body
    ]);

    return $response;
  }

  public function getUserInfo() {
      $query = 'query Me { me { id } }';

      $response = $this->sendRequest($query, null);
      $responseBody = $response->getBody()->getContents();
      $data = json_decode($responseBody, true);

      // Check for errors
      if (!empty($data['errors'])) {
          $errorMessage = implode(', ', array_column($data['errors'], 'message'));
          throw new Exception('GraphQL query failed: ' . $errorMessage);
      }

      // Return user info
      return $data['data']['me'] ?? null;
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

    // Parse response for authorization scopes
    $response = $this->sendRequest($query, null);
    $responseBody = $response->getBody()->getContents();
    $data = json_decode($responseBody, true);

    // Check for errors during authorization scopes retrieval
    if (!empty($data['errors'])) {
        $errorMessage = implode(', ', array_column($data['errors'], 'message'));
        throw new Exception('Authorization scopes retrieval failed: ' . $errorMessage);
    }

    // Return authorization scopes
    return $data['data']['authorization']['scopes'];
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

    // Parse response
    $data = json_decode($response->getBody(), true);

    // Check for errors
    if (!empty($data['errors'])) {
        $errorMessage = implode(', ', array_column($data['errors'], 'message'));
        throw new Exception('GraphQL query failed: ' . $errorMessage);
    }

    // Return invoice details
    return $data['data']['lnInvoiceCreate'];
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

      // Parse response for invoice payment status by hash
      $data = json_decode($response->getBody(), true);

      // Check for errors during invoice payment status by hash
      if (!empty($data['errors'])) {
          $errorMessage = implode(', ', array_column($data['errors'], 'message'));
          throw new Exception('Invoice payment status retrieval failed: ' . $errorMessage);
      }

      // Return payment status
      return $data['data']['lnInvoicePaymentStatusByHash'];
  }
}
