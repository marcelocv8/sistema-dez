import { useEffect, useState, type ButtonHTMLAttributes, type HTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from 'react';
import { AlertTriangle, LoaderCircle } from 'lucide-react';
import logoUrl from './assets/logo-clinica-dez.png';

export function BrandLogo({ className = '', inverted = false }: { className?: string; inverted?: boolean }) {
  return <img className={`dez-brand ${inverted ? 'dez-brand--inverted' : ''} ${className}`} src={logoUrl} alt="Clínica DEZ" width={323} height={117} decoding="async" />;
}

/** Debounces a fast-changing value (e.g. a search input) to avoid a request per keystroke. */
export function useDebouncedValue<T>(value: T, delay = 350): T {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(timer);
  }, [value, delay]);
  return debounced;
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'navy' | 'success' | 'warning' | 'danger' | 'purple' | 'outline'; icon?: ReactNode };
export function Button({ variant = 'primary', icon, className = '', children, ...props }: ButtonProps) {
  return <button className={`dez-button dez-button--${variant} ${className}`} {...props}>{icon}{children}</button>;
}

export function IconButton({ label, children, className = '', ...props }: ButtonHTMLAttributes<HTMLButtonElement> & { label: string }) {
  return <button aria-label={label} title={label} className={`dez-icon-button ${className}`} {...props}>{children}</button>;
}

export function Panel({ className = '', children, ...props }: HTMLAttributes<HTMLElement>) {
  return <section className={`dez-panel ${className}`} {...props}>{children}</section>;
}

export function SectionTitle({ title, action, subtitle }: { title: string; subtitle?: string; action?: ReactNode }) {
  return <header className="dez-section-title"><div><h2>{title}</h2>{subtitle ? <p>{subtitle}</p> : null}</div>{action}</header>;
}

export function StatusPill({ tone, children }: { tone: 'success' | 'warning' | 'danger' | 'purple' | 'info' | 'neutral'; children: ReactNode }) {
  return <span className={`dez-status dez-status--${tone}`}>{children}</span>;
}

export function Alert({ tone = 'warning', title, children, action }: { tone?: 'warning' | 'danger' | 'info'; title: string; children: ReactNode; action?: ReactNode }) {
  return <div className={`dez-alert dez-alert--${tone}`}><AlertTriangle aria-hidden="true" size={18} /><div><strong>{title}</strong><p>{children}</p></div>{action}</div>;
}

export function TextField({ label, ...props }: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  return <label className="dez-field"><span>{label}</span><input {...props} /></label>;
}

export function SelectField({ label, children, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { label: string; children: ReactNode }) {
  return <label className="dez-field"><span>{label}</span><select {...props}>{children}</select></label>;
}

export function LoadingState({ label = 'Carregando' }: { label?: string }) {
  return <div className="dez-loading" role="status"><LoaderCircle className="dez-spin" size={20} />{label}</div>;
}

export function EmptyState({ title, description }: { title: string; description: string }) {
  return <div className="dez-empty"><strong>{title}</strong><p>{description}</p></div>;
}
