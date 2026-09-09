-- Extração read-only MT do STG legado (Caminho B — psql no servidor).
-- Gera os mesmos CSVs do Caminho A (script PHP). Rodar a partir da pasta de saída:
--   psql -U postgres -d dez -f extract_mt.sql
--
-- ATENÇÃO: a coluna impressao_aso_json é o POST do cadastro; o importador
-- parseia. Se o cast ::jsonb falhar em alguma linha, use o Caminho A (script PHP).

\copy (SELECT aso_risco_id AS legacy_id, COALESCE(descricao_risco,'') AS descricao, CASE WHEN ativo='t' THEN 1 ELSE 0 END AS ativo FROM tb_aso_risco ORDER BY aso_risco_id) TO 'mt_riscos.csv' WITH (FORMAT csv, HEADER true)

\copy (SELECT aso_setor_id AS legacy_id, convenio_id AS legacy_convenio_id, COALESCE(descricao_setor,'') AS descricao, CASE WHEN ativo='t' THEN 1 ELSE 0 END AS ativo FROM tb_aso_setor ORDER BY aso_setor_id) TO 'mt_setores.csv' WITH (FORMAT csv, HEADER true)

\copy (SELECT aso_funcao_id AS legacy_id, aso_setor_id AS legacy_setor_id, aso_risco_id AS legacy_risco_id, COALESCE(descricao_funcao,'') AS descricao, CASE WHEN ativo='t' THEN 1 ELSE 0 END AS ativo FROM tb_aso_funcao ORDER BY aso_funcao_id) TO 'mt_funcoes.csv' WITH (FORMAT csv, HEADER true)

\copy (
  SELECT ca.cadastro_aso_id AS legacy_id,
         ca.paciente_id AS legacy_paciente_id,
         COALESCE(ca.convenio_id, ca.convenio2) AS legacy_convenio_id,
         '' AS legacy_setor_id,
         '' AS legacy_funcao_id,
         '' AS legacy_risco_ids,
         COALESCE(e.exames,'') AS legacy_exame_nomes,
         ca.tipo,
         '' AS legacy_situacao,
         COALESCE(to_char(ca.data_cadastro,'YYYY-MM-DD'),'') AS data_cadastro,
         COALESCE(to_char(ca.data_realizacao,'YYYY-MM-DD'),'') AS data_realizacao,
         COALESCE(to_char(ca.data_validade,'YYYY-MM-DD'),'') AS data_validade,
         CASE WHEN ca.ativo='t' THEN 1 ELSE 0 END AS ativo,
         COALESCE(ca.impressao_aso,'') AS impressao_aso_json
  FROM tb_cadastro_aso ca
  LEFT JOIN (
    SELECT ae.cadastro_aso_id,
           string_agg(DISTINCT COALESCE(pt.nome, pt.descricao, CAST(pt.procedimento_tuss_id AS text)), ' | ') AS exames
    FROM tb_agenda_exames ae
    LEFT JOIN tb_procedimento_tuss pt ON pt.procedimento_tuss_id = ae.procedimento_tuss_id
    WHERE ae.cadastro_aso_id IS NOT NULL
    GROUP BY ae.cadastro_aso_id
  ) e ON e.cadastro_aso_id = ca.cadastro_aso_id
  ORDER BY ca.cadastro_aso_id
) TO 'mt_asos.csv' WITH (FORMAT csv, HEADER true)
