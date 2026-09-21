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

```bash
# 0) (se ainda não houver) acesso de superusuário ao PostgreSQL — ver Anexo E do plano

# 1) base: usuário dez, diretórios, FrankenPHP, units, sudoers
sudo DEZ_REPO_URL=git@github.com:marcelocv8/sistema-dez.git \
  /opt/dez/app/deploy/scripts/install.sh

# 2) banco (role isolado + database)
sudo -u postgres psql -p 5432 -d postgres -v app_password="'<SENHA>'" \
  -f /opt/dez/app/deploy/sql/provision.sql

# 3) ambiente, migrations e usuários (um por pessoa, com RBAC)
sudo /opt/dez/app/deploy/scripts/bootstrap.sh \
  --url https://dez.clinicadez.com.br \
  --db-password '<SENHA>' \
  --admin-email voce@exemplo.com --admin-name "Seu Nome"

# 4) URL pública (túnel NOVO; exige `cloudflared tunnel login` uma vez, com navegador)
sudo -u dez /opt/dez/app/deploy/scripts/tunnel.sh dez.clinicadez.com.br

# 5) subir tudo
sudo systemctl enable --now dez-web.service dez-schedule.timer dez-backup.timer dez-tunnel.service
curl -s -o /dev/null -w '%{http_code}\n' https://dez.clinicadez.com.br/up
```

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

Alternativa por SSH: `ssh dez@192.168.0.170 'sudo /opt/dez/app/deploy/scripts/deploy.sh <artefato>'`.

Rollback manual: `sudo /opt/dez/app/deploy/scripts/deploy.sh --rollback <commit>`.

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

```bash
sudo -u dez mkdir -p /opt/dez/actions-runner && cd /opt/dez/actions-runner
sudo -u dez curl -fsSL -o runner.tar.gz \
  https://github.com/actions/runner/releases/latest/download/actions-runner-linux-x64.tar.gz
sudo -u dez tar -xzf runner.tar.gz
# token em: repo → Settings → Actions → Runners → New self-hosted runner
sudo -u dez ./config.sh --url https://github.com/marcelocv8/sistema-dez \
  --token <TOKEN> --name dez-server --labels dez --work /opt/dez/_work --unattended
sudo ./svc.sh install dez && sudo ./svc.sh start
```

## Regras que não podem ser quebradas

1. **Nunca** editar/reiniciar `/etc/cloudflared/config.yml` ou `cloudflared.service`
   (túnel do STG em produção).
2. **Nunca** rodar `php artisan db:seed` puro: o `DatabaseSeeder` cria
   `test@example.com` quando `APP_ENV != local`.
3. Imports e migrations pesadas **fora do horário da clínica**.
4. Segredos (`APP_KEY`, `IDENTITY_PEPPER`, `MANAGEMENT_TOKEN_SECRET`) são
   **exclusivos deste servidor** — nunca copiar do ambiente de dev.
