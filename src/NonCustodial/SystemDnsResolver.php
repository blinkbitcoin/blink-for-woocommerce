<?php

declare(strict_types=1);

namespace Blink\WC\NonCustodial;

final class SystemDnsResolver implements DnsResolverInterface {
  public function resolve(string $host): array {
    // An IP literal needs no lookup, and gethostbynamel() would fail on one.
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
      return [$host];
    }

    $ips = [];

    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
      $ips = $v4;
    }

    if (function_exists('dns_get_record')) {
      $aaaa = @dns_get_record($host, DNS_AAAA);
      if (is_array($aaaa)) {
        foreach ($aaaa as $record) {
          if (!empty($record['ipv6'])) {
            $ips[] = $record['ipv6'];
          }
        }
      }
    }

    return array_values(array_unique($ips));
  }
}
