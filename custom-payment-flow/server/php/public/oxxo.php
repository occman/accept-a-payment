<?php
require_once 'shared.php';

try {
  $paymentIntent = App\Checkout::createIntent($stripe, App\PaymentMethods::params('oxxo'));
} catch (App\PageAborted) {
  exit;
}
?>


<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>OXXO</title>
    <link rel="stylesheet" href="css/base.css" />
    <script src="https://js.stripe.com/v3/"></script>
    <script src="./utils.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', async () => {
        const stripe = Stripe('<?= $_ENV["STRIPE_PUBLISHABLE_KEY"]; ?>', {
          apiVersion: '2020-08-27',
        });

        const paymentForm = document.querySelector('#payment-form');
        paymentForm.addEventListener('submit', async (e) => {
          // Avoid a full page POST request.
          e.preventDefault();

          // Customer inputs
          const nameInput = document.querySelector('#name');
          const emailInput = document.querySelector('#email');

          // Confirm the payment that was created server side:
          const {error, paymentIntent} = await stripe.confirmOxxoPayment(
            '<?= $paymentIntent->client_secret; ?>', {
              payment_method: {
                billing_details: {
                  name: nameInput.value,
                  email: emailInput.value,
                },
              },
            }
          );
          if(error) {
            addMessage(error.message);
            return;
          }
          addMessage(`Payment (${paymentIntent.id}): ${paymentIntent.status}`);
        });
      });
    </script>
  </head>
  <body>
    <main>
      <a href="/">home</a>
      <h1>OXXO</h1>

      <form id="payment-form">
        <label for="name">
          Name
        </label>
        <input id="name" value="Jenny Rosen" required>

        <label for="email">
          Email
        </label>
        <input id="email" value="jr.succeed_immediately@example.com" required>

        <!-- Used to display form errors. -->
        <div id="error-message" role="alert"></div>

        <button type="submit">Pay</button>
      </form>

      <div id="messages" role="alert" style="display: none;"></div>
    </main>
  </body>
</html>
