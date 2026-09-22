<?php

/**
 * Extração read-only dos dados de Medicina do Trabalho do STG legado.
 *
 * Gera os CSVs esperados por `stg:import-mt` no formato canônico:
 *   mt_riscos.csv  (legacy_id,descricao,ativo)
 *   mt_setores.csv (legacy_id,legacy_convenio_id,descricao,ativo)
 *   mt_funcoes.csv (legacy_id,legacy_setor_id,legacy_risco_id,descricao,ativo)
 *   mt_asos.csv    (legacy_id,legacy_paciente_id,legacy_convenio_id,legacy_setor_id,
 *                   legacy_funcao_id,legacy_risco_ids,legacy_exame_nomes,tipo,
 *                   legacy_situacao,data_cadastro,data_realizacao,data_validade,ativo)
 *
 * Uso (na rede da Clínica DEZ, com acesso ao Postgres do servidor):
 *   php extract_mt.php --host 10.x.x.x --port 5432 --db dez --user postgres --out <pasta>
 *   (senha via variável de ambiente STG_DB_PASSWORD)
 *   php extract_mt.php --discover  # lista colunas reais, se o schema divergir
 *
 * Somente SELECT. Nenhuma escrita no banco legado.
 */

declare(strict_types=1);

// Valores OBRIGATÓRIOS (um ':') para as opções que sempre recebem valor: o PHP
// só aceita `--host valor` / `--host=valor` nessa forma. Com '::' (valor
// opcional) o getopt ignora `--host valor` e o host chega vazio ao DSN.
$args = getopt('', ['host:', 'port:', 'db:', 'user:', 'out:', 'prefix:', 'discover::']) ?: [];
$host = (string) ($args['host'] ?? 'localhost');
$port = (string) ($args['port'] ?? '5432');
$db = (string) ($args['db'] ?? 'dez');
$user = (string) ($args['user'] ?? 'postgres');
$password = (string) (getenv('STG_DB_PASSWORD') ?: '');

// Prefixo das tabelas. No STG da clínica o CodeIgniter usa dbprefix = "ponto.",
// e no PostgreSQL esse "prefixo com ponto" é na verdade o SCHEMA: as tabelas
// ficam em ponto.tb_aso_risco. Passe --prefix=ponto. (com o ponto no final).
$prefix = (string) ($args['prefix'] ?? '');

// `array_key_exists`: com 'discover::' (valor opcional) o getopt devolve
// `discover => false` quando a flag vem sozinha — o teste anterior
// (`!== null && !== false`) ignorava o `--discover` sem valor.
if (array_key_exists('discover', $args)) {
    $pdo = connect($host, $port, $db, $user, $password);
    discover($pdo);
    exit(0);
}

$out = (string) ($args['out'] ?? (__DIR__.'/../../../STG/dezstg2/_extracoes_stg/mt'));
if (! is_dir($out)) {
    mkdir($out, 0775, true);
}

$pdo = connect($host, $port, $db, $user, $password);
$pdo->beginTransaction();

// Nome de tabela já com o prefixo/schema aplicado
$t = static fn (string $table): string => $prefix.$table;

writeCsv($out, 'mt_riscos.csv', ['legacy_id', 'descricao', 'ativo'], $pdo->query(
    "SELECT aso_risco_id AS legacy_id,
            COALESCE(descricao_risco, '') AS descricao,
            CASE WHEN ativo = 't' THEN 1 ELSE 0 END AS ativo
     FROM {$t('tb_aso_risco')}
     ORDER BY aso_risco_id"
)->fetchAll(PDO::FETCH_ASSOC));

writeCsv($out, 'mt_setores.csv', ['legacy_id', 'legacy_convenio_id', 'descricao', 'ativo'], $pdo->query(
    "SELECT aso_setor_id AS legacy_id,
            convenio_id AS legacy_convenio_id,
            COALESCE(descricao_setor, '') AS descricao,
            CASE WHEN ativo = 't' THEN 1 ELSE 0 END AS ativo
     FROM {$t('tb_aso_setor')}
     ORDER BY aso_setor_id"
)->fetchAll(PDO::FETCH_ASSOC));

writeCsv($out, 'mt_funcoes.csv', ['legacy_id', 'legacy_setor_id', 'legacy_risco_id', 'descricao', 'ativo'], $pdo->query(
    "SELECT aso_funcao_id AS legacy_id,
            aso_setor_id AS legacy_setor_id,
            aso_risco_id AS legacy_risco_id,
            COALESCE(descricao_funcao, '') AS descricao,
            CASE WHEN ativo = 't' THEN 1 ELSE 0 END AS ativo
     FROM {$t('tb_aso_funcao')}
     ORDER BY aso_funcao_id"
)->fetchAll(PDO::FETCH_ASSOC));

$examesPorAso = [];
$stmt = $pdo->query(
    "SELECT ae.cadastro_aso_id AS legacy_aso_id,
            string_agg(DISTINCT COALESCE(pt.nome, pt.descricao, CAST(pt.procedimento_tuss_id AS text)), ' | ') AS exames
     FROM {$t('tb_agenda_exames')} ae
     LEFT JOIN {$t('tb_procedimento_tuss')} pt ON pt.procedimento_tuss_id = ae.procedimento_tuss_id
     WHERE ae.cadastro_aso_id IS NOT NULL
     GROUP BY ae.cadastro_aso_id"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $examesPorAso[(string) $row['legacy_aso_id']] = (string) $row['exames'];
}

$asoRows = [];
foreach ($pdo->query(
    "SELECT ca.cadastro_aso_id AS legacy_id,
            ca.paciente_id AS legacy_paciente_id,
            COALESCE(ca.convenio_id, ca.convenio2) AS legacy_convenio_id,
            ca.tipo,
            COALESCE(ca.data_cadastro, '') AS data_cadastro,
            COALESCE(ca.data_realizacao, '') AS data_realizacao,
            COALESCE(ca.data_validade, '') AS data_validade,
            CASE WHEN ca.ativo = 't' THEN 1 ELSE 0 END AS ativo
     FROM {$t('tb_cadastro_aso')} ca
     ORDER BY ca.cadastro_aso_id"
) as $row) {
    $meta = parseImpressaoAso($row['legacy_id'], $pdo, $prefix);
    $asoRows[] = [
        'legacy_id' => $row['legacy_id'],
        'legacy_paciente_id' => $row['legacy_paciente_id'],
        'legacy_convenio_id' => $row['legacy_convenio_id'],
        'legacy_setor_id' => $meta['setor'],
        'legacy_funcao_id' => $meta['funcao'],
        'legacy_risco_ids' => implode('|', $meta['riscos']),
        'legacy_exame_nomes' => $examesPorAso[(string) $row['legacy_id']] ?? '',
        'tipo' => $row['tipo'],
        'legacy_situacao' => $meta['situacao'],
        'data_cadastro' => normalizeDate((string) $row['data_cadastro']),
        'data_realizacao' => normalizeDate((string) $row['data_realizacao']),
        'data_validade' => normalizeDate((string) $row['data_validade']),
        'ativo' => $row['ativo'],
        'impressao_aso_json' => $meta['raw'],
    ];
}

writeCsv($out, 'mt_asos.csv', [
    'legacy_id', 'legacy_paciente_id', 'legacy_convenio_id', 'legacy_setor_id',
    'legacy_funcao_id', 'legacy_risco_ids', 'legacy_exame_nomes', 'tipo',
    'legacy_situacao', 'data_cadastro', 'data_realizacao', 'data_validade', 'ativo',
    'impressao_aso_json',
], $asoRows);

$pdo->rollBack();

echo "CSVs gravados em: $out\n";
echo "Concluído (read-only; transação revertida — nenhuma escrita).\n";

function connect(string $host, string $port, string $db, string $user, string $password): PDO
{
    if ($password === '') {
        fwrite(STDERR, "Defina a senha: \$env:STG_DB_PASSWORD='...'\n");
        exit(1);
    }

    try {
        return new PDO(
            "pgsql:host=$host;port=$port;dbname=$db",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        fwrite(STDERR, 'Falha de conexão: '.$e->getMessage()."\n");
        exit(1);
    }
}

function discover(PDO $pdo): void
{
    // O schema do STG varia entre instalações (prefixo de tabela, nomes). Este
    // modo é o ponto de partida quando as consultas de extração falham com
    // "relation ... does not exist": lista as tabelas candidatas e as colunas.
    $patterns = ['%risco%', '%aso%', '%setor%', '%funcao%', '%ocupacional%', '%procedimento_tuss%'];

    $sql = 'SELECT table_name FROM information_schema.tables
            WHERE table_schema = \'public\' AND ('
        .implode(' OR ', array_fill(0, count($patterns), 'table_name ILIKE ?'))
        .') ORDER BY table_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($patterns);
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($tables === []) {
        echo "Nenhuma tabela casou com: ".implode(', ', $patterns)."\n";
        echo "Liste tudo com: select tablename from pg_tables where schemaname='public' order by 1;\n";

        return;
    }

    foreach ($tables as $table) {
        echo "=== $table ===\n";
        $cols = $pdo->prepare(
            "SELECT column_name, data_type FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = ?
             ORDER BY ordinal_position"
        );
        $cols->execute([$table]);
        foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $col) {
            echo "  {$col['column_name']} : {$col['data_type']}\n";
        }
    }
}

/** Lê o JSON `impressao_aso` (POST do cadastro) para setor/função/riscos/situação. */
function parseImpressaoAso(string $legacyAsoId, PDO $pdo, string $prefix = ''): array
{
    $stmt = $pdo->prepare("SELECT impressao_aso FROM {$prefix}tb_cadastro_aso WHERE cadastro_aso_id = ?");
    $stmt->execute([(int) $legacyAsoId]);
    $raw = (string) ($stmt->fetchColumn() ?: '');
    $data = json_decode($raw, true);

    if (! is_array($data)) {
        return ['setor' => '', 'funcao' => '', 'riscos' => [], 'situacao' => '', 'raw' => ''];
    }

    $riscos = $data['riscos'] ?? [];
    if (! is_array($riscos)) {
        $riscos = [$riscos];
    }

    $situacao = $data['situacao'] ?? $data['situacao_aso'] ?? '';

    return [
        'setor' => (string) ($data['setor'] ?? $data['setor2'] ?? ''),
        'funcao' => (string) ($data['funcao'] ?? $data['funcao2'] ?? ''),
        'riscos' => array_values(array_filter(array_map('strval', $riscos))),
        'situacao' => (string) $situacao,
        'raw' => $raw,
    ];
}

function normalizeDate(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $parsed = date_create($value);

    return $parsed ? $parsed->format('Y-m-d') : '';
}

function writeCsv(string $dir, string $name, array $header, array $rows): void
{
    $handle = fopen($dir.'/'.$name, 'wb');
    fputcsv($handle, $header);
    foreach ($rows as $row) {
        fputcsv($handle, array_map(static fn ($value): string => (string) $value, $row));
    }
    fclose($handle);
    echo basename($name).': '.count($rows)." linha(s)\n";
}
