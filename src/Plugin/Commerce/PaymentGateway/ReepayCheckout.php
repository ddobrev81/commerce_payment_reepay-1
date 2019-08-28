<?php

namespace Drupal\commerce_payment_reepay\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment_reepay\ReepayApi;
use Drupal\commerce_payment_reepay\ReepayCheckoutApi;
use Drupal\commerce_payment_reepay\ReepayEvents;
use Drupal\commerce_payment_reepay\Event\PaymentEvent;
use Drupal\commerce_payment_reepay\Event\WebhookEvent;
use Drupal\commerce_payment_reepay\Exception\WebhookException;
use Drupal\commerce_payment_reepay\Model\ReepayCharge;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Reepay Checkout payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "reepay_checkout",
 *   label = @Translation("Reepay Checkout"),
 *   display_label = @Translation("Reepay Checkout"),
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_payment_reepay\PluginForm\OffsiteRedirect\ReepayCheckoutForm",
 *   },
 *   credit_card_types = {
 *     "amex", "dinersclub", "discover", "jcb", "maestro", "mastercard", "visa",
 *   },
 * )
 */
class ReepayCheckout extends OffsitePaymentGatewayBase {

  use LoggerChannelTrait;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The order entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $orderStorage;

  /**
   * The payment entity storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $paymentStorage;

  /**
   * The Reepay API.
   *
   * @var \Drupal\commerce_payment_reepay\ReepayApi
   */
  protected $reepayApi;

  /**
   * The Reepay Checkout API.
   *
   * @var \Drupal\commerce_payment_reepay\ReepayCheckoutApi
   */
  protected $reepayCheckoutApi;

  /**
   * Constructs a new ReepayCheckout object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, TimeInterface $time, EventDispatcherInterface $eventDispatcher) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);
    $this->eventDispatcher = $eventDispatcher;
    $this->orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $this->paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');
    $this->reepayApi = new ReepayApi($configuration['private_key'] ?? '');
    $this->reepayCheckoutApi = new ReepayCheckoutApi($configuration['private_key'] ?? '');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'public_key' => '',
        'private_key' => '',
        'webhook_key' => '',
        'checkout_type' => 'redirect',
        'session_type' => 'charge',
        'configuration_handle' => '',
        'locale' => '',
        'order_number_prefix' => '',
        'button_text' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $configuration = $this->getConfiguration();
    $form['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public key', [], ['context' => 'Reepay']),
      '#description' => $this->t('The public API key.', [], ['context' => 'Reepay']),
      '#default_value' => isset($configuration['public_key']) ? $configuration['public_key'] : '',
      '#attributes' => ['placeholder' => 'pub_00000000000000000000000000000000'],
      '#required' => TRUE,
    ];
    $form['private_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Private key', [], ['context' => 'Reepay']),
      '#description' => $this->t('The private API key.', [], ['context' => 'Reepay']),
      '#default_value' => isset($configuration['private_key']) ? $configuration['private_key'] : '',
      '#attributes' => ['placeholder' => 'prv_1111111111111111111111111111111'],
      '#required' => TRUE,
    ];
    $form['webhook_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook key', [], ['context' => 'Reepay']),
      '#description' => $this->t('The secret webhook key.', [], ['context' => 'Reepay']),
      '#default_value' => isset($configuration['webhook_key']) ? $configuration['webhook_key'] : '',
      '#attributes' => ['placeholder' => 'webhook_secret_22222222222222222222222222222222'],
    ];
    $checkout_type_description =
      '<ul>' .
      '<li>' . $this->t('<em>Redirect</em> - a complete page redirect (without js)', [], ['context' => 'Reepay']) . '</li>' .
      '<li>' . $this->t('<em>Window</em> - a complete page redirect', [], ['context' => 'Reepay']) . '</li>' .
      '<li>' . $this->t('<em>Overlay (Modal)</em> - a full page overlay on top of your web page', [], ['context' => 'Reepay']) . '</li>' .
      '<li>' . $this->t('<em>Embedded</em> - a component integrated directly into your web page', [], ['context' => 'Reepay']) . '</li>' .
      '</ul>';
    $form['checkout_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Checkout type', [], ['context' => 'Reepay']),
      '#description' => $checkout_type_description,
      '#default_value' => isset($configuration['checkout_type']) ? $configuration['checkout_type'] : '',
      '#required' => TRUE,
      '#options' => [
        'redirect' => $this->t('Redirect', [], ['context' => 'Reepay']),
        'window' => $this->t('Window', [], ['context' => 'Reepay']),
        'overlay' => $this->t('Overlay (Modal)', [], ['context' => 'Reepay']),
        'embedded' => $this->t('Embedded', [], ['context' => 'Reepay']),
      ],
    ];
    $form['session_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Session type', [], ['context' => 'Reepay']),
      '#description' => $this->t('The type of session: <em>Charge</em> for single payments and <em>Recurring</em> for recurring payments.', [], ['context' => 'Reepay']),
      '#default_value' => isset($configuration['session_type']) ? $configuration['session_type'] : '',
      '#required' => TRUE,
      '#options' => [
        'charge' => $this->t('Charge', [], ['context' => 'Reepay']),
        'recurring' => $this->t('Recurring', [], ['context' => 'Reepay']),
      ],
    ];
    $form['configuration_handle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Configuration handle', [], ['context' => 'Reepay']),
      '#description' => $this->t('Optional configuration handle to use for all sessions.', [], ['context' => 'Reepay']),
      '#default_value' => isset($configuration['configuration_handle']) ? $configuration['configuration_handle'] : '',
    ];
    $form['locale'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Locale', [], ['context' => 'Reepay']),
      '#description' => $this->t('Optional locale for session. E.g. <em>en_GB</em>, <em>da_DK</em>, <em>es_ES</em>.', [], ['context' => 'Reepay']),
      '#default_value' => isset($configuration['locale']) ? $configuration['locale'] : '',
      '#attributes' => ['placeholder' => 'en_US'],
    ];
    $form['order_number_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Order number prefix', [], ['context' => 'Reepay']),
      '#description' => $this->t('An optional value to prefix the order number with.', [], ['context' => 'Reepay']),
      '#default_value' => isset($configuration['order_number_prefix']) ? $configuration['order_number_prefix'] : '',
      '#attributes' => ['placeholder' => '99-'],
    ];
    $form['button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button text', [], ['context' => 'Reepay']),
      '#description' => $this->t('An optional alternative payment button text.', [], ['context' => 'Reepay']),
      '#default_value' => isset($configuration['button_text']) ? $configuration['button_text'] : '',
      '#attributes' => ['placeholder' => $this->t('Buy', [], ['context' => 'Reepay'])],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['public_key'] = $values['public_key'];
      $this->configuration['private_key'] = $values['private_key'];
      $this->configuration['webhook_key'] = $values['webhook_key'];
      $this->configuration['checkout_type'] = $values['checkout_type'];
      $this->configuration['session_type'] = $values['session_type'];
      $this->configuration['configuration_handle'] = $values['configuration_handle'];
      $this->configuration['locale'] = $values['locale'];
      $this->configuration['order_number_prefix'] = $values['order_number_prefix'];
      $this->configuration['button_text'] = $values['button_text'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    if ($values['checkout_type'] !== 'redirect') {
      $args = ['@checkout_type' => $form['checkout_type']['#options'][$values['checkout_type']]];
      $options = ['context' => 'Reepay'];
      $form_state->setError($form['checkout_type'], $this->t('Checkout type "@checkout_type" not implemented.', $args, $options));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    try {
      // Get the order, charge and payment
      $invoiceHandle = $request->query->get('invoice') ?? '';
      $charge = $this->reepayApi->getCharge($invoiceHandle);
      if (!$charge instanceof ReepayCharge) {
        $this->getLogger('commerce_payment_reepay')->error(
          $this->t('Reepay charge for order @handle not found'),
          ['@handle' => $invoiceHandle]
        );
        throw new PaymentGatewayException();
      }
      $payment = $this->getPayment($order, $charge);

      // The return url can be tampered with so check actual status of the charge.
      if ($charge->getState() != 'authorized') {
        $this->getLogger('commerce_payment_reepay')->error(
          $this->t('Possible attempt at tampering with return url for order @handle'),
          ['@handle' => $invoiceHandle]
        );
        throw new PaymentGatewayException();
      }

      // If the error message was not empty, log it and continue processing.
      $error = $request->query->get('error');
      if (!empty($error)) {
        $this->getLogger('commerce_payment_reepay')->error(
          $this->t('Error on return url for order @handle: @error'),
          ['@handle' => $orderUuid, '@error' => $error]
        );
        throw new PaymentGatewayException();
      }

      // Dispatch a PROCESS_PAYMENT-event to allow other modules to act on a payment.
      $paymentGateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($this->entityId);
      $paymentEvent = new PaymentEvent($paymentGateway, $order, $payment, $charge, $request);
      $this->eventDispatcher->dispatch(ReepayEvents::PROCESS_PAYMENT, $paymentEvent);
    }
    catch (\Exception $exception) {
      throw new PaymentGatewayException($exception->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    drupal_set_message($this->t('You have canceled checkout at @gateway but may resume the checkout process here when you are ready.', [
      '@gateway' => $this->getDisplayLabel(),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    try {
      // Decode the contents and check the signature.
      $contents = json_decode($request->getContent());
      $paymentGateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($this->entityId);
      $webhookEvent = new WebhookEvent($paymentGateway, $contents);
      if (!$webhookEvent->validSignature()) {
        throw new WebhookException('Signature check failed');
      }

      // Dispatch a PROCESS_WEBHOOK-event to allow other modules to act on an
      // incoming webhook before the default event subscriber .
      $this->eventDispatcher->dispatch(ReepayEvents::PROCESS_WEBHOOK, $webhookEvent);
    }
    catch (\Exception $exception) {
      // Log any error and return HTTP Bad request to the client.
      $this->getLogger('commerce_payment_reepay')->error(
        $this->t('Webhook error:<br />@exception<br /><pre>@contents</pre>'),
        [
          '@exception' => $exception->getMessage(),
          '@contents' => $request->getContent(),
        ]
      );
      return new Response('', Response::HTTP_BAD_REQUEST);
    }
  }

  /**
   * Get the Reepay API.
   *
   * @return \Drupal\commerce_payment_reepay\ReepayApi;
   *   The API client.
   */
  public function getReepayApi() {
    return $this->reepayApi;
  }

  /**
   * Get the Reepay Checkout API.
   *
   * @return \Drupal\commerce_payment_reepay\ReepayCheckoutApi;
   *   The API client.
   */
  public function getReepayCheckoutApi() {
    return $this->reepayCheckoutApi;
  }

  /**
   * Load an order by its properties.
   *
   * @param array $properties
   *   An array of properties and their value.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|NULL
   *   The order or NULL if not found.
   */
  public function loadOrderByProperties(array $properties) {
    $orders = $this->orderStorage->loadByProperties($properties);
    if (!empty($orders)) {
      $order = reset($orders);
      return $order;
    }
    return NULL;
  }

  /**
   * Get a Payment-object from an order and a Reepay Charge-object.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order to get the payment for.
   * @param \Drupal\commerce_payment_reepay\Model\ReepayCharge $charge
   *   The Reepay Charge-object.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   The payment object.
   */
  public function getPayment(OrderInterface $order, ReepayCharge $charge): PaymentInterface {
    $query = $this->paymentStorage->getQuery()
      ->condition('remote_id', $charge->getTransaction())
      ->condition('order_id', $order->id());
    $payments = $query->execute();
    if (!empty($payments)) {
      $payment_id = reset($payments);
      return $this->paymentStorage->load($payment_id);
    }

    $source = $charge->getSource();
    if (in_array($source->type, ['card', 'card_token'])) {
      $payment_type = $source->card_type;
    }
    else {
      $payment_type = $source->card;
    }
    $payment = $this->paymentStorage->create([
      'state' => 'authorization',
      'amount' => $charge->getPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => ($this->getMode() === 'test'),
      'remote_id' => $charge->getTransaction(),
      'remote_state' => $charge->getState(),
      'payment_type' => $payment_type,
    ]);
    $payment->save();
    return $payment;
  }

  /**
   * Process a payment according to the given Reepay Charge.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment to process.
   */
  public function processPayment(PaymentInterface $payment, ReepayCharge $charge) {
    $paymentState = $payment->getState();
    $workflow = $paymentState->getWorkflow();

    switch ($charge->getState()) {
      case 'authorized':
        $transition = $workflow->getTransition('authorize');
        break;
      case 'settled':
        $transition = $workflow->getTransition('capture');
        break;
      case 'failed':
      case 'cancelled':
        $transition = $workflow->getTransition('void');
        break;
    }
    $paymentState->applyTransition($transition);
    $payment->save();
  }

}