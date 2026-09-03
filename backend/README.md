# Sistema DEZ API

Backend do Sistema Próprio DEZ em Laravel 13. Inclui catálogo público, agendamento transacional, preços conciliados do STG e a administração autenticada de catálogo, vínculos, usuários, pendências e auditoria.

## Executar localmente

Com PHP 8.3+ e Composer:

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Com Docker Desktop funcionando:

```bash
docker compose up --build
```

A API fica em `http://localhost:8000`; PostgreSQL em `localhost:5433` e Redis em `localhost:6379`.

### Docker Desktop: erro em `dockerInference`

Se o Model Runner deixar um reparse point corrompido após um desligamento incompleto, encerre totalmente o Docker Desktop, remova a tag com `fsutil reparsepoint delete "%LOCALAPPDATA%\Docker\run\dockerInference"`, apague somente esse arquivo e abra o Docker novamente. O socket deve ser recriado pelo próprio Docker. Acompanhe também o [docker/desktop-feedback#625](https://github.com/docker/desktop-feedback/issues/625).

## Contrato público v1

- `GET /api/v1/public/services`: catálogo filtrável por unidade, convênio, modalidade e tipo.
- `GET /api/v1/public/availability`: disponibilidade em períodos de até 30 dias.
- `POST /api/v1/public/appointments`: reserva idempotente de horário ou bloco de chegada.

Os modos de oferta são `FIXED_SLOT`, `ARRIVAL_BLOCK` e `WALK_IN_INFO`. Em blocos de chegada, somente o primeiro bloco com capacidade é oferecido. A ordem efetiva de atendimento será definida no check-in e não pelo horário indicativo.

O contrato detalhado está em [openapi.yaml](openapi.yaml).

## Catálogo STG e acesso da equipe

O importador exige as nove fontes da extração STG (legado, fora deste repo).
No Docker, a pasta apontada por `STG_IMPORT_SOURCE` é montada como `/data/stg`
somente para leitura (padrão: `C:/Projects/STG/dezstg2/_extracoes_stg` na máquina
do dono; ver `compose.yaml`).

```bash
php artisan stg:import-catalog --dry-run
php artisan stg:import-catalog --commit
php artisan stg:resolve-issues --dry-run
php artisan stg:resolve-issues --commit
php artisan team:user recepcao@exemplo.com --name="Nome da pessoa" --role=RECEPTION
```

O resolvedor aplica somente as decisões conhecidas no plano, em uma transação, e mantém combinações novas como `OPEN`. O comando de usuário solicita a senha sem eco quando `--password` não é informado. Não há senha padrão. A equipe autentica via Sanctum stateful/cookie e consulta `GET /api/v1/team/prices`; preços em quarentena nunca aparecem nessa resposta.

## Administração da equipe

- `/api/v1/team/catalog/{resource}`: CRUD com desativação para unidades, convênios, procedimentos e profissionais.
- `/api/v1/team/catalog-links`: vínculos profissional–procedimento–convênio–unidade, separados de `service_offerings`.
- `/api/v1/team/import-issues`: tratamento auditado das pendências da última importação.
- `/api/v1/team/users`: usuários e perfis; o último Admin ativo não pode ser removido.
- `/api/v1/team/audit-logs`: trilha antes/depois das alterações administrativas.

Recepção possui leitura do catálogo; Supervisor administra catálogo, vínculos e reconciliação; Admin também administra usuários. Desativação preserva o histórico e nenhum vínculo cadastral publica agenda automaticamente.

## Verificação

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
```
