<?php

declare(strict_types=1);

namespace Blink\WC\Tests\Integration\Helpers;

use Blink\WC\Helpers\OrderStates;
use Blink\WC\Tests\Support\IntegrationTestCase;

final class OrderStatesTest extends IntegrationTestCase {
  private const OPTION = 'blink_test_order_states';

  public function tear_down() {
    delete_option(self::OPTION);
    parent::tear_down();
  }

  public function test_every_configurable_state_has_a_label(): void {
    $this->assertSame(
      [
        OrderStates::NEW => 'New',
        OrderStates::PENDING => 'Pending',
        OrderStates::PAID => 'Paid',
        OrderStates::SETTLED => 'Settled',
        OrderStates::EXPIRED => 'Expired'
      ],
      (new OrderStates())->getOrderStateLabels()
    );
  }

  public function test_the_settings_field_offers_an_explicit_no_mapping_option(): void {
    $states = new OrderStates();
    update_option(self::OPTION, $states->getDefaultOrderStateMappings());

    ob_start();
    try {
      $states->renderOrderStatesHtml(['id' => self::OPTION]);
      $html = (string) ob_get_contents();
    } finally {
      ob_end_clean();
    }

    $this->assertStringContainsString(
      '<option value="BLINK_IGNORE">- no mapping / defaults -</option>',
      $html
    );
  }
}
