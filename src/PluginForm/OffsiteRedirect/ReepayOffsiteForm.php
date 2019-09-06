<?php

namespace Drupal\commerce_payment_reepay\PluginForm\OffsiteRedirect;

use CommerceGuys\Intl\Formatter\NumberFormatterInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

class ReepayOffsiteForm extends BasePaymentOffsiteForm {

  use StringTranslationTrait;

  /**
   * The number formatter.
   *
   * @var \CommerceGuys\Intl\Formatter\NumberFormatterInterface
   */
  protected $numberFormatter;

  /**
   * Subscription order service.
   *
   * @var \Drupal\interflora_subscription\Service\SubscriptionOrderService
   */
  protected $subscriptionOrderService;

  /**
   * {@inheritdoc}
   */
  public function __construct() {
    $number_formatter = \Drupal::service('commerce_price.number_formatter_factory')
      ->createInstance(NumberFormatterInterface::DECIMAL);
    $number_formatter->setMaximumFractionDigits(6);
    $number_formatter->setMinimumFractionDigits(2);
    $number_formatter->setGroupingUsed(FALSE);
    $this->numberFormatter = $number_formatter;

    $this->subscriptionOrderService = \Drupal::service('interflora_subscription.order');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    // Get the configuration array.
    $configuration = $payment_gateway_plugin->getConfiguration();
    $order = $payment->getOrder();

    // Adding this class to the form triggers javascript in the the
    // page--checkout--payment template, which adds the reepay class.
    $form['#attributes']['class'][] = 'reepay-payment-form';

    $form['#attached']['library'][] = 'commerce_payment_reepay/reepay';
    $form['#attached']['drupalSettings'] = [
      'reepay' => [
        'reepayApi' => $configuration['public_key'],
        'cancel' => $form['#cancel_url'],
        'return' => $form['#return_url'],
      ],
    ];
    $form['#attached']['library'][] = 'commerce_payment_reepay/handling';

    $form['order-details'] = [
      '#type' => 'container',
    ];
    $form['order-details']['recurring-amount'] = [
      '#type' => 'item',
      '#title' => t('Purchase information'),
      '#description' => $order->getTotalPrice(),
      ];
    $form['order-details']['order-number'] = [
      '#type' => 'item',
      '#title' => t('Order number:'),
      // @todo Do we need to use a special prefix for the order id like we do
      // for DIBS?
      '#description' => $order->id(),
    ];
    $form['payment-details'] = [
      '#type' => 'item',
      '#title' => t('Enter your payment details'),
      '#description' => t('You can pay using the following payment cards:'),
    ];

    $form['card-details'] = [
      '#type' => 'container',
    ];
    $form['card-details']['number'] = [
      '#type' => 'textfield',
      '#title' => t('CreditCard number'),
      '#attributes' => [
        'data-reepay' => 'number'
      ]
    ];
    $form['card-details']['month'] = [
      '#type' => 'textfield',
      '#title' => t('Month'),
      '#attributes' => [
        'data-reepay' => 'month'
      ]
    ];
    $form['card-details']['year'] = [
      '#type' => 'textfield',
      '#title' => t('Year'),
      '#attributes' => [
        'data-reepay' => 'year'
      ]
    ];
    $form['card-details']['cvv'] = [
      '#type' => 'textfield',
      '#title' => t('CVV'),
      '#attributes' => [
        'data-reepay' => 'cvv'
      ]
    ];
    $form['reepay-token'] = [
      '#type' => 'hidden',
      '#default_value' => '',
      '#attributes' => [
        'data-reepay' => 'token',
        'name' => 'reepay-token',
      ]
    ];
    $form['submit'] = [
      '#type' => 'button',
      '#value' => $this->t('Pay'),
    ];

    $form['payment-information'] = [
      '#type' => 'item',
      '#description' => t('This is a recurring payment in order to pay for your subscription. You are always able to change your credit card information in MyInterflora.'),
    ];

    return $form;
  }

  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $card_token = $values['payment_process']['offsite_payment']['reepay-token'];
    if (empty($card_token)) {
      $form_state->setError($form['reepay-token'], t('Invalid token'));
    }
  }

}
