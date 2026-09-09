import {GET} from '@/app/api/config/route';

describe('GET /api/config', () => {
  it('returns the publishable key', async () => {
    const response = await GET();

    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({publishableKey: 'pk_test_123'});
  });
});
