<?php declare(strict_types=1);

namespace App\Repo;

use App\Core\Database;

/**
 * `admin_settings` — os parâmetros que o jogo lê em tempo real (tela /admin/configuracoes).
 *
 * NÃO confundir com App\Repo\SettingsRepo, que é outra coisa: aquele guarda a PREFERÊNCIA DE
 * CADA JOGADOR (`player_settings`, conciliada por user_id). Este aqui guarda a regra do jogo
 * inteiro, uma linha por parâmetro, e só a administração escreve nele. Os dois nomes são
 * parecidos porque as duas telas se chamam "Configurações"; o prefixo Admin- é o que impede
 * um `use` distraído de trocar as tabelas.
 *
 * Aqui mora TODO o SQL da tela e, mais importante, a validação por tipo. A validação vive no
 * repositório (e não no controller) porque a garantia não é da tela: é da tabela. Quando o
 * jogo passar a gravar uma chave por outro caminho — um comando de console, um job —, ele vai
 * passar pelos mesmos `validar()` e `gravar()` e não conseguirá deixar um `int` com a palavra
 * "muitos" dentro.
 *
 * O par valor/tipo é a razão de a coluna `value` ser TEXT e de tudo sair daqui como string:
 * uma tabela de chave/valor genérica não tem coluna por tipo. A conversão para o tipo real
 * acontece na leitura (`valor()`), num único lugar, com o padrão do código como rede.
 *
 * CHAVES DO CÓDIGO
 * ----------------
 * As doze chaves semeadas por db/patch_v6_admin.php são lidas pelo jogo. Elas podem ser
 * editadas à vontade, mas NÃO podem ser apagadas: sumir com a linha faria o jogo cair
 * silenciosamente na constante embutida, e o operador continuaria vendo na tela o valor que
 * ele acha que configurou. Chave criada pelo painel não tem esse laço e pode ser removida.
 */
final class AdminSettingsRepo
{
    /** Os quatro widgets que a tela sabe desenhar e validar. */
    public const TIPOS = ['string', 'int', 'bool', 'json'];

    /** Grupo usado quando a chave nova não declara um. */
    public const GRUPO_PADRAO = 'geral';

    /** Ações gravadas em admin_audit_log — é por elas que o histórico da tela é lido. */
    public const ACAO_ALTERAR = 'config_alterada';
    public const ACAO_CRIAR   = 'config_criada';
    public const ACAO_EXCLUIR = 'config_excluida';

    /**
     * Chaves que o CÓDIGO do jogo consome (sementes do patch v6). Lista literal, e não uma
     * coluna `protegida` no banco: a proteção descreve o código, então tem de mudar junto com
     * ele, num commit — não por um UPDATE que ninguém revisa.
     */
    public const CHAVES_DO_CODIGO = [
        'maintenance_enabled',
        'maintenance_message',
        'registration_open',
        'region_capacity_total',
        'region_capacity_hunters',
        'region_capacity_mercenaries',
        'human_signal_ttl_min',
        'world_event_interval_min',
        'safe_shift_minutes',
        'squad_max',
        'xp_per_level',
        'support_sla_hours',
    ];

    /** Teto de um texto simples quando a chave não declara o seu. A coluna é TEXT. */
    private const TEXTO_MAX = 2000;

    /** Teto de um documento JSON. Acima disso não é configuração, é conteúdo. */
    private const JSON_MAX = 8000;

    // =====================================================================  leitura

    /**
     * Todas as linhas, cruas, na ordem canônica (grupo, chave). Filtro e ordenação de tela
     * acontecem em PHP: a tabela tem dezenas de linhas, não milhares, e devolver o conjunto
     * inteiro deixa a contagem dos filtros ("3 de 12") honesta sem uma segunda consulta.
     *
     * @return list<array<string,mixed>>
     */
    public static function todas(): array
    {
        return Database::all(
            'SELECT s.`key`, s.`value`, s.kind, s.label, s.grupo, s.updated_at, s.updated_by,
                    u.username AS updated_por
               FROM admin_settings s
               LEFT JOIN users u ON u.id = s.updated_by
              ORDER BY s.grupo, s.`key`'
        );
    }

    /** @return array<string,mixed>|null */
    public static function porChave(string $chave): ?array
    {
        return Database::one(
            'SELECT s.`key`, s.`value`, s.kind, s.label, s.grupo, s.updated_at, s.updated_by,
                    u.username AS updated_por
               FROM admin_settings s
               LEFT JOIN users u ON u.id = s.updated_by
              WHERE s.`key` = :k',
            ['k' => $chave]
        );
    }

    /**
     * Valor já convertido para o tipo declarado — é assim que o JOGO deve ler a tabela.
     * O padrão do chamador é a rede: chave ausente, valor NULL ou JSON quebrado devolvem o
     * padrão em vez de derrubar a requisição do jogador por causa de um erro de digitação
     * no painel.
     */
    public static function valor(string $chave, mixed $padrao = null): mixed
    {
        try {
            $linha = Database::one('SELECT `value`, kind FROM admin_settings WHERE `key` = :k', ['k' => $chave]);
        } catch (\Throwable $e) {
            error_log('[extracthunters] AdminSettingsRepo::valor(' . $chave . '): ' . $e->getMessage());
            return $padrao;
        }
        if ($linha === null || $linha['value'] === null) {
            return $padrao;
        }

        $bruto = (string)$linha['value'];
        return match ((string)$linha['kind']) {
            'int'   => is_numeric($bruto) ? (int)$bruto : $padrao,
            'bool'  => $bruto === '1',
            'json'  => json_decode($bruto, true) ?? $padrao,
            default => $bruto,
        };
    }

    /**
     * A ÚLTIMA alteração de cada chave, lida da trilha de auditoria.
     *
     * É o que faz a tela mostrar "era 20" ao lado do campo. O valor anterior não é guardado em
     * admin_settings de propósito — uma coluna `value_old` só lembraria de UM passo e mentiria
     * na segunda edição. O histórico verdadeiro é o log, e é dele que a tela lê.
     *
     * @return array<string,array{de:?string,para:?string,quando:string,quem:?string}>
     */
    public static function ultimasMudancas(int $limite = 400): array
    {
        try {
            $linhas = Database::all(
                'SELECT a.entity_id, a.meta, a.created_at, u.username
                   FROM admin_audit_log a
                   LEFT JOIN users u ON u.id = a.admin_id
                  WHERE a.entity = :ent AND a.action = :acao
                  ORDER BY a.id DESC
                  LIMIT ' . max(1, min(2000, $limite)),
                ['ent' => 'config', 'acao' => self::ACAO_ALTERAR]
            );
        } catch (\Throwable $e) {
            error_log('[extracthunters] AdminSettingsRepo::ultimasMudancas: ' . $e->getMessage());
            return [];
        }

        $por = [];
        foreach ($linhas as $l) {
            $chave = (string)($l['entity_id'] ?? '');
            if ($chave === '' || isset($por[$chave])) {
                continue; // ORDER BY id DESC: a primeira que aparece já é a mais recente
            }
            $meta = json_decode((string)($l['meta'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            $por[$chave] = [
                'de'     => array_key_exists('de', $meta) && $meta['de'] !== null ? (string)$meta['de'] : null,
                'para'   => array_key_exists('para', $meta) && $meta['para'] !== null ? (string)$meta['para'] : null,
                'quando' => (string)($l['created_at'] ?? ''),
                'quem'   => isset($l['username']) && $l['username'] !== null ? (string)$l['username'] : null,
            ];
        }
        return $por;
    }

    // =====================================================================  escrita

    /**
     * Grava um valor JÁ VALIDADO. Devolve false quando nada mudou — o chamador usa isso para
     * não sujar a auditoria com "alterou de 4 para 4", que é o que aconteceria toda vez que
     * alguém salvasse o formulário sem mexer em nada.
     */
    public static function gravar(string $chave, ?string $valor, int $adminId): bool
    {
        $atual = Database::one('SELECT `value` FROM admin_settings WHERE `key` = :k', ['k' => $chave]);
        if ($atual === null) {
            return false;
        }
        if ((string)($atual['value'] ?? '') === (string)$valor) {
            return false;
        }
        Database::run(
            'UPDATE admin_settings SET `value` = :v, updated_by = :by, updated_at = NOW() WHERE `key` = :k',
            ['v' => $valor, 'by' => $adminId > 0 ? $adminId : null, 'k' => $chave]
        );
        return true;
    }

    /** @param array{key:string,value:?string,kind:string,label:string,grupo:string} $linha */
    public static function criar(array $linha, int $adminId): void
    {
        Database::insert('admin_settings', [
            'key'        => $linha['key'],
            'value'      => $linha['value'],
            'kind'       => $linha['kind'],
            'label'      => $linha['label'],
            'grupo'      => $linha['grupo'],
            'updated_by' => $adminId > 0 ? $adminId : null,
        ]);
    }

    public static function remover(string $chave): void
    {
        Database::run('DELETE FROM admin_settings WHERE `key` = :k', ['k' => $chave]);
    }

    public static function existe(string $chave): bool
    {
        return (int)Database::value('SELECT COUNT(*) FROM admin_settings WHERE `key` = :k', ['k' => $chave]) > 0;
    }

    /** Chave lida pelo código do jogo — editável, nunca removível. */
    public static function protegida(string $chave): bool
    {
        return in_array($chave, self::CHAVES_DO_CODIGO, true);
    }

    // =====================================================================  validação

    /**
     * Valida e NORMALIZA um valor cru vindo do formulário.
     *
     * Devolve sempre a mesma forma: ['ok'=>bool, 'valor'=>?string, 'erro'=>string]. `valor` é
     * o que vai para o banco — já canônico, para que dois jeitos de escrever a mesma coisa
     * ("1" / " 1 ", `{"a":1}` / `{ "a": 1 }`) não pareçam alterações diferentes na auditoria.
     *
     * $regra vem de app/Data/admin-config.php: min, max, max_len.
     *
     * @param array<string,mixed> $regra
     * @return array{ok:bool,valor:?string,erro:string}
     */
    public static function validar(string $tipo, string $bruto, array $regra = []): array
    {
        return match ($tipo) {
            'bool'  => self::validarBool($bruto),
            'int'   => self::validarInt($bruto, $regra),
            'json'  => self::validarJson($bruto),
            default => self::validarTexto($bruto, $regra),
        };
    }

    /** @return array{ok:bool,valor:?string,erro:string} */
    private static function validarBool(string $bruto): array
    {
        $ligado = in_array(strtolower(trim($bruto)), ['1', 'on', 'true', 'sim'], true);
        return ['ok' => true, 'valor' => $ligado ? '1' : '0', 'erro' => ''];
    }

    /**
     * @param array<string,mixed> $regra
     * @return array{ok:bool,valor:?string,erro:string}
     */
    private static function validarInt(string $bruto, array $regra): array
    {
        // Espaço e separador de milhar são erro de digitação, não intenção: "1 000" e "10.000"
        // são aceitos e normalizados. Já "10,5" NÃO é: um int arredondado em silêncio vira o
        // tipo de bug que só aparece meses depois, num cálculo de capacidade.
        $limpo = str_replace([' ', '.', "\u{00A0}"], '', trim($bruto));

        if ($limpo === '') {
            return ['ok' => false, 'valor' => null, 'erro' => 'Informe um número.'];
        }
        if (!preg_match('/^-?\d+$/', $limpo)) {
            return ['ok' => false, 'valor' => null, 'erro' => 'Só números inteiros — sem vírgula nem unidade.'];
        }

        $n = (int)$limpo;
        if ((string)$n !== ltrim($limpo, '+')) {
            // (int) satura em PHP_INT_MAX sem avisar: comparar a volta pega o estouro.
            return ['ok' => false, 'valor' => null, 'erro' => 'Número grande demais para este campo.'];
        }

        $min = isset($regra['min']) ? (int)$regra['min'] : null;
        $max = isset($regra['max']) ? (int)$regra['max'] : null;
        if ($min !== null && $n < $min) {
            return ['ok' => false, 'valor' => null, 'erro' => 'Mínimo aceito: ' . $min . '.'];
        }
        if ($max !== null && $n > $max) {
            return ['ok' => false, 'valor' => null, 'erro' => 'Máximo aceito: ' . $max . '.'];
        }

        return ['ok' => true, 'valor' => (string)$n, 'erro' => ''];
    }

    /**
     * @param array<string,mixed> $regra
     * @return array{ok:bool,valor:?string,erro:string}
     */
    private static function validarTexto(string $bruto, array $regra): array
    {
        // UTF-8 quebrado é recusado, e não corrigido em silêncio: toda a limpeza abaixo usa
        // regex com /u, que devolve NULL diante de bytes inválidos — sem esta porta, o texto
        // do operador seria substituído por vazio e gravado assim, sem ninguém perceber.
        if (!mb_check_encoding($bruto, 'UTF-8')) {
            return ['ok' => false, 'valor' => null, 'erro' => 'O texto chegou com caracteres corrompidos. Redigite o campo.'];
        }

        // \r\n do navegador vira \n antes de medir: senão o mesmo texto tem tamanhos
        // diferentes conforme o sistema de quem digitou.
        $txt = str_replace(["\r\n", "\r"], "\n", $bruto);

        // Caractere de controle não imprimível não tem uso legítimo aqui e atravessa a tela
        // sem deixar rastro visível — some antes de ser medido e gravado.
        $txt = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $txt);
        $txt = trim($txt);

        $max = isset($regra['max_len']) ? max(1, (int)$regra['max_len']) : self::TEXTO_MAX;
        if (mb_strlen($txt) > $max) {
            return [
                'ok'    => false,
                'valor' => null,
                'erro'  => 'Máximo de ' . $max . ' caracteres (enviou ' . mb_strlen($txt) . ').',
            ];
        }

        return ['ok' => true, 'valor' => $txt, 'erro' => ''];
    }

    /**
     * JSON é gravado COMPACTO e devolvido indentado para leitura (ver `jsonBonito`). Guardar a
     * indentação que o operador digitou faria toda re-indentação parecer uma mudança de valor
     * na auditoria.
     *
     * @return array{ok:bool,valor:?string,erro:string}
     */
    private static function validarJson(string $bruto): array
    {
        $txt = trim($bruto);
        if ($txt === '') {
            return ['ok' => true, 'valor' => null, 'erro' => ''];
        }
        if (strlen($txt) > self::JSON_MAX) {
            return [
                'ok'    => false,
                'valor' => null,
                'erro'  => 'Documento acima de ' . self::JSON_MAX . ' bytes — isso é conteúdo, não configuração.',
            ];
        }

        try {
            $dado = json_decode($txt, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['ok' => false, 'valor' => null, 'erro' => 'JSON inválido: ' . $e->getMessage() . '.'];
        }
        if (!is_array($dado)) {
            return ['ok' => false, 'valor' => null, 'erro' => 'A raiz precisa ser um objeto {…} ou uma lista […].'];
        }

        $compacto = json_encode($dado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['ok' => true, 'valor' => $compacto === false ? null : $compacto, 'erro' => ''];
    }

    /** JSON compacto do banco -> indentado, para caber no editor sem virar uma linha só. */
    public static function jsonBonito(?string $compacto): string
    {
        if ($compacto === null || trim($compacto) === '') {
            return '';
        }
        $dado = json_decode($compacto, true);
        if ($dado === null) {
            return $compacto; // gravado quebrado por outro caminho: mostra como está, não esconde
        }
        $bonito = json_encode($dado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $bonito === false ? $compacto : $bonito;
    }

    // =====================================================  validação de chave nova

    /**
     * Confere os campos de uma chave nova. Devolve erros por CAMPO (e não uma frase só): o
     * formulário precisa saber qual caixa fica vermelha.
     *
     * @param array<string,string> $bruto
     * @return array{erros:array<string,string>,linha:array<string,?string>}
     */
    public static function validarNova(array $bruto): array
    {
        $erros = [];

        $chave = strtolower(trim($bruto['key'] ?? ''));
        if ($chave === '') {
            $erros['key'] = 'Informe a chave.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $chave)) {
            $erros['key'] = 'De 3 a 64 caracteres: letras minúsculas, números e _ , começando por letra.';
        } elseif (self::existe($chave)) {
            $erros['key'] = 'Já existe uma chave com este nome.';
        }

        $label = trim($bruto['label'] ?? '');
        if ($label === '') {
            $erros['label'] = 'Informe um rótulo — é o que o operador lê na tela.';
        } elseif (mb_strlen($label) > 120) {
            $erros['label'] = 'Máximo de 120 caracteres.';
        }

        $tipo = strtolower(trim($bruto['kind'] ?? ''));
        if (!in_array($tipo, self::TIPOS, true)) {
            $erros['kind'] = 'Tipo desconhecido.';
            $tipo = 'string';
        }

        $grupo = strtolower(trim($bruto['grupo'] ?? '')) ?: self::GRUPO_PADRAO;
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $grupo)) {
            $erros['grupo'] = 'Grupo inválido: letras minúsculas, números e _ , até 32 caracteres.';
        }

        $valor = self::validar($tipo, $bruto['value'] ?? '');
        if (!$valor['ok']) {
            $erros['value'] = $valor['erro'];
        }

        return [
            'erros' => $erros,
            'linha' => [
                'key'   => $chave,
                'label' => $label,
                'kind'  => $tipo,
                'grupo' => $grupo,
                'value' => $valor['valor'],
            ],
        ];
    }
}
