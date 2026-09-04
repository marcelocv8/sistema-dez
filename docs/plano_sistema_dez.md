# Sistema DEZ — Clínica DEZ
## Especificação (v0.11)

> **Objetivo:** ponto de partida para o **Sistema DEZ** — sistema de gestão próprio que substitui o STG ("STG CLINICAS v2.0", CodeIgniter 1.7.2 / PHP 5), mantendo **a rotina que funciona hoje** e resolvendo os pontos fracos (fechamento de caixa, segurança antifraude, agendamento online, usabilidade, dados na mão).
>
> **v0.11** — Repo privado `marcelocv8/sistema-dez` criado (04/09/2026): o código do
> novo sistema saiu de `C:\Projects\STG\sistema-dez-api|` + `sistema-dez-frontend/`
> para o monorepo em `C:\Projects\sistema-dez\` (`backend/` + `frontend/` +
> `docs/plano_sistema_dez.md`). O legado STG (`dezstg2/`, `_extracoes_stg/`,
> `_extracoes_laudos/`, mockups, `VALIDACAO_LAUDOS.md`, plano Hermes) permanece em
> `C:\Projects\STG\` e é montado no Docker via `STG_IMPORT_SOURCE`
> (ver `backend/compose.yaml`). **Regra de git: commit e push somente com
> autorização explícita do dono, nunca automático** (ver `AGENTS.md` na raiz do repo).
>
> **Onde encontrar cada coisa (para agentes):** código novo → `C:\Projects\sistema-dez\`
> (repo `marcelocv8/sistema-dez`, branch `main`); legado, extrações e referências →
> `C:\Projects\STG\`; fork WhatsApp → `C:\Projects\Chat App Crédito` (via API, §6.12.1).
>
> **v0.10** — Fase 2B implementada e validada localmente (03/09/2026): publicação/retirada explícitas por Admin/Supervisor, validade de até 30 dias, bloqueio de convênios com guia e link privado para o paciente consultar, remarcar e cancelar. Recebida a validação do dono para o exemplo de vínculo/oferta/horário da Fase 2A. Seu exemplo continua privado: 1 vínculo, 1 oferta, 1 escala, 52 ocorrências e 0 pacientes/agendamentos. Testes finais: 58 backend/363 asserções em `sistema_dez_test`, 9 Vitest, 7 typechecks, 4 builds e 4 fluxos Playwright (2B e regressão 2A, desktop/celular). **Não houve publicação na internet nem liberação automática de oferta clínica.** Detalhes, endereços locais e pendências em §14.8.
>
> **v0.9 — histórico de 02/09/2026:** Fase 2A implementada e validada tecnicamente no ambiente local: Messejana como única unidade operacional, ofertas/escalas/exceções, ocorrências de 90 dias e agenda interna auditada. Testes daquele ciclo: 39 backend/228 asserções, 5 Vitest, 7 typechecks, 4 builds e 2 fluxos Playwright. Naquele momento o primeiro vínculo ainda dependia do Admin; essa configuração e a validação do exemplo foram recebidas posteriormente (§14.8).
>
> **v0.8** — Resolução das 85 pendências materializada no PostgreSQL real (31/08/2026): 5 `RESOLVED`, 33 `IGNORED` e 47 `UNMATCHED`; quatro maiores preços promovidos para `VALID`, seis candidatos continuam em quarentena. Criado o comando idempotente `stg:resolve-issues` com `--dry-run`/`--commit`, transação e proteção contra sobrescrever decisões manuais. Banco restaurado e reimportado após validação isolada. O primeiro Admin (`marcelo@appcredito.com.br`) aguarda apenas a senha definida pelo operador; nenhum segredo foi inventado ou persistido.
>
> **v0.7** — Fase 1B concluída (31/08/2026): CRUDs auditados de unidades, convênios, procedimentos e profissionais; RBAC ampliado para catálogo, vínculos, equipe, importações e auditoria; gestão de usuários sem senha padrão e proteção do último Admin; vínculos profissional–procedimento–convênio–unidade em tabela própria, sem publicação automática no agendamento; **85 pendências classificadas** (§14.5.1); página real `/cadastros`; OpenAPI v0.3 e tipos regenerados. Banco real migrado com 0 vínculos e 0 usuários — relações clínicas e credenciais não foram inventadas. Validação da fase: 26 testes backend/130 asserções, 3 testes frontend, typecheck de 7 projetos, 4 builds e QA desktop/mobile sem erros de console. A materialização das decisões e os totais finais estão registrados na v0.8.
>
> **v0.6** — Fase 1A concluída (31/08/2026): **Laravel 13 + React** confirmados como stack final; Sanctum stateful, perfis mínimos da equipe e consulta real de preços entregues; catálogo STG importado de forma idempotente no PostgreSQL com checksum, dry-run, reconciliação e quarentena. Duas execuções confirmadas mantiveram os mesmos totais: 998 procedimentos, 40 convênios, 87 profissionais, 2.313 preços armazenados (2.303 válidos e 10 candidatos em quarentena), 36 linhas idênticas colapsadas e 85 pendências reportadas. A página `/precos` e a identidade da equipe usam a API real; os demais módulos visuais continuam mockados. Detalhes e próxima fase em §14.
>
> **v0.5** — primeiro ciclo de implementação (30–31/08/2026): backend **Laravel 13** criado em `sistema-dez-api/` (**hoje `backend/` no repo `marcelocv8/sistema-dez`**, em `C:\Projects\sistema-dez\`) (API pública v1: catálogo, disponibilidade e agendamento idempotente; modos FIXED_SLOT / ARRIVAL_BLOCK / WALK_IN_INFO; LGPD com CPF hash+pepper; testes) e frontend **React monorepo** criado em `sistema-dez-frontend/` (**hoje `frontend/` no repo**) (apps equipe, agendamento, paciente e painel-tv + packages ui/domain/api; Storybook, Playwright, Vitest, MSW); mockups aprovados em `C:\Projects\STG\mockups_sistema_clinica_dez/`; **consulta de preços** entra no escopo (§6.20, T21 — decisão do dono 31/08). Detalhes do ciclo em §14. Stack final ainda **pendente de confirmação do dono** (§11 #1): o que existe hoje é o primeiro ciclo visual/funcional (app equipe opera com dados mock; catálogo do STG extraído mas ainda não importado — Fase 1).
>
> **v0.4** — decisões do dono (30/08/2026): nome oficial **Sistema DEZ**; controle de ponto (relatório exclusivo Admin/Supervisor); WhatsApp com paciente = fork do **Chat App Crédito** (`C:\Projects\Chat App Crédito`), integrado via API; orçamentos por leitura de prescrição com resolução obrigatória (sim/não); rotinas diárias e mensais por usuário; escalas fixas × ordem de chegada com lista de espera e prioridade legal; chamadas de TV também pelo médico logado; fluxo **chegada → recepção → caixa (pagamento) → atendimento → resultado**; prontuário moderno, auditável, retido 20 anos (laudos: 5 anos, decisão do dono); portal de resultados com acesso temporário (exames próprios + DB); repasse com confirmação auditável e comissão customizável por profissional; financeiro fora do escopo exceto caixa + boletos B2B; tabelas múltiplas por convênio (Particular = convênio padrão); Gerencianet descartado; Stone (cartão) e Inter (PIX) = integrações opcionais (últimas tarefas); gestão Sou DEZ = última tarefa. Decisões viradas em tarefas em `§12`.
>
> **v0.3** — laboratórios Solis/Imuneweb descartados; Bate-papo = equipe-only (paciente via WhatsApp = fork do Chat App Crédito); Inter (boletos) = integrar cedo.
>
> **v0.2** — incorpora as decisões do dono: manter Teleconsulta (novo, estado-da-arte) e Bate-papo interno; confirmar via WhatsApp; API para agentes Hermes; Doctoralia; boletos Inter; fechamento de caixa fiel ao método real; faturamento B2B (não planos de saúde).
>
> **Fonte de verdade:** código novo em `C:\Projects\sistema-dez\` (repo privado
> `marcelocv8/sistema-dez`: `backend/` + `frontend/` + `docs/plano_sistema_dez.md`)
> + sistema ao vivo `sistema.clinicadez.com.br`. Legado de referência em
> `C:\Projects\STG\dezstg2`; dados de negócio validados no inventário
> (`C:\Projects\STG\_extracoes_stg\`); mockups aprovados em
> `C:\Projects\STG\mockups_sistema_clinica_dez\` (§14).
>
> **Plano detalhado de implementação:** `C:\Projects\STG\.hermes\plans\2026-08-16_221155-laudos-sistema-proprio.md` — divisão de tarefas Backend (BE), Frontend (FE) e QA/Testes.

---

## 1. Contexto e decisão estratégica

O STG é um monolito white-label (~1,15 milhão de linhas PHP) que atende *qualquer* estabelecimento de saúde. A Clínica DEZ (cardiologia + clínica popular, Messejana) usa uma fração — confirmado ao vivo: só **Recepção, Procedimentos, Consulta, Imagem/Laudo, Financeiro, Impressão, Aplicativo e Dashboards** estão ativos. Farmácia, Estoque, Internação, Centro Cirúrgico, Emergência, Laboratório, CCIH, CEATOX, SCA e GIÁH estão desativados.

**Decisão:** construir **novo, sob medida**, extraindo do STG dados, regras e integrações (não refatorar). Riscos que isso resolve: framework de 2009 sem suporte, credenciais hardcoded, SQL injection, `display_errors` ligado em produção, 391 MB de PDFs no webroot (LGPD), zero versionamento, e limitações na operação real.

---

## 2. Objetivos e princípios de design

### 2.1 Objetivos (por prioridade)
1. **Segurança do negócio** — todo atendimento vira receita registrada (antifraude na recepção).
2. **Não quebrar a rotina dos médicos** — laudos e fluxo clínico ficam **funcionalmente idênticos**. **Motivo:** médicos e recepcionistas/digitadores já conhecem a sequência, os modelos, os campos e a nomenclatura do STG; quando há revisão, ela ocorre visualmente no impresso antes da assinatura. Trocar a rotina ao mesmo tempo que o sistema aumenta risco de erro, resistência e queda de produtividade. A modernização deve melhorar interface, desempenho, salvamento e auditoria sem exigir reaprendizado. Preservamos o comportamento clínico necessário — não os bugs ou falhas de segurança do legado.
3. **Fechamento de caixa simples e confiável** — clínica popular, muito dinheiro em espécie.
4. **Agendamento online moderno** integrado ao site + **Doctoralia**.
5. **Facilidade de uso para a equipe** + **dados na mão**.
6. **Base técnica moderna** que dure 10+ anos.

### 2.2 Princípios
- **Nada se apaga, só se anula** (soft-delete + auditoria imutável em dinheiro e atendimento).
- **Todo atendimento nasce rastreável** (operador, data/hora, estação, ação).
- **Antifraude por desenho**, não por confiança (seção 7).
- **API-first**: web, app, site, TV e **agentes Hermes** consomem a mesma API.
- **Fatiado por domínio** (agendamento, laudos, financeiro, cadastros).
- **Mobile-friendly** desde o início.

---

## 3. Escopo: o que entra e o que fica de fora

### 3.1 ENTRA

| Módulo | Base STG | Nota |
|---|---|---|
| **Cadastros** (pacientes, profissionais, convênios, procedimentos/preços, salas) | `cadastros/*`, `ambulatorio/procedimento`, `ambulatorio/empresa`, `seguranca/operador` | Núcleo — **Particular = convênio padrão**; tabelas múltiplas por convênio |
| **Consulta de preços (tabela)** | `ambulatorio/autocomplete` (`procedimentovalor`), `tb_procedimento_convenio` | **NOVO (31/08)** — busca por código TUSS/nome a partir de 3 caracteres; consultas e exames agrupados por tipo; preço por convênio (§6.20) |
| **Agendamento** (interno + online + Doctoralia) | `ambulatorio/agenda`, `agenda_model` | Reescrito, moderno |
| **Recepção & fila de atendimento** | `ambulatorio/exame` (senhas, salas de espera) | Reescrito — cadastro complementar ativa o caixa; lista de espera com prioridade legal |
| **Laudos & exames** (fluxo cardio) | `ambulatorio/laudo`, `laudo_model`, `laudoportal_model` | **Preservar fluxo**, layout moderno, retenção 5 anos |
| **Caixa & fechamento** | `cadastros/caixa` | **Reescrito p/ o método real do dono** — pagamento antes do atendimento; resto do financeiro fora do escopo (§6.5, §12) |
| **Repasses médicos (comissão)** | `procedimento` (% médico), relatórios do STG | **Novo fluxo** — comissão customizável por profissional + confirmação auditável do médico |
| **Faturamento B2B (empresas)** | `cadastros/convenio`, `contasreceber` | **Reescrito** — fatura fim do mês + boleto Inter + filtro de adimplência |
| **Medicina do Trabalho** (ASO/PCMSO) | `ambulatorio/saudeocupacional` | **Manter** |
| **Teleconsulta** | — | **NOVO, estado-da-arte** (não reaproveitar STG) |
| **Bate-papo interno** | — (STG tem `batepapo`, descartar) | **NOVO, básico** (médico ↔ recepção) |
| **Controle de Ponto** | — | **NOVO** — login diário = marcação; relatório Admin/Supervisor (§6.15) |
| **Rotinas diárias/mensais** | — | **NOVO** — confirmações 2ª camada, orçamentos D+1, aniversariantes; tick com crítica (§6.17) |
| **Orçamentos por prescrição** | — | **NOVO** — prescrição (WhatsApp/upload) → itens → orçamento validado → resolução obrigatória (§6.16) |
| **Prontuário eletrônico** | conceitos do STG | **NOVO, layout moderno** — auditável, retenção 20 anos; IA opcional (§6.18) |
| **Portal de resultados (acesso temporário)** | `laudoportal_model` | Credencial temporária no site — exames próprios + DB (§6.19) |
| **Painel de chamadas (TV)** | `painel_novo/*`, `exame.php` (listaresperasenhas) | Reescrito, web-based; chamada também pelo médico logado |
| **WhatsApp paciente (fork)** | — | Integração via API com o fork do Chat App Crédito (§6.12.1) |
| **Sou DEZ (fidelidade)** | `fidelidade/*` | Gestão do programa — **última tarefa** (§12) |
| **Marketing** (o que se usa hoje) | `ambulatorio/guia/marketing`, `empresa/listarpostsblog` | Manter funcionalidades em uso |
| **Prescrição digital** (Memed) | `laudo.php` (ApiMedicoMemed), `prescriptionAPI` | Manter integração |
| **Relatórios & dashboards** | `dashboard_model`, relatórios do STG (a selecionar) | Reescrito + **aproveitar relatórios escolhidos** |
| **API de integração (agentes Hermes)** | `fidelidade/api.php` (padrão) | **NOVO, read+write, escopada e auditada** |

### 3.2 FICA DE FORA (não usamos — descartar)
Farmácia, Estoque, Internação, Centro Cirúrgico, Emergência, Laboratório interno (LAB DEZ — resultados laboratoriais chegam via integração/portal do DB, §6.19), CCIH, CEATOX, SCA, GIÁH, iugu e **Gerencianet** (gateways descartados — decisão do dono 30/08/2026; reavaliar apenas ao automatizar pagamentos), e as 4 versões duplicadas de TinyMCE/jQuery/fullcalendar.

> **Nota sobre Teleconsulta e Bate-papo:** ambos entram, mas **não serão herdados do STG** (o STG tem `teleconsulta.php` e `batepapo.php` rudimentares). Serão construídos do zero, modernos.

### 3.3 Dados que o Sistema DEZ herda do STG (já extraídos)
O catálogo reconciliado contém 998 procedimentos (51 classificados como `ATIVO` com correspondência segura), 40 convênios com ID legado (17 ativos), 87 profissionais (29 ativos) e 2 unidades. As classificações brutas contêm linhas adicionais ou ambíguas; elas são preservadas como pendências, sem criação de IDs artificiais. Para preços foram processadas 2.032 linhas de Particular e 317 de SOU DEZ, resultando em 2.303 preços válidos, 10 candidatos em quarentena e 36 duplicatas idênticas colapsadas. Fontes: `dez_api_consulta.json`, `dez_api_exame.json`, `dez_precos_particular.csv`, `dez_precos_soudez71.csv` e arquivos de classificação em `_extracoes_stg/`.

---

## 4. Arquitetura e stack recomendada

**Modelo:** API REST/GraphQL no backend + 5 frontends consumindo a mesma API (web equipe, agendamento online, painel TV, app paciente, e clientes Hermes).

| Camada | Recomendação | Alternativa | Justificativa |
|---|---|---|---|
| **Backend** | **Laravel 13** (PHP 8.4) — confirmado | — | PHP maduro, filas/queues nativas, migra lógica SQL do STG com familiaridade |
| **Banco** | **PostgreSQL** (manter) | — | O STG já usa Postgres; reaproveitar a modelagem, não o schema bagunçado |
| **Frontend web (equipe)** | **React + Vite + TypeScript** — confirmado | — | Monorepo compartilhado já implementado para equipe, agendamento, painel e paciente |
| **Agendamento online (site)** | Widget/API pública no site existente | — | Integração simples |
| **Painel TV** | Página web (TV com navegador/Chromecast) | — | Substitui painel desktop |
| **App paciente** | PWA (mesma API) | — | Substitui `appAPI`/`appPacienteAPI` |
| **Fila/worker** | Laravel Queues (Redis) | — | WhatsApp/email/lembretes, conciliação |
| **Infra** | Docker + VPS (ou PaaS) | — | Isolamento e backup simples |

**Princípios técnicos obrigatórios:** git desde o 1º commit; migrations versionadas; testes nos pontos críticos (caixa, antifraude, faturamento); secrets em variáveis de ambiente; auditoria imutável como tabela transversal.

---

## 5. Modelo de domínio (núcleo)

```
Unidade/Empresa 1 ── N  Salas / Setores
   │
   ├── N Profissionais (médico/enfermeiro/digitador/revisor/recepcionista)
   │        └── especialidades, CRM, agenda, **procedimentos atendidos**, % comissão (repasse)
   │             └── unidade/modalidade/convênio/oferta quando aplicável
   ├── N Convênios — dois tipos distintos (Particular = convênio padrão da Clínica DEZ):
   │        ├── B2B/Empresa (tabela de preços própria + fatura fim do mês + boleto)
   │        ├── Particular / SOU DEZ / Parcerias
   │        └── Plano de saúde (TISS) — MODELADO, PORÉM INATIVO
   ├── N Procedimentos (consulta/exame) ── grupos/classes, TUSS, preço por convênio
   ├── N Pacientes (LGPD: dados mínimos, consentimento)
   └── N Operadores (usuários) ── perfil/permissões ── trilha de auditoria

Atendimento (ficha) ── 1 Paciente
   ├── origem (agendado / encaixe / walk-in / Doctoralia / teleconsulta)
   ├── procedimento(s) + convênio + profissional + sala
   ├── status (chegada → cadastrado na recepção → pago no caixa → em atendimento → realizado → entregue)
   ├── pagamento confirmado antes do atendimento (ou autorização B2B adimplente)  ← antifraude
   └── laudo(s) / receituário / atestado

Movimentação financeira ── atendimento (pagamento precede o atendimento)
   ├── caixa diário (turno, operador) ── fechamento consolidado (7–15 dias)
   │      └── formas: espécie, débito, crédito, PIX ── % extra customizável (débito/crédito)
   ├── repasse médico (comissão customizável por profissional) ── confirmação auditável do médico
   ├── faturas B2B (fim do mês) + boletos Inter ── contas a receber (único financeiro além do caixa)
   └── conciliação Stone/Inter ── integração opcional (últimas tarefas)
```

> **Regra de ouro (dono, 30/08/2026):** a rota do paciente é fixa — `Chegada → Cadastro complementar (recepção) → Pagamento (caixa) → Atendimento → Resultado de exames/retornos`. O **caixa só é ativado após a passagem pela recepção** (cadastro da ficha) e o **atendimento clínico só inicia com pagamento confirmado** (ou autorização B2B adimplente — sem parada no caixa, §6.8). Todo atendimento nasce do cadastro e toda cobrança nasce do caixa; nada fica órfão. É disso que nasce a segurança do negócio (seção 7).

---

## 6. Módulos — especificação

### 6.1 Cadastros base
**Objetivo:** dados mestres com tela simples e busca rápida (full-text + filtros — resolver o problema que gerou o `autocomplete.php` de 11,5 mil linhas).

- **Pacientes:** nome, CPF, nascimento, contato, responsável, convênio padrão; LGPD (consentimento, portabilidade, exclusão). Repositório de documentos com controle de acesso (não PDFs soltos no webroot).
- **Profissionais:** especialidade(s), CRM, agenda vinculada, **consultas e exames que realiza (vínculo N:M com procedimentos)**, disponibilidade online, modalidade (presencial/teleconsulta quando aplicável), **percentual de comissão (repasse), customizável por profissional**, perfil de acesso (médico × digitador × revisor). O cadastro deve permitir pesquisar e marcar em separado consultas e exames, ativar/desativar vínculos e indicar unidade/convênio quando houver diferença.
- **Convênios:** **Particular = convênio padrão da Clínica DEZ**; SOU DEZ, **B2B/empresa (cada empresa fechada vira um convênio diferente, com tabela de preços própria e fatura fechada no fim do mês separadamente)**, parcerias; planos de saúde modelados mas inativos. Tabelas de preços **múltiplas, uma por convênio** (mesmo conceito do STG).
- **Procedimentos:** catálogo (998), grupo/classe, TUSS, valor particular e por convênio, flag ativo/legado.
- **Salas/Setores:** para agenda, fila e painel.

**Aproveitar:** catálogos inteiros (`C:\Projects\STG\_extracoes_stg\`, fora do repo). **Melhorar:** cadastro em lote, importação CSV, deduplicação, busca instantânea.

### 6.2 Agendamento (interno + online + Doctoralia)
**Objetivo:** agenda única que alimenta recepção, site **e Doctoralia**, sem overbooking.

**Interno (recepção):**
- Agenda por **médico/especialidade/tipo/sala**, com **horários fixos** e **criados/especiais**, **feriados** e **bloqueios** (STG já tem: `gravarhorariofixo`, `listarferiados`, `virada`, `encaixe`).
- O cadastro do profissional terá a seção **"Consultas e exames atendidos"**. A recepção/admin poderá buscar no catálogo e marcar os procedimentos que aquele profissional realiza, separados por tipo (consulta/exame), com ativo/inativo, unidade e disponibilidade online.
- A disponibilidade não será inferida pelo nome, especialidade ou texto do site: o motor seguirá `profissional_procedimento ativo → agenda ativa → horários livres`.
- **Encaixe** com gestão de ausência e lista de espera.
- Calendário dia/semana drag-and-drop com cores por status.

**Online (site):**
0. O sistema filtra os profissionais pelo vínculo ativo entre **profissional e procedimento**; só depois cruza com agenda, unidade, convênio/modalidade e horários livres.
1. Cliente escolhe **procedimento/consulta** (preço + convênio) → **profissional elegível** → **horário**.
2. Poucas telas, mobile-first, sem cadastro obrigatório (nome, CPF, nascimento e celular; e-mail opcional).
3. **Direção visual aprovada:** mockup D — concierge do próximo horário, busca, modalidade presencial/tele e transparência sobre horário marcado versus ordem de chegada. Arquivo de referência: `C:\Projects\STG\mockups_agendamento\D_concierge_proximo_horario.html`.
4. **Confirmação via WhatsApp pelo fork do Chat App Crédito (§6.12.1):** mensagem automática no momento do agendamento + **pedido de confirmação 2 dias antes (D-2)** — a ordem de disparos é automática e definida pelo Sistema DEZ em integração com o fork. Sem resposta do cliente → o pendente vira **rotina de ligação no dia seguinte** (módulo Rotinas, §6.17).
5. **Cancelamento/remarcação** pelo próprio cliente.
6. Regras: sem sobreposição; antecedência mínima de 30 min; janela **online de 30 dias** (regra específica de §6.2.1) e **interna de 90 dias**; convênios que exigem guia ficam bloqueados no online; vaga de encaixe separada permanece pendente.

**Doctoralia (novo):**
- **Publicar** profissionais e serviços da clínica no Doctoralia.
- **Receber agendamentos** do Doctoralia direto na agenda do sistema (sincronização de disponibilidade nos dois sentidos).
- Nota: depende da API/parceria do Doctoralia (Docplanner) — **verificar termos e nível de acesso** (decisão em aberto, seção 11).

**Aproveitar do STG:** tipos de agenda, horários, feriados, salas, virada. **Melhorar:** UI moderna, exposição pública via API, confirmação/lembrete automáticos.

### 6.2.1 Escala de consultas e exames — FONTE DA VERDADE da disponibilidade (decisão do dono 17/08/2026)

**Problema real (confirmado com a recepção):** a escala médica **não existe em sistema nenhum** — a Samara monta por semana (papel/Excel/WhatsApp), médicos fixos e não-fixos alteram a agenda. O STG tem grade cadastrada desatualizada. Resultado: qualquer agendamento online baseado em grade fixa projeta horários que **não existem** (ex.: terças caindo no domingo, médicos em dias errados) — risco de cliente agendar e não ter atendimento.

**Decisões do dono (17/08/2026):**
1. **Janela de 30 dias com a mesma escala do dia** — a escala do dia da semana se repete pelos próximos 30 dias (não é semanal). Quando a recepção mudar um dia, vale dali em diante.
2. **Página de edição de escala** (consultas e exames) para a Samara/recepcionista com acesso: ajusta médico, dia, horário, duração, limite. Simples e rápida.
3. **Conflitos com agendamentos já realizados:** ao editar/remover uma faixa que já tem agendamentos, o sistema **não apaga silenciosamente** — notifica a Samara (pendência/aviso) para resolver com o médico ou com o paciente (remarcar/cancelar).

**Solução no Sistema DEZ — escala vira dado estruturado:**

1. **Entidade `escala_medica`** (uma linha por faixa, com vigência):
   `medico_id, data_inicio, data_fim, dia_semana, hora_inicio, hora_fim, duracao_consulta_min, limite_atendimentos_dia, ativo`
   - Exemplos reais (17/08): Caio → ter 12:00–13:40, 5 min, **limite 20/dia**; Felipe → ter 15:00, 10 min (único à tarde); Leonardo → sex 12:30, 10 min (consulta) / **15 min para laringoscopia e nasolaringoscopia** (duração por procedimento: `procedimento.duracao_min` sobrescreve a da escala).
   - Vigência padrão de 30 dias (rolante): ao salvar, a faixa vale para os próximos 30 dias; a recepção ajusta quando o médico muda.
2. **Tela de escala na recepção** (2–3 min): médico, dia, início/fim, duração, limite — ou **importação da planilha** que a Samara já monta (mesma estrutura). Registro de quem alterou e quando (rastreabilidade).
3. **Regra de ouro do motor de disponibilidade:** só oferece horários cobertos pela escala **vigente** (`hoje <= data_fim`). **Sem escala vigente → zero vagas online** (mensagem: "confirme com a recepção"). Nada de projetar a partir de grade fixa.
4. **Limite diário respeitado na oferta e na escrita**: o motor desconta agendamentos existentes do dia (Caio: 20/dia, nunca mais).
5. **Edição com tratamento de conflitos:** alterar/remover faixa com agendamentos → aviso com a lista de afetados e pendência para a Samara (resolver com médico/paciente). Bloqueios manuais (ausência, feriado, troca de última hora) continuam valendo.
6. **Janela online = 30 dias** (escala vigente cobre 30 dias).

**No agendamento provisório (Worker `dez-agendamento` + `/agendar-beta/`), já aplicado em 17/08 (v5):** janela de 30 dias com a mesma escala do dia, duração por procedimento (`TEMPO_PROC`: laringoscopia/nasolaringoscopia 15 min) e grade corrigida com os dados confirmados pela recepção (Caio, Felipe, Leonardo). Rotina manual até o Sistema DEZ: receber a escala da Samara → atualizar `GRADES` no Worker (docs/worker/dez-agendamento-worker.js).

### 6.2.2 Regras de agendamento confirmadas com a recepção (17/08/2026)

Regras de negócio que o motor de agendamento (novo e provisório) deve respeitar:

1. **Retornos limitados:** no máximo **4 retornos por agenda (médico/dia)**, a não ser que não haja pacientes novos suficientes para preencher (regra da Aline).
2. **Ordem de chegada (consultas) e sem agendamento (exames técnicos):** consultas em geral são **ordem de chegada em cascata** — o site oferece blocos de 3 horários do início do turno; o bloco seguinte só abre quando os anteriores ocupam (médico atende por chegada e não espera paciente do fim do turno; decisão do dono 18/08). Raio X (ter/qua/qui, 08:00–15:00), ECG (seg–sex, 08:00–15:00), laringoscopia/nasolaringoscopia (Leonardo faz) e o médico do áudio 15.03.01 ("Doutor Masaritao" — a identificar) são **ordem de chegada sem horário marcado — nunca gerar slots**; o card mostra "Ordem de chegada" e o paciente usa a fila.
   **Complemento (dono, 30/08/2026):** médicos e exames podem operar em **escala fixa (horário marcado) ou ordem de chegada** — ambos os modos valem para consultas e exames. Para ordem de chegada existe **página de lista de espera do dia**, ordenada pela ordem de atendimento, que respeita as **regras de prioridade legais do Brasil** (Lei 10.048/2000 e afins: PCD, gestante, lactante, criança de colo, obeso — Lei 14.154/2021, idoso 60+ com sub-prioridade 80+); a prioridade reordena a fila sem apagar a chegada original (auditoria de toda reordenação).
3. **Exames sem médico com agenda própria:** mamografia (sex 08:00–15:00, 40 min) — o agendamento não exige profissional (escrita com `cod_profissional` vazio; validar com a recepção). A lista completa de exames sem médico chega do dono.
4. **Exames que a clínica NÃO realiza ficam fora do catálogo online** (13 já identificados em 17/08: escleroterapia, compressão/magnificação mamária, prevenção gineco isolada, US gemelar, fisioterapia, retorno psiquiatra, RNM cardíaca/próstata) — a recepção manda a lista completa.
5. **Capacidade por agenda:** limites explícitos (Caio 20/dia, Júlio César 20/dia, Fabiana sáb 7 testes, Larissa 5 consultas + 7 testes, pneumo 8/dia, Pablo/Felipe sem limite declarado) — nunca ofertar além do que o médico atende.

### 6.3 Recepção & fila de atendimento
- **Check-in** rápido (nome/CPF), emissão de **senha**.
- **Fila por sala/setor** com prioridade, chamada manual/automática.
- **Status:** chegada → cadastrado → pago (caixa) → em atendimento → realizado → entregue (resultado/retorno).
- **Atendimento no balcão:** recepção faz o **cadastro complementar** (dados, convênio, procedimentos) e **ativa a ficha para o caixa**; a **cobrança** (valores + forma de pagamento) é feita pelo perfil **Caixa** — base do antifraude.
- **Fluxo obrigatório (dono, 30/08/2026):** chegada do cliente → cadastro complementar na recepção → caixa para pagamento → atendimento → resultado de exames/retornos (caso haja). O **caixa só é ativado após a recepção** (nunca recebe ficha sem cadastro) e o **atendimento clínico só inicia com pagamento confirmado** — espécie, débito, crédito ou PIX (B2B: autorização, §6.8).
- **Histórico do paciente** na tela (últimos atendimentos, pendências, laudos).
- **Bate-papo interno** (6.12) para avisar o médico que o paciente chegou / está na sala.

**Aproveitar:** `listarsalasespera`, `listaresperasenhas`, `chamarProximaSenha`, `finalizarChamadaSenha`, `enviarsalaesperamedico*`. **Melhorar:** painel unificado, chamadas integradas à TV, menos cliques.

### 6.4 Laudos e exames — **preservar a rotina médica**

**Objetivo:** fluxo de laudos **funcionalmente idêntico** ao atual; muda interface, desempenho, salvamento e segurança. Médicos, digitadores e revisores não devem ser obrigados a mudar a sequência de trabalho.

**Por que preservar:** a equipe já conhece os modelos, campos, nomenclatura e sequência do STG; alterar o comportamento clínico durante a migração aumenta risco de erro e queda de produtividade. Preservamos o que é usado e clinicamente aceito — não os bugs ou falhas de segurança do legado.

**Tipos (cardio, já no STG):** Ecocardiograma e Eco stress, Holter, MAPA, Teste ergométrico, Tilt test, Doppler (carótidas/vascular), ECG, laudo clínico e, se ainda usado, laudo laboratorial.

**Pontos obrigatórios:**
- preservar a rotina médica 1:1 — médico dita → recepcionista/digitadora digita → imprime → médico revisa visualmente e assina no papel;
- não criar revisão digital obrigatória nem bloquear a impressão;
- importar todos os templates/linhas por médico e procedimento, incluindo `01PADRAO`, pré-atendimento e triagem;
- validar tipos estruturados, cálculos, impressão/PDF, assinatura manual, reimpressão, anexos, adendo, histórico e portal;
- manter versões/auditoria no Sistema DEZ, sem replicar a falha de validação de revisor identificada no STG;
- **retenção e layout:** laudos armazenados/auditáveis por **5 anos** (decisão do dono, revisar juridicamente antes do go-live) e interface com **layout moderno** preservando a rotina (o prontuário eletrônico retém 20 anos — §6.18);
- fonte detalhada e checklist: `C:\Projects\STG\VALIDACAO_LAUDOS.md`.

**Fluxo a preservar (replicar a rotina real da DEZ):**
```
Solicitação → realização/captura do exame
   → MÉDICO DITA para recepcionista/digitadora
   → DIGITAÇÃO
   → IMPRESSÃO
   → REVISÃO VISUAL no impresso + ASSINATURA MANUAL do médico
   → entrega física ou publicação segura no portal
```
- A revisão digital não é uma etapa obrigatória nem deve bloquear a impressão.
- **Modelos de laudo e linhas por médico** (`buscarModelosLaudo`, `buscarModelosLinha`, `modelomedico`, `salvarModeloMedico`) — **importar todos os templates existentes**. Primeira extração autenticada realizada em 16/08/2026: 72 modelos de laudo ativos + 9 linhas ativas preservados em `C:\Projects\STG\_extracoes_laudos\`; a classificação por uso 12/24m será posterior e servirá para ordenar/limpar, não para bloquear a importação.
- **Editor de texto rico** com atalhos, autocompletar, variáveis (nome, idade, medidas) e **blocos reutilizáveis**.
- **Rascunho e reimpressão:** salvar rascunho, imprimir, reimprimir e registrar eventos sem burocratizar o fluxo.
- **Resultado online (§6.19):** todos os **exames próprios** publicados em portal do site com **acesso temporário (login/senha)**; a recepção imprime folha com QR Code/instruções e credencial. Exames laboratoriais do **DB**: resultados via integração ou acesso ao portal deles.
- **Histórico de laudos** por paciente (`carregarlaudohistorico`, `todoslaudo`).
- **Auditoria:** quem digitou, imprimiu, reimprimiu, marcou assinatura manual/liberou e publicou resultado; revisão visual em papel não vira uma etapa digital artificial.
- **Assinatura:** assinatura manual no impresso é o padrão do MVP; assinatura digital fica como evolução opcional, sem bloquear a rotina.
- **Prescrição via Memed** no mesmo fluxo.
- **Faturamento** do laudo já engatado no atendimento (quando B2B/particular).

**Melhorar (sem mudar rotina):** carregamento instantâneo, salvamento automático discreto, atalhos, busca integrada, impressão fiel e portal seguro. **Não mudar:** sequência médico dita → recepção digita → imprime → médico assina.

### 6.5 Caixa & fechamento — **fiel ao método real** (resto do financeiro fora do escopo)
**Contexto do dono (como funciona hoje):**
- Relatório "conferência caixa" mostra: **faturado no dia**, **recebido em cartão (Stone)**, **recebido em PIX (Inter)**, **recebido em dinheiro**; nas despesas: **repasses médicos** (dinheiro/PIX) e **demais despesas em dinheiro**.
- A partir disso calcula-se **quanto deve ter em dinheiro**, e confere-se com **extrato Stone** e **extrato Inter**.
- Fechamento é feito em **intervalos de 7–15 dias**, fechando **todos os caixas diários de uma vez** (dá trabalho).
- Entre fechamentos, a funcionária do caixa **guarda um relatório diário + o dinheiro do dia no cofre**.
- Repasses médicos têm **relatório em papel com visto/assinatura do médico**, para garantir o recebimento de ambos os lados (evitar "disse-me-disse").

**O que o Sistema DEZ entrega (simplificando o que dá trabalho):**
- **Caixa diário** aberto por turno/operador, com **fundo de troco**.
- Registro automático de **recebimentos por forma** (dinheiro, PIX, débito, crédito) e **despesas** (repasses médicos, demais despesas).
- **Relatório diário de caixa** gerado **automaticamente** ao fim do dia (substitui o papel guardado no cofre), com o **valor de dinheiro a guardar** já calculado.
- **Fechamento consolidado** (7–15 dias): fechar **vários caixas diários em lote**, um a um, sem retrabalho.
- **Formas de pagamento (dono, 30/08/2026):** **cartão de crédito, débito, PIX e espécie**, com **percentual extra customizável** por forma de pagamento (débito e crédito) definido no cadastro.
- **Conciliação:** o sistema deixa pronto o **valor esperado por forma** (cartão, PIX, dinheiro) para conferir com os extratos. **Integrações Stone (cartão) e Inter (PIX) são opcionais e ficam entre as últimas tarefas** (§12); antes disso, conferência manual/importação de extrato como opção intermediária.
- **Sangria** com dupla confirmação e comprovante (STG já tem `novasangria`/`comprovantesangria`).
- **Quebra de caixa** (diferença) exige **justificativa**; acima de limite, **aprovação de supervisor**; fechamento **assinado e imutável**.
- **Dupla assinatura** para cancelamentos/estornos/descontos acima de valor X.

### 6.6 Repasses médicos (comissão) — **com confirmação auditável do médico**
**Objetivo:** pagar o médico corretamente e **com prova de recebimento dos dois lados**, eliminando o papel.

- **Comissão customizável por profissional** (dono, 30/08/2026): % ou valor fixo **definido por médico** (por procedimento quando houver diferença — o STG já tem "% médico" no procedimento).
- **Relatório de produção médica** (mesmo conceito do STG, **layout moderno**): lista de atendimentos/serviços realizados, valores e total do que o médico **irá receber no dia**.
- **Confirmação auditável do médico no próprio relatório**: o médico valida o que vai receber e só então a clínica **segue para o pagamento** (substitui o visto em papel — prova dos dois lados).
- **Pagamento manual** (dinheiro/PIX) com registro do meio e **baixa no caixa** como despesa. Lançamento automático no Inter = **fora do escopo** (futuro, não é prioridade).
- **Histórico** de repasses por médico, para conferência e acerto de contas.

### 6.7 Medicina do Trabalho (manter)
- **Empresas cliente (B2B)**, com **setores**, **funções/cargos** e **riscos ocupacionais** (STG modela: `pesquisarempresa`, `pesquisarfuncoes`, `pesquisarrisco`, `gravarsetor`).
- **ASO**: admissional, periódico, demissional, mudança de função, retorno ao trabalho.
- **Agendamento em lote** por empresa + **controle de vencimento** dos ASOs periódicos (alerta de renovação).
- **Emissão de ASO** com modelos próprios e vínculo com exames.
- **Relatório por empresa** (atestados, pendências, vencimentos).

**Aproveitar:** modelagem empresa→função→risco e fluxos ASO. **Melhorar:** lote, alertas, emissão digital.

### 6.8 Faturamento B2B (empresas) — **não planos de saúde**
**Contexto do dono:** hoje **não** se fatura plano de saúde/convênio médico. O que existe são **empresas clientes** para as quais se enviam **faturas** com: listagem de serviços, quem tomou, data, preços e total. Cada empresa tem **tabela de preços própria**. Junto com a fatura vão **boletos do Inter**; a empresa confere e paga via boleto.

**O que o Sistema DEZ entrega:**
- **Tabela de preços por empresa** — cada empresa fechada vira um **convênio diferente**, com preços próprios e **fatura fechada no fim do mês separadamente** (dono, 30/08/2026).
- **Fluxo de cobrança (dono, 30/08/2026):** usuário valida a fatura da empresa → **confirma a emissão do boleto (Inter)** → encaminha à empresa → **recebimento integrado** (baixa automática) facilitando a cobrança.
- **Geração de fatura** (PDF) com listagem de atendimentos/serviços, datas, valores e total — a partir dos atendimentos já registrados (sem redigitar).
- **Filtro de adimplência:** paciente B2B entra por **autorização** (sem parada no caixa — fatura no fim do mês), mas o sistema sinaliza/bloqueia (configurável) atendimento de **empresa inadimplente** (fatura em aberto vencida).
- **Controle de pagamento**: baixa do boleto/fatura → contas a receber.
- **Histórico por empresa** (faturas emitidas, em aberto, pagas).

**Planos de saúde (TISS/guias):** modelados no sistema (entidade "plano de saúde" + guias), porém **INATIVOS** por ora — ativáveis no futuro sem refazer o sistema.

### 6.9 Teleconsulta — **novo, estado da arte**
**Objetivo:** consulta por vídeo integrada ao fluxo de atendimento, sem depender do módulo rudimentar do STG.

- **Vídeo WebRTC** (sem plugin), com qualidade e estabilidade; sala virtual criada a partir do agendamento.
- **Fluxo integrado**: o atendimento de teleconsulta entra na mesma agenda, no mesmo prontuário e no mesmo laudo/prescrição (Memed).
- **Sala de espera virtual** + notificação via WhatsApp com o link.
- **Prontuário na tela da consulta** (histórico, exames, laudos).
- **Gravação opcional** (com consentimento LGPD) e **auditoria**.
- **Decisão em aberto:** infra própria (WebRTC self-hosted, ex. LiveKit/Janus) vs. plataforma de vídeo (ex. Twilio Video/Whereby) — seção 11.

### 6.10 Painel de chamadas (TV)
- **Painel web** numa TV (navegador/Chromecast/Raspberry), sem software desktop.
- Mostra **"Senha A012 → Sala 3 / Dr. Fulano"**, fila de próximos, avisos, logotipo.
- **Chamada** pela recepção (botão), **automática** pela fila ou **pelo médico logado** — o médico chama seus pacientes (dono, 30/08/2026); **áudio (TTS)** opcional.
- **Setores configuráveis** (STG tem `setores_painel`, `vincular_setores`, `gravarconfiguracaobotao`).
- **Histórico de chamadas** (quem, quando, qual senha) → alimenta auditoria.

### 6.11 Marketing
- **Posts/conteúdo** do aplicativo/portal.
- **Listas de pacientes** para campanhas (com consentimento LGPD).
- **Exportação de dados** (produção, convênios, procedimentos) via API para o agente de marketing.

### 6.12 Bate-papo interno — **novo, básico**
**Objetivo:** comunicação rápida **médico ↔ recepção** (e demais papéis), sem depender de WhatsApp pessoal.

- Conversas 1:1 e grupos por setor (recepção, salas, laboratório).
- Mensagens **vinculáveis a paciente/atendimento** (ex.: "paciente X chegou, sala 3").
- Notificações (web + push opcional).
- Histórico e busca; sem recursos pesados (nada de canais corporativos complexos).

**Fora do escopo:** conversa com o **paciente** não é feita pelo Bate-papo — o canal oficial com o paciente é o **WhatsApp, via fork do Chat App Crédito** (§6.12.1), com o qual o Sistema DEZ se integra via API.

### 6.12.1 Comunicação com o paciente — WhatsApp (fork do Chat App Crédito) — **decisão do dono 30/08/2026**

**O canal WhatsApp do Sistema DEZ é o fork do projeto "Chat App Crédito"**, em andamento em `C:\Projects\Chat App Crédito` (backend + frontend Svelte, base Baileys). O Sistema DEZ **não constrói despachante próprio de WhatsApp** (Twilio/Cloud API como canal direto descartados); consome e integra o fork **via API**.

- **Disparos automáticos definidos pelo Sistema DEZ** (regras + fila), executados pelo fork: confirmação no momento do agendamento, **pedido de confirmação D-2**, avisos de orçamento, lembretes.
- **Recebimento das respostas** do cliente (sim/não de confirmações e orçamentos) de volta ao Sistema DEZ, alimentando os módulos de agenda, orçamentos e rotinas.
- **Última prioridade (futuro):** **agendamento automático via WhatsApp**, com a opção do cliente escolher **falar com atendente**.

### 6.13 Relatórios & dashboards ("dados na mão")
- **KPIs:** produção por médico, receita por período/convênio/procedimento, taxa de no-show, ticket médio, mix de formas de pagamento, quebra de caixa, sangrias, repasses.
- **Financeiro (dentro do escopo):** relatórios de caixa/fechamento e **faturas B2B em aberto**. DRE, fluxo de caixa e contas a pagar/receber gerais **ficam fora do escopo** (dono, 30/08/2026 — além do fechamento de caixa, a parte financeira não entra ainda, §12).
- **Operacional:** fila, tempo de atendimento, agendados vs realizados.
- **Exportação** CSV/Excel + **agendamento** de relatórios (e-mail/WhatsApp).
- **Relatórios do STG a aproveitar:** o dono **selecionará depois** quais quer trazer — fica **pendente** a lista (seção 11).

### 6.14 API de integração (agentes Hermes)
**Objetivo:** agentes Hermes usam o sistema como **fonte de informação e de edição** de dados, de forma segura.

- **API REST autenticada** (token por agente, com escopos — ex.: leitura de agenda, escrita de agendamento, leitura financeira).
- **Read + write** (não só leitura como o `fidelidade/api` do STG): os agentes criam/atualizam registros **através de endpoints com permissão**, não tocando o banco direto.
- **Auditoria**: toda ação de agente é registrada (qual agente, qual endpoint, o quê mudou).
- **Padrão**: considerar também **MCP** (Model Context Protocol) como camada opcional, se os agentes Hermes suportarem — senão REST/JSON é o caminho mais eficiente.
- **Desenho**: DTOs estáveis e versionados (`/v1/...`) para não quebrar os agentes a cada mudança.

### 6.15 Controle de ponto — **NOVO (decisão do dono 30/08/2026)**
**Objetivo:** apontamento de trabalho baseado no **login diário** dos usuários, com relatório para gestão.

- **Marcação = login:** cada login no dia registra a entrada do usuário. **Logout é mais complicado** — em vez disso, existe um **horário de saída configurável** (definido pelo Admin) que fecha o dia do usuário.
- **Relogar é permitido:** se o usuário quiser voltar/relogar após a saída (ou relogar no dia), nada é bloqueado — mas o evento fica **avisado/destacado no relatório de ponto**.
- **Relatório de ponto:** acesso exclusivo de **Admin e Supervisor** (§7.5) — entradas, saídas, relogins, por usuário e período; exportação (CSV/PDF).
- **Dados auditáveis** (append-only), prontos para cruzar com folha de pagamento futura.

### 6.16 Orçamentos por prescrição — **NOVO (decisão do dono 30/08/2026)**
**Objetivo:** transformar **prescrições médicas** em **orçamento**, com validação do atendente e **retorno obrigatório** do cliente.

- **Entrada da prescrição:** foto/PDF enviada pelo cliente **via WhatsApp (fork, §6.12.1)** ou **upload na recepção** — a prescrição fica anexada ao orçamento (rastreabilidade).
- **Listagem do que o médico pediu:** o sistema apresenta a lista de itens solicitados (checklist montada pelo atendente sobre a prescrição anexada); **leitura automática (OCR/IA) = tarefa opcional futura** (§12).
- **Elaboração do orçamento:** itens → catálogo de procedimentos (tabela do convênio do cliente) → orçamento gerado **sob validação do atendente** (quem valida fica registrado).
- **Envio e retorno:** orçamento enviado ao cliente (WhatsApp via fork e/ou ligação nossa).
- **Resolução obrigatória:** todo orçamento enviado **precisa ser resolvido — sim ou não**. Sem resposta, entra **rotina de retorno no dia seguinte** (§6.17) para mensagem/ligação. O orçamento nunca encerra em aberto: status final sempre `aceito`, `recusado` ou `perdido/sem contato` (registrado pelo atendente, auditável).
- **Conversão:** orçamento aceito gera agendamento/atendimento vinculado (ponta a ponta).

### 6.17 Rotinas diárias e mensais — **NOVO (decisão do dono 30/08/2026)**
**Objetivo:** lista de trabalho **por usuário** gerada automaticamente pelo Sistema DEZ; o usuário **ticka** cada item feito, **com crítica/observação**, criando histórico auditável.

**Rotinas diárias geradas automaticamente:**
1. **Confirmações de consultas (segunda camada):** pacientes que **não responderam** ao pedido de confirmação D-2 via WhatsApp (§6.12.1) viram **ligação para o atendente no dia seguinte**.
2. **Busca de retorno sobre orçamentos enviados (dia seguinte):** orçamentos sem resposta (§6.16) → mensagem/ligação para obter o **sim ou não**.
3. **Ligações para clientes aniversariantes de consulta:** quem completou X tempo da consulta (janela configurável, ex.: 6 meses/1 ano) → ligação de retorno/care.
4. **Outras rotinas configuráveis** que a gestão entender importantes (ex.: ASO a vencer, faturas B2B a vencer).

**Dinâmica:**
- Página única de rotinas: lista do dia por usuário, item a item; tick com crítica (observação registrada quando necessário, ex.: "sem contato, tentar amanhã").
- **Rotinas mensais (financeiro):** usuários com demandas financeiras recebem rotinas mensais **na mesma página e dinâmica** (ex.: conferir faturas do mês, disparar boletos — §6.8).

### 6.18 Prontuário eletrônico — **NOVO, layout moderno (decisão do dono 30/08/2026)**
**Objetivo:** prontuário que **facilite o trabalho médico** — usa os **conceitos do STG** (histórico, atendimentos, laudos, prescrições) com interface nova e moderna. O mesmo vale para laudos (§6.4).

- **Layout moderno:** visão do paciente com histórico cronológico, atendimentos, alertas/alergias, exames, laudos e prescrições (Memed) — tudo em 1–2 cliques durante a consulta.
- **Auditável:** toda leitura/edição registrada (operador, data/hora, o quê) em **auditoria imutável (append-only)**; nada se apaga, só se anula (§2.2).
- **Retenção:** prontuário armazenado por **20 anos** (CFM 1.821/2007 — recomendação incorporada); **laudos: 5 anos** (decisão do dono, revisar juridicamente antes do go-live).
- **Auxílio de diagnóstico com IA** (com base nos dados do paciente): **opcional, última tarefa** (§12) — nunca bloqueia ou substitui o trabalho médico.

### 6.19 Portal de resultados — acesso temporário — **decisão do dono 30/08/2026**
**Objetivo:** o cliente visualiza **resultados de exames** em ambiente do **site**, com **acesso temporário (login e senha)**.

- **Todos os exames próprios** (não terceirizados): ao liberar o resultado, o sistema gera **credencial temporária** (login/senha, prazo configurável) e a recepção imprime **folha com QR Code/instruções** (mesmo desenho do `laudoportal_model` do STG).
- **Exames laboratoriais (DB — Diagnósticos do Brasil):** resultados via **integração** ou **acesso ao portal deles** — o cliente sempre sai com o resultado (impressão quando preferir).
- **Segurança:** credencial com expiração, acesso auditado, documentos fora do webroot com controle de acesso (§7.6, LGPD).

### 6.20 Consulta de preços (tabela de preços) — **NOVO (decisão do dono 31/08/2026)**
**Objetivo:** recepção consulta o preço de **consultas e procedimentos** no balcão em segundos — o paciente pergunta "quanto custa?" e a resposta sai da tela, sem adivinhar nem perguntar ao caixa. Essencial no dia-a-dia do atendimento; é a mesma fonte que alimenta orçamentos (§6.16).

**Busca (barra única):**
- Autocomplete **a partir de 3 caracteres digitados** (sem Enter): busca simultânea por **código TUSS/código interno** e por **nome** — mesmo comportamento do autocomplete do STG (`autocomplete.php`).
- Resultado mostra: código, nome, tipo, grupo e preço por convênio.

**Agrupamento por tipo:**
- Abas/seções **Consultas | Exames** (decisão do dono 31/08), com **sub-grupos** visíveis (ex.: LABORATORIAL, FISIOTERAPIA, CARDIOLOGIA) — usa `procedures.type` + `procedures.group_name`.
- Mesma base já extraída do STG: `dez_api_consulta.json` (grupo CONSULTA) e `dez_api_exame.json` (demais grupos) (§3.3).
- Filtro por **convênio** (Particular = padrão; SOU DEZ; B2B/empresas).

**Preço:**
- Valor por convênio (`service_offerings.price` no Sistema DEZ; `tb_procedimento_convenio.valortotal` no STG); destaque para o convênio do paciente quando conhecido.
- Exibir o valor por forma de pagamento quando houver % extra configurado (§6.5).
- **Somente leitura:** a consulta não altera preço — mudança de valor é ação de cadastro (Admin), registrada (§7.3 "Preço travado").

**Permissão (dono, 31/08):** **Recepção vê preços de tabela** — preço de tabela é público ao paciente (mesma informação do site). O sigilo do §7.5 ("Recepção não vê valores") refere-se a valores de **caixa/faturamento**, não à tabela.

**Referência STG:** `ambulatorio/autocomplete.php` → `procedimentovalor()` / `exametemp_model::listarautocompleteprocedimentosvalor()` (busca com valor), `procedimentovalororcamento()` (valor por convênio); tabelas `tb_procedimento_convenio` (valortotal), `tb_procedimento_tuss` (código/nome), `tb_ambulatorio_grupo` (tipo CONSULTA/ESPECIALIDADE/CIRURGICO/EXAME).

**Backend (Fase 1):** endpoint autenticado de equipe (ex.: `GET /api/v1/team/prices?q=&tipo=&agreement_id=`) — busca por prefixo de código/nome a partir de 3 caracteres, agrupada por tipo e sub-grupo, preço por convênio. Depende do import do catálogo (Fase 0→1).

**Tela:** página dedicada no app equipe, mobile-friendly (balcão). A busca pode ser **reutilizada** na recepção (montagem de atendimento) e nos orçamentos (§6.16).

---

## 7. Segurança & antifraude (transversal, prioridade máxima)

> Impedir que a recepção deixe de faturar ou desvie atendimento. Resolve-se por **desenho de processo + rastreabilidade + controles de exceção**.

### 7.1 Cadeia fechada
1. Todo paciente gera um **Atendimento** (mesmo walk-in), com número sequencial — nascido na **recepção** (cadastro complementar).
2. O **caixa só é ativado após a recepção**; o **atendimento clínico só inicia com pagamento confirmado** no caixa (espécie, débito, crédito, PIX — com % extra customizável) **ou autorização B2B adimplente** (§6.8).
3. O **pagamento** gera **comprovante/recibo numerado** e, quando aplicável, **fatura/boleto**.
4. **Nenhum atendimento fica em aberto** ao fim do dia sem alerta — nem ficha paga sem atendimento, nem atendimento sem pagamento.

### 7.2 Rastreabilidade
- Toda ação crítica grava **operador, data/hora, estação/IP** em **auditoria imutável** (append-only).
- **Soft-delete** em tudo: exclusão vira "anulação" com motivo.

### 7.3 Controles de exceção
- **Cancelamentos/estornos/descontos** exigem **motivo** + **validação do Gerente** (sem limite de valor — até 100% do serviço).
- **Reabertura de caixa fechado**: bloqueada; exceção só por supervisor, com auditoria.
- **Recibos sem lacuna**: pulo de numeração gera alerta.
- **Preço travado**: recepção não altera valor; desconto é ação registrada, não edição de preço.
- **Repasse médico**: **confirmação auditável** do médico (6.6) fecha o ciclo de pagamento — ninguém paga/recebe sem prova.

### 7.4 Alertas de anomalia
- Atendimento **realizado sem recebimento** após N minutos.
- **Cancelamentos/estornos frequentes** concentrados num operador.
- **Quebra de caixa recorrente** no mesmo operador/turno.
- **Conciliação divergente** (dinheiro esperado vs. guardado no cofre; faturamento vs. boletos).
- **Acesso fora de horário** ou padrão incomum.

### 7.5 Permissões por perfil
| Perfil | O que faz | Vê valores? |
|---|---|---|
| **Sócio/Admin** | Tudo; fecha caixa, aprova despesas, relatórios | Sim (total) |
| **Gerente** | Valida descontos/estornos/cancelamentos; autoriza despesas pré-autorizadas (R$200) | Sim |
| **Recepção** | Check-in, agenda, fila, monta atendimento | **Não** |
| **Caixa** | Recebimentos, pagamentos, sangria, fechamento diário | Sim (do caixa) |
| **Médico** | Atende, laudos, prescrição, repasse | Próprio (repasse) |
| **Digitador** | Digita laudos (transcreve o exame no modelo) | Não |
| **Revisor** | Revisa laudos antes da assinatura | Não |
| **Enfermagem** | Prepara/realiza exames (ECG, Holter, MAPA, ergométrico, sinais vitais) | Não |

- **Caixa ≠ Recepção**: perfis separados — nem todo funcionário é caixa; recepção não vê valores financeiros.
- **Segregação de função**: quem abre o caixa não aprova a quebra; quem fatura não estorna sozinho; desconto/estorno exige validação do Gerente.
- **Perfis cumulativos**: um usuário pode ter **vários perfis** ao mesmo tempo (ex.: Enfermagem + Recepção); as permissões **somam**.
- **Ponto e TV**: o **relatório de ponto** (§6.15) é exclusivo de **Admin e Supervisor**; o médico logado pode **chamar seus pacientes no painel TV** (§6.10) para sua sala.
- **Preços de tabela (31/08):** a **consulta de preços** (§6.20) é visível à **Recepção** no balcão — preço de tabela é público ao paciente (mesma informação do site). O sigilo da coluna "Vê valores?" refere-se a **valores de caixa/faturamento**, não à tabela.

### 7.6 Segurança técnica (lições do STG)
- Secrets em variáveis de ambiente (nunca `postgres/123456` no código).
- `display_errors` desligado; logs centralizados.
- **ORM/query builder parametrizado** (nada de SQL interpolado).
- CSRF, autenticação forte (**2FA para perfis sensíveis**), senhas com hash.
- **LGPD**: dados mínimos, consentimento, criptografia em repouso, uploads fora do webroot com controle de acesso e expurgo.
- **Backup** automático + testes de restauração.

---

## 8. Integrações — decisão

| Integração | Hoje | Decisão | Motivo |
|---|---|---|---|
| **Memed** (prescrição digital) | 132 refs, no laudo | ✅ **MANTER** | Rotina do médico (receita digital + assinatura). |
| **Omie** (contábil/ERP) | ~172 refs | ✅ **MANTER** | Contabilidade integrada; trocar é caro. |
| **VHSys** (NF de serviço) | 38 refs | ✅ **MANTER** | Obrigação fiscal. |
| **Gerencianet** (gateway) | 21 refs | ❌ **DESCARTAR do escopo** (dono, 30/08) | Pagamentos = espécie/débito/crédito/PIX; reavaliar só ao automatizar pagamentos. |
| **WhatsApp (fork Chat App Crédito)** | — (novo) | ✅ **CANAL OFICIAL — integrar via API do fork** | Confirmações D-2, orçamentos, lembretes (§6.12.1); Twilio/Cloud API descartados. |
| **Inter (boletos)** | — (novo) | ✅ **INTEGRAR CEDO — prioridade** | Boletos das faturas B2B com recebimento integrado (§6.8) — "muita vantagem". |
| **Doctoralia** | — (novo) | ✅ **MANTER (integrar)** | Divulgar serviços + agendar no sistema. Verificar API/parceria. |
| **Agentes Hermes** | — (novo) | ✅ **MANTER (API própria)** | Read+write escopado e auditado (6.14). |
| **Stone (cartão/maquininha)** | manual | ⏳ **OPCIONAL — ÚLTIMAS TAREFAS** | Integração p/ conciliação automática do cartão (§6.5); antes disso, conferência manual. |
| **Inter (PIX/extrato)** | manual | ⏳ **OPCIONAL — ÚLTIMAS TAREFAS** | Integração p/ conciliação automática do PIX (§6.5); antes disso, conferência manual. |
| **iugu** (gateway legado) | 1 ref | ❌ **DESCARTAR** | Abandonado. |

**Resolvido em 30/08/2026:** WhatsApp = fork do Chat App Crédito via API; Stone (cartão)/Inter (PIX) = opcionais, últimas tarefas; Gerencianet descartado. **Pendentes (adiadas):** Doctoralia (termos/nível de API). **Descartadas:** Solis e Imuneweb/Alberto Lima (não são usadas); Twilio/WhatsApp Cloud API como canal direto — decisão do dono.

---

## 9. Migração do STG (faseada, sem travar a clínica)

Operar **em paralelo**; cortar o STG só com o Sistema DEZ validado em produção real. **Laudos são a última coisa a migrar** (mais sensível).

1. **Fase 0 — Extração/congelamento** (em andamento): catálogos, preços, convênios, profissionais, **modelos de laudo e linhas**, regras de agenda, ASO. Não perder nada.
2. **Fase 1A — concluída:** catálogo/preços reais, importador STG seguro, autenticação da equipe e **consulta de preços** (§6.20, T21). **Fase 1B — concluída:** CRUDs de cadastros, RBAC para o escopo atual, vínculos profissional–procedimento–convênio–unidade, pendências e auditoria. Primeiro Admin ativo, com senha definida pelo dono.
3. **Fase 2 — Agendamento:** **2A entregue**, com exemplo de vínculo/oferta/escala configurado e validado pelo dono. **2B entregue e testada localmente:** publicação controlada e gestão da reserva pelo paciente (§14.8). Falta homologar a oferta destinada a pacientes reais e preparar a publicação externa; Doctoralia e WhatsApp continuam pendentes.
4. **Fase 3 — Caixa + Repasses + B2B**: pagamento antes do atendimento (formas + % extras), caixa diário, fechamento consolidado, repasse com confirmação auditável do médico, fatura B2B + boleto Inter (adimplência).
5. **Fase 4 — Recepção & fila (lista de espera c/ prioridade) + Painel TV (médico chama) + Bate-papo + Ponto + Rotinas + Orçamentos.**
6. **Fase 5 — Laudos + Prontuário + Portal de resultados** (mais cuidadosa): replicar fluxo/modelos 1:1, prontuário moderno, credenciais temporárias, treinar digitadores/médicos, rodar em paralelo.
7. **Fase 6 — Teleconsulta + Medicina do Trabalho + Marketing/Relatórios + API agentes + últimas tarefas (Stone/Inter, IA no prontuário, agendamento automático WhatsApp, Sou DEZ).**
8. **Corte do STG** após estabilidade, mantendo STG **read-only** para consulta histórica (PDFs de laudos antigos).

---

## 10. Roadmap resumido

| Fase | Entrega | Critério de aceite |
|---|---|---|
| 0 | Extração + seed | 100% dos catálogos/modelos importados |
| 1A — concluída | Catálogo real + acesso da equipe + consulta de preços | Import idempotente e rastreável; Recepção consulta preços reais sem o STG (§6.20) |
| 1B — concluída | CRUDs + RBAC + vínculos operacionais + reconciliação | Equipe mantém cadastros/permissões; vínculo separado da publicação online; alterações auditadas |
| 2 | Agendamento interno + online + escalas/ordem de chegada + Doctoralia + fork WhatsApp | Marcação pelo site/Doctoralia ponta a ponta + confirmação D-2 via fork |
| 3 | Caixa (pagamento-antes) + repasses (confirmação auditável) + fatura B2B/boleto Inter | Fechamento consolidado + confirmação do médico + boleto emitido/recebido |
| 4 | Recepção + lista de espera + TV + chat + ponto + rotinas + orçamentos | Fluxo do paciente completo + rotinas diárias rodando |
| 5 | Laudos + prontuário + portal de resultados | Médicos/digitadores usam sem sentir a troca; cliente acessa resultado no site |
| 6 | Teleconsulta + MT + Marketing + BI + API agentes | Tudo operacional |
| 7 | Corte STG (read-only) | STG desligado, Sistema DEZ 100% operacional |
| 8 | Últimas tarefas (opcionais) | Stone (cartão), Inter (PIX), IA no prontuário, agendamento automático WhatsApp c/ atendente, Sou DEZ |

---

## 11. Decisões em aberto (para o dono)

> **Resolvidas em 16/08:** Solis e Imuneweb/Alberto Lima **descartadas** (não são usadas); Bate-papo = **equipe-only**; **Inter (boletos) = integrar cedo**. **Resolvidas em 30/08:** WhatsApp = fork do Chat App Crédito (#2); portal = resultados via acesso temporário (#8); Sou DEZ = última tarefa (#12); Gerencianet descartado; Stone/Inter = últimas tarefas; prontuário 20 anos/laudos 5 anos. Demais decisões **adiadas para outro dia de trabalho**.

1. ~~**Stack final** (Laravel × NestJS × outro).~~ — **RESOLVIDO 31/08:** **Laravel 13 + React/Vite/TypeScript**, PostgreSQL e Redis em Docker.
2. ~~**WhatsApp:** Twilio × Cloud API~~ — **RESOLVIDO 30/08:** fork do Chat App Crédito via API (§6.12.1).
3. **Doctoralia:** viabilidade/termos da API de agenda e quais serviços divulgar.
4. **Teleconsulta:** infra própria (WebRTC/LiveKit) × plataforma de vídeo; regras (quais consultas elegíveis, preço).
5. **Inter:** escopo da integração (emissão de boleto + baixa automática?) — "integrar cedo" já decidido.
6. **Relatórios do STG a aproveitar:** lista a selecionar (pendente).
7. **Limites de exceção** (valor p/ dupla assinatura; teto de quebra de caixa aceitável).
8. ~~**Portal do paciente:** só agendamento, ou também resultados/laudos?~~ — **RESOLVIDO 30/08:** resultados de exames próprios via acesso temporário no site (§6.19); DB via integração/portal deles.
9. **App do paciente:** PWA × app nativo.
10. **Orçamento/prazo** e **quem desenvolve** (interno, agência, ou eu + subagentes).
11. **Medicina do Trabalho:** módulo do mesmo sistema ou produto separado?
12. ~~**SOU DEZ (fidelidade):** escopo inicial ou fase posterior?~~ — **RESOLVIDO 30/08:** gestão do programa (cadastro, pagamentos, planos ativos, benefícios) como **última tarefa** (§12, T20).
13. **Retorno de consulta:** grátis dentro de X dias ou pago? (benchmark SIM trata no FAQ — definir política).
14. **Pacotes de procedimentos** (ex. "Check-up do Coração" com preço fechado): criar como produto no sistema/site? (insight M3/S5/P2 do benchmark SIM)

---

## 12. Decisões do dono (30/08/2026) — tarefas do Sistema DEZ

Cada decisão virou tarefa de implementação (BE/FE/QA). **ÚLTIMA** = última prioridade, executar apenas com o restante estável. Fases conforme seções 9/10.

| ID | Tarefa | Módulo | Fase |
|---|---|---|---|
| T1 | **Controle de ponto**: login diário = marcação; horário de saída configurável; relogin permitido e sinalizado; relatório exclusivo Admin/Supervisor | 6.15 | 4 |
| T2 | **Integração com o fork do Chat App Crédito**: API de disparo + recebimento de respostas (confirmações, orçamentos) | 6.12.1 | 2 |
| T3 | **Confirmações D-2 automáticas**: ordem de disparos definida pelo Sistema DEZ; sem resposta → rotina de ligação D+1 | 6.2/6.17 | 2 |
| T4 | **Escalas fixas × ordem de chegada** (consultas e exames) + **lista de espera do dia** com prioridade legal | 6.2.2/6.3 | 2 |
| T5 | **Agendamento integrado ao site** (requisito já especificado — manter) | 6.2 | 2 |
| T6 | **Fluxo pagamento antes do atendimento**: chegada → recepção → caixa → atendimento → resultado; caixa só ativa após recepção | 6.3/7.1 | 3 |
| T7 | **Formas de pagamento** (espécie/débito/crédito/PIX) + **% extra customizável** por forma | 6.5 | 3 |
| T8 | **Repasse médico**: comissão customizável por profissional + relatório de produção (layout moderno) + confirmação auditável do médico → pagamento manual | 6.6 | 3 |
| T9 | **Faturamento B2B**: fatura fim do mês por empresa (convênio próprio; Particular = padrão) + boleto Inter (valida → emite → encaminha → baixa integrada) + **filtro de adimplência** | 6.8/6.1 | 3 |
| T10 | **Fechamento de caixa**: entradas cartão/PIX/espécie + despesas de repasse médico (método real, consolidado 7–15 dias) | 6.5 | 3 |
| T11 | **Orçamentos por prescrição**: prescrição (WhatsApp/upload) → lista de itens → orçamento validado pelo atendente → **resolução obrigatória (sim/não)** → retorno D+1 | 6.16 | 4 |
| T12 | **Rotinas diárias e mensais**: confirmações 2ª camada, retorno de orçamentos, aniversariantes de consulta, financeiras mensais; tick com crítica | 6.17 | 4 |
| T13 | **TV**: médico logado chama seus pacientes | 6.10 | 4 |
| T14 | **Prontuário eletrônico**: conceito STG + layout moderno, auditável, 20 anos (laudos 5 anos) | 6.18 | 5 |
| T15 | **Portal de resultados**: acesso temporário (login/senha) para exames próprios + integração/portal DB | 6.19 | 5 |
| T16 | **Integração Stone (cartão)** — opcional | 6.5/8 | ÚLTIMA |
| T17 | **Integração Inter (PIX/extrato)** — opcional | 6.5/8 | ÚLTIMA |
| T18 | **Auxílio de diagnóstico com IA** no prontuário — opcional | 6.18 | ÚLTIMA |
| T19 | **Agendamento automático via WhatsApp** com opção de falar com atendente — opcional | 6.12.1 | ÚLTIMA |
| T20 | **Gestão Sou DEZ** (cadastro, pagamentos, planos ativos, uso de benefícios) — opcional | — | ÚLTIMA |
| T21 | **Consulta de preços (tabela)**: página com busca por código TUSS/nome a partir de 3 caracteres; consultas e exames agrupados por tipo; preço por convênio (Particular padrão); Recepção vê preços de tabela | 6.20 | 1 |

> **Fora do escopo (ainda):** toda a parte financeira além do fechamento de caixa e dos boletos B2B — DRE, fluxo de caixa, contas a pagar/receber gerais e automatização do pagamento de repasses no Inter (futuro, não prioridade).

---

## 13. Anexo A — Mapeamento STG → Sistema DEZ

| Área | Arquivo(s) STG | O que aproveitar |
|---|---|---|
| Laudos cardio | `ambulatorio/laudo.php`, `laudo_model.php`, `laudoportal_model.php` | Fluxo digitação→revisão→assinatura; modelos/linhas por médico; tipos (eco, eco stress, holter, MAPA, ergométrico, tilt, doppler, ECG) |
| Agenda | `agenda_model.php` | Tipos de agenda (modelo/livre), horários fixos/criados, feriados, salas, virada, encaixe |
| Recepção/fila | `exame.php` (listaresperasenhas, chamarProximaSenha, enviarsalaespera*) | Lógica de senha/sala/triagem por tipo |
| Caixa | `cadastros/caixa.php` | Entradas/saídas, sangria, fluxo de caixa, relatórios — reescrito p/ fechamento consolidado |
| Repasse médico | `procedimento` (% médico), relatórios de produção | Cálculo de comissão por serviço |
| Faturamento B2B | `cadastros/convenio`, `contasreceber` | Tabela de preço por empresa, fatura/lista |
| Medicina do Trabalho | `saudeocupacional.php` + `saudeocupacional_model.php` | Empresa→setor→função→risco, fluxo ASO |
| Painel TV | `painel_novo/*`, `exame.php` (painelrecepcao) | Senhas, setores, botões, chamada |
| API de integração | `fidelidade/api.php` | Padrão JSON (ampliado p/ read+write escopado) |
| Dados mestres | `C:\Projects\STG\_extracoes_stg\*` (fora do repo) | Catálogo (998), preços (2.032), 46 convênios, 87 profissionais, NAP |
| Consulta de preços | `ambulatorio/autocomplete` (`procedimentovalor`), `tb_procedimento_convenio`, `tb_ambulatorio_grupo` | Autocomplete por código/nome com valor por convênio; grupos por tipo (§6.20) |
| Prescrição | `laudo.php` (ApiMedicoMemed), `prescriptionAPI.php` | Integração Memed |
| Relatórios | relatórios do STG (a selecionar) | **Pendente — dono escolherá quais** |

---

## 14. Andamento da implementação — ciclos v0.5 a v0.10 (30/08–03/09/2026)

Registro do que foi **implementado e validado** neste ciclo. Não substitui decisões pendentes do §11 — o dono define os rumos a partir daqui.

### 14.1 Backend — `backend/` no repo (antes `sistema-dez-api/` em `C:\Projects\STG\`) (Laravel 13 + PostgreSQL, Docker)
- **Contrato público v1** (`/api/v1/public/*`, documentado em `openapi.yaml`):
  - `GET services` — catálogo filtrável por unidade, convênio, modalidade e tipo (consulta/exame); busca por nome.
  - `GET availability` — disponibilidade por período ≤ 30 dias; modos de oferta:
    - `FIXED_SLOT` — horários fixos com `remaining_capacity`;
    - `ARRIVAL_BLOCK` — blocos de chegada (só o primeiro bloco com capacidade é ofertado);
    - `WALK_IN_INFO` — ordem de chegada, sem slots reserváveis (ex.: ECG, Raio X).
  - `POST appointments` — reserva **idempotente** (`idempotency_key`), revalida disponibilidade em transação com lock, grava `price_snapshot`, devolve `management_token` (para cancelamento/remarcação futuros) e registra auditoria.
- **Migrações:** catálogo (`units`, `agreements` com `is_default`, `procedures` com tipo/grupo/TUSS/duração, `professionals`, `patients`, `service_offerings` = preço por unidade×procedimento×profissional×convênio) e agendamento (`schedule_windows` com vigência, `schedule_exceptions`, `schedule_occurrences`, `appointments`, `queue_entries` com prioridade legal) + `audit_logs` (append-only).
- **LGPD aplicada (§7.6):** CPF armazenado apenas como `cpf_hash` (HMAC-SHA256 + pepper) + últimos 4 dígitos; consentimento de privacidade registrado no agendamento (`privacy_consent_at` + fonte); senhas/tokens com hash.
- **Qualidade:** feature tests dos 3 endpoints (idempotência, capacidade, janela de 30 dias, validações), `openapi.yaml`, Docker (`compose.yaml`), Pint.
- **Estado atual:** banco PostgreSQL preenchido pelo importador STG da Fase 1A; API pública preservada e API autenticada da equipe disponível. Nenhum procedimento importado é publicado automaticamente no agendamento público: publicação continua exigindo oferta, profissional e agenda validados.

### 14.2 Frontend — `frontend/` no repo (antes `sistema-dez-frontend/` em `C:\Projects\STG\`) (monorepo React + Vite + TS, pnpm)
- **Apps:** `equipe` com login real, `/precos`, `/cadastros`, Visão geral e Agenda consumindo a API real. Agenda cria, remarca e cancela na visão Dia; “Ofertas e horários” também controla publicação. `agendamento` consome catálogo/horários reais e oferece gestão da reserva por link privado; não há ofertas publicadas no banco real por decisão de segurança. Recepção/fila, Laudos e Relatórios permanecem mockados ou sem integração operacional; `painel-tv` e o app separado `paciente` continuam mockados. O link de agendamento não é o portal de resultados.
- **Packages:** `ui` (identidade visual + componentes, Storybook), `domain` (regras compartilhadas + testes), `api` (clientes, tipos gerados do `openapi.yaml` — `schema.generated.ts` — e mocks MSW).
- **Qualidade:** sete projetos passaram no typecheck, 2 testes Vitest passaram e os quatro apps geraram builds de produção. O fluxo `/precos` foi validado via Playwright no navegador em desktop e mobile: login/mode de desenvolvimento, busca, TUSS, troca de convênio e ausência de rolagem horizontal.

### 14.3 Mockups aprovados (30/08/2026) — `C:\Projects\STG\mockups_sistema_clinica_dez\` (legado, fora do repo)
`01-agenda-referencia-oficial`, `02-recepcao-command-center`, `03-laudos-workspace`, `04-dashboard-bento`, `05-responsivo-multidevice`.

### 14.4 Fase 1A concluída — catálogo real, preços e acesso da equipe (31/08/2026)

- **Stack confirmada:** Laravel 13 + PHP 8.4, PostgreSQL 17, Redis, React + Vite + TypeScript e Docker.
- **Importador STG:** comando Artisan com `--dry-run` e `--commit`, checksum SHA-256, transação/rollback, execução idempotente, relatório por execução e registro de pendências. Convênios sem ID não recebem ID artificial; Particular legado `49` é o padrão.
- **Resultado real e repetível:** duas execuções confirmadas produziram o mesmo checksum `6aa1c45724afe7b4a9dc6403eeb08b6fc2057be0c363fb04654bc6cfb1507107` e os mesmos totais:
  - fontes: 998 procedimentos, 40 convênios, 87 profissionais, 2.032 linhas Particular e 317 linhas SOU DEZ;
  - catálogo: 998 procedimentos (51 ativos), 40 convênios (17 ativos), 87 profissionais (29 ativos) e 2 unidades;
  - preços: 2.313 armazenados, sendo 2.303 válidos e 10 candidatos em quarentena; 36 linhas idênticas colapsadas;
  - reconciliação: 85 pendências — 6 classificações ambíguas de procedimento, 5 conflitos de preço, 47 profissionais sem classificação, 6 convênios sem ID legado correspondente, 11 classificações de procedimento sem entidade correspondente e 10 classificações de profissional sem entidade correspondente.
- **Quarentena:** `BIOPSIA PEQUENA` (4 candidatos), `US CALCANEO DIREITO` (4) e `US TIREOIDE` (2). Nenhum deles aparece como preço válido por escolha automática.
- **Autenticação e autorização:** Sanctum stateful por cookie/CSRF; login, logout e identidade; perfis cumulativos `ADMIN`, `SUPERVISOR`, `RECEPTION`; permissão `prices.view`; criação de usuário por comando Artisan sem senha padrão no código; rate limit no login e na consulta.
- **Contratos entregues:** `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/me`, `GET /api/v1/team/price-options` e `GET /api/v1/team/prices`. Busca por nome, TUSS ou código legado a partir de três caracteres, sem diferença de caixa/acentos, Particular padrão e ordenação/grupamento determinísticos. OpenAPI atualizado e tipos frontend regenerados.
- **Frontend entregue:** `/precos` para a Recepção, debounce, abas Consultas/Exames, convênio, subgrupos e estados de carregamento/erro/vazio. Identidade e preços usam API real; MSW só inicia quando explicitamente habilitado para desenvolvimento.
- **Validação final:** backend em PostgreSQL real isolado com 20 testes e 96 asserções; dry-run sem persistência; duas importações idempotentes; quarentena/reconciliação verificadas por SQL; smokes HTTP (`/up` 200, CSRF 204, rotas protegidas 401 sem sessão, catálogo público 200); Pint aprovado. Frontend com typecheck dos sete projetos, 2 testes Vitest, quatro builds Vite e validação Playwright desktop/mobile.

### 14.5 Fase 1B concluída — cadastros, RBAC, vínculos e reconciliação (31/08/2026)

- **CRUDs reais e auditados:** unidades, convênios, procedimentos e profissionais com busca, filtros, validação, paginação, criação/edição e desativação sem apagar histórico. Cadastros manuais recebem classificação `MANUAL`; código legado continua opcional e único.
- **Proteções de negócio:** o convênio padrão não pode ser desativado ou perder essa condição sem substituição; o último Admin ativo não pode ser removido; um Admin não pode remover o próprio acesso administrativo; senhas novas têm mínimo de 12 caracteres e nunca possuem valor padrão.
- **RBAC do escopo atual:** permissões separadas para preços, leitura/gestão de catálogo, leitura/gestão de vínculos, equipe, leitura/resolução de importações e auditoria. Recepção lê catálogo e vínculos; Supervisor também altera catálogo, vínculos e pendências; Admin também administra usuários.
- **Vínculos seguros:** nova tabela `catalog_links` relaciona unidade, procedimento, profissional e convênio com unicidade, chaves estrangeiras e índices. Ela é deliberadamente independente de `service_offerings`: criar ou validar vínculo não publica serviço, preço ou agenda online.
- **Reconciliação operacional:** as pendências da última importação podem ser filtradas e marcadas como `RESOLVED`, `IGNORED`, `UNMATCHED` com justificativa ou reabertas; resolução, operador e horário ficam registrados. As 85 pendências reais iniciaram como `OPEN` e foram materializadas pelo comando idempotente `stg:resolve-issues`.
- **Auditoria:** todas as mutações administrativas registram entidade/chave, ação, operador, IP, rota e estados antes/depois. A API oferece consulta cronológica da trilha.
- **Usuários:** CRUD exclusivo do Admin para nome, e-mail, perfil, situação e troca opcional de senha. O comando Artisan sem senha padrão permanece disponível para bootstrap do primeiro Admin.
- **Contratos/API:** 17 novas rotas administrativas sob `/api/v1/team`, protegidas por Sanctum, rate limit e permissão específica. OpenAPI atualizado para v0.3 e `schema.generated.ts` regenerado.
- **Frontend:** nova página `/cadastros`, carregada sob demanda, com abas condicionadas às permissões: Cadastros, Vínculos, Pendências STG, Usuários e Auditoria. MSW continua apenas como modo explícito de desenvolvimento.
- **Banco real após a migração:** 85 pendências materializadas (§14.5.1): 5 `RESOLVED`, 33 `IGNORED` e 47 `UNMATCHED`; 2.307 preços `VALID` e 6 em quarentena; 0 vínculos e 0 usuários. Nenhuma entidade foi apagada, nenhum ID legado artificial foi criado e as chaves estrangeiras foram verificadas sem índice ausente.
- **Validação final:** backend com 27 testes e 140 asserções no PostgreSQL isolado; Pint aprovado; migrações reais aplicadas; saúde/catálogo público em 200 e todas as novas rotas em 401 sem sessão. Frontend com typecheck dos 7 projetos, 3 testes Vitest, 4 builds Vite e validação no navegador em desktop e 390×844, sem overlay, erros/avisos de console ou rolagem horizontal. Dry-run/repetição do resolvedor real confirmados sem alterações na segunda execução.

### 14.5.1 Resolução das 85 pendências (decisão do dono, 31/08/2026)

**Regras gerais aplicadas:**
1. **Conflitos de preço:** sempre manter o **maior valor** — correção posterior no sistema se necessário.
2. **Duplicatas por acento:** `IGNORED` — mesma entidade, sem impacto operacional.
3. **Entidades sem classificação:** manter sem classificação (`UNMATCHED`) — corrigir nos cadastros do sistema.
4. **Classificação sem entidade correspondente:** `IGNORED` — linha órfã no CSV de classificação, sem entidade legada para vincular.

**Resolução por grupo:**

| Grupo | Qtd | Resolução | Detalhe |
|---|---|---|---|
| `AMBIGUOUS_CLASSIFICATION` (procedure) | 6 | **IGNORED** | Duplicatas de acento: RUBEOLA/RUBÉOLA (×4) e EXERESE/EXÉRESE (×2); todos LEGADO sem uso |
| `AMBIGUOUS_PRICE` (price) | 5 | **RESOLVED** (maior valor/disposição explícita) | US CALCANEO DIREITO: R$120 em ambas as unidades; BIOPSIA PEQUENA: R$120 em ambas as unidades; US TIREOIDE/SOU DEZ teve a decisão registrada, mas permanece em quarentena; o registro duplicado do US CALCANEO (unidade 1) também fica representado pelo maior valor |
| `CLASSIFICATION_NOT_FOUND` (professional) | 47 | **UNMATCHED** (manter sem classificação) | Profissionais sem linha no `classif_profissionais.csv`; inclui entradas de teste/legado (MEDICO TESTE, TELE LAUDOS, LAB DEZ) e profissionais reais sem classificação. Corrigir nos cadastros |
| `LEGACY_ID_NOT_FOUND` (agreement) | 6 | **IGNORED** | Linhas no CSV sem convênio correspondente: UNICRED, TESTE NOVO 2, UHS BRAZIL, WORKMED (ativo), VIDA PLENA, UNIMED CENTRAL NACIONAL |
| `LEGACY_ID_NOT_FOUND` (procedure) | 11 | **IGNORED** | Linhas no CSV sem procedimento correspondente: laudos various (ORTOPEDISTA, OTORRINO, PSIQUIATRA, NEUROLOGISTA, DERMATOLOGISTA), US DOPPLER CAROTIDAS, ASO DEMISSIONAL S&V, etc. |
| `LEGACY_ID_NOT_FOUND` (professional) | 10 | **IGNORED** | Linhas no CSV com nomes truncados sem profissional correspondente: Caio Araújo de, Carla Cristina, Cristiane Françã, Débora Silvest, etc. |

**Status final:** 85 pendências → 5 `RESOLVED` (preços), 33 `IGNORED` (6 duplicatas de acento + 27 linhas órfãs), 47 `UNMATCHED` (profissionais sem classificação, corrigir nos cadastros). Nenhuma entidade foi apagada.

**Observação sobre os 10 candidatos em quarentena** (§14.4): `BIOPSIA PEQUENA` (4 candidatos) e `US CALCANEO DIREITO` (4 candidatos) — resolvidos acima com o maior valor; quatro maiores linhas foram promovidas para `VALID` e as quatro menores continuam preservadas em quarentena. `US TIREOIDE` (2 candidatos) — mantidos em quarentena até conferência manual de preço. Total atual: 2.307 válidos e 6 em quarentena.

---

### 14.6 Preparação do piloto — situação atual

- **Primeiro Admin concluído:** Marcelo Cavalcante de Vasconcelos, `marcelo@appcredito.com.br`, perfil `ADMIN`, ativo. Cadastro existente desde 01/09, confirmado no banco em 02/09; senha definida pelo dono, sem redefinição nesta fase.
- **Decisões das 85 pendências materializadas:** 5 `RESOLVED`, 33 `IGNORED` e 47 `UNMATCHED`. Os 47 profissionais continuam preservados para classificação segura nos cadastros; os 6 preços em quarentena não viram preços válidos automaticamente (§14.5.1).
- **Exemplo configurado pelo Admin e validado pelo dono:** 1 vínculo, 1 oferta e 1 escala. Conferência em 03/09/2026: CLINICA MEDICA → ANDRE DIEGO DASMASCENO E SILVA → PARTICULAR M → Messejana, R$ 130,00, segunda-feira 08:00–12:00, blocos por ordem de chegada. Foi informado como exemplo para teste, não como autorização para disponibilizar atendimento real online.
- **Operação inicial:** somente `CLINICA DEZ MESSEJANA`. Alberto Sabin permanece ativa no catálogo, mas com operação desabilitada. Outras unidades podem ser habilitadas posteriormente pelo cadastro, sem alteração específica de código.

### 14.7 Fase 2A — piloto interno de agenda em Messejana (02/09/2026)

**Situação atual: implementação entregue; validação do dono recebida para o exemplo de vínculos, ofertas e horários.** Os resultados abaixo preservam o fechamento técnico de 02/09; a configuração posterior e a Fase 2B estão em §14.8. O ciclo com pacientes reais não foi presumido a partir da validação do exemplo.

#### Entregue

- **Ofertas internas:** conversão explícita de vínculo ativo com entidades ativas para oferta; seleção obrigatória de preço válido e vigente compatível com procedimento, convênio e unidade. `catalog_link_id` e `procedure_price_id` preservam a origem; `service_offerings.price` mantém a fotografia operacional. Ofertas novas ficam offline, e o envio de `online_enabled` pelo fluxo interno é rejeitado.
- **Escalas e exceções:** janelas semanais com vigência, duração, limite diário e blocos por chegada; validações de sobreposição/configuração, bloqueios integrais/parciais por data, edição e desativação auditadas. `WALK_IN_INFO` é configurável, mas não permite reserva nem entrada em fila nesta fase.
- **Ocorrências:** materialização idempotente dos próximos 90 dias, executada diariamente às 00:10 em `America/Fortaleza` por serviço dedicado no Docker. Mudanças de escala fecham ocorrências indisponíveis sem apagar agendamentos existentes. A reserva trava profissional/oferta/ocorrência e revalida capacidade em transação; a ocupação também considera outros serviços do mesmo profissional.
- **Agenda interna:** localização/cadastro de paciente por CPF, criação com origem `RECEPTION`, operador e preço congelado; repetição idempotente, remarcação com nova checagem de capacidade e atualização do preço congelado, cancelamento com motivo sem exclusão física. Criação, remarcação, cancelamento e alterações de escala registram auditoria.
- **Permissões:** `schedule.view` e `appointments.manage` para Admin/Supervisor/Recepção; `schedule.manage` apenas para Admin/Supervisor.
- **API autenticada:** gestão de `/api/v1/team/service-offerings`, `schedule-windows` e `schedule-exceptions`; opções em `/api/v1/team/service-offering-options`; disponibilidade em `/api/v1/team/availability`; paciente por CPF em `POST /api/v1/team/patients/lookup`; listagem/criação de `/api/v1/team/appointments` e ações `PATCH .../{id}/reschedule` / `PATCH .../{id}/cancel`. Listagem com filtros de unidade, profissional, status e intervalo de datas. OpenAPI v0.4 inclui também painel (`GET /api/v1/dashboard`) e agenda; tipos frontend regenerados.
- **Interface real:** Cadastros → “Ofertas e horários” para Admin/Supervisor; `/agenda` com filtro profissional, visão Dia, criação por CPF, remarcação, cancelamento e mensagens de validação/capacidade. Semana/Mês permanecem desabilitadas. Corrigidos filtro Ativos/Inativos dos cadastros, corte de cartões da agenda e acesso a Sair no celular durante o QA. MSW permanece opt-in.
- **Banco:** duas migrações aplicadas, índices diários e de ocorrências, cobertura das cinco referências operacionais e de outras cinco referências identificadas na revisão final. Consulta ao catálogo PostgreSQL confirmou **0 chaves estrangeiras sem índice de suporte**.

#### Validação técnica final

- **Backend:** 39 testes / 228 asserções aprovados exclusivamente em `sistema_dez_test` no PostgreSQL real, incluindo RBAC, unidade operacional, preço/quarentena, vínculo inativo, proteção contra publicação, escalas/exceções, materialização repetível, histórico preservado, ciclo de agendamento, auditoria e concorrência por processos distintos disputando a última vaga. A base dos testes possui trava para rejeitar outro nome de banco PostgreSQL. Pint aprovado.
- **Frontend:** typecheck dos 7 projetos, 5 testes Vitest e builds dos 4 apps aprovados. Playwright aprovado em desktop 1440×1000 e celular Pixel 7: Admin entra → cria oferta e escala → sai → Recepção entra → agenda por CPF → vê o registro → remarca → cancela. A Recepção não vê a aba de gestão de ofertas. Sem erros de execução/avisos inesperados no console, sem corte do cartão e sem rolagem horizontal; o 401 de `/me` antes do login é esperado.
- **Ambiente da validação visual:** frontend isolado em `http://localhost:4175`, API de testes em `http://localhost:18001`, sem MSW. O Browser interno abriu a aplicação local, mas não havia sessão do Admin; o fluxo autenticado foi executado pelo Playwright previsto no plano, com contas e dados temporários exclusivamente no banco de testes. Isso **não equivale** ao aceite do Admin real.
- **Implantação local:** API e serviço diário atualizados no Docker; migrações concluídas; `/up` 200, catálogo público 200 com lista vazia e rotas internas 401 sem sessão. Duas execuções reais de `schedule:materialize` mantiveram 0 ocorrências, pois ainda não há oferta clínica configurada. Backup anterior à fase preservado em `artifacts/dez-pre-fase2a-20260902.dump` (376.534 bytes).

#### Totais históricos após a implantação de 02/09, antes do exemplo do Admin

- 1 usuário Admin ativo; 2 unidades preservadas, somente Messejana operacional.
- 998 procedimentos, 40 convênios, 87 profissionais e 2.313 preços: 2.307 `VALID` e 6 `QUARANTINED`.
- 85 pendências rastreadas: 5 `RESOLVED`, 33 `IGNORED`, 47 `UNMATCHED`.
- **0 vínculos, 0 ofertas, 0 escalas, 0 ocorrências, 0 pacientes e 0 agendamentos reais.** Nenhuma relação clínica ou paciente fictício foi inserido; os dados demonstrados nos testes são isolados.

#### Aceite posterior

O dono informou que configurou vínculos e “Ofertas e horários” em um exemplo de teste e que a validação estava aparentemente correta, autorizando continuar. Essa validação foi registrada sem alterar sua senha, escolher relações clínicas adicionais ou publicar a oferta. As pendências atuais estão em §14.8.

### 14.8 Fase 2B — publicação online controlada e gestão pelo paciente (03/09/2026)

**Situação: implementada, testada e aplicada no Docker local.** Laravel 13 + React permanecem a stack final. Esta atualização foi feita após os testes finais; produção externa e homologação de atendimento clínico real permanecem separadas.

#### Entregue

- **Publicação explícita:** Admin/Supervisor autorizam ou retiram uma oferta em Cadastros → Ofertas e horários. Confirmação de preço/escala e data final são obrigatórias; criar ou editar oferta mantém/desliga publicação. `published_at` e `published_until` registram a autorização, com auditoria.
- **Elegibilidade:** unidade ativa e operacional, vínculo ativo com as mesmas entidades, procedimento/profissional/convênio ativos e habilitados online, preço válido e vigente compatível. Na publicação, o preço da oferta deve corresponder ao preço de origem e deve existir disponibilidade no período. Convênios agora têm `requires_authorization` (“exige guia/autorização”); quando verdadeiro, ficam fora do online. Cadastros inválidos e publicações vencidas deixam de ser elegíveis nas consultas e reservas.
- **Janelas distintas:** operação interna/materialização de 90 dias; online de hoje até 29 dias à frente, respeitando a data final da autorização e antecedência mínima de 30 minutos. Isso aplica a regra específica de §6.2.1 ao público. A data final é inclusiva no fuso de Fortaleza; regressão desse limite está testada.
- **Paciente:** escolhe a oferta exata (profissional/unidade/convênio), vê preço e vagas, informa dados/consentimento e recebe confirmação com link privado. Pode reabrir, remarcar na mesma oferta ou cancelar mediante motivo e confirmação. Alterações somente antes do início e para reservas confirmadas; cancelamento repetido é idempotente. Remarcação revalida capacidade/preço e atualiza a fotografia do valor. Reservas existentes continuam consultáveis/canceláveis mesmo após retirada da oferta do online.
- **Proteção do link:** credencial no fragmento do endereço do frontend e no cabeçalho Bearer da API, nunca aceita por query string. Banco guarda hash; auditoria e respostas de consulta não expõem token, CPF, telefone ou nome do paciente. Respostas privadas usam `no-store`/`no-referrer`; link expira 24 horas após o horário reservado e acompanha a remarcação. Limite de 30 requisições/minuto por IP nas rotas de gestão. Cookies da equipe não são enviados pelo cliente público; cache da tela não reaproveita dados ao trocar de link.
- **Capacidade compartilhada:** site e recepção utilizam o mesmo motor, transações e locks. Duas requisições simultâneas, inclusive uma pública e outra interna, nunca excedem a última vaga. Não há nova agenda paralela ao piloto interno.
- **Interfaces honestas:** estados de carregamento/erro/vazio, mensagem quando não há oferta online, nenhuma promessa de envio automático por WhatsApp e horários de demanda espontânea derivados da escala. `WALK_IN_INFO` informa horários, sem reservar ou entrar na fila.
- **Contratos:** OpenAPI v0.5 e tipos frontend regenerados. APIs novas: `PUT/DELETE /api/v1/team/service-offerings/{id}/publication`; `GET /api/v1/public/appointments/{id}`; `GET .../{id}/availability`; `PATCH .../{id}/reschedule`; `PATCH .../{id}/cancel`. Sessão/CSRF continuam exigidos nas ações da equipe; o link privado é independente da sessão.

#### Validação final e ambiente

- **Backend:** 58 testes, 363 asserções aprovadas em PostgreSQL `sistema_dez_test`; RBAC, elegibilidade, guia, prazo, acesso cruzado/expirado, capacidade, preço, auditoria, idempotência e regressões anteriores. Cache/sessão/fila dos testes foram isolados também das variáveis herdadas do Docker. Pint executado nos arquivos alterados; `--dirty` indisponível no contêiner sem Git, aplicado escopo explícito equivalente.
- **Frontend:** 9 testes Vitest, typecheck dos 7 projetos e builds dos 4 apps aprovados. Inclusos proteção contra cache de outro link, reserva cancelada e confirmação explícita de publicação.
- **Playwright:** 4 fluxos aprovados, em desktop 1440×1000 e Pixel 7: dois da Fase 2B (Admin publica → paciente agenda → reabre link → remarca → cancela → Admin retira) e dois de regressão 2A (Admin configura → Recepção agenda/remarca/cancela). API temporária em `localhost:18001`, frontends em `localhost:4175/4176`, sem MSW; somente contas/dados de `sistema_dez_test`. Sem erro de execução/console do fluxo público ou rolagem horizontal. Os avisos do terminal sobre `NO_COLOR` não são erros da aplicação.
- **Browser interno:** página real de login e catálogo público verificados, interação com filtro Exames e estado vazio conferidos; sem overlay ou erro de console no público. Fluxos autenticados foram executados pelo Playwright previsto no projeto com credenciais temporárias, não com a senha do Marcelo.
- **Implantação:** migração `2026_09_02_193342_add_controlled_publication_and_patient_access` aplicada; API/scheduler atualizados. Saúde 200, catálogo público 200 vazio, CSRF 204 e ofertas internas 401 sem sessão. Backup prévio: `artifacts/dez-pre-fase2b-20260903.dump` (385.625 bytes).
- **Endereços locais atuais:** equipe `http://localhost:5175/cadastros`; agendamento `http://localhost:5174`; API `http://localhost:8000`. A porta 5173 já estava ocupada pelo AppChat, preservado sem intervenção. Origens de 5175 adicionadas à configuração local; não é implantação na internet. Para reiniciar a equipe nessa porta: `pnpm --filter @dez/equipe dev --port 5175`.

#### Dados reais preservados

- 998 procedimentos, 40 convênios, 87 profissionais, 2.313 preços (2.307 válidos e 6 em quarentena); Messejana continua sendo a única unidade operacional.
- 1 vínculo, 1 oferta privada, 1 escala, 52 ocorrências, 0 pacientes e 0 agendamentos. Nenhuma importação ou publicação automática foi feita nesta fase.
- Oferta escolhida pelo dono: `01a06398-9383-73d2-aaf8-261307612a3d`, CLINICA MEDICA / ANDRE DIEGO DASMASCENO E SILVA / PARTICULAR M / Messejana; R$ 130,00; `ARRIVAL_BLOCK`; segunda-feira 08:00–12:00; vigência inicial 02/09/2026. Mantida como **exemplo de teste privado**, sem alterações de preço/horário nesta entrega.

#### O que falta / próximo passo

1. **Homologação local pelo dono da Fase 2B:** conferir controles novos e decidir qual oferta tem preço, profissional e escala efetivamente autorizados para pacientes. Não transformar o exemplo em atendimento real sem essa decisão.
2. **Publicação externa controlada:** escolher domínio/hospedagem, HTTPS, segredos exclusivos (a configuração Docker atual é apenas local), políticas de privacidade/retensão, backups/restauração, monitoramento e operação da recepção. Não expor diretamente a configuração local na internet.
3. **Integrações ainda pendentes:** Doctoralia, fork WhatsApp, mensagens/lembretes automáticos e recuperação/entrega do link por canal verificado. O paciente deve guardar o link exibido; não foi criado login de paciente ou recuperação por CPF.
4. **Próxima fase de produto candidata:** detalhar a Fase 3 (Caixa + Repasses + B2B), incluindo suas dependências de recepção/check-in e autorização antes do atendimento. Ainda não implementada nem iniciada automaticamente.

Permanecem pendentes Semana/Mês, fila/check-in, caixa, percentuais por pagamento, 2FA, Doctoralia, WhatsApp, laudos/prontuário/resultados e demais módulos. Recepção/fila, Laudos, Relatórios, Painel TV e o app separado do paciente continuam mockados ou sem integração operacional. Avisos/resolução de conflitos de escala com pacientes existentes, importação da planilha de escala e publicação de demanda espontânea com tratamento completo dos bloqueios devem ser homologados/complementados antes de uso amplo. O STG não foi desativado.

### 14.9 Simplificação do online — publicação automática (04/09/2026)

**Decisão do dono:** o fluxo vínculo → oferta → escala → publicar era burocrático demais.
Nova regra: **oferta do convênio padrão (`is_default`, hoje PARTICULAR M) com vínculo,
preço e escala válidos entra no ar sozinha** (opt-out com botão Pausar); **sem
vencimento**; o preço espelha a origem vigente sozinho (a fotografia continua
congelada em cada reserva). Flags "Habilitado online" dos cadastros deixam de
travar o online (colunas preservadas, ignoradas na regra). Site público sem
seletor de convênio. Inter (boletos) confirmado como **última prioridade** —
Fase 3 entrega fatura/baixa manuais.

#### Entregue

- **Elegibilidade** (`ServiceOffering::eligibleForPublication`): sem `online_enabled`
  de procedimento/profissional/convênio e sem `published_until`; exige convênio
  padrão + vínculo/preço/escala válidos; guia continua bloqueado; janela 30 dias
  e antecedência 30 min intactas.
- **Auto-ON + opt-out:** `RefreshOfferingVisibility` (preço espelhado + visibilidade
  + auditoria `visibility.refreshed`, inclusive de sistema) roda no save de oferta,
  escalas, exceções e no `schedule:materialize` — que agora revisa **todas** as
  ofertas (é ele que tira do ar unidade/vínculo/preço/escala que perder
  elegibilidade). `PATCH .../visibility` (pausar/retomar, auditado) substitui o
  `PUT/DELETE .../publication` (removido). Editar oferta não derruba mais do ar.
- **Reserva/remarcação** (`BookAppointment`, `ChangeAppointment`): sem checagem de
  `published_until` (campo segue na tabela, sem uso).
- **Frontend:** painel "Publicação online controlada" virou toggle Pausar/Retomar
  com preço e data de atualização; alerta "Online automático"; tipos regenerados
  (OpenAPI **v0.6.0**; path quebrado do `generate:openapi` corrigido para
  `../../../backend/openapi.yaml`).
- **Testes:** `ControlledPublicationTest` substituído por `OnlineVisibilityTest`
  (auto-ON, pausa com RBAC/auditoria, inelegibilidade por matriz, preço espelhado
  com snapshot preservado); `SchedulingE2ESeeder` marca PARTICULAR PILOTO como
  padrão; spec Playwright reescrito (entra no ar sozinha → reserva → remarca →
  cancela → pausa tira do ar); piloto interno usa CPF próprio (`111.444.777-35`)
  para não colidir com o paciente da publicação no mesmo banco de teste.

#### Validação final e ambiente

- **Backend:** 57 testes / 355 asserções em `sistema_dez_test` (PHP 8.4 **nativo**,
  sem Docker — ver `AGENTS.md` §5); Pint aprovado.
- **Frontend:** 10 Vitest, typecheck 7 projetos, 4 builds.
- **Playwright** (API nativa `:18001` + `sistema_dez_test` zerado por config):
  visibilidade desktop + mobile e regressão piloto desktop + mobile, sem erros
  de console e sem rolagem horizontal.
- **Dados reais:** oferta-exemplo `01a06398…` (CLINICA MEDICA / ANDRE DIEGO /
  PARTICULAR M / R$130) **entrou no ar sozinha no localhost** via
  `schedule:materialize` — comportamento aprovado, sem exposição externa.

### 14.10 Fase 3A-1 — fundação pagamento-antes + perfis de caixa (04/09/2026)

**Situação: implementada e validada localmente, sem commit.** Gate 3A-0 baixado por
decisão do dono (homologação considerada feita).

**Decisões aplicadas:** fase fatiada 3A→3D; Inter por último (pós-3D — Fase 3 com
fatura/baixa manuais); perfis novos já nesta etapa; `users.role` segue perfil
único (perfis cumulativos do §7.5 ficam para 3B+).

#### Entregue

- **State machine (T6, base):** `appointments` com `payment_status`
  (`PENDING`/`PAID`/`AUTHORIZED`), `paid_at`, `payment_method_id`,
  `cash_movement_id`, `b2b_authorization_code`, `care_started_at`.
  `POST .../check-in` (CONFIRMED→CHECKED_IN + fila WAITING),
  `POST .../start-care` (trava antifraude: só CHECKED_IN + PAID/AUTHORIZED,
  fila IN_SERVICE), `POST .../authorize-b2b` (convênio empresarial ativo +
  código; provisório até 3D). Tudo com auditoria.
- **Caixa (T7, base):** `payment_methods` (ESPECIE/DEBITO/CREDITO/PIX com
  `% extra`), `cash_registers` (unidade/turno/fundo/status/abertura/fechamento
  + `last_receipt_number`) e `cash_movements` (RECEBIMENTO com recibo sequencial
  por caixa, sem lacuna, sob lock). `POST .../checkout` cobra preço congelado +
  % da forma; `GET .../current` com resumo por forma e dinheiro esperado;
  `POST .../close` com fotografia dos totais (imutável após). Receitas do caixa
  sem fechamento em lote, quebra, sangria e dupla assinatura ficam para 3B.
- **RBAC:** `CASHIER`, `MANAGER`, `DOCTOR` + permissões `cash.view`,
  `cash.operate`, `cash.close`, `discounts.validate`. Recepção segue sem ver
  valores de caixa; caixa não gerencia catálogo (testado).
- **Frontend:** `/caixa` (abrir com turno/fundo, resumo, fichas pendentes por
  data, cobrar com recibo, fechar) com gate por permissão; agenda com badge de
  pagamento por ficha; editor com check-in/início de atendimento (o modal agora
  remonta com o dado fresco da lista após cada ação).
- **Contratos:** OpenAPI **v0.7.0** + tipos regenerados.

#### Validação final e ambiente

- **Backend:** 63 testes / 419 asserções em `sistema_dez_test` (PHP nativo);
  Pint aprovado. Inclui `CashFoundationTest` (6 testes: abertura, recibo
  sequencial, dupla cobrança/fechamento/método inválido rejeitados, fluxo
  visita completo, B2B sem caixa, matriz RBAC).
- **Frontend:** 13 Vitest (inclui 3 da `/caixa`), typecheck 7 projetos, 4 builds.
- **Playwright** (API `:18001`, banco zerado por run): caixa desktop + mobile,
  regressão publicação desktop + mobile e piloto desktop + mobile — 6 fluxos,
  sem erros de console e sem rolagem horizontal.
- **Dados reais:** migrations aplicadas (`payment_methods` com 4 formas);
  nenhum caixa aberto, nenhuma cobrança real.

---

### 14.11 Fase 3B — fechamento consolidado + quebra/sangria + estorno (04/09/2026)

**Situação: implementada e validada tecnicamente, sem commit.** Validação manual
acumulada para o final, por decisão do dono (3A-1 + 3B + 3C + 3D juntas).

**Decisões aplicadas:** lote parcial com relatório; contado só em dinheiro
(cartão/PIX por extrato); qualquer quebra exige justificativa + supervisor
(`discounts.validate`); sangria em registro simples auditado (sem dupla
confirmação); estorno com supervisor antes de cancelar ficha paga.

#### Entregue

- **Fechamento:** `POST .../close` com `counted_cash` + `note`; fotografia com
  contado/diferença/justificativa; `POST .../close-batch` parcial
  (`closed[]/failed[]`); reabertura bloqueada (422).
- **Sangria:** `POST .../sangrias` (valor/motivo/comprovante, só ESPECIE, só
  caixa ABERTO, recibo sequencial compartilhado, auditoria
  `cash.sangria_created`); abate o dinheiro esperado.
- **Estorno:** `POST .../appointments/{id}/void-payment` (só PAID + caixa
  ABERTO, `discounts.validate`, ficha volta a PENDING, auditoria
  `payment.voided`); cancelar ficha PAID sem estorno retorna 422.
- **Relatório:** `GET .../daily-report?unit_id&date` (esperado x contado,
  sangrias, estornos, alerta de lacuna de recibo); `GET .../cash-registers`
  com filtros `status/from/to`.
- **Resumo:** `expected_cash = fundo + ESPECIE(RECEBIMENTO − ESTORNO) − SANGRIA`;
  `by_method` segue só recebimentos.
- **Frontend:** `/caixa` com fechamento contado/justificativa + aviso
  supervisor, sangria, fichas pagas/estorno (só `discounts.validate`),
  lote multi-caixa e relatório diário; tipos regenerados (OpenAPI **v0.8.0**).
- **Contratos:** OpenAPI v0.8.0 + `schema.generated.ts` regenerado.

#### Validação técnica

- **Backend:** 69 testes / 477 asserções em `sistema_dez_test` (PHP nativo);
  inclui `CashClosingTest` (6 testes: exato, quebra nota+supervisor, lote
  parcial, sangria, estorno/desbloqueio-cancelamento, relatório+RBAC). Pint.
- **Frontend:** 14 Vitest (4 da `/caixa`), typecheck 7 projetos, 4 builds.
- **Playwright** (API `:18001` com `FRONTEND_URLS=http://localhost:4175`,
  banco zerado por fluxo): novo `cash-closing` desktop + mobile (cobrar →
  sangria R$30 → fechar exato R$220 → relatório sem lacuna) + regressão
  `cash-checkout` desktop + mobile — 4 fluxos, sem erro console/rolagem.
- **Dados reais:** nenhuma cobrança/fechamento real; E2E usou só
  `sistema_dez_test` (API de testes parada após a validação).

---

### 14.12 Fase 3C — repasses médicos com confirmação do médico (04/09/2026)

**Situação: implementada e validada em backend + frontend, sem commit.**
Validação manual acumulada para o final, por decisão do dono (3A-1 + 3B + 3C
+ 3D juntas). **E2E Playwright (`payouts.spec`) ainda não passou de ponta a
ponta** — ver "Ponto de atenção" abaixo; funcionalidade comprovada em backend,
frontend e evidência visual (telas de gerar/confirmar/pagar com valores
corretos: comissão R$ 30,00 sobre ficha de R$ 150,00; cofre R$ 220,00 após o
pagamento em espécie).

**Decisões do dono aplicadas:** comissão **% padrão por médico + override por
procedimento** (valor fixo como alternativa); entram fichas **PAID +
AUTHORIZED** juntas (B2B autorizado entra mesmo antes da baixa da fatura, que
é 3D); **médico loga (DOCTOR) e confirma** os próprios repasses; pagamento
**em espécie abate o caixa, via PIX não** (só registra + comprovante).

#### Entregue (backend, OpenAPI v0.9.0)

- **Cadastro:** `professionals` com `commission_type` (PERCENT|FIXED, % ≤ 100),
  `commission_value` e `user_id` (vínculo login DOCTOR ↔ profissional, único);
  overrides por procedimento em `professional_procedure_commissions` (único por
  par, auditado); papel DOCTOR liberado no select de usuários.
- **Repasse:** tabelas `payouts` (OPEN|CONFIRMED|CONTESTED|PAID, totais,
  confirmado/pago por/em, comprovante) + `payout_items` (fotografia de
  bruto/comissão/regra por ficha; um atendimento paga comissão uma única vez).
- **Fluxo:** `POST .../payouts/generate` (idempotente por atendimento; médico
  gera só o próprio) → `POST .../confirm` / `.../contest {note}` (só o médico
  vinculado; ADMIN/MANAGER como override) → `PATCH .../payout-items/{id}`
  (ajuste do supervisor, só OPEN/CONTESTED) → `POST .../payouts/{id}/pay`
  (só CONFIRMED; ESPECIE exige caixa ABERTO e cria movimento `REPASSE`
  abatendo o dinheiro esperado; PIX exige comprovante e não movimenta o
  caixa). Tudo auditado (`payout.generated/confirmed/contested/paid`,
  `payout.item_adjusted`, `cash.repasse_paid`).
- **Antifraude:** void/cancel de ficha em repasse CONFIRMED/PAID retorna 422;
  em repasse OPEN/CONTESTED o item sai do lote com recálculo automático.
- **RBAC novo:** `payouts.view|manage|confirm|pay` — ADMIN/MANAGER tudo;
  SUPERVISOR ver+gerar/ajustar; CASHIER ver+pagar; DOCTOR ver+confirmar os
  próprios (+ `catalog-links.view` p/ operar); RECEPTION nada.
- **Caixa:** `summary()` e `daily-report` passam a abater `REPASSE` em espécie;
  `GET .../professionals/mine` (profissional do login, com pré-seleção na tela).
- **Frontend:** página `/repasses` (menu Gestão, gate `payouts.view`) — filtro
  médico/período/situação, gerar, detalhe com itens, confirmar/contestar
  (médico), ajustar (supervisor), pagar (ESPECIE c/ caixa aberto ou PIX c/
  comprovante), histórico + export CSV; `/cadastros` com comissão, overrides e
  vínculo de login; UI de erro + retry onde antes falhava em silêncio.

#### Validação técnica

- **Backend:** 77 testes / 564 asserções em `sistema_dez_test` (PHP nativo),
  Pint aprovado; inclui `PayoutTest` (7 testes: cálculo %/fixo/override,
  PAID+AUTHORIZED juntos, idempotência, confirm só do médico vinculado,
  contest+ajuste, pay espécie abate / PIX não abate, travas void/cancel,
  RBAC, `/mine`) e teste do `GET cash-registers` com filtros.
- **Frontend:** 16 Vitest (2 da `/repasses`), typecheck 7 projetos, 4 builds.
- **Playwright:** spec `payouts.spec.ts` + config `playwright.payouts.config.ts`
  criados (admin configura 20% → recepção agenda → caixa cobra R$150 → médico
  gera/confirma R$30 → caixa paga em espécie → cofre R$220); **sem passe
  completo até o fechamento desta versão**.

#### Ponto de atenção (E2E instável — ambiente, não produto)

O spec trava em pontos aleatórios por espera estourada. Causas mapeadas:
`localhost` resolvendo p/ `::1` sem nada escutando (serve duplo 127.0.0.1 +
`[::1]` na :18001); `DB_HOST=localhost` com o mesmo problema (usar
`127.0.0.1` nos comandos); serves PHP single-thread enfileirando sob
StrictMode (tudo dispara dobrado) + checkpoints de 30–40s do Postgres no
Docker Desktop; serves obsoletos servindo código velho (sempre reiniciar a
:18001 após editar o backend); cookie Lax não viaja em POST cross-site
(`localhost` × `127.0.0.1` são sites diferentes — não misturar hosts entre
página e API). Bugs REAIS achados pelo E2E e já corrigidos estão embutidos
acima. Lições de ambiente também viraram §15.

---

## 15. Ambiente em outro PC (só teste, sem prod)

> **Premissa do dono (04/09/2026):** pode usar **bancos diferentes em cada PC**
> nesta fase. **Não existe produção do Sistema DEZ** — tudo aqui é teste.
> Cada PC tem seus próprios bancos (`sistema_dez` p/ dev, `sistema_dez_test`
> p/ testes); nada é sincronizado entre máquinas.

### 15.1 Pré-requisitos

- **PHP 8.4** com extensões `pdo_pgsql`, `mbstring`, `openssl` (+ Composer).
- **Node 22+** com **pnpm 11** (`npm i -g pnpm`).
- **Docker Desktop** (só p/ `postgres` + `redis`) **ou** PostgreSQL 17 local.
- Git + conta com acesso ao repo privado `marcelocv8/sistema-dez`.

### 15.2 Clonar e subir o banco

```powershell
git clone https://github.com/marcelocv8/sistema-dez.git
cd sistema-dez\backend
docker compose up -d postgres redis   # sobe PG na 5433 e redis na 6379
```

O compose cria o banco `sistema_dez` (usuário/senha `sistema_dez` /
`local_change_me`). Crie também o banco de testes (nome fixo exigido pelos
testes e pela API de E2E):

```sql
CREATE DATABASE sistema_dez_test OWNER sistema_dez;
```

### 15.3 Backend (Laravel)

```powershell
cd backend
Copy-Item .env.example .env
php artisan key:generate
# No .env, confira: DB_CONNECTION=pgsql, DB_HOST=127.0.0.1 (NÃO localhost —
# localhost às vezes resolve p/ ::1 e a conexão falha de forma intermitente),
# DB_PORT=5433, DB_DATABASE=sistema_dez, DB_USERNAME=sistema_dez,
# DB_PASSWORD=local_change_me, IDENTITY_PEPPER=<hex aleatório>,
# MANAGEMENT_TOKEN_SECRET=<hex aleatório>
composer install
php artisan migrate
php artisan team:user admin@sua-clinica.test --name="Admin local" --role=ADMIN
# (informe a senha quando pedir; sem senha padrão)
php artisan serve --host=127.0.0.1 --port=8000
```

### 15.4 Frontend (React)

```powershell
cd frontend
pnpm install
# O app equipe precisa saber onde está a API:
$env:VITE_API_URL='http://localhost:8000'; pnpm --filter @dez/equipe dev --port 5175
```

Sem `VITE_API_URL`, o frontend chama o próprio Vite e login/catálogo falham.
Agendamento: `$env:VITE_API_URL='http://localhost:8000'; pnpm --filter
@dez/agendamento dev --port 5174`.

### 15.5 Testes

```powershell
# Backend (sempre no banco isolado sistema_dez_test, nunca em outro):
$env:DB_CONNECTION='pgsql'; $env:DB_HOST='127.0.0.1'; $env:DB_PORT='5433'
$env:DB_DATABASE='sistema_dez_test'; $env:DB_USERNAME='sistema_dez'
$env:DB_PASSWORD='local_change_me'
php artisan test --compact
php vendor/bin/pint --dirty   # obrigatório após editar PHP
# Frontend:
pnpm test --run; pnpm typecheck; pnpm build
```

### 15.6 E2E local (quando precisar)

API de testes na :18001 contra `sistema_dez_test`, com sessão em banco (nunca
`SESSION_DRIVER=array`) e `SANCTUM_STATEFUL_DOMAINS` contendo **só** a porta
da equipe:

```powershell
$env:DB_DATABASE='sistema_dez_test'; $env:APP_ENV='testing'
$env:SESSION_DRIVER='database'; $env:CACHE_STORE='database'
$env:SANCTUM_STATEFUL_DOMAINS='localhost:4175'; $env:FRONTEND_URLS='http://localhost:4175'
$env:E2E_PASSWORD='<senha temporária ≥ 12 chars>'
php artisan migrate:fresh --force; php artisan db:seed --class=SchedulingE2ESeeder --force
php artisan serve --host=127.0.0.1 --port=18001
# em outro shell: cd frontend; $env:E2E_PASSWORD='<a mesma>'; npx playwright test --config=playwright.payouts.config.ts
```

Se o Chromium preferir IPv6 (`localhost` → `::1`), suba também
`php -S "[::1]:18001" -t public` com o mesmo ambiente. **Reinicie a :18001
sempre que editar o backend** (serve não recarrega sozinho) e zere o banco a
cada fluxo (slots de uso único conflitam entre runs).

### 15.7 O que NÃO vai junto / não funciona no outro PC

- **Dados do STG** (catálogo real, preços, laudos): ficam em `C:\Projects\STG\`
  (fora do repo); sem copiá-los, `stg:*` não tem o que importar — testes e E2E
  usam factories/seeds próprios e funcionam sem isso.
- **`backend/compose.local.yaml`**: segredos locais, untracked, **nunca
  commitar**; cada PC tem o seu.
- `.env`, dumps, `vendor/`, `node_modules/`: nunca entram no git.

---

*Documento vivo — a cada decisão, uma nova versão. Próximo passo: 3D (B2B
manual: fatura fim do mês + baixa + adimplência), depois E2E do repasse até o
verde e validação manual acumulada (3A-1→3D). Inter segue adiada para pós-3D.*
