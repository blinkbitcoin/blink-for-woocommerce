<?php

declare(strict_types=1);

namespace Blink\WC;

use Blink\WC\Helpers\BlinkApiClient;
use Blink\WC\Helpers\BlinkApiHelper;
use Blink\WC\Http\GuzzleHttpClient;
use Blink\WC\Http\HttpClientInterface;
use Blink\WC\NonCustodial\BlinkApiSatsRateProvider;
use Blink\WC\NonCustodial\Bolt11\Bolt11Decoder;
use Blink\WC\NonCustodial\Bolt11\InvoiceValidator;
use Blink\WC\NonCustodial\DnsResolverInterface;
use Blink\WC\NonCustodial\InvoiceFactory;
use Blink\WC\NonCustodial\InvoiceRepository;
use Blink\WC\NonCustodial\LnurlClient;
use Blink\WC\NonCustodial\LnurlClientInterface;
use Blink\WC\NonCustodial\PollBudget;
use Blink\WC\NonCustodial\SatsRateProviderInterface;
use Blink\WC\NonCustodial\SettlementScheduler;
use Blink\WC\NonCustodial\SettlementService;
use Blink\WC\NonCustodial\SystemDnsResolver;
use Blink\WC\NonCustodial\UnpaidOrderGuard;
use Blink\WC\NonCustodial\UrlPolicy;
use Blink\WC\NonCustodial\UrlPolicyInterface;
use Blink\WC\NonCustodial\SettlementOutcomeApplier;
use Blink\WC\Orders\OrderStatusApplier;
use Blink\WC\Orders\WcSettlementOutcomeApplier;
use Blink\WC\Support\ActionSchedulerAdapter;
use Blink\WC\Support\ClockInterface;
use Blink\WC\Support\DbLock;
use Blink\WC\Support\DbRateLimiter;
use Blink\WC\Support\JitterInterface;
use Blink\WC\Support\LockInterface;
use Blink\WC\Support\LoggerInterface;
use Blink\WC\Support\NullScheduler;
use Blink\WC\Support\RandomJitter;
use Blink\WC\Support\RandomSourceInterface;
use Blink\WC\Support\RateLimiterInterface;
use Blink\WC\Support\SchedulerInterface;
use Blink\WC\Support\SystemClock;
use Blink\WC\Support\WcLogger;
use Blink\WC\Support\WpRandomSource;

/**
 * Builds the non-custodial object graph.
 *
 * WooCommerce constructs the gateway itself, so there is nowhere to inject
 * anything from the outside. Rather than adopt a container for a dozen
 * objects, every class takes its collaborators as constructor arguments and
 * this is the single place that knows how to assemble them.
 *
 * Each service passes through a `blink_service_*` filter, so a site or an
 * integration test can replace one piece without touching the rest.
 */
final class Services {
  private static ?self $instance = null;

  /** @var array<string,mixed> */
  private array $memo = [];

  public static function instance(): self {
    return self::$instance ??= new self();
  }

  /** Replaces the graph. Intended for tests; pass null to restore the default. */
  public static function replace(?self $services): void {
    self::$instance = $services;
  }

  public function clock(): ClockInterface {
    return $this->memo(__FUNCTION__, static fn(): ClockInterface => new SystemClock());
  }

  public function logger(): LoggerInterface {
    return $this->memo(__FUNCTION__, static fn(): LoggerInterface => new WcLogger());
  }

  public function randomSource(): RandomSourceInterface {
    return $this->memo(
      __FUNCTION__,
      static fn(): RandomSourceInterface => new WpRandomSource()
    );
  }

  public function jitter(): JitterInterface {
    return $this->memo(
      __FUNCTION__,
      fn(): JitterInterface => new RandomJitter($this->randomSource())
    );
  }

  public function http(): HttpClientInterface {
    return $this->memo(
      __FUNCTION__,
      static fn(): HttpClientInterface => new GuzzleHttpClient(new \GuzzleHttp\Client())
    );
  }

  public function dnsResolver(): DnsResolverInterface {
    return $this->memo(
      __FUNCTION__,
      static fn(): DnsResolverInterface => new SystemDnsResolver()
    );
  }

  public function urlPolicy(): UrlPolicyInterface {
    return $this->memo(
      __FUNCTION__,
      fn(): UrlPolicyInterface => new UrlPolicy(
        $this->dnsResolver(),
        $this->logger(),
        // A site can allow a provider that serves LNURL from another host.
        array_values((array) apply_filters('blink_lnurl_extra_allowed_hosts', []))
      )
    );
  }

  public function lnurlClient(): LnurlClientInterface {
    return $this->memo(
      __FUNCTION__,
      fn(): LnurlClientInterface => new LnurlClient(
        $this->http(),
        $this->urlPolicy(),
        $this->logger()
      )
    );
  }

  public function invoiceValidator(): InvoiceValidator {
    return $this->memo(
      __FUNCTION__,
      fn(): InvoiceValidator => new InvoiceValidator(new Bolt11Decoder(), $this->clock())
    );
  }

  public function invoiceRepository(): InvoiceRepository {
    return $this->memo(
      __FUNCTION__,
      fn(): InvoiceRepository => new InvoiceRepository($this->clock())
    );
  }

  public function statusApplier(): OrderStatusApplier {
    return $this->memo(
      __FUNCTION__,
      static fn(): OrderStatusApplier => new OrderStatusApplier()
    );
  }

  public function satsRateProvider(): SatsRateProviderInterface {
    return $this->memo(__FUNCTION__, function (): SatsRateProviderInterface {
      $config = BlinkApiHelper::getConfig();

      return new BlinkApiSatsRateProvider(
        new BlinkApiClient(
          (string) ($config['url'] ?? BlinkApiHelper::getApiUrl(null)),
          (string) ($config['api_key'] ?? '')
        ),
        $this->logger()
      );
    });
  }

  public function invoiceFactory(): InvoiceFactory {
    return $this->memo(
      __FUNCTION__,
      fn(): InvoiceFactory => new InvoiceFactory(
        $this->lnurlClient(),
        $this->satsRateProvider(),
        $this->invoiceValidator(),
        $this->clock(),
        $this->logger(),
        // Real LNURL servers vary in how they echo metadata, so a site can
        // relax this. It is on by default and its absence is logged loudly.
        (bool) apply_filters('blink_bolt11_require_description_binding', true)
      )
    );
  }

  public function lock(): LockInterface {
    return $this->memo(__FUNCTION__, function (): LockInterface {
      global $wpdb;

      return new DbLock($wpdb, $this->clock());
    });
  }

  public function rateLimiter(): RateLimiterInterface {
    return $this->memo(__FUNCTION__, function (): RateLimiterInterface {
      global $wpdb;

      return new DbRateLimiter($wpdb, $this->clock());
    });
  }

  public function pollBudget(): PollBudget {
    return $this->memo(
      __FUNCTION__,
      fn(): PollBudget => new PollBudget($this->rateLimiter())
    );
  }

  public function settlement(): SettlementService {
    return $this->memo(
      __FUNCTION__,
      fn(): SettlementService => new SettlementService(
        $this->lnurlClient(),
        $this->invoiceRepository(),
        $this->pollBudget(),
        $this->lock(),
        $this->clock(),
        $this->logger()
      )
    );
  }

  public function unpaidOrderGuard(): UnpaidOrderGuard {
    return $this->memo(
      __FUNCTION__,
      fn(): UnpaidOrderGuard => new UnpaidOrderGuard(
        $this->invoiceRepository(),
        $this->clock()
      )
    );
  }

  public function scheduler(): SchedulerInterface {
    return $this->memo(__FUNCTION__, static function (): SchedulerInterface {
      $adapter = new ActionSchedulerAdapter();

      // WooCommerce ships Action Scheduler, so its absence means something
      // unusual; degrade to browser-driven settlement rather than fatal.
      return $adapter->isAvailable() ? $adapter : new NullScheduler();
    });
  }

  public function outcomeApplier(): SettlementOutcomeApplier {
    return $this->memo(
      __FUNCTION__,
      fn(): SettlementOutcomeApplier => new WcSettlementOutcomeApplier(
        $this->statusApplier(),
        $this->invoiceRepository()
      )
    );
  }

  public function settlementScheduler(): SettlementScheduler {
    return $this->memo(
      __FUNCTION__,
      fn(): SettlementScheduler => new SettlementScheduler(
        $this->scheduler(),
        $this->settlement(),
        $this->invoiceRepository(),
        $this->outcomeApplier(),
        $this->clock(),
        $this->jitter(),
        $this->logger()
      )
    );
  }

  /**
   * @template T
   * @param callable():T $factory
   * @return T
   */
  private function memo(string $key, callable $factory): mixed {
    if (!array_key_exists($key, $this->memo)) {
      $this->memo[$key] = apply_filters('blink_service_' . $key, $factory());
    }

    return $this->memo[$key];
  }
}
