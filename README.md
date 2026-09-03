# Sistema DEZ — monorepo (privado)

Sistema de gestão próprio da Clínica DEZ (substitui o STG legado).
Repo oficial: **`marcelocv8/sistema-dez`** (privado), branch `main`.

## Estrutura

| Pasta | Conteúdo |
|---|---|
| `backend/` | API Laravel 13 + PostgreSQL (contrato v1, catálogo, agendamento, equipe). Ver `backend/README.md`. |
| `frontend/` | Monorepo React + Vite + TypeScript via pnpm (apps `equipe`, `agendamento`, `painel-tv`, `paciente`). Ver `frontend/README.md`. |
| `docs/plano_sistema_dez.md` | Especificação viva do sistema (fonte de verdade de produto). |

## O que NÃO está neste repo (de propósito)

- Legado STG (`dezstg2/`, ~1,15 M linhas), extrações (`_extracoes_stg/`, `_extracoes_laudos/`), mockups e plano Hermes — ficam na máquina do dono em `C:\Projects\STG\`. O backend monta as fontes de importação via volume configurável (`STG_IMPORT_SOURCE`, ver `backend/compose.yaml`).
- Segredos (`.env`), dumps de banco (`*.dump`, `*.sql`), `vendor/`, `node_modules/`, relatórios de teste — cobertos pelo `.gitignore`.

## Rodar localmente

```bash
# Backend (precisa de PHP 8.4+ / Composer, ou Docker Desktop)
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
# ou: docker compose up --build  (API em http://localhost:8000)

# Frontend (precisa de pnpm)
cd frontend
pnpm install
pnpm dev:equipe
```

## Regra de ouro do git

**Commit e push SOMENTE com autorização explícita do dono** (ver `AGENTS.md`). Nunca commitar `.env`, dumps ou segredos.
