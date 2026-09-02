def test_config_returns_publishable_key(client, monkeypatch):
    monkeypatch.setenv('STRIPE_PUBLISHABLE_KEY', 'pk_test_from_env')

    response = client.get('/config')

    assert response.status_code == 200
    assert response.get_json() == {'publishableKey': 'pk_test_from_env'}


def test_config_returns_null_when_key_missing(client, monkeypatch):
    monkeypatch.delenv('STRIPE_PUBLISHABLE_KEY', raising=False)

    response = client.get('/config')

    assert response.status_code == 200
    assert response.get_json() == {'publishableKey': None}
