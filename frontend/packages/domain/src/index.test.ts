import { describe, expect, it } from 'vitest';
import { formatMoney } from './index';

describe('formatMoney', () => {
  it('formats decimal strings without floating UI artifacts', () => {
    expect(formatMoney({ amount: '24560.00', currency: 'BRL' })).toContain('24.560,00');
  });
});
