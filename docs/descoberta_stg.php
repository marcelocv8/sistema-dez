<?php

/**
 * Descoberta do schema do STG legado (SOMENTE LEITURA).
 *
 * Uso:
 *   STG_DB_PASSWORD='...' php descoberta_stg.php \
 *     --host=127.0.0.1 --port=5432 --db=dez --user=postgres --schema=ponto \
 *     --pattern=%medico% --pattern=%operador% --pattern=%agenda%
 *
 * Sem nenhum --pattern, lista todas as tabelas do schema (com estimativa de
 * linhas). Com --pattern, filtra e imprime as COLUNAS de cada tabela que casar.
 *
 * Todas as consultas são SELECT em information_schema/pg_class — não escreve
 * nada no banco do STG.
 */
$options = [
    'host' => '127.0.0.1',
    'port' => '5432',
    'db' => 'dez',
    'user' => 'postgres',
    'schema' => 'public',
    'patterns' => [],
];

foreach (array_slice($argv, 1) as $argument) {
    if (! str_starts_with($argument, '--')) {
        continue;
    }

    [$key, $value] = str_contains($argument, '=') ? explode('=', $argument, 2) : [substr($argument, 2), ''];

    $key = substr($key, 2);

    if ($key === 'pattern') {
        $options['patterns'][] = $value;

        continue;
    }

    if (array_key_exists($key, $options)) {
        $options[$key] = $value;
    }
}

$password = (string) (getenv('STG_DB_PASSWORD') ?: '');

if ($password === '') {
    fwrite(STDERR, "Defina a senha: STG_DB_PASSWORD='...'\n");
    exit(1);
}

try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $options['host'], $options['port'], $options['db']),
        $options['user'],
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
    );
    $pdo->exec('SET default_transaction_read_only = on');
} catch (Throwable $exception) {
    fwrite(STDERR, 'Falha de conexão: '.$exception->getMessage()."\n");
    exit(1);
}

$schema = $options['schema'];
$sql = 'SELECT c.relname AS table_name, c.reltuples::bigint AS approx_rows
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = ? AND c.relkind IN (\'r\', \'p\')';

$params = [$schema];

if ($options['patterns'] !== []) {
    $sql .= ' AND ('.implode(' OR ', array_fill(0, count($options['patterns']), 'c.relname ILIKE ?')).')';
    $params = [...$params, ...$options['patterns']];
}

$sql .= ' ORDER BY c.relname';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$tables = $statement->fetchAll();

printf("schema=%s  tabelas encontradas=%d  (%s)\n", $schema, count($tables), $options['patterns'] === [] ? 'todas' : 'padrões: '.implode(' ', $options['patterns']));

$columnStatement = $pdo->prepare(
    'SELECT column_name, data_type FROM information_schema.columns
     WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position'
);

foreach ($tables as $table) {
    printf("\n=== %s.%s  (~%d linhas)\n", $schema, $table['table_name'], $table['approx_rows']);
    $columnStatement->execute([$schema, $table['table_name']]);
    foreach ($columnStatement->fetchAll() as $column) {
        printf("  %-40s %s\n", $column['column_name'], $column['data_type']);
    }
}
