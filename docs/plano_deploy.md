# Plano de Deploy — Sistema DEZ no servidor da Clínica DEZ

> **Versão 1.3 — 22/09/2026**
> Documento **operacional** (infra/deploy). O plano de **produto** é
> `docs/plano_sistema_dez.md` (lá está o §16, que aponta para este arquivo).
>
> **v1.3:** correções descobertas na execução real no servidor (§20.3) —
> quoting do `-v` no `provision.sql`, `bootstrap.sh` sem depender do `vendor/`
> (`APP_KEY` via `openssl`), `provision.sql` versionado (`*.sql` do `.gitignore`),
> crases executadas dentro de heredoc, URL do asset do runner do GitHub (o nome
> inclui a versão) e ordem ajustada: `.env` → túnel → **primeiro deploy do CI**
> → migrations e usuários. Sequência remota atualizada no §21.
>
> **v1.2:** checagens do servidor registradas em §3.3 (senha do `postgres`,
> `max_connections`, `password_encryption=md5`, hostname livre, baseline do STG);
> pendências **P2/P3/P4 fechadas**; ordem de bootstrap **sem transferência de
> arquivos** (§3.4, §6.4) e `tunnel.sh` passou a entregar as credenciais para o
> usuário `dez`; sequência remota da F1 em §21.
>
> **v1.1:** F2 implementada e validada localmente (código, `deploy/`, workflow);
> Anexo A sincronizado com o `Caddyfile` real (correção do prefixo sem barra e
> preservação de query string); pendências consolidadas no §20.2.
>
> **Contexto:** o Sistema DEZ vai rodar em **fase de teste**, **na mesma máquina
> física** que serve o **STG de produção**, com URL pública estável, dados reais
> importados do legado e atualização por `git push` → `git pull` + redeploy.
> **O STG permanece sendo a produção principal.**

---

## 0. TL;DR

| Item | Decisão |
|---|---|
| URL | `https://dez.clinicadez.com.br` (Cloudflare Tunnel **novo e separado** do túnel do STG) |
| Runtime | **FrankenPHP static** (PHP 8.5 embutido) em `127.0.0.1:8002` — **sem Docker, sem PHP no host** |
| Banco | **PostgreSQL 16 existente** (`127.0.0.1:5432`), database `sistema_dez` + role com limites |
| Frontends | 4 SPAs (equipe, agendamento, paciente, painel-tv) buildados no **CI**, servidos sob `/app/ /agendar/ /resultados/ /painel/` |
| Build | **100% no GitHub Actions** (nada de `pnpm`/`composer` no servidor — 2 vCPU/1,9 GB livres) |
| Deploy | botão **"Run workflow"** no GitHub → self-hosted runner → `/opt/dez/deploy.sh` → rollback automático |
| Login | **somente o login do app** (um usuário por pessoa, RBAC). Sem camada extra (decisão do dono) |
| Backup | diário 03:00 (`pg_dump -Fc` + `storage` + `.env`), retenção 14 diários / 4 semanais |
| Dados | catálogo + preços + modelos de laudo + MT importados do STG; pacientes/atendimentos via importador novo (F7) |

---

## 1. Objetivo e escopo

1. Disponibilizar o Sistema DEZ para a equipe testar, de dentro e de fora da
   clínica, em **URL estável** e **HTTPS**.
2. **Não degradar o STG**: nenhuma alteração em Apache, vhost `stg8080.conf`,
   banco legado, túnel atual do Cloudflare ou serviço Java do painel.
3. Permitir **atualização remota** com um clique: `git push` (autorizado) →
   workflow → `git pull` + redeploy no servidor.
4. Ter **volume de dados realista** para o teste: catálogo, preços, modelos de
   laudo, MT e (F7) pacientes/atendimentos do legado.
5. Ter **backup diário** e **rollback** confiáveis.

**Fora de escopo agora:** virar produção, desligar o STG, 2FA, conciliação
Stone/Inter, WhatsApp (fica `WHATSAPP_ENABLED=false`).

---

## 2. Decisões fechadas

| # | Decisão | Motivo |
|---|---|---|
| D1 | **Conviver com o STG na mesma máquina**, isolando tudo em `127.0.0.1` e em diretórios/serviços próprios | Decisão do dono |
| D2 | **PostgreSQL compartilhado** (cluster 16 existente) com role limitado | Só há 2 vCPU / 3,7 GB (≈1,9 GB livres) e `systemd-oomd` ativo; um 2º cluster custaria ~150–250 MB |
| D3 | **Sem Docker** | Não instalado; evitar daemon extra num host de produção |
| D4 | **Sem PHP no host** — FrankenPHP static (PHP 8.5) | Ubuntu 24.04 traz PHP 8.3, mas `endroid/qr-code` exige `^8.4`; e mexer no PHP do host pode afetar cron do STG |
| D5 | **Sem Tailscale** | O domínio já está no Cloudflare e já há `cloudflared` rodando |
| D6 | **Túnel novo e separado** (`dez`), nunca editar o túnel `dd9ef614-…` | O túnel atual serve o **STG** (`sistema.clinicadez.com.br` → `127.0.0.1:8080`); reiniciá-lo derrubaria a produção |
| D7 | **Build no CI** (GitHub Actions), executar no servidor só o artefato pronto | `vite build` dos 4 apps pica 600 MB–1,2 GB; no servidor isso pode acionar o OOM killer |
| D8 | **Deploy por self-hosted runner** no servidor | Dá o "botão único" remoto sem expor endpoint privilegiado na internet |
| D9 | **Sem camada extra de login** (nem Basic Auth, nem Cloudflare Access) | Decisão do dono: apenas o login do app, um usuário por pessoa com RBAC |
| D10 | **Backup diário** ativo | Decisão do dono (mudou de ideia em relação ao início) |
| D11 | `systemd` com `MemoryMax=` em todos os units do DEZ | Em OOM, o kernel mata **o nosso** serviço, nunca o STG |

---

## 3. Inventário do servidor (verificado em 21/09/2026)

| Item | Valor |
|---|---|
| SO | Ubuntu **24.04.2 LTS** (Noble), kernel 7.0.0-31, bare metal (`systemd-detect-virt: none`) |
| Hardware | **2 vCPU**, 3,7 GiB RAM (1,9 GiB usados, ~**1,9 GiB disponíveis**), swap 3,7 GiB (427 MiB em uso), disco 109 GB com **81 GB livres** |
| Hostname / IP | `server-H110M-H-DDR3` / **192.168.0.170/24** (`enp1s0`) |
| Web (STG) | `apache2` em `*:80` e `127.0.0.1:8080`; vhost `stg8080.conf` → `DocumentRoot /home/server/projetos/dezstg2` |
| Banco (STG) | `postgresql@16-main` em **127.0.0.1:5432** (não exposto na rede) |
| Túnel atual | `cloudflared.service` (ativo), config `/etc/cloudflared/config.yml`, túnel `dd9ef614-b95d-4a49-a4a3-cc9b8c9f6c63`, ingress `sistema.clinicadez.com.br → http://127.0.0.1:8080`, credenciais em `/home/server/.cloudflared/` |
| Domínio | `clinicadez.com.br` com NS **Cloudflare** (`evelyn.ns.cloudflare.com`, `lars.ns.cloudflare.com`) |
| Serviço Java | `painel.service` (`painelWeb-20.0.1-SNAPSHOT.jar`, ~247 MB RSS) escutando em `*:8099` |
| Outros | Samba (`smbd`/`nmbd`), AnyDesk (7070/59244), CUPS, GNOME (`gdm`, `gnome-shell` ~206 MB), `unattended-upgrades` **enabled** |
| Ferramentas | `git 2.43.0` ✅ · `cloudflared` ✅ · `java` ✅ · **ausentes:** docker, node, npm, pnpm, tailscale |
| `pg_hba.conf` | **tudo `md5`** — `local all postgres md5`, `local all all md5`, `host all all 127.0.0.1/32 md5`, `host all all ::1/128 md5`, **`host all all 0.0.0.0/0 md5`** |
| Portas livres para nós | **8002** (borda/app) e **2019** (admin Caddy, ficará desligado) |

### 3.1 Consumo de memória observado (RSS)

| Processo | RSS |
|---|---|
| `java` (painelWeb) | 247 MB |
| `gnome-shell` | 206 MB |
| `postgres` (2 processos) | ~281 MB |
| **`apache2` (10+ processos)** | **~630 MB** |
| `cloudflared` | 27 MB |

> ⚠️ O Apache do STG é o maior consumidor da máquina. **Não encostar.**
> Nosso orçamento de RAM para o DEZ: **~300–350 MB** (FrankenPHP + runner + túnel).

### 3.2 Riscos herdados do ambiente (não são nossos, mas afetam)

- `systemd-oomd` ativo + swap já em uso → qualquer build/import pesado pode
  acionar o OOM killer. **Mitigação:** build no CI, `MemoryMax`, imports fora
  do horário da clínica.
- GNOME (`gnome-shell` ~206 MB) num servidor → dá para liberar trocando para
  `multi-user.target`, **mas isso quebra a visão de desktop do AnyDesk**.
  Fica como alavanca futura, só a pedido.
### 3.3 Checagens executadas no servidor (22/09/2026)

Comandos rodados no terminal do servidor (via AnyDesk) e resultados. Servem de
**baseline** — se algum destes mudar de forma inesperada, algo saiu do previsto.

```bash
# recursos / ferramentas
free -h; df -h /var                          # → 1,8 Gi disponíveis; 80 G livres em /
curl -sS -o /dev/null -w '%{http_code}\n' https://github.com   # → 200
git --version                                # → 2.43.0
ss -lntp | grep 8002 || echo "8002 LIVRE"    # → 8002 LIVRE

# baseline do STG (deve continuar idêntico no fim da F1)
curl -sI https://sistema.clinicadez.com.br | head -3   # → HTTP/2 200
sudo systemctl is-active apache2 postgresql@16-main cloudflared painel
                                             # → active / active / active / active

# túnel atual (o do STG) — existe UM só, e não será tocado
cloudflared tunnel list                      # → dd9ef614-…  clinica-dez-stg
ls -la ~/.cloudflared/                       # → cert.pem + dd9ef614-….json (usuário server)

# PostgreSQL (superusuário confirmado na config do STG; db legado 'dez')
PGPASSWORD='<senha-do-postgres>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres -At -P pager=off \
  -c "select datname || ' ' || pg_size_pretty(pg_database_size(datname)) from pg_database order by pg_database_size(datname) desc limit 15;"
# → dez 2403 MB | painelWeb 8215 kB | postgres 7503 kB | template1 7409 kB | template0 7345 kB
PGPASSWORD='<senha-do-postgres>' psql ... -c "show max_connections;"       # → 100
PGPASSWORD='<senha-do-postgres>' psql ... -c "show password_encryption;"   # → md5

# hostnames de teste
dig +short dez.clinicadez.com.br                             # → vazio (LIVRE)
dig +short sistema-dez.clinicadez.com.br                     # → vazio (LIVRE)
```

| Conclusão | Efeito |
|---|---|
| a senha do `postgres` (achada na config do STG) funciona, db legado é **`dez`** (2,4 GB) | P2 resolvido; MT sai de `psql -d dez` |
| `max_connections = 100` | `CONNECTION LIMIT 15` do role segue folgado |
| `password_encryption = md5` | **O role nasce em MD5 e casa com o `pg_hba` — zero mudança no `pg_hba`.** P3 resolvido |
| `dez.clinicadez.com.br` livre | P4 resolvido |
| Só existe 1 túnel (`clinica-dez-stg`) | Criaremos um UUID novo; o do STG não é lido nem editado |
| `cloudflared` 2026.7.3 (desatualizado) | **Não atualizar**: o upgrade reinicia o túnel que serve o STG |
| Serviços do STG `active`, HTTP/2 200 | Baseline gravado para conferência no fim da F1 |

> **Alternativa ao `PGPASSWORD`:** se o pager voltar a travar, use
> `export PSQL_PAGER=cat` (ou `-P pager=off`, como acima). Aperte `q` para sair
> do `less`.

### 3.4 Ordem de bootstrap adotada (sem transferência de arquivos)

O `install.sh` vive **dentro** do repo, mas o repo ainda não está no servidor.
Solução adotada (dispensa `scp`/AnyDesk-file-transfer):

1. criar o usuário `dez` + `/opt/dez/.ssh` (mínimo);
2. gerar a **deploy key** e registrá-la no GitHub (read-only);
3. `git clone` para `/opt/dez/app` já usando a chave;
4. rodar `deploy/scripts/install.sh` **de dentro do clone** (idempotente — detecta usuário e repo existentes e apenas ajusta `core.sshCommand`).

Detalhes na seção 6.4 e o passo a passo em `deploy/README.md`.

---

## 4. Topologia

```
Internet
   │  HTTPS
   ▼
Cloudflare (proxy)  ──  dez.clinicadez.com.br
   │  (DNS CNAME criado por `cloudflared tunnel route dns`)
   ▼
cloudflared  ──  dez-tunnel.service   (TÚNEL NOVO, isolado)
   │  http://127.0.0.1:8002
   ▼
FrankenPHP static   127.0.0.1:8002          [dez-web.service]
   ├── /app/*        → /opt/dez/frontend/equipe        (SPA + login do app)
   ├── /agendar/*    → /opt/dez/frontend/agendamento   (SPA pública)
   ├── /resultados/* → /opt/dez/frontend/paciente      (portal, credencial temporária)
   ├── /painel/*     → /opt/dez/frontend/painel-tv     (TV)
   └── /*            → /opt/dez/app/backend/public     (Laravel: /api/v1, /sanctum, /up)
   ▼
PostgreSQL 16 existente   127.0.0.1:5432
   └── database sistema_dez  ·  role sistema_dez (limites de sessão)
```

---

## 5. Layout no servidor

```
/opt/dez/
├── bin/
│   ├── frankenphp              # binário estático (root:root 755)
│   ├── php.ini                 # (root:root 644) usado via PHPRC
│   └── artisan                 # wrapper: frankenphp php-cli .../artisan "$@"
├── Caddyfile                   # (root:root 644)
├── app/                        # git clone do repo (dez:dez)  ← única coisa que o git toca
│   ├── backend/  frontend/  docs/  deploy/  .github/
├── frontend/                   # dists publicados pelo CI (dez:dez) — FORA do git
│   ├── equipe/  agendamento/  paciente/  painel-tv/
└── _work/                      # área do GitHub Actions runner

/var/log/dez/{deploy.log,backup.log}
/var/backups/dez/{diario,semanal}
/etc/systemd/system/{dez-web.service,dez-schedule.service,dez-schedule.timer,
                     dez-backup.service,dez-backup.timer,dez-tunnel.service}
/etc/sudoers.d/dez-deploy
```

> **Por que os `dist` ficam fora do repo:** o `deploy.sh` aborta se a árvore do
> git estiver suja. Escrever build dentro de `app/` sujaria o `git status` e
> quebraria o próprio deploy. `vendor/`, `storage/` e `bootstrap/cache` são
> gitignored (verificado), então eles podem ficar dentro do repo.

---

## 6. Contas, permissões e serviços

### 6.1 Usuário de sistema

```bash
sudo adduser --system --group --home /opt/dez --shell /bin/bash dez
sudo mkdir -p /opt/dez/{bin,frontend,_work} /var/log/dez /var/backups/dez/{diario,semanal}
sudo chown -R dez:dez /opt/dez /var/log/dez /var/backups/dez
```
`dez` **não** entra no grupo `sudo`. Sem senha de login; acesso só via runner/SSH por chave.

### 6.2 sudoers restrito (só reiniciar os nossos units)

`/etc/sudoers.d/dez-deploy` (modo 0440, validar com `sudo visudo -c`):
```
dez ALL=(root) NOPASSWD: /usr/bin/systemctl restart dez-web.service
dez ALL=(root) NOPASSWD: /usr/bin/systemctl restart dez-schedule.timer
dez ALL=(root) NOPASSWD: /usr/bin/systemctl daemon-reload
```
> **Segurança:** `dez` escreve no repositório (via `git pull`), portanto não pode
> ter sudo genérico. Os units são arquivos **root-owned** — um commit malicioso
> não consegue alterar o que o `systemctl` executa.

### 6.3 Units e timers (conteúdo no Anexo B)

| Unit | Papel | `MemoryMax` |
|---|---|---|
| `dez-web.service` | FrankenPHP (borda + app) em `:8002` | 512 M |
| `dez-schedule.service` + `.timer` | `artisan schedule:run` a cada minuto (**sem processo residente**) | 128 M |
| `dez-backup.service` + `.timer` | backup diário 03:00 | 256 M |
| `dez-tunnel.service` | `cloudflared tunnel run dez` | 96 M |

### 6.4 Deploy key e clone do repositório

O repo é privado e o `deploy.sh` faz `git pull` em `/opt/dez/app`, então o
usuário `dez` precisa de leitura via SSH:

```bash
sudo adduser --system --group --home /opt/dez --shell /bin/bash dez
sudo mkdir -p /opt/dez/.ssh && sudo chown -R dez:dez /opt/dez && sudo chmod 700 /opt/dez/.ssh

sudo -u dez ssh-keygen -t ed25519 -N "" -f /opt/dez/.ssh/dez_deploy
sudo cat /opt/dez/.ssh/dez_deploy.pub
#   -> GitHub > repo > Settings > Deploy keys > Add (SEM "Allow write access")

# teste (esperado: "Hi ...! You've successfully authenticated" + "does not provide shell access")
sudo -u dez ssh -i /opt/dez/.ssh/dez_deploy -o IdentitiesOnly=yes \
  -o StrictHostKeyChecking=accept-new -T git@github.com

sudo -u dez env GIT_SSH_COMMAND="ssh -i /opt/dez/.ssh/dez_deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new" \
  git clone git@github.com:marcelocv8/sistema-dez.git /opt/dez/app
```

O `install.sh` grava `core.sshCommand` no clone (para o `git pull` do deploy não
depender de `~/.ssh/config`) e semeia `known_hosts` com `ssh-keyscan`.

> **Se a porta 22 estiver bloqueada** na rede da clínica (o teste acima dá
> timeout), usar `ssh.github.com:443` em `/opt/dez/.ssh/config`:
> `Host github.com` / `HostName ssh.github.com` / `Port 443` / `User git` /
> `IdentityFile /opt/dez/.ssh/dez_deploy`.

### 6.5 Self-hosted runner (F6)

O asset do runner **inclui a versão no nome**:
`…/releases/latest/download/actions-runner-linux-x64.tar.gz` → **404**.
Conferir a versão atual em <https://github.com/actions/runner/releases/latest>
(verificado em 22/09/2026: **v2.337.0**, 226 MB).

```bash
sudo -u dez mkdir -p /opt/dez/actions-runner
cd /opt/dez/actions-runner
sudo -u dez curl -fL --retry 3 -o runner.tar.gz \
  https://github.com/actions/runner/releases/latest/download/actions-runner-linux-x64-2.337.0.tar.gz
sudo -u dez tar -xzf runner.tar.gz

# token (expira em ~1h): repo > Settings > Actions > Runners > New self-hosted runner
sudo -u dez ./config.sh --url https://github.com/marcelocv8/sistema-dez \
  --token <TOKEN> --name dez-server --labels dez --work /opt/dez/_work --unattended
sudo ./svc.sh install dez
sudo ./svc.sh start
sudo ./svc.sh status
```

O runner **precisa** rodar como `dez`: é quem tem o `sudo` restrito (§6.2) e o
dono de `/opt/dez`. O label **`dez`** é o que o workflow usa em
`runs-on: [self-hosted, dez]`.

---

## 7. Banco de dados

### 7.1 Estratégia: cluster existente, isolamento por role

Reaproveita o `postgresql@16-main` em `127.0.0.1:5432`. **Nada de segundo cluster.**

### 7.2 Acesso de superusuário — **RESOLVIDO (22/09/2026)**

A senha do `postgres` estava na própria config do STG
(`/home/server/projetos/dezstg2/application/config/database.php`):
`hostname=localhost`, `username=postgres`, `password=<a senha do STG>`, `database=dez`
(usuário e senha do **superusuário** — valor obtido na própria config, não
repetido aqui).

Confirmado funcionando (`select current_user, version()` → `postgres`,
PostgreSQL **16.15**). Não foi preciso mexer no `pg_hba` nem resetar senha.

> Registrado como item de hardening do STG (fora do escopo deste deploy):
> senha fraca no superusuário e a linha `host all all 0.0.0.0/0 md5`
> no `pg_hba` — hoje inofensiva porque `listen_addresses` é loopback.

### 7.3 Provisionamento (executar como superusuário)

`deploy/sql/provision.sql` (a senha entra por variável, não fica no arquivo):
```sql
CREATE ROLE sistema_dez LOGIN PASSWORD :'app_password' CONNECTION LIMIT 15;
CREATE DATABASE sistema_dez OWNER sistema_dez ENCODING 'UTF8';
ALTER ROLE sistema_dez SET statement_timeout = '15s';
ALTER ROLE sistema_dez SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE sistema_dez SET work_mem = '4MB';
ALTER ROLE sistema_dez SET maintenance_work_mem = '32MB';
ALTER ROLE sistema_dez SET search_path = public;
REVOKE ALL ON DATABASE sistema_dez FROM PUBLIC;
```
```bash
# gera a senha do role na própria máquina (não precisa inventar fora)
SENHA=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
echo "senha do role sistema_dez: $SENHA"      # guarde antes de continuar

PGPASSWORD='<senha-do-postgres>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres \
  -v app_password="$SENHA" -f /opt/dez/app/deploy/sql/provision.sql
```
> **Atenção ao quoting:** o SQL usa `:'app_password'`, que **já** envolve o valor
> em aspas. Passar `-v app_password="'$SENHA'"` grava a senha **com aspas**
> literais e o login falha depois. Passe o valor cru: `-v app_password="$SENHA"`.
> Roda como o **superusuário** (a senha está na config do STG — ver §7.2).
> Só **cria** objetos novos — não altera nada do banco `dez`.

**Justificativa dos limites:** o DEZ compartilha o cluster com a produção do
STG. `statement_timeout=15s` + `CONNECTION LIMIT 15` garantem que uma consulta
ruim ou um vazamento de conexões do DEZ não derrube o STG. Imports longos rodam
com `SET statement_timeout = 0` **na própria sessão** do comando de import.

### 7.4 Autenticação: `md5` — **confirmado, nada a mudar**

O cluster está com `password_encryption = md5` (verificado em 22/09) e o
`pg_hba` também pede `md5` em `127.0.0.1/32` — **alinhados**, então o role
`sistema_dez` nasce com a senha em MD5 e conecta sem ajuste nenhum.

Teste que vale (é o que a aplicação vai fazer):
```bash
PGPASSWORD='<senha-do-role>' psql -h 127.0.0.1 -p 5432 -U sistema_dez -d sistema_dez \
  -At -P pager=off -c "select current_user, current_database();"
# esperado: sistema_dez|sistema_dez
```
> Quando o STG for desativado, o hardening natural do cluster é migrar
> `password_encryption` para `scram-sha-256` (e o `pg_hba` junto). Não fazer
> agora: exigiria trocar a senha de todos os roles em uso pelo legado.

---

## 8. Runtime: FrankenPHP static

### 8.1 Instalação

```bash
# release oficial (linux x86_64). Conferir a versão mais recente em
# https://github.com/php/frankenphp/releases
curl -fsSL -o /tmp/frankenphp.tar.gz \
  https://github.com/php/frankenphp/releases/latest/download/frankenphp-linux-x86_64.tar.gz
sudo tar -xzf /tmp/frankenphp.tar.gz -C /opt/dez/bin
sudo chmod 755 /opt/dez/bin/frankenphp
/opt/dez/bin/frankenphp version
/opt/dez/bin/frankenphp php-cli -v      # confirma o PHP embutido (8.5.x)
```
O binário estático já inclui `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `zip`,
`opcache`, `curl`, `fileinfo`, `openssl` — **nada de `apt install php*`**.

### 8.2 `php.ini` (`/opt/dez/bin/php.ini`, lido via `PHPRC`)

```ini
memory_limit = 256M
max_execution_time = 60
upload_max_filesize = 20M
post_max_size = 24M
date.timezone = America/Fortaleza
expose_php = 0

opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 64
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0     ; deploy sempre reinicia o serviço
opcache.save_comments = 1           ; exigido por anotações/reflexão
opcache.jit = off

realpath_cache_size = 4096k
realpath_cache_ttl = 600
```
Verificação:
```bash
PHPRC=/opt/dez/bin /opt/dez/bin/frankenphp php-cli --ini
PHPRC=/opt/dez/bin /opt/dez/bin/frankenphp php-cli -i | grep -Ei "opcache.enable|pdo_pgsql|memory_limit"
```

### 8.3 Wrapper de CLI

`/opt/dez/bin/artisan` (root:root 755):
```bash
#!/usr/bin/env bash
set -euo pipefail
export PHPRC=/opt/dez/bin
exec /opt/dez/bin/frankenphp php-cli /opt/dez/app/backend/artisan "$@"
```
Uso: `sudo -u dez /opt/dez/bin/artisan migrate --force`.

---

## 9. Roteamento e borda

`/opt/dez/Caddyfile` (conteúdo completo no **Anexo A**): um único processo serve
os 4 SPAs por prefixo (`handle_path`) e cai no Laravel por padrão (`php_server`).

Pontos que **exigem** as mudanças de código da seção 10:
- cada SPA precisa de `base` no Vite igual ao seu prefixo, senão o HTML pede
  `/assets/...` na raiz e a requisição cai no app errado;
- a equipe precisa de `basename` no `BrowserRouter`;
- o app paciente detecta o portal por `pathname.endsWith('/resultados')`.

Validação **antes** de aplicar no servidor (obrigatória):
```bash
# na máquina de dev (Windows), com os dists reais e o binário do FrankenPHP:
#   1) pnpm build
#   2) frankenphp run --config deploy/caddy/Caddyfile (com DEZ_ROOT apontando p/ os dists locais)
#   3) abrir http://127.0.0.1:8002/app/  /agendar/  /resultados/  /painel/
```

### 9.1 Ordem de diretivas (ponto de atenção)

`redir` roda antes de `handle`/`handle_path`; entre os `handle*` vale a
especificidade do matcher. O fallback do Laravel fica no `handle` mais genérico.
**Testar** `/app`, `/app/`, `/app/assets/x.js` e `/` para confirmar.

---

## 10. Ajustes de código necessários (F2)

Tudo abaixo é no repositório, **no PC de dev**, com validação local. **Nada é
commitado sem autorização explícita do dono** (`AGENTS.md` §2).

### 10.1 Backend

| Arquivo | Mudança | Motivo |
|---|---|---|
| `backend/bootstrap/app.php` | `$middleware->trustProxies(at: '*')` | TLS termina na Cloudflare; sem isso o Laravel acha que é HTTP e cookies/URLs quebram |
| `backend/bootstrap/app.php` | `$middleware->validateCsrfTokens(except: ['api/v1/public/*'])` | Tudo passa a estar na **mesma origem**; o `statefulApi()` aplica CSRF a qualquer requisição cujo `Referer/Origin` esteja em `SANCTUM_STATEFUL_DOMAINS`, e o POST público anônimo de agendamento cairia em **419** (bug já visto em §14.22 do plano) |

> Os endpoints públicos (`appointments`, `portal/login`, `reschedule`,
> `cancel`) são anônimos e usam `Bearer`/`management_token` — não há sessão a
> proteger. A isenção fica **restrita** ao prefixo público.

### 10.2 Frontend

| Arquivo | Mudança |
|---|---|
| `frontend/apps/equipe/vite.config.ts` | `base: '/app/'` |
| `frontend/apps/agendamento/vite.config.ts` | `base: '/agendar/'` |
| `frontend/apps/paciente/vite.config.ts` | `base: '/resultados/'` |
| `frontend/apps/painel-tv/vite.config.ts` | `base: '/painel/'` |
| `frontend/apps/equipe/src/App.tsx` | `<BrowserRouter basename={import.meta.env.BASE_URL}>` |
| `frontend/apps/paciente/src/main.tsx` | trocar `pathname.endsWith('/resultados')` por `pathname.replace(/\/$/, '').endsWith('/resultados')` |

**Não** definir `VITE_API_URL` no build: com a variável vazia
(`import.meta.env.VITE_API_URL ?? ''`), todas as chamadas viram relativas
(`/api/v1/...`) e caem no mesmo host — **sem CORS**.

### 10.3 Arquivos novos de deploy

`deploy/README.md` · `deploy/env.production.example` · `deploy/caddy/Caddyfile`
· `deploy/frankenphp/php.ini` · `deploy/systemd/*.{service,timer}` ·
`deploy/scripts/{install,bootstrap,deploy,backup,tunnel}.sh` · `deploy/sql/provision.sql`
· `.github/workflows/deploy.yml` · ajustes no `.gitignore`.

### 10.4 Documentação

`docs/plano_sistema_dez.md`: nova **§16** apontando para este arquivo + **v0.18**
no cabeçalho.

---

## 11. Variáveis de ambiente de produção

`/opt/dez/app/backend/.env` (**untracked**, nunca commitar) — `deploy/env.production.example`:

```dotenv
APP_NAME="Sistema DEZ"
APP_ENV=production
APP_KEY=                       # php artisan key:generate (chave NOVA, exclusiva do servidor)
APP_DEBUG=false
APP_URL=https://dez.clinicadez.com.br

BUSINESS_TIMEZONE=America/Fortaleza
PUBLIC_BOOKING_HORIZON_DAYS=30
IDENTITY_PEPPER=               # hex aleatório NOVO (nunca copiar do dev)
MANAGEMENT_TOKEN_SECRET=       # hex aleatório NOVO

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1              # NÃO usar localhost (pode resolver ::1)
DB_PORT=5432
DB_DATABASE=sistema_dez
DB_USERNAME=sistema_dez
DB_PASSWORD=                   # gerada no provisionamento

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=false
SESSION_DOMAIN=null
SESSION_SAME_SITE=lax

CACHE_STORE=database
QUEUE_CONNECTION=database      # não há jobs de fila; schedule usa database
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

SANCTUM_STATEFUL_DOMAINS=dez.clinicadez.com.br
FRONTEND_URLS=https://dez.clinicadez.com.br

PORTAL_TTL_DAYS=30
PORTAL_BASE_URL=https://dez.clinicadez.com.br/resultados
BOOKING_BASE_URL=https://dez.clinicadez.com.br/agendar

WHATSAPP_ENABLED=false
MT_DUE_WARNING_DAYS=30
DEEPSEEK_API_KEY=              # opcional; sem a chave a IA do prontuário fica oculta
STG_IMPORT_PATH=/opt/dez/_import/stg
STG_LAUDOS_PATH=/opt/dez/_import/laudos
```

### 11.1 Bootstrap dos dados (F5)

```bash
cd /opt/dez/app/backend
/opt/dez/bin/artisan key:generate
/opt/dez/bin/artisan migrate --force

# ⚠️ NUNCA rodar `db:seed` puro: o DatabaseSeeder cria `test@example.com`
#    quando APP_ENV != local. Para dados fictícios, chamar o seeder por nome.
# /opt/dez/bin/artisan db:seed --class=MockDataSeeder --force

# usuários reais (um por pessoa, RBAC) — a senha é definida no prompt:
/opt/dez/bin/artisan team:user marcelo@... --name="Marcelo" --role=ADMIN
/opt/dez/bin/artisan team:user <2º>@...     --name="..."     --role=RECEPTION
/opt/dez/bin/artisan team:user <3º>@...     --name="..."     --role=DOCTOR

/opt/dez/bin/artisan optimize     # config+route+view+event cache (route:cache OK no Laravel 13)
```

---

## 12. Build e deploy contínuo

### 12.1 Por que o build fica no CI

`pnpm build` dos 4 apps + `composer install` podem consumir 600 MB–1,2 GB de
pico. Com ~1,9 GB livres e `systemd-oomd` ativo numa máquina que roda o STG,
isso é inaceitável. **Decisão:** GitHub Actions.

### 12.2 Workflow (`.github/workflows/deploy.yml`, anexo D)

```
workflow_dispatch  ──►  build (ubuntu-latest)  ──►  deploy (self-hosted, label `dez`)
   │                        │                            │
   │                   testes + typecheck            baixa artefato
   │                   pnpm build (4 apps)           git pull --ff-only
   │                   composer install --no-dev     migrate --force
   │                   tar: vendor/ + dist/          publica dist/ + vendor/
   │                   upload-artifact               restart units + health check
   │                                                 rollback automático se falhar
```

- **`build`** (runner do GitHub): `shivammathur/setup-php` 8.4, `composer install --no-dev --optimize-autoloader`, `corepack enable` + `pnpm install --frozen-lockfile`, `pnpm build`, empacota `vendor/` + os 4 `dist/` em `dez-build.tgz` e publica como artefato.
- **`deploy`** (`runs-on: [self-hosted, dez]`): baixa o artefato e roda
  `/opt/dez/app/deploy/scripts/deploy.sh <caminho-do-tgz>` — **sem `sudo` na
  frente**: o sudoers do `dez` libera só 3 comandos `systemctl`, e o próprio
  `deploy.sh` chama `sudo systemctl` quando precisa.
- Depois de estável, adicionar gatilho `push: branches: [main]` (opcional).

### 12.3 `deploy.sh`

Ver **Anexo C**. Garantias: aborta se a árvore estiver suja, `git pull --ff-only`
(sem merge), rollback automático para o commit anterior em qualquer falha,
health check em `/up`, log append em `/var/log/dez/deploy.log`.

---

## 13. Backup diário

`dez-backup.timer` (03:00, `Persistent=true`). `deploy/scripts/backup.sh`:

1. `pg_dump -Fc` do database `sistema_dez` → `/var/backups/dez/diario/sistema_dez-<data>.dump`
2. `tar.gz` de `backend/storage/app` e de `backend/.env`
3. **verificação**: `pg_restore --list` no dump (prova que não está corrompido)
4. retenção: apaga diários com mais de **14 dias**; mantém **4 semanais**
5. log em `/var/log/dez/backup.log`

**Restauração** (testada em F6):
```bash
sudo -u dez /opt/dez/bin/frankenphp php-cli --version >/dev/null
pg_restore -h 127.0.0.1 -U sistema_dez -d sistema_dez --clean --if-exists \
  /var/backups/dez/diario/sistema_dez-<data>.dump
```
> Rollback de **código** é `git reset --hard <commit>` (via `deploy.sh`).
> Rollback de **dados** exige o dump — por isso o backup deixa de ser opcional.

---

## 14. Cloudflare Tunnel (túnel novo, F4)

**Nunca tocar** em `cloudflared.service` / `/etc/cloudflared/config.yml` (túnel do STG).

```bash
# Rode como o usuário 'server' (dono de ~/.cloudflared/cert.pem), SEM sudo na
# frente — o script pede sudo só para instalar os arquivos em /opt/dez.
# Ele cria o túnel, cria o CNAME no Cloudflare, valida o ingress e entrega as
# credenciais para o serviço (que roda como 'dez').
/opt/dez/app/deploy/scripts/tunnel.sh dez.clinicadez.com.br
```
O script gera `/opt/dez/cloudflared/config.yml` (0640 `dez:dez`):
```yaml
tunnel: <UUID-do-tunel-dez>
credentials-file: /opt/dez/cloudflared/<UUID>.json
ingress:
  - hostname: dez.clinicadez.com.br
    service: http://127.0.0.1:8002
  - service: http_status:404
```
> **Por que não rodar como `dez`:** o `cert.pem` da conta Cloudflare está no
> `~/.cloudflared/` do usuário `server` (foi ele que criou o túnel do STG).
> Copiar o `cert.pem` seria pior que copiar só as credenciais do túnel novo —
> e assim evitamos um `cloudflared tunnel login` com navegador.

`dez-tunnel.service` (anexo B). Validação:
```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://dez.clinicadez.com.br/up
sudo systemctl is-active cloudflared          # túnel do STG continua ativo
```

---

## 15. Importação dos dados do STG

| Bloco | O que traz | Fonte | Comando |
|---|---|---|---|
| Catálogo | 998 procedimentos, 40 convênios, 87 profissionais, 2.303 preços, vínculos, 2 unidades | `_extracoes_stg` do PC de dev (20 arq., **0,46 MB**) | `artisan stg:import-catalog --commit` |
| Modelos de laudo | 72 modelos + 9 linhas | `_extracoes_laudos` (89 arq., **0,45 MB**) | `artisan stg:import-report-templates --commit` |
| Medicina do Trabalho | riscos, setores, funções, ASOs | **gerado no próprio servidor** (o PG do STG é local!) | `psql -d <legado> -f docs/extracao_mt/extract_mt.sql` → `stg:import-mt --commit` |
| Pacientes + atendimentos (24 meses) | pacientes, agendamentos | **importador novo (F7)** | `stg:import-patients` / `stg:import-appointments` |

### 15.1 Upload das extrações (PC de dev → servidor)

```bash
# no PC (Windows/PowerShell):
Compress-Archive -Path "C:\Projects\STG\dezstg2\_extracoes_stg\*" -DestinationPath "$env:TEMP\dez-stg.zip" -Force
Compress-Archive -Path "C:\Projects\STG\_extracoes_laudos\*"      -DestinationPath "$env:TEMP\dez-laudos.zip" -Force
scp "$env:TEMP\dez-stg.zip" "$env:TEMP\dez-laudos.zip" server@192.168.0.170:/tmp/
```
```bash
# no servidor:
sudo mkdir -p /opt/dez/_import/{stg,laudos}
sudo unzip -o /tmp/dez-stg.zip    -d /opt/dez/_import/stg
sudo unzip -o /tmp/dez-laudos.zip -d /opt/dez/_import/laudos
sudo chown -R dez:dez /opt/dez/_import
```
Depois, **sempre** `--dry-run` antes de `--commit`, e fora do horário da clínica.

### 15.2 Importadores novos (F7)

Não existe importador de pacientes/atendimentos hoje. Escopo proposto
(MVP aprovado):
- `stg:import-patients` — pacientes com CPF em **hash + pepper**
  (`IDENTITY_PEPPER`), consentimento marcado como importado, deduplicação;
- `stg:import-appointments` — atendimentos/agendamentos dos **últimos 24 meses**;
- **fora:** financeiro, caixa, sangrias, repasses e laudos históricos (importar
  saldos de caixa produziria fechamentos inconsistentes).

Leitura **read-only** do banco legado, com lotes e log de reconciliação.

---

## 16. Runbook do dia a dia

| Situação | Comando |
|---|---|
| **Publicar uma melhoria** | no GitHub: **Actions → deploy → Run workflow** (ou, como `dez`, `ssh dez@192.168.0.170 '/opt/dez/app/deploy/scripts/deploy.sh <artefato>'`) |
| Ver o que subiu | `tail -n 50 /var/log/dez/deploy.log` |
| Estado dos serviços | `systemctl status dez-web dez-tunnel dez-schedule.timer dez-backup.timer` |
| Log da aplicação | `tail -f /opt/dez/app/backend/storage/logs/laravel-$(date +%F).log` |
| Reiniciar a app | `sudo systemctl restart dez-web` |
| Rodar um comando artisan | `sudo -u dez /opt/dez/bin/artisan <cmd>` |
| URL de saúde | `curl -s https://dez.clinicadez.com.br/up` |
| Backup manual | `sudo /opt/dez/app/deploy/scripts/backup.sh` |
| Consumo de RAM do DEZ | `systemctl status dez-web \| grep Memory` |

---

## 17. Rollback

| Cenário | Ação |
|---|---|
| Deploy falhou (health check, migration, teste) | **automático**: `deploy.sh` faz `git reset --hard <commit anterior>` + restart |
| Deploy passou mas quebrou na prática | `ssh dez@… '/opt/dez/app/deploy/scripts/deploy.sh --rollback <commit-bom>'` |
| Migration ruim | restaurar o dump do dia (`pg_restore`) e voltar o commit |
| Túnel/URL fora do ar | `sudo systemctl restart dez-tunnel` (não afeta o túnel do STG) |
| Emergência total | `sudo systemctl stop dez-web dez-tunnel` — o STG continua intacto |

---

## 18. Riscos e mitigações

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| OOM killer derrubar **o STG** | Média | Crítico | PG compartilhado (sem 2º cluster), build no CI, `MemoryMax` em todos os units, 4 threads, imports fora do horário |
| Reiniciar o túnel do STG por engano | Baixa | Crítico | Túnel **novo** com UUID/config/service próprios; o atual não é lido nem editado |
| Mexer no Apache/vhost do STG | Baixa | Crítico | Não usamos Apache; docroot do STG (`/home/server/projetos/dezstg2`) fica intocado; nosso root é `/opt/dez/app/backend/public` |
| Import pesado competir com produção | Média | Alto | janela 00:00–06:00, `statement_timeout`, transação por lote, `--dry-run` primeiro |
| Login exposto ao mundo com dados reais (sem camada extra — D9) | Média | Alto | `throttle:team-login` 5/min, senhas fortes individuais, RBAC, auditoria; **recomenda-se 2FA quando virar prod** (§7.6 do plano) |
| Senha do `postgres` desconhecida | Alta | Bloqueante | Anexo E (recuperação sem derrubar o STG) |
| `md5` × `scram` desalinhados | Média | Médio | Teste real de conexão antes de seguir (§7.4) |
| Atualização automática do Ubuntu reiniciar serviços | Baixa | Médio | `Restart=always` + `systemctl enable` nos units |
| URL do túnel mudar | Muito baixa | Baixo | Túnel nomeado = hostname fixo |
| `route:cache` falhar | Baixa | Baixo | Já validado no Laravel 13 (rotas closure são serializáveis) |

---

## 19. Checklist de aceite por fase

### F1 — Provisionamento (base) — ordem real, sem transferência de arquivos

- [ ] 1. usuário `dez` + `/opt/dez/.ssh` (700) criados
- [ ] 2. deploy key gerada e registrada no GitHub (read-only); `ssh -T git@github.com` responde
- [ ] 3. repo clonado em `/opt/dez/app` com a deploy key
- [ ] 4. `install.sh` rodado: FrankenPHP static (`php-cli -i` com `pdo_pgsql`/`opcache`), `php.ini`, `Caddyfile`, 6 units/timers, `visudo -c` OK
- [ ] 5. role `sistema_dez` + database criados; `psql -h 127.0.0.1 -U sistema_dez -d sistema_dez` → `sistema_dez|sistema_dez`
- [ ] 6. **STG intacto**: `curl -sI https://sistema.clinicadez.com.br` = 200 e os 4 serviços (`apache2`, `postgresql@16-main`, `cloudflared`, `painel`) `active`

### F2 — Código (no PC de dev)
- [ ] `trustProxies` + CSRF público aplicados
- [ ] `base` nos 4 `vite.config.ts` e `basename` na equipe; ajuste no paciente
- [ ] `pnpm build` + **validação local da borda** (Anexo A) abrindo os 4 prefixos
- [ ] `php artisan test --compact` verde; `vendor/bin/pint --dirty` ok
- [ ] **Diff apresentado ao dono e autorização obtida antes de qualquer commit**

### F3 — Ambiente e primeiro deploy (ordem ajustada)
- [ ] `.env` de produção com chaves novas (**`APP_KEY` via `openssl`**, não artisan) — `bootstrap.sh`
- [ ] `vendor/` + os 4 `dist/` publicados pelo primeiro deploy do CI (§21.9) — sem isso o `artisan` não roda
- [ ] `migrate --force` (executado pelo próprio `deploy.sh`); `/up` = 200 em `127.0.0.1:8002`
- [ ] `team:user` dos testadores **depois** do deploy; login funcionando (local e pela URL)

### F4 — URL
- [ ] Túnel `dez` criado, CNAME em `dez.clinicadez.com.br`, `dez-tunnel.service` ativo
- [ ] `https://dez.clinicadez.com.br/up` = 200; login stateful funcionando pela URL (cookie `Secure`)

### F5 — Dados
- [ ] Uploads das extrações + `stg:import-catalog` / `stg:import-report-templates` / `stg:import-mt` (`--dry-run` → `--commit`)
- [ ] Catálogo/preços/modelos/MT visíveis nas telas

### F6 — Operação
- [ ] Self-hosted runner instalado; "Run workflow" faz deploy de ponta a ponta
- [ ] **Rollback testado** (commit ruim → volta sozinho)
- [ ] `dez-backup.timer` rodou; dump verificado com `pg_restore --list`; restauração testada

### F7 — Importadores de pacientes/histórico
- [ ] `stg:import-patients` e `stg:import-appointments` (24 meses) com reconciliação
- [ ] Contagens conferidas contra o legado; amostragem validada com o dono

---

## 20. Estado e pendências

### 20.1 Estado da implementação (F2 concluída em 21/09/2026)

Código e artefatos de deploy **prontos e validados localmente**:

| Entregue | Onde |
|---|---|
| `trustProxies` (loopback) + isenção de CSRF em `api/v1/public/*` | `backend/bootstrap/app.php` |
| `base` nos 4 apps + `basename` da equipe + rota do portal tolerante a barra | `frontend/apps/*/vite.config.ts`, `equipe/src/App.tsx`, `equipe/src/pages/ProntuarioPage.tsx`, `paciente/src/main.tsx` |
| Borda, `php.ini`, `.env` de produção, provisionamento do role, 6 units/timers, sudoers | `deploy/` |
| Scripts `install` · `bootstrap` · `deploy` · `backup` · `tunnel` | `deploy/scripts/` |
| Workflow verify → build (GitHub) → deploy (self-hosted) | `.github/workflows/deploy.yml` |
| §16 no plano de produto | `docs/plano_sistema_dez.md` (v0.18) |

**Validação executada:** Pint ✅ · typecheck 7/7 ✅ · build dos 4 apps ✅ (HTMLs
com os prefixos corretos) · **borda real** com FrankenPHP 8.5 e os dists reais
(`/`→302, `/app/`→200, `/app/visao-geral`→200 SPA fallback, asset→200,
`/agendar/` `/resultados/` `/painel/`→200, `/up`→200, API inexistente→404) ✅ ·
`bash -n` nos 5 scripts ✅ · introspecção do middleware
(`trusted_proxies=["127.0.0.1","::1"]`, `csrf_never_verify=["api/v1/public/*"]`) ✅.

**Dois bugs encontrados e corrigidos durante a validação da borda:**
1. `/app` **sem barra final** não casa com `handle_path /app/*` e caía no 404 do
   Laravel → adicionados `redir` para os 4 prefixos.
2. O `redir` do Caddy **descarta a query string** (quebraria
   `/painel?unit_id=abc`, lido pelo painel-tv) → alvos passaram a usar
   `{query}` (ver Anexo A).

> **Nota para o futuro:** o `PreventRequestForgery` tem `runningUnitTests()` e
> **ignora CSRF na suíte** — o bug de 419 só aparece em HTTP real, por isso a
> validação foi feita na borda e não só pelos testes.

### 20.2 Pendências

| # | Pendência | Status | Detalhe |
|---|---|---|---|
| P1 | Suíte de testes do backend (`php artisan test --compact`) | ⛔ **bloqueado** | Docker Desktop desligado no PC de dev; precisa do PostgreSQL na `5433`. Único item que falta do F2 |
| P2 | Senha do superusuário `postgres` | ✅ **resolvido** | achada na config do STG; `select current_user` → `postgres`, PG 16.15 (valor **não** versionado) |
| P3 | `password_encryption` (`md5` × `scram`) | ✅ **resolvido** | `md5` — role nasce em MD5, `pg_hba` já em MD5, sem ajuste |
| P4 | Hostname do teste | ✅ **resolvido** | `dez.clinicadez.com.br` (livre, confirmado) |
| P5 | Janela de import | ✅ **definida** | 00:00–06:00 |
| P6 | Subir as extrações do STG para `/opt/dez/_import/` | ⏳ pendente | F5; ~0,9 MB (catálogo + laudos) |
| P7 | Escopo do F7 | ✅ **definido** | pacientes + atendimentos de 24 meses; **sem** financeiro/caixa |
| P8 | Runner do GitHub + teste de rollback | ⏳ pendente | F6 |
| P9 | Executar a F1 no servidor | ⏳ **em andamento** | ver §20.3 |

### 20.3 Andamento da execução no servidor (22/09/2026)

| Passo | Status | Observação |
|---|---|---|
| usuário `dez` + `/opt/dez/.ssh` | ✅ | |
| deploy key + `ssh -T git@github.com` | ✅ | `Hi marcelocv8/sistema-dez! You've successfully authenticated` |
| clone em `/opt/dez/app` | ✅ | 1406 objetos, 1,14 MiB |
| `install.sh` (base) | ✅ | FrankenPHP **v1.12.7 / PHP 8.5.10**; extensões com `pdo_pgsql`; 6 units; `visudo -c` OK; `/opt/dez` 0755 |
| `provision.sql` (role + database) | ⚠️ parcial | objetos criados (`CREATE ROLE/DATABASE` + 5 `ALTER ROLE` + `REVOKE` + `GRANT`), mas a senha foi gravada **com aspas** (erro de quoting no `-v`) → corrigir com `ALTER ROLE` (§21.6) |
| `bootstrap.sh` (`.env` + chaves) | ⚠️ | `.env` criado; agora pausa de propósito avisando que falta `vendor/` (chega no primeiro deploy do CI) |
| túnel + serviços + validação | ⏳ | pendente |

**Bugs de script encontrados e corrigidos nesta execução** (todos já no `main`):
1. `apt-get update` abortando por causa do `Label` do PPA `ondrej/php` → `AllowReleaseInfoChange` + só rodar o apt se faltar pacote.
2. `/opt/dez` com permissão fechada → `server` não conseguia rodar o `tunnel.sh` → `chmod 0755`.
3. URL do FrankenPHP: o asset `.tar.gz` **não existe** (404); é o binário puro (~173 MB).
4. `frankenphp php-cli -m` **não repassa o `-m`** (exit 255) e, com `pipefail`, reprovava o binário → passou a usar `php-cli -r` + `extension_loaded`.
5. `deploy/sql/provision.sql` era **ignorado** pela regra `*.sql` do `.gitignore` → adicionada `!deploy/sql/*.sql`.
6. **Quoting do `-v`**: `-v app_password="'$SENHA'"` grava a senha **com aspas literais** (o `:'app_password'` do SQL já as adiciona) → usar `-v app_password="$SENHA"`.
7. `bootstrap.sh` exigia `vendor/` para gerar a `APP_KEY` → agora usa `openssl rand -base64 32` e pausa com orientação quando o `vendor/` ainda não chegou.
8. Crases dentro de heredoc **sem aspas** no `bootstrap.sh` eram executadas como substituição de comando → trocadas por aspas.
9. (antes de rodar) Asset do runner do GitHub: `…/latest/download/actions-runner-linux-x64.tar.gz` responde **404** — o nome inclui a versão (`…-2.337.0.tar.gz`). Corrigido no §6.5.

---



---

## 21. Sequência remota da F1 (copiar e colar no AnyDesk)

Tudo abaixo roda **pelo terminal do servidor, remotamente** — não precisa estar
na rede da clínica nem transferir arquivos do PC de dev.

> Cole **um bloco por vez** e use `Ctrl+Shift+V`. O `sudo` vai pedir a senha do
> usuário `server`.

**21.1 — mínimo (usuário + diretório da chave)**
```bash
sudo adduser --system --group --home /opt/dez --shell /bin/bash dez
sudo mkdir -p /opt/dez/.ssh
sudo chown -R dez:dez /opt/dez
sudo chmod 700 /opt/dez/.ssh
```

**21.2 — deploy key**
```bash
sudo -u dez ssh-keygen -t ed25519 -N "" -f /opt/dez/.ssh/dez_deploy
sudo cat /opt/dez/.ssh/dez_deploy.pub
```
→ copie a chave pública e cadastre em **GitHub → repo → Settings → Deploy keys → Add deploy key** (deixe **"Allow write access" DESMARCADO**).

**21.3 — testar a saída SSH para o GitHub**
```bash
sudo -u dez ssh -i /opt/dez/.ssh/dez_deploy -o IdentitiesOnly=yes \
  -o StrictHostKeyChecking=accept-new -T git@github.com
```
Esperado: `Hi marcelocv8/sistema-dez! You've successfully authenticated` +
`does not provide shell access`. Se der **timeout**, ver o fallback
`ssh.github.com:443` em §6.4.

**21.4 — clonar o repositório**
```bash
sudo -u dez env GIT_SSH_COMMAND="ssh -i /opt/dez/.ssh/dez_deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new" \
  git clone git@github.com:marcelocv8/sistema-dez.git /opt/dez/app
```

**21.5 — base (FrankenPHP, php.ini, Caddyfile, units, sudoers)**
```bash
sudo /opt/dez/app/deploy/scripts/install.sh
```
Conferir: `/opt/dez/bin/frankenphp version` e
`PHPRC=/opt/dez/bin /opt/dez/bin/frankenphp php-cli -m | grep -E 'pdo_pgsql|opcache'`.

**21.6 — banco (role + database; só cria objetos novos)**
```bash
SENHA=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
echo "senha do role sistema_dez: $SENHA"
PGPASSWORD='<senha-do-postgres>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres \
  -v app_password="$SENHA" -f /opt/dez/app/deploy/sql/provision.sql
PGPASSWORD="$SENHA" psql -h 127.0.0.1 -p 5432 -U sistema_dez -d sistema_dez \
  -At -P pager=off -c "select current_user, current_database();"
```
Esperado: `sistema_dez|sistema_dez`. **Guarde a senha.**
> Sem aspas internas no `-v`: o `:'app_password'` do SQL já as adiciona.

**21.7 — ambiente (`.env` + chaves) — ainda SEM migrations**
```bash
sudo /opt/dez/app/deploy/scripts/bootstrap.sh \
  --url https://dez.clinicadez.com.br --db-password "$SENHA" \
  --admin-email <SEU_EMAIL> --admin-name "<SEU_NOME>"
```
> Aqui o script para de propósito avisando que falta `vendor/`: ele chega no
> primeiro deploy do CI (F6). O `bootstrap.sh` gera o `.env`, a `APP_KEY`
> (via `openssl`, **não** via artisan) e os segredos.

**21.8 — URL pública (como `server`, sem `sudo` na frente)**
```bash
/opt/dez/app/deploy/scripts/tunnel.sh dez.clinicadez.com.br
```

**21.9 — primeiro deploy (F6): publica `vendor/` + os 4 frontends, roda as migrations**
Instalar o runner (**§6.5**) e depois, no GitHub, **Actions → deploy → Run workflow**.
O `deploy.sh` reinicia o `dez-web` e valida `/up`.

```bash
sudo systemctl enable --now dez-web.service dez-schedule.timer dez-backup.timer dez-tunnel.service
curl -s -o /dev/null -w 'local=%{http_code}\n'   http://127.0.0.1:8002/up
curl -s -o /dev/null -w 'publico=%{http_code}\n' https://dez.clinicadez.com.br/up
curl -s -o /dev/null -w '/app/=%{http_code}\n'   https://dez.clinicadez.com.br/app/
```

**21.10 — usuários do app** (depois do deploy, que já rodou as migrations)
```bash
sudo -u dez /opt/dez/bin/artisan team:user <EMAIL> --name="<Nome>" --role=ADMIN
sudo -u dez /opt/dez/bin/artisan team:user <EMAIL2> --name="<Nome2>" --role=RECEPTION
```
perfis: `ADMIN | SUPERVISOR | RECEPTION | CASHIER | MANAGER | DOCTOR`

**21.11 — confirmar que o STG segue intacto**
```bash
curl -sI https://sistema.clinicadez.com.br | head -1
sudo systemctl is-active apache2 postgresql@16-main cloudflared painel
```
Esperado: `HTTP/2 200` e `active` nos quatro.

---

## Anexo A — `/opt/dez/Caddyfile`

```
{
	frankenphp {
		num_threads 4
	}
	admin off
}

:8002 {
	encode zstd br gzip

	# URL "crua" cai no app da equipe
	redir / /app/ 302

	# Prefixo sem a barra final não casa com `/app/*` no handle_path e cairia
	# no 404 do Laravel — normaliza para a forma com barra. O `{query}` é
	# necessário porque o `redir` do Caddy NÃO preserva a query string sozinho
	# (ex.: /painel?unit_id=abc, lido pelo painel-tv).
	redir /app /app/?{query} 302
	redir /agendar /agendar/?{query} 302
	redir /resultados /resultados/?{query} 302
	redir /painel /painel/?{query} 302

	handle_path /app/* {
		root * /opt/dez/frontend/equipe
		try_files {path} /index.html
		file_server
	}

	handle_path /agendar/* {
		root * /opt/dez/frontend/agendamento
		try_files {path} /index.html
		file_server
	}

	handle_path /resultados/* {
		root * /opt/dez/frontend/paciente
		try_files {path} /index.html
		file_server
	}

	handle_path /painel/* {
		root * /opt/dez/frontend/painel-tv
		try_files {path} /index.html
		file_server
	}

	# tudo o mais é Laravel (/api/v1, /sanctum, /up, /storage)
	handle {
		root * /opt/dez/app/backend/public
		php_server
	}
}
```
> **Sem `basic_auth`** (D9). Se um dia for necessário, basta um bloco
> `basic_auth { usuario <hash bcrypt> }` antes dos `handle_path`.

## Anexo B — units systemd

`dez-web.service`
```ini
[Unit]
Description=Sistema DEZ — FrankenPHP (borda + app)
After=network-online.target postgresql@16-main.service
Wants=network-online.target

[Service]
Type=simple
User=dez
Group=dez
WorkingDirectory=/opt/dez/app/backend
Environment=PHPRC=/opt/dez/bin
ExecStart=/opt/dez/bin/frankenphp run --config /opt/dez/Caddyfile
Restart=always
RestartSec=3
MemoryMax=512M
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=true
ReadWritePaths=/opt/dez/app/backend/storage /opt/dez/app/backend/bootstrap/cache

[Install]
WantedBy=multi-user.target
```

`dez-schedule.service` / `dez-schedule.timer`
```ini
[Unit]
Description=Sistema DEZ — Laravel scheduler (execução por minuto)

[Service]
Type=oneshot
User=dez
Group=dez
WorkingDirectory=/opt/dez/app/backend
ExecStart=/opt/dez/bin/artisan schedule:run
MemoryMax=128M
```
```ini
[Unit]
Description=Sistema DEZ — dispara o scheduler a cada minuto

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
AccuracySec=1s

[Install]
WantedBy=timers.target
```

`dez-backup.service` / `dez-backup.timer`
```ini
[Unit]
Description=Sistema DEZ — backup diário

[Service]
Type=oneshot
User=dez
ExecStart=/opt/dez/app/deploy/scripts/backup.sh
MemoryMax=256M
```
```ini
[Unit]
Description=Sistema DEZ — backup diário 03:00

[Timer]
OnCalendar=*-*-* 03:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

`dez-tunnel.service`
```ini
[Unit]
Description=Sistema DEZ — Cloudflare Tunnel (dez)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=dez
Group=dez
ExecStart=/usr/bin/cloudflared --no-autoupdate --config /opt/dez/cloudflared/config.yml tunnel run
Restart=always
RestartSec=5
MemoryMax=96M
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
```

## Anexo C — `deploy/scripts/deploy.sh`

> ⚠️ **A fonte de verdade é o arquivo real:** `deploy/scripts/deploy.sh`
> (versionado). O bloco abaixo é o esboço original e pode estar desatualizado —
> em caso de divergência, vale o arquivo.

### Esboço original (histórico)

```bash
#!/usr/bin/env bash
# Uso: deploy.sh <artefato.tgz>   |   deploy.sh --rollback <commit>
set -euo pipefail

REPO=/opt/dez/app
FRONT=/opt/dez/frontend
APP=$REPO/backend
LOG=/var/log/dez/deploy.log
ARTIFACT="${1:-}"

log() { printf '%s %s\n' "$(date -Is)" "$*" | tee -a "$LOG"; }

if [ "$ARTIFACT" = "--rollback" ]; then
  TARGET="${2:?informe o commit}"
  git -C "$REPO" reset --hard "$TARGET"
  sudo /usr/bin/systemctl restart dez-web.service
  sudo /usr/bin/systemctl restart dez-schedule.timer
  log "rollback manual para $TARGET"
  exit 0
fi

[ -n "$ARTIFACT" ] && [ -f "$ARTIFACT" ] || { log "ABORTADO: artefato ausente"; exit 1; }

PREV=$(git -C "$REPO" rev-parse HEAD)
[ -z "$(git -C "$REPO" status --porcelain)" ] || { log "ABORTADO: árvore suja"; exit 1; }

on_error() {
  log "FALHA no deploy — rollback para $PREV"
  git -C "$REPO" reset --hard "$PREV"
  sudo /usr/bin/systemctl restart dez-web.service || true
  exit 1
}
trap on_error ERR

WORK=$(mktemp -d /tmp/dez-deploy.XXXXXX)
tar -xzf "$ARTIFACT" -C "$WORK"

log "atualizando código"
git -C "$REPO" fetch --prune origin
git -C "$REPO" pull --ff-only origin main
NEW=$(git -C "$REPO" rev-parse HEAD)

log "publicando vendor/ e frontends/"
rsync -a --delete "$WORK/vendor/" "$APP/vendor/"
for app in equipe agendamento paciente painel-tv; do
  mkdir -p "$FRONT/$app"
  rsync -a --delete "$WORK/dist/$app/" "$FRONT/$app/"
done

log "migrations"
/opt/dez/bin/artisan migrate --force

log "otimizando caches"
/opt/dez/bin/artisan optimize

log "reiniciando serviços"
sudo /usr/bin/systemctl restart dez-web.service
sudo /usr/bin/systemctl restart dez-schedule.timer

sleep 2
curl -fsS http://127.0.0.1:8002/up >/dev/null || { log "health check falhou"; on_error; }

rm -rf "$WORK"
log "deploy ok: $PREV -> $NEW"
```

## Anexo D — `.github/workflows/deploy.yml`

> ⚠️ **A fonte de verdade é o arquivo real:** `.github/workflows/deploy.yml`
> (tem 3 jobs: `verify` → `build` → `deploy`). O bloco abaixo é o esboço
> original (2 jobs) e **não** reflete o atual.

### Esboço original (histórico)

```yaml
name: deploy

on:
  workflow_dispatch:
  # push:
  #   branches: [main]

concurrency:
  group: deploy-dez
  cancel-in-progress: false

jobs:
  build:
    runs-on: ubuntu-latest
    defaults: { run: { working-directory: backend } }
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', extensions: dom, curl, libxml, mbstring, zip, pdo, pgsql, pdo_pgsql, coverage: none }
      - run: composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
      - uses: pnpm/action-setup@v4
        with: { version: 11 }
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: pnpm, cache-dependency-path: frontend/pnpm-lock.yaml }
      - run: pnpm install --frozen-lockfile
        working-directory: frontend
      - run: pnpm build
        working-directory: frontend
      - name: Empacotar artefato
        run: |
          set -e
          mkdir -p /tmp/out/dist
          cp -r backend/vendor /tmp/out/vendor
          for app in equipe agendamento paciente painel-tv; do
            cp -r frontend/apps/$app/dist /tmp/out/dist/$app
          done
          tar -czf dez-build.tgz -C /tmp/out .
      - uses: actions/upload-artifact@v4
        with: { name: dez-build, path: dez-build.tgz, retention-days: 7 }

  deploy:
    needs: build
    runs-on: [self-hosted, dez]
    steps:
      - uses: actions/download-artifact@v4
        with: { name: dez-build, path: . }
      - run: /opt/dez/app/deploy/scripts/deploy.sh "$GITHUB_WORKSPACE/dez-build.tgz"
```

## Anexo E — comandos pendentes (recuperar acesso ao Postgres)

**E1 — procurar a senha nas configs do STG (leitura, sem sudo):**
```bash
grep -rniE "password|senha" /home/server/projetos/dezstg2/application/config/database.php 2>/dev/null
cat ~/.pgpass 2>/dev/null
```

**E2 — procurar na config/unidade do painel Java:**
```bash
systemctl cat painel
grep -rniE "password|senha|datasource" /home/server/painelWeb* /home/server/*.yml /home/server/*.properties /home/server/*.conf 2>/dev/null | head
```

**E3 — testar a senha encontrada (troque `<SENHA>`):**
```bash
PGPASSWORD='<SENHA>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres -c "select version();"
```
Se funcionar, os dados que eu preciso:
```bash
PGPASSWORD='<SENHA>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres -c "select datname, pg_size_pretty(pg_database_size(datname)) as tamanho from pg_database order by pg_database_size(datname) desc limit 15;"
PGPASSWORD='<SENHA>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres -c "show max_connections;"
PGPASSWORD='<SENHA>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres -c "show password_encryption;"
```
> **Não me mande a senha.** Use-a localmente; eu só preciso da **saída** dos comandos acima.

**E4 — plano B: `peer` temporário (se a senha não aparecer em lugar nenhum)**

`reload` **não derruba conexões** do STG; `restart` derrubaria. Usar reload.
```bash
sudo cp /etc/postgresql/16/main/pg_hba.conf /etc/postgresql/16/main/pg_hba.conf.bak
sudo nano /etc/postgresql/16/main/pg_hba.conf
#   trocar APENAS a 1ª linha:
#   de:  local   all   postgres   md5
#   p/ : local   all   postgres   peer
sudo systemctl reload postgresql@16-main
sudo -u postgres psql -p 5432 -c "select version();"          # agora entra por peer
sudo -u postgres psql -p 5432 -c "\password postgres"          # define uma senha nova (anote!)
sudo cp /etc/postgresql/16/main/pg_hba.conf.bak /etc/postgresql/16/main/pg_hba.conf
sudo systemctl reload postgresql@16-main
PGPASSWORD='<NOVA>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres -c "select 'ok';"   # confirma
```
