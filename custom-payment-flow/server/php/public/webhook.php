<?php

require_once 'shared.php';
header('Content-Type: application/json');

$input = file_get_contents('php://input');
$event = null;

try {
  // Make sure the event is coming from Stripe by checking the signature header
  $event = App\WebhookHandler::constructEvent(
    $input,
    $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '',
    $config->webhookSecret
  );
}
catch (Exception $e) {
  http_response_code(403);
  echo json_encode([ 'error' => $e->getMessage() ]);
  exit;
}

echo json_encode(App\WebhookHandler::handle($event));
