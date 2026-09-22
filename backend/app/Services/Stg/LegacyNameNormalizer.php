<?php

namespace App\Services\Stg;

use Illuminate\Support\Str;

/**
 * Normalização de nomes do legado STG, compartilhada pelos importadores.
 *
 * O `nome_agenda` da grade vem com título ("DR. AUGUSTO UCHIDA",
 * "DRA.LAICE CUNHA", "DR.GLAUBER JEAN") enquanto o cadastro do operador não
 * tem. Remover o título dos DOIS lados é o que faz o casamento funcionar —
 * e a mesma função precisa ser usada ao gravar `search_name`, senão o
 * profissional criado por um comando não é encontrado pelo outro.
 */
final class LegacyNameNormalizer
{
    /**
     * Títulos removidos do início do nome. Exigir um separador (ponto ou
     * espaço) depois do título evita que "DR" case antes de "DRA" e sobre um
     * "A." no nome ("DRA.LAICE" → "LAICE", não "A.LAICE").
     */
    private const TITLES = 'DOUTORA|DOUTOR|ENFERMEIRA|PROFA|PROF|DRA|DR|ENF';

    /** Chave de busca/comparação: sem acento, caixa alta, espaços normalizados e sem título. */
    public static function key(?string $value): string
    {
        $normalized = Str::of((string) $value)->ascii()->squish()->upper()->toString();

        if ($normalized === '') {
            return '';
        }

        return trim((string) preg_replace('/^('.self::TITLES.')[.\s]+/', '', $normalized));
    }

    /** Nome para exibição: sem título e sem espaços duplicados, preservando a caixa original. */
    public static function display(?string $value): string
    {
        $stripped = (string) preg_replace('/^('.self::TITLES.')[.\s]+/i', '', (string) $value);

        return Str::of($stripped)->squish()->toString();
    }
}
