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

- `backend/` — API Laravel 13. Contrato em `backend/openapi.yaml`. Servida em
  dev pelo FrankenPHP via `backend/Caddyfile` (ver §5).
  Importação do legado lê `STG_IMPORT_PATH` (`/data/stg` no Docker); as fontes
  físicas do STG estão em `C:\Projects\STG\dezstg2\_extracoes_stg` (fora do repo).
- `frontend/` — pnpm monorepo (`apps/`, `packages/`). Mock MSW só com `VITE_USE_MSW=true`.
- `docs/plano_sistema_dez.md` — especificação viva; §14 registra o andamento.
- Legado e referências (NÃO mover para cá): `C:\Projects\STG\dezstg2`,
  `C:\Projects\STG\_extracoes_stg`, `C:\Projects\STG\_extracoes_laudos`,
  `C:\Projects\STG\mockups_sistema_clinica_dez`, `C:\Projects\STG\VALIDACAO_LAUDOS.md`.

## 5. Ambiente local do dono (PHP nativo — PROIBIDO composer/teste via Docker)

- O PC do dono tem **PHP 8.4 nativo** em `%LOCALAPPDATA%\php\php.exe` (com
  `composer.phar` ao lado e `pdo_pgsql`) e o PATH de usuário já inclui a pasta.
  **NUNCA** rode `composer install`, Pint, PHPUnit/artisan-test nem `php artisan
  serve` dentro de containers (`docker run composer`, `compose exec api`, etc.):
  o bind-mount do Docker Desktop no Windows trava o PC inteiro. Docker serve
  **só** para `postgres` + `redis`.
- **API/HTTP (dev):** a API local roda no **FrankenPHP nativo no Windows**, não
  no `php artisan serve`. Binário em
  `%LOCALAPPDATA%\frankenphp\frankenphp.exe` (PHP 8.5 embutido, ~32 threads) com
  `php.ini` próprio ao lado, habilitando as mesmas extensões do PHP nativo
  (`pdo_pgsql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `intl`, `zip`).
  Dentro de `backend/`:
  - subir: `& "$env:LOCALAPPDATA\frankenphp\frankenphp.exe" run --config Caddyfile`
  - parar: `Get-CimInstance Win32_Process -Filter "Name='frankenphp.exe'" | ForEach-Object { Stop-Process -Id $_.ProcessId -Force }`
  - fallback single-thread: `php artisan serve --port=8000`.
  `PHP_CLI_SERVER_WORKERS` **não funciona no Windows** (o servidor embutido
  depende de `fork`) — não perca tempo com ele. Detalhes e medições em
  `docs/plano_sistema_dez.md` §14.24.
- **OPcache** já está habilitado no `php.ini` nativo (`opcache.enable=1`,
  `opcache.enable_cli=1`) — o fallback `php artisan serve` também se beneficia.
- Comandos padrão (sempre com caminho completo do PHP, pois cada chamada de
  shell nasce sem o PATH recarregado):
  - `& "$env:LOCALAPPDATA\php\php.exe" "$env:LOCALAPPDATA\php\composer.phar" install`
    em `backend/`; `vendor/` é gitignored.
  - Pint: `php vendor/bin/pint --dirty` (obrigatório após editar PHP).
  - Testes: `php artisan test --compact` em `backend/` com
    `DB_CONNECTION=pgsql DB_HOST=localhost DB_PORT=5433 DB_DATABASE=sistema_dez_test
    DB_USERNAME=sistema_dez DB_PASSWORD=local_change_me` (mesmas do CI em
    `.github/workflows/backend-tests.yml`). Nunca em outro banco.
- Frontends dev sobem com `VITE_API_URL` explícito, senão chamam o próprio Vite:
  `$env:VITE_API_URL='http://localhost:8000'; pnpm --filter @dez/equipe dev --port 5175`
  (agendamento na 5174). Sem `VITE_API_URL`, login e catálogo falham com
  "Não foi possível concluir a solicitação."
- API de E2E (porta 18001, banco `sistema_dez_test`, seed `SchedulingE2ESeeder`
  com `E2E_PASSWORD` ≥ 12 chars): subir com `SESSION_DRIVER=database`,
  `CACHE_STORE=database` e `SANCTUM_STATEFUL_DOMAINS` contendo **só a porta da
  equipe (4175)** — a porta do agendamento (4176) tem que ficar DE FORA, senão
  o POST público anônimo cai no `VerifyCsrfToken` (419) por causa do
  `statefulApi()`. Nunca `SESSION_DRIVER=array` (sessão morre entre requests).
- Segredos locais do compose vivem em `backend/compose.local.yaml` (untracked,
  **nunca commitar**); todo `docker compose up` precisa de
  `-f compose.yaml -f compose.local.yaml`. Antes de parar containers, confira
  que não vai derrubar o uso do dono.
