<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Database;

/**
 * VALIDAÇÃO DE UM CAMPO DE ENTRADA — o único lugar da API onde um valor vindo de fora vira
 * um valor que pode ir para o banco.
 *
 * Todo campo devolve sempre a mesma forma: ['ok' => bool, 'valor' => mixed, 'erro' => string].
 * `valor` já sai CANÔNICO (bool vira 0/1, número vira int/float, JSON vira texto compacto),
 * de modo que dois jeitos de escrever a mesma coisa gravem exatamente os mesmos bytes.
 *
 * DUAS REGRAS QUE VALEM PARA TODOS OS TIPOS
 *
 * 1. Tipo errado é ERRO, não conversão. `"quantidade": "muitas"` não vira 0 em silêncio:
 *    um número inventado a partir de lixo é o defeito que só aparece semanas depois, no
 *    relatório. A única tolerância é com número escrito como texto ("12"), porque metade dos
 *    clientes HTTP manda assim e a intenção é inequívoca.
 *
 * 2. O que não foi enviado não é tocado. Numa alteração parcial (PATCH), campo ausente e
 *    campo com `null` são coisas diferentes: o primeiro diz "não mexa", o segundo diz
 *    "apague". Quem resolve essa distinção é a Tabela; aqui só se valida o que chegou.
 */
final class Campo
{
    /** @return array{ok:bool, valor:mixed, erro:string} */
    private static function ok(mixed $valor): array
    {
        return ['ok' => true, 'valor' => $valor, 'erro' => ''];
    }

    /** @return array{ok:bool, valor:mixed, erro:string} */
    private static function nao(string $erro): array
    {
        return ['ok' => false, 'valor' => null, 'erro' => $erro];
    }

    /**
     * Valida um valor contra a regra declarada em App\Api\Catalogo.
     *
     * @param array<string,mixed> $regra
     * @return array{ok:bool, valor:mixed, erro:string}
     */
    public static function validar(mixed $bruto, array $regra): array
    {
        $tipo = (string)($regra['tipo'] ?? 'texto');

        // Nulo explícito: só passa em coluna que aceita nulo. Recusar aqui evita o
        // "Column cannot be null" do MySQL virar 500 — o cliente merece 422 com o nome do campo.
        if ($bruto === null) {
            return !empty($regra['nulo'])
                ? self::ok(null)
                : self::nao('Este campo não aceita valor nulo.');
        }

        return match ($tipo) {
            'int'     => self::int($bruto, $regra),
            'bool'    => self::bool($bruto),
            'decimal' => self::decimal($bruto, $regra),
            'enum'    => self::enum($bruto, $regra),
            'json'    => self::json($bruto),
            'data'    => self::data($bruto),
            'slug'    => self::slug($bruto, $regra),
            'ref'     => self::ref($bruto, $regra),
            default   => self::texto($bruto, $regra),
        };
    }

    /** @param array<string,mixed> $regra @return array{ok:bool, valor:mixed, erro:string} */
    private static function texto(mixed $bruto, array $regra): array
    {
        if (is_bool($bruto) || is_array($bruto)) {
            return self::nao('Esperava texto.');
        }
        $v = trim((string)$bruto);
        $max = (int)($regra['max'] ?? 255);
        if (mb_strlen($v) > $max) {
            return self::nao('Texto longo demais (máximo de ' . $max . ' caracteres).');
        }
        if ($v === '' && !empty($regra['nulo'])) {
            // Texto vazio em coluna que aceita nulo vira NULL, e não ''. Guardar os dois
            // estados criaria duas maneiras de dizer "não preenchido" na mesma coluna.
            return self::ok(null);
        }
        return self::ok($v);
    }

    /** @param array<string,mixed> $regra @return array{ok:bool, valor:mixed, erro:string} */
    private static function slug(mixed $bruto, array $regra): array
    {
        $v = strtolower(trim((string)(is_scalar($bruto) ? $bruto : '')));
        if ($v === '') {
            return self::nao('O identificador não pode ficar vazio.');
        }
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $v)) {
            return self::nao('Use apenas letras minúsculas, números e hífen (ex.: caca-ao-javali).');
        }
        $max = (int)($regra['max'] ?? 64);
        if (strlen($v) > $max) {
            return self::nao('Identificador longo demais (máximo de ' . $max . ' caracteres).');
        }
        return self::ok($v);
    }

    /** @param array<string,mixed> $regra @return array{ok:bool, valor:mixed, erro:string} */
    private static function int(mixed $bruto, array $regra): array
    {
        if (is_bool($bruto) || is_array($bruto)) {
            return self::nao('Esperava um número inteiro.');
        }
        $v = is_string($bruto) ? trim($bruto) : $bruto;
        if ($v === '') {
            return !empty($regra['nulo']) ? self::ok(null) : self::nao('Esperava um número inteiro.');
        }
        if (!is_int($v) && !preg_match('/^-?\d{1,10}$/', (string)$v)) {
            return self::nao('Esperava um número inteiro.');
        }
        $n = (int)$v;
        if (isset($regra['min']) && $n < (int)$regra['min']) {
            return self::nao('Valor mínimo: ' . (int)$regra['min'] . '.');
        }
        if (isset($regra['max']) && $n > (int)$regra['max']) {
            return self::nao('Valor máximo: ' . (int)$regra['max'] . '.');
        }
        return self::ok($n);
    }

    /** @return array{ok:bool, valor:mixed, erro:string} */
    private static function bool(mixed $bruto): array
    {
        if (is_bool($bruto)) {
            return self::ok($bruto ? 1 : 0);
        }
        if (is_int($bruto) && ($bruto === 0 || $bruto === 1)) {
            return self::ok($bruto);
        }
        $v = strtolower(trim((string)(is_scalar($bruto) ? $bruto : '')));
        if (in_array($v, ['1', 'true', 'sim', 'on'], true)) {
            return self::ok(1);
        }
        if (in_array($v, ['0', 'false', 'nao', 'não', 'off'], true)) {
            return self::ok(0);
        }
        return self::nao('Esperava verdadeiro ou falso.');
    }

    /** @param array<string,mixed> $regra @return array{ok:bool, valor:mixed, erro:string} */
    private static function decimal(mixed $bruto, array $regra): array
    {
        if (is_bool($bruto) || is_array($bruto)) {
            return self::nao('Esperava um número.');
        }
        $v = is_string($bruto) ? trim($bruto) : $bruto;
        if (!is_int($v) && !is_float($v) && !preg_match('/^-?\d{1,7}(?:\.\d{1,6})?$/', (string)$v)) {
            return self::nao('Esperava um número decimal (use ponto, não vírgula).');
        }
        $n = (float)$v;
        if (isset($regra['min']) && $n < (float)$regra['min']) {
            return self::nao('Valor mínimo: ' . $regra['min'] . '.');
        }
        if (isset($regra['max']) && $n > (float)$regra['max']) {
            return self::nao('Valor máximo: ' . $regra['max'] . '.');
        }
        // Arredonda para a escala da coluna AQUI: deixar o MySQL truncar devolveria ao
        // cliente um valor diferente do que ele mandou, sem nenhum aviso.
        return self::ok(round($n, (int)($regra['escala'] ?? 2)));
    }

    /** @param array<string,mixed> $regra @return array{ok:bool, valor:mixed, erro:string} */
    private static function enum(mixed $bruto, array $regra): array
    {
        $opcoes = array_map('strval', (array)($regra['opcoes'] ?? []));
        $v = trim((string)(is_scalar($bruto) ? $bruto : ''));
        if ($v === '' && !empty($regra['nulo'])) {
            return self::ok(null);
        }
        if (!in_array($v, $opcoes, true)) {
            return self::nao('Valor fora da lista. Aceitos: ' . implode(', ', $opcoes) . '.');
        }
        return self::ok($v);
    }

    /** @return array{ok:bool, valor:mixed, erro:string} */
    private static function json(mixed $bruto): array
    {
        // Objeto/lista já decodificado (veio no corpo JSON) é o caso normal. Texto também é
        // aceito, porque um cliente que monta a requisição à mão costuma mandar a string.
        if (is_array($bruto)) {
            return self::ok(json_encode($bruto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $v = trim((string)(is_scalar($bruto) ? $bruto : ''));
        if ($v === '') {
            return self::ok(null);
        }
        $d = json_decode($v, true);
        if ($d === null && strtolower($v) !== 'null') {
            return self::nao('Conteúdo JSON inválido.');
        }
        return self::ok(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array{ok:bool, valor:mixed, erro:string} */
    private static function data(mixed $bruto): array
    {
        $v = trim((string)(is_scalar($bruto) ? $bruto : ''));
        if ($v === '') {
            return self::ok(null);
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        // 31/02 passa pelo createFromFormat e vira 03/03: só o ida-e-volta prova a data.
        if ($d === false || $d->format('Y-m-d') !== $v) {
            return self::nao('Use uma data no formato AAAA-MM-DD.');
        }
        return self::ok($v);
    }

    /**
     * Referência a outra tabela (o que seria uma chave estrangeira).
     *
     * A checagem acontece AQUI e não no banco porque este esquema não usa FOREIGN KEY: sem
     * ela, criar uma oferta de loja apontando para um item inexistente seria aceito e só
     * apareceria como linha em branco na vitrine do jogador.
     *
     * @param array<string,mixed> $regra
     * @return array{ok:bool, valor:mixed, erro:string}
     */
    private static function ref(mixed $bruto, array $regra): array
    {
        $num = self::int($bruto, ['min' => 1, 'nulo' => !empty($regra['nulo'])]);
        if (!$num['ok'] || $num['valor'] === null) {
            return $num['ok'] ? $num : self::nao('Esperava o id (número) do registro referenciado.');
        }

        $tabela = (string)($regra['tabela'] ?? '');
        $coluna = (string)($regra['coluna'] ?? 'id');
        // Nome de tabela/coluna vem da declaração do catálogo (código), nunca do cliente —
        // mas a conferência do formato fica aqui do mesmo jeito: é a linha que impede que uma
        // declaração nova e mal escrita vire concatenação de SQL.
        if (!preg_match('/^[a-z_]{1,64}$/', $tabela) || !preg_match('/^[a-z_]{1,64}$/', $coluna)) {
            throw new \LogicException('Declaração de referência inválida no catálogo da API.');
        }

        $existe = (int)Database::value(
            "SELECT COUNT(*) FROM `$tabela` WHERE `$coluna` = :v",
            ['v' => $num['valor']]
        );
        if ($existe === 0) {
            return self::nao('Não existe registro com este id em ' . $tabela . '.');
        }
        return self::ok($num['valor']);
    }
}
