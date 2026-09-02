import importlib
import os
import sys
from unittest import mock

import pytest

HERE = os.path.dirname(os.path.abspath(__file__))
SERVER_DIR = os.path.dirname(HERE)

if SERVER_DIR not in sys.path:
    sys.path.insert(0, SERVER_DIR)


@pytest.fixture(scope='session')
def server_module():
    # The server resolves STATIC_DIR at import time and configures the stripe
    # client from env, so provide offline-safe values before importing it.
    env = {
        'STATIC_DIR': 'static-test',
        'STRIPE_SECRET_KEY': 'sk_test_fake',
        'STRIPE_PUBLISHABLE_KEY': 'pk_test_fake',
    }
    with mock.patch.dict(os.environ, env):
        sys.modules.pop('server', None)
        return importlib.import_module('server')


@pytest.fixture
def stripe_mock(server_module, monkeypatch):
    fake_stripe = mock.MagicMock(name='stripe')
    monkeypatch.setattr(server_module, 'stripe', fake_stripe)
    return fake_stripe


@pytest.fixture
def client(server_module, stripe_mock):
    server_module.app.config['TESTING'] = True
    with server_module.app.test_client() as test_client:
        yield test_client
