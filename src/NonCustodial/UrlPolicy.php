<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

use Blink\WC\Support\LoggerInterface;

/**
 * Decides whether the plugin may fetch a URL.
 *
 * The merchant's configured Lightning address is the only thing trusted here.
 * Everything the LNURL server returns -- the callback URL, the verify URL --
 * is attacker-controlled from this plugin's point of view, and both are
 * fetched by the shop's own server, so an unchecked URL is a server-side
 * request forgery straight into the hosting network.
 *
 * Containment rule: the target host must be the address host, or a subdomain
 * of it. This replaces a "last two labels match" heuristic that collapsed
 * pay.example.co.uk to co.uk and so admitted every .co.uk host on the
 * internet. A public suffix list would be the general fix, but it is a large
 * blob to ship and update, and it is not needed: nothing in LUD-06 or LUD-16
 * entitles an address at blink.sv to serve its callback from blink.io.
 */
final class UrlPolicy implements UrlPolicyInterface {
  private const MAX_URL_LENGTH = 2048;

  /**
   * @param list<string> $extraAllowedHosts Hosts a site owner has explicitly
   *   allowed beyond the address domain. Resolved at wiring time rather than
   *   read here, so this class stays free of WordPress.
   */
  public function __construct(
    private DnsResolverInterface $dns,
    private LoggerInterface $log,
    private array $extraAllowedHosts = []
  ) {
  }

  public function check(string $url, LnAddress $address): UrlDecision {
    $decision = $this->evaluate($url, $address);
    if (!$decision->allowed) {
      $this->log->debug('LNURL URL rejected (' . $decision->reason . '): ' . $url);
    }

    return $decision;
  }

  private function evaluate(string $url, LnAddress $address): UrlDecision {
    if ($url === '' || strlen($url) > self::MAX_URL_LENGTH) {
      return UrlDecision::deny('bad length');
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- wp_parse_url() only compensates for PHP < 5.4.7, and this class must stay free of WordPress.
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
      return UrlDecision::deny('unparseable');
    }

    // Credentials in the authority are a parser-confusion vector and no LNURL
    // server needs them.
    if (isset($parts['user']) || isset($parts['pass'])) {
      return UrlDecision::deny('credentials in url');
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    $host = strtolower(trim($parts['host'], '[]'));
    $hostForUrl = strtolower($parts['host']);
    $localDev = $address->isLocalDev();

    if ($scheme !== 'https' && $scheme !== 'http') {
      return UrlDecision::deny('scheme not http(s)');
    }

    // Plain HTTP only for a local development address pointing at a local
    // host. Previously HTTPS was enforced only on the well-known fetch, so a
    // callback or verify URL could come back as http:// on the public
    // internet and the invoice would travel in clear text.
    if ($scheme === 'http' && !($localDev && LnAddress::hostIsLocalDev($host))) {
      return UrlDecision::deny('plain http outside local dev');
    }

    if ($localDev) {
      return LnAddress::hostIsLocalDev($host) || $this->isIpLiteral($host)
        ? UrlDecision::allow()
        : UrlDecision::deny('local dev address may only reach local hosts');
    }

    // A public address must never be served from a bare IP: it defeats the
    // containment rule below and no legitimate provider does it.
    if ($this->isIpLiteral($host)) {
      return UrlDecision::deny('ip literal host');
    }

    if (
      !$this->isContainedBy($host, $address->host) &&
      !$this->isExplicitlyAllowed($host)
    ) {
      return UrlDecision::deny('host outside the address domain');
    }

    $ips = $this->dns->resolve($host);
    if ($ips === []) {
      return UrlDecision::deny('host does not resolve');
    }

    foreach ($ips as $ip) {
      if (!$this->isPublicIp($ip)) {
        return UrlDecision::deny('resolves to non-public address ' . $ip);
      }
    }

    // Only https reaches this point: plain http is rejected above unless the
    // address is local dev, and that case has already returned.
    $port = $parts['port'] ?? 443;

    return UrlDecision::allow([$hostForUrl . ':' . $port => $ips]);
  }

  private function isIpLiteral(string $host): bool {
    return filter_var($host, FILTER_VALIDATE_IP) !== false;
  }

  /**
   * A per-site escape hatch for a provider that genuinely serves LNURL from a
   * different domain.
   *
   * Deliberately explicit and opt-in: a site owner adding a host here is
   * making a decision, where a broad heuristic would make it for them.
   */
  private function isExplicitlyAllowed(string $host): bool {
    foreach ($this->extraAllowedHosts as $allowed) {
      if (strtolower(trim((string) $allowed)) === $host) {
        return true;
      }
    }

    return false;
  }

  /** The target host must equal the address host or be a subdomain of it. */
  private function isContainedBy(string $host, string $addressHost): bool {
    $host = rtrim($host, '.');
    $addressHost = rtrim($addressHost, '.');

    return $host === $addressHost || str_ends_with($host, '.' . $addressHost);
  }

  /**
   * Whether an address is safe to connect to from the shop's server.
   *
   * filter_var's own flags miss several ranges that matter in hosting
   * environments, so they are checked explicitly.
   */
  private function isPublicIp(string $ip): bool {
    if (
      filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
      ) === false
    ) {
      return false;
    }

    // IPv4-mapped IPv6 (::ffff:127.0.0.1): filter_var does not apply the IPv4
    // private ranges to the mapped form, so unwrap and re-check.
    if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
      return $this->isPublicIp($m[1]);
    }

    // NAT64 (64:ff9b::/96) wraps an IPv4 address the same way.
    if (preg_match('/^64:ff9b::(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
      return $this->isPublicIp($m[1]);
    }

    // Carrier-grade NAT, not covered by FILTER_FLAG_NO_RES_RANGE.
    if (preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip)) {
      return false;
    }

    return true;
  }
}
