-- Provisionamento do database do Sistema DEZ no cluster PostgreSQL 16 já
-- existente no servidor da clínica (COMPARTILHADO com a produção do STG).
--
-- Executar como superusuário, passando a senha da aplicação por variável:
--
--   sudo -u postgres psql -p 5432 -d postgres \
--     -v app_password="'<SENHA_GERADA>'" -f deploy/sql/provision.sql
--
-- Nada aqui toca no banco legado do STG: apenas cria um role e um database
-- novos. Os limites de sessão existem para que uma consulta ruim do DEZ não
-- prejudique a produção (ver docs/plano_deploy.md §7.3).

CREATE ROLE sistema_dez LOGIN PASSWORD :'app_password' CONNECTION LIMIT 15;

CREATE DATABASE sistema_dez OWNER sistema_dez ENCODING 'UTF8';

ALTER ROLE sistema_dez SET statement_timeout = '15s';
ALTER ROLE sistema_dez SET idle_in_transaction_session_timeout = '60s';
ALTER ROLE sistema_dez SET work_mem = '4MB';
ALTER ROLE sistema_dez SET maintenance_work_mem = '32MB';
ALTER ROLE sistema_dez SET search_path = public;

REVOKE ALL ON DATABASE sistema_dez FROM PUBLIC;
GRANT ALL ON DATABASE sistema_dez TO sistema_dez;
