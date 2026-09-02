import json
from unittest import mock

import pytest
import stripe as real_stripe


def post_intent(client, body):
    return client.post(
        '/create-payment-intent',
        data=json.dumps(body),
        content_type='application/json',
    )


def test_creates_payment_intent_with_card(client, stripe_mock):
    stripe_mock.PaymentIntent.create.return_value = mock.Mock(
        client_secret='pi_123_secret_abc')

    response = post_intent(client, {'paymentMethodType': 'card',
                                    'currency': 'usd'})

    assert response.status_code == 200
    assert response.get_json() == {'clientSecret': 'pi_123_secret_abc'}
    stripe_mock.PaymentIntent.create.assert_called_once_with(
        payment_method_types=['card'], amount=5999, currency='usd')


def test_link_adds_card_to_payment_method_types(client, stripe_mock):
    stripe_mock.PaymentIntent.create.return_value = mock.Mock(
        client_secret='secret')

    response = post_intent(client, {'paymentMethodType': 'link',
                                    'currency': 'usd'})

    assert response.status_code == 200
    params = stripe_mock.PaymentIntent.create.call_args.kwargs
    assert params['payment_method_types'] == ['link', 'card']


def test_acss_debit_adds_mandate_options(client, stripe_mock):
    stripe_mock.PaymentIntent.create.return_value = mock.Mock(
        client_secret='secret')

    response = post_intent(client, {'paymentMethodType': 'acss_debit',
                                    'currency': 'cad'})

    assert response.status_code == 200
    params = stripe_mock.PaymentIntent.create.call_args.kwargs
    assert params['currency'] == 'cad'
    assert params['payment_method_options'] == {
        'acss_debit': {
            'mandate_options': {
                'payment_schedule': 'sporadic',
                'transaction_type': 'personal',
            }
        }
    }


def test_uses_tax_calculation_when_enabled(client, stripe_mock,
                                           server_module, monkeypatch):
    monkeypatch.setattr(server_module, 'calcuateTax', True)
    stripe_mock.tax.Calculation.create.return_value = {
        'id': 'taxcalc_123', 'amount_total': 6499}
    stripe_mock.PaymentIntent.create.return_value = mock.Mock(
        client_secret='secret')

    response = post_intent(client, {'paymentMethodType': 'card',
                                    'currency': 'usd'})

    assert response.status_code == 200
    tax_params = stripe_mock.tax.Calculation.create.call_args.kwargs
    assert tax_params['currency'] == 'usd'
    assert tax_params['line_items'][0]['amount'] == 5999
    params = stripe_mock.PaymentIntent.create.call_args.kwargs
    assert params['amount'] == 6499
    assert params['metadata'] == {'tax_calculation': 'taxcalc_123'}


@pytest.mark.parametrize('body', [
    {'currency': 'usd'},
    {'paymentMethodType': 'card'},
    {},
])
def test_missing_params_raise_key_error(client, stripe_mock, body):
    with pytest.raises(KeyError):
        post_intent(client, body)

    stripe_mock.PaymentIntent.create.assert_not_called()


def test_invalid_json_body_raises(client, stripe_mock):
    with pytest.raises(json.JSONDecodeError):
        client.post('/create-payment-intent', data='not json',
                    content_type='application/json')

    stripe_mock.PaymentIntent.create.assert_not_called()


def test_stripe_error_returns_400(client, stripe_mock):
    stripe_mock.error = real_stripe.error
    stripe_mock.PaymentIntent.create.side_effect = (
        real_stripe.error.InvalidRequestError('Invalid currency: xyz',
                                              'currency'))

    response = post_intent(client, {'paymentMethodType': 'card',
                                    'currency': 'xyz'})

    assert response.status_code == 400
    assert response.get_json() == {
        'error': {'message': 'Invalid currency: xyz'}}


def test_unexpected_error_returns_400(client, stripe_mock):
    stripe_mock.error = real_stripe.error
    stripe_mock.PaymentIntent.create.side_effect = RuntimeError('boom')

    response = post_intent(client, {'paymentMethodType': 'card',
                                    'currency': 'usd'})

    assert response.status_code == 400
    assert response.get_json() == {'error': {'message': 'boom'}}
