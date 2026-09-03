import { FormEvent, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { api, type CurrentUser, type ProblemDetails } from '@dez/api';
import { BrandLogo, Button, TextField } from '@dez/ui';

export function LoginPage({ onAuthenticated }: { onAuthenticated: (user: CurrentUser) => void }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const login = useMutation({
    mutationFn: api.login,
    onSuccess: onAuthenticated,
  });

  function submit(event: FormEvent) {
    event.preventDefault();
    login.mutate({ email, password });
  }

  const error = login.error as ProblemDetails | null;

  return <main className="login-page">
    <form className="login-card" onSubmit={submit}>
      <BrandLogo />
      <div><h1>Acesso da equipe</h1><p>Entre com sua conta do Sistema DEZ.</p></div>
      <TextField label="E-mail" type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} required />
      <TextField label="Senha" type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} required />
      {error ? <p className="form-error" role="alert">{error.message}</p> : null}
      <Button type="submit" disabled={login.isPending}>{login.isPending ? 'Entrando...' : 'Entrar'}</Button>
    </form>
  </main>;
}
