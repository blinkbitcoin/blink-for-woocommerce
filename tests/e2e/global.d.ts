export {};

declare global {
  interface Window {
    BlinkPay: {
      ajaxUrl: string;
      deadline: number;
      i18n: Record<string, string>;
      lightningUri: string;
      nonce: string;
      orderId: number;
      orderKey: string;
      paymentRequest: string;
      pollInterval: number;
      redirectUrl: string;
    };
  }
}
