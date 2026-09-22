# Extração MT do STG legado — pacote pronto para a rede da Clínica DEZ

> **Estado:** a extração só pode rodar **dentro da rede da Clínica DEZ**
> (o Postgres legado, db `dez`, não é acessível de fora). Quando estiver na
> rede, siga um dos dois caminhos abaixo. **Somente leitura** — nada é
> escrito no banco do STG.

O que este pacote produz (formato canônico consumido por `stg:import-mt`):

| Arquivo | Colunas |
|---|---|
| `mt_riscos.csv` | `legacy_id, descricao, ativo` |
| `mt_setores.csv` | `legacy_id, legacy_convenio_id, descricao, ativo` |
| `mt_funcoes.csv` | `legacy_id, legacy_setor_id, legacy_risco_id, descricao, ativo` |
| `mt_asos.csv` | `legacy_id, legacy_paciente_id, legacy_convenio_id, legacy_setor_id, legacy_funcao_id, legacy_risco_ids (separado por \|), legacy_exame_nomes, tipo, legacy_situacao, data_cadastro, data_realizacao, data_validade, ativo, impressao_aso_json` |

## Caminho A — script PHP (recomendado)

1. Descobrir o IP do servidor do STG na rede (o mesmo host usado pelo painel web).
2. Descobrir a senha do Postgres (a mesma que o painel usa — está em
   `application/config/database.php` do CodeIgniter, mas pode divergir no servidor).
3. Rodar, em `C:\Projects\sistema-dez\docs\extracao_mt\`:

```powershell
$env:STG_DB_PASSWORD='...'
& "$env:LOCALAPPDATA\php\php.exe" extract_mt.php --host <IP_SERVIDOR> --port 5432 --db dez --user postgres --out "C:\Projects\STG\dezstg2\_extracoes_stg\mt"
```

4. Se der erro de coluna inexistente, rode o modo de descoberta e compare com
   as consultas do script:

```powershell
& "$env:LOCALAPPDATA\php\php.exe" extract_mt.php --host <IP_SERVIDOR> --db dez --user postgres --discover
```

Depois, importar no Sistema DEZ:

```powershell
cd C:\Projects\sistema-dez\backend
# com STG_IMPORT_PATH apontando para C:/Projects/STG/dezstg2/_extracoes_stg
& "$env:LOCALAPPDATA\php\php.exe" artisan stg:import-mt --dry-run
& "$env:LOCALAPPDATA\php\php.exe" artisan stg:import-mt --commit
```

## Caminho B — psql no servidor (sem PHP)

Se o script PHP não tiver linha de rede até o servidor, rode o SQL do
`extract_mt.sql` direto no servidor (`psql -U postgres -d dez -f extract_mt.sql`)
e copie os CSVs gerados para `_extracoes_stg\mt\`. O SQL gera exatamente os
mesmos arquivos/colunas do Caminho A.

## Observações sobre os dados legados

- `tipo` no legado: `ADMISSIONAL`, `PERÍODICO`, `RETORNO AO TRABALHO`,
  `MUDANÇA DE FUNÇÃO`, `DEMISSIONAL` (o importador normaliza para
  `PERIODICO`, `RETORNO_AO_TRABALHO`, `MUDANCA_DE_FUNCAO`).
- Setor/função/riscos de cada ASO ficam dentro do JSON `impressao_aso`
  (POST do cadastro) — o script parseia e joga em `legacy_setor_id`,
  `legacy_funcao_id`, `legacy_risco_ids`. Linhas sem JSON válido saem em
  branco e não bloqueiam o import.
- Convenio da ASO: o legado usa `convenio_id` (modalidade empresa) ou
  `convenio2` (particular). O importador aceita `COALESCE` dos dois e registra
  issue quando a empresa não casa com nenhum agreement do DEZ.
- Situação dos exames (`tb_aso_situacao`) viaja só como texto
  (`legacy_situacao`); o status no DEZ é o próprio (`PENDENTE`/`AGENDADO`/
  `EMITIDO`/`CANCELADO`), inicializado como `EMITIDO` quando existe
  `data_validade`, senão `PENDENTE`.

## Pendências desta etapa (preencher quando na rede)

- [ ] IP/host do servidor do STG: ____________________
- [ ] Porta do Postgres (default 5432): ____________________
- [ ] Credenciais testadas: [ ]
- [ ] CSVs gerados e copiados para `_extracoes_stg\mt\`: [ ]
- [ ] `stg:import-mt --dry-run` sem erros: [ ]
- [ ] `stg:import-mt --commit` + conferência de contagens: [ ]
