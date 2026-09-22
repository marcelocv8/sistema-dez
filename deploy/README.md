# Deploy do Sistema DEZ — servidor da Clínica DEZ

> Runbook curto. O documento completo (inventário do servidor, decisões,
> riscos, anexos com todos os arquivos) é **`docs/plano_deploy.md`**.

## O que é isto

Artefatos para rodar o Sistema DEZ **em fase de teste**, na mesma máquina que
serve o **STG de produção**, com URL pública estável e atualização por
`git push` → workflow → `git pull` + redeploy.

**O STG não é tocado:** sem Docker, sem PHP no host, sem mexer no Apache/vhost
`stg8080.conf`, no PostgreSQL existente (além de criar role/database) nem no
túnel `cloudflared` atual (que serve o STG).

## Layout no servidor

| Caminho | Conteúdo |
|---|---|
| `/opt/dez/bin/` | `frankenphp` (static), `php.ini`, wrapper `artisan` |
| `/opt/dez/Caddyfile` | borda: 4 SPAs por prefixo + Laravel |
| `/opt/dez/app/` | clone do repo (única coisa que o git toca) |
| `/opt/dez/frontend/<app>/` | dists publicados pelo CI (**fora** do git) |
| `/opt/dez/releases/` | últimos 5 artefatos, usados no rollback |
| `/opt/dez/_import/` | extrações do STG para importação |
| `/var/log/dez/` · `/var/backups/dez/` | logs e backups |

## Ordem de implantação

> O `install.sh` mora **dentro** do repo, mas o repo ainda não está no servidor.
> Por isso a ordem é: preparar o mínimo (usuário + chave) → clonar → rodar o
> `install.sh` de dentro do clone. Sem transferência manual de arquivos.

```bash
# 0) mínimo: usuário de sistema + diretório da chave
sudo adduser --system --group --home /opt/dez --shell /bin/bash dez
sudo mkdir -p /opt/dez/.ssh
sudo chown -R dez:dez /opt/dez && sudo chmod 700 /opt/dez/.ssh

# 1) chave de deploy (leitura do repo privado)
sudo -u dez ssh-keygen -t ed25519 -N "" -f /opt/dez/.ssh/dez_deploy
sudo cat /opt/dez/.ssh/dez_deploy.pub
#    -> cole em GitHub > Settings > Deploy keys (SEM "Allow write access")

# 2) testar a saída para o GitHub (esperado: "successfully authenticated" + Permission denied)
sudo -u dez ssh -i /opt/dez/.ssh/dez_deploy -o IdentitiesOnly=yes \
  -o StrictHostKeyChecking=accept-new -T git@github.com

# 3) clonar
sudo -u dez env GIT_SSH_COMMAND="ssh -i /opt/dez/.ssh/dez_deploy -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new" \
  git clone git@github.com:marcelocv8/sistema-dez.git /opt/dez/app

# 4) base: FrankenPHP, php.ini, Caddyfile, units, sudoers (idempotente)
sudo /opt/dez/app/deploy/scripts/install.sh

# 5) banco: role sistema_dez + database (só cria objetos novos)
SENHA=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
echo "senha do role sistema_dez: $SENHA"
PGPASSWORD='<senha-do-postgres>' psql -h 127.0.0.1 -p 5432 -U postgres -d postgres \
  -v app_password="$SENHA" -f /opt/dez/app/deploy/sql/provision.sql

# 6) ambiente, migrations e usuários (um por pessoa, com RBAC)
sudo /opt/dez/app/deploy/scripts/bootstrap.sh \
  --url https://dez.clinicadez.com.br --db-password "$SENHA" \
  --admin-email voce@exemplo.com --admin-name "Seu Nome"

# 7) URL pública — rodar como o usuário 'server' (dono do cert.pem), SEM sudo na frente
/opt/dez/app/deploy/scripts/tunnel.sh dez.clinicadez.com.br

# 8) subir tudo e validar
sudo systemctl enable --now dez-web.service dez-schedule.timer dez-backup.timer dez-tunnel.service
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8002/up
curl -s -o /dev/null -w '%{http_code}\n' https://dez.clinicadez.com.br/up
```

> Se o SSH na porta 22 para o GitHub estiver bloqueado na rede da clínica, use
> `ssh.github.com:443`: crie `/opt/dez/.ssh/config` com
> `Host github.com` / `HostName ssh.github.com` / `Port 443` / `User git` /
> `IdentityFile /opt/dez/.ssh/dez_deploy`.

## Dados do STG (catálogo, preços, modelos de laudo, MT)

Ver §15 do plano. Resumo: subir as extrações para `/opt/dez/_import/` e rodar
`stg:import-catalog`, `stg:import-report-templates` e `stg:import-mt`
(sempre `--dry-run` antes de `--commit`, fora do horário da clínica).

```bash
sudo -u dez /opt/dez/bin/artisan stg:import-catalog --dry-run
```

## Atualização (dia a dia)

1. No PC de dev: commit + `git push` (**somente com autorização do dono**).
2. No GitHub: **Actions → deploy → Run workflow**.
3. O runner do servidor baixa o artefato e roda o `deploy.sh`, que faz
   `git pull --ff-only`, migrations, caches, restart e health check — com
   rollback automático se falhar.

Alternativa por SSH (**sem `sudo` na frente** — o `deploy.sh` chama `sudo systemctl` internamente): `ssh dez@192.168.0.170 '/opt/dez/app/deploy/scripts/deploy.sh <artefato>'`.

Rollback manual: `/opt/dez/app/deploy/scripts/deploy.sh --rollback <commit>` (como `dez`).

## Operação

| Situação | Comando |
|---|---|
| Log do deploy | `tail -f /var/log/dez/deploy.log` |
| Log da aplicação | `tail -f /opt/dez/app/backend/storage/logs/laravel-$(date +%F).log` |
| Status | `systemctl status dez-web dez-tunnel dez-schedule.timer dez-backup.timer` |
| Reiniciar a app | `sudo systemctl restart dez-web` |
| Backup manual | `sudo -u dez /opt/dez/app/deploy/scripts/backup.sh` |
| Consumo de memória | `systemctl status dez-web \| grep Memory` |
| Parar tudo (emergência) | `sudo systemctl stop dez-web dez-tunnel` — **o STG continua no ar** |

## Instalar o runner do GitHub (F6)

O asset do runner **inclui a versão no nome** — `…/latest/download/actions-runner-linux-x64.tar.gz` responde **404**. Confira a versão atual em
<https://github.com/actions/runner/releases/latest> (verificado em 22/09/2026: **v2.337.0**).

```bash
sudo -u dez mkdir -p /opt/dez/actions-runner
cd /opt/dez/actions-runner
sudo -u dez curl -fL --retry 3 -o runner.tar.gz \
  https://github.com/actions/runner/releases/latest/download/actions-runner-linux-x64-2.337.0.tar.gz
sudo -u dez tar -xzf runner.tar.gz
```

Token (expira em ~1 hora): **repo → Settings → Actions → Runners → New self-hosted runner → Linux**.
```bash
sudo -u dez ./config.sh --url https://github.com/marcelocv8/sistema-dez \
  --token <TOKEN> --name dez-server --labels dez --work /opt/dez/_work --unattended
sudo ./svc.sh install dez
sudo ./svc.sh start
sudo ./svc.sh status
```
Confirmado: o runner precisa rodar como **`dez`** (é quem tem o `sudo` restrito e o dono de `/opt/dez`).

Depois disso, no GitHub: **Actions → deploy → Run workflow**. O job `verify` roda os
testes; passando, o job `deploy` roda no servidor e publica `vendor/` + os 4 frontends.

## Regras que não podem ser quebradas

1. **Nunca** editar/reiniciar `/etc/cloudflared/config.yml` ou `cloudflared.service`
   (túnel do STG em produção).
2. **Nunca** rodar `php artisan db:seed` puro: o `DatabaseSeeder` cria
   `test@example.com` quando `APP_ENV != local`.
3. Imports e migrations pesadas **fora do horário da clínica**.
4. Segredos (`APP_KEY`, `IDENTITY_PEPPER`, `MANAGEMENT_TOKEN_SECRET`) são
   **exclusivos deste servidor** — nunca copiar do ambiente de dev.
