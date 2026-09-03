# AGENTS.md — Sistema DEZ (`marcelocv8/sistema-dez`)

> Estas regras valem para **qualquer agente** operando neste repo. Em caso de
> conflito com instruções de outro repo, **esta página manda** dentro de
> `C:\Projects\sistema-dez\`.

## 1. Onde commitar e dar push (regra anti-repo-errado)

- **Repo oficial e único destino:** `marcelocv8/sistema-dez`
  (`https://github.com/marcelocv8/sistema-dez.git`), branch **`main`**.
- **Workdir permitido para git:** `C:\Projects\sistema-dez\` **apenas**.
- **PROIBIDO** commitar, dar push ou abrir PR a partir de:
  `C:\Projects\STG\` (legado + área de extração), `C:\Projects\Chat App Crédito\`,
  ou qualquer outra pasta. O dono tem múltiplos repos — na dúvida, **pare e pergunte**.
- Antes de qualquer operação git, rode `git remote -v` e confirme que o único
  remoto é `marcelocv8/sistema-dez`. Se aparecer `laravel/laravel` ou outro
  owner, **ABORTE** e avise o dono.
- Nunca `git push --force`, nunca `--no-verify`, nunca commit vazio.

## 2. Commit e push SOMENTE com autorização explícita do dono

- **É proibido commitar ou dar push automaticamente** ao concluir uma
  implementação de plano, tarefa ou fase — mesmo que o plano peça.
- Fluxo obrigatório:
  1. Implemente e valide localmente (testes descritos no plano).
  2. Mostre `git status --short --branch` + `git diff --stat` e **peça autorização**.
  3. Só após o dono responder "pode commitar/pode dar push", execute.
- Exceção única: o dono escreveu explicitamente "commit/push autorizado" na
  mensagem da tarefa atual. Autorização de uma tarefa **não vale** para a próxima.

## 3. Checklist pré-commit (sempre)

- [ ] `git status --short --branch` revisado — só arquivos intencionais.
- [ ] `git diff` lido — sem segredo, sem dado de paciente, sem dump.
- [ ] Nenhum destes no stage: `.env*`, `*.dump`, `*.sql`, `storage/*.key`,
      `auth.json`, `vendor/`, `node_modules/`, `playwright-report/`, `test-results/`.
- [ ] `composer.lock` / `pnpm-lock.yaml` incluídos se dependências mudaram.
- [ ] Mensagem curta no padrão do repo (`feat:`, `fix:`, `chore:`, `docs:`).

## 4. Mapa do repo (para agentes novos)

- `backend/` — API Laravel 13. Contrato em `backend/openapi.yaml`.
  Importação do legado lê `STG_IMPORT_PATH` (`/data/stg` no Docker); as fontes
  físicas do STG estão em `C:\Projects\STG\dezstg2\_extracoes_stg` (fora do repo).
- `frontend/` — pnpm monorepo (`apps/`, `packages/`). Mock MSW só com `VITE_USE_MSW=true`.
- `docs/plano_sistema_dez.md` — especificação viva; §14 registra o andamento.
- Legado e referências (NÃO mover para cá): `C:\Projects\STG\dezstg2`,
  `C:\Projects\STG\_extracoes_stg`, `C:\Projects\STG\_extracoes_laudos`,
  `C:\Projects\STG\mockups_sistema_clinica_dez`, `C:\Projects\STG\VALIDACAO_LAUDOS.md`.
