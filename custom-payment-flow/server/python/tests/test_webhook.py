import json


def post_webhook(client, payload, signature=None):
    headers = {}
    if signature:
        headers['stripe-signature'] = signature
    return client.post('/webhook', data=json.dumps(payload),
                       content_type='application/json', headers=headers)


def test_verifies_signature_and_handles_succeeded_event(client, stripe_mock,
                                                        monkeypatch, capsys):
    monkeypatch.setenv('STRIPE_WEBHOOK_SECRET', 'whsec_test')
    payload = {'type': 'payment_intent.succeeded',
               'data': {'object': {'id': 'pi_123'}}}
    stripe_mock.Webhook.construct_event.return_value = payload

    response = post_webhook(client, payload, signature='t=1,v1=sig')

    assert response.status_code == 200
    assert response.get_json() == {'status': 'success'}
    stripe_mock.Webhook.construct_event.assert_called_once_with(
        payload=json.dumps(payload).encode(),
        sig_header='t=1,v1=sig',
        secret='whsec_test',
    )
    assert 'Payment received' in capsys.readouterr().out


def test_handles_payment_failed_event_without_secret(client, stripe_mock,
                                                     monkeypatch, capsys):
    monkeypatch.delenv('STRIPE_WEBHOOK_SECRET', raising=False)
    payload = {'type': 'payment_intent.payment_failed',
               'data': {'object': {'id': 'pi_123'}}}

    response = post_webhook(client, payload)

    assert response.status_code == 200
    assert response.get_json() == {'status': 'success'}
    stripe_mock.Webhook.construct_event.assert_not_called()
    assert 'Payment failed' in capsys.readouterr().out
