export type UUID = string;
export type Money = { amount: string; currency: 'BRL' };

export type AppointmentStatus = 'CONFIRMED' | 'CHECKED_IN' | 'CANCELLED' | 'DONE';
export type QueueStatus = 'WAITING' | 'IN_SERVICE' | 'CHECKOUT' | 'FINISHED';
export type Permission =
  | 'prices.view'
  | 'catalog.view'
  | 'team.manage'
  | 'catalog.manage'
  | 'catalog-links.view'
  | 'catalog-links.manage'
  | 'team.view'
  | 'imports.view'
  | 'imports.resolve'
  | 'audit.view'
  | 'schedule.view'
  | 'schedule.manage'
  | 'appointments.manage'
  | 'queue.call'
  | 'cash.view'
  | 'cash.operate'
  | 'cash.close'
  | 'discounts.validate'
  | 'payouts.view'
  | 'payouts.manage'
  | 'payouts.confirm'
  | 'payouts.pay'
  | 'b2b.view'
  | 'b2b.manage'
  | 'b2b.settle'
  | 'attendance.view'
  | 'chat.use'
  | 'budgets.view'
  | 'budgets.manage'
  | 'routine.view'
  | 'routine.tick'
  | 'routine.manage'
  | 'reports.view'
  | 'reports.write'
  | 'reports.sign'
  | 'prontuario.view'
  | 'prontuario.write'
  | 'ai.use'
  | 'soudez.view'
  | 'soudez.manage';

export const clinicTimeZone = 'America/Fortaleza';

export function formatMoney(value: Money | string | number): string {
  const amount = typeof value === 'object' ? Number(value.amount) : Number(value);
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(amount);
}

export function formatClinicDate(value: string | Date, options?: Intl.DateTimeFormatOptions): string {
  return new Intl.DateTimeFormat('pt-BR', {
    timeZone: clinicTimeZone,
    day: '2-digit',
    month: 'long',
    year: 'numeric',
    ...options,
  }).format(new Date(value));
}
