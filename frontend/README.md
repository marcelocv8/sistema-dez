# Ecossistema Clínica DEZ — frontend

Monorepo React, Vite e TypeScript do primeiro ciclo visual do sistema próprio.

## Aplicativos

- `apps/equipe`: dashboard, agenda, recepção e workspace de laudos.
- `apps/agendamento`: agendamento público mobile-first integrado ao contrato `/api/v1/public`.
- `apps/painel-tv`: painel de chamadas e fila.
- `apps/paciente`: shell PWA do portal do paciente.

Os pacotes `ui`, `domain` e `api` concentram, respectivamente, a identidade visual, as regras compartilhadas e os clientes/contratos. O backend real é o padrão; configure `VITE_API_URL=http://localhost:8000` quando necessário. Os mocks só são ativados explicitamente com `VITE_USE_MSW=true`.

Os fluxos reais disponíveis no app equipe são login → `/precos` para consulta operacional e login → `/cadastros` para catálogo, vínculos, reconciliação, usuários e auditoria conforme as permissões do perfil.

## Comandos

```bash
pnpm install
pnpm dev:equipe
pnpm dev:agendamento
pnpm build
pnpm typecheck
pnpm test
pnpm test:e2e
pnpm storybook
```

A logo utilizada é o ativo oficial limpo, sem assinatura ou dizeres adicionais. A interface usa a paleta aprovada: azul estrutural, teal da marca e cores semânticas independentes.
