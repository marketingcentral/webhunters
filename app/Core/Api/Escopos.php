<?php declare(strict_types=1);

namespace App\Core\Api;

/**
 * CATÁLOGO FECHADO DE ESCOPOS — a única lista de permissões que a API reconhece.
 *
 * Um escopo é `<recurso>:<verbo>`, com verbo `read` (ler) ou `write` (criar, alterar,
 * apagar). Nada mais existe: não há coringa, não há `*`, não há "admin".
 *
 * POR QUE NÃO EXISTE CORINGA
 * --------------------------
 * `*` é confortável no dia da integração e impossível de revisar seis meses depois: ninguém
 * consegue responder "o que esta chave pode fazer?" olhando um asterisco. Sem coringa, a
 * resposta é sempre a lista literal — e a tela do painel mostra exatamente ela. O custo é
 * marcar 22 caixas para criar uma chave total; o botão "marcar todos" resolve isso na
 * interface SEM criar um valor especial no banco.
 *
 * POR QUE `write` NÃO INCLUI `read`
 * ---------------------------------
 * São concedidos separadamente. Uma integração que só empurra estado (um bot que aplica
 * banimento, por exemplo) não precisa poder LISTAR a base de jogadores, e essas são
 * exatamente as duas capacidades que um vazamento separa: escrever faz barulho e é
 * auditado; ler em massa é silencioso e é o que vira dump. Quem precisa dos dois marca os
 * dois — o painel deixa isso à vista.
 *
 * ESTA LISTA É A FONTE ÚNICA. O que não estiver aqui não pode ser gravado numa chave
 * (ApiKeyRepo::normalizarEscopos descarta), não pode ser exigido por um endpoint
 * (App\Api\Recurso valida na montagem da referência) e não aparece no painel.
 */
final class Escopos
{
    /** Verbos possíveis. `read` = GET; `write` = POST/PUT/PATCH/DELETE. */
    public const VERBOS = ['read', 'write'];

    /**
     * Recursos governados pela API, na ordem em que a tela do painel os agrupa.
     *
     * A chave é o primeiro segmento da rota (/api/v1/players/...), então mudar uma chave
     * aqui muda a URL pública — é contrato, não rótulo.
     *
     * @var array<string, array{rotulo:string, descricao:string, icone:string}>
     */
    public const RECURSOS = [
        'players' => [
            'rotulo'    => 'Jogadores',
            'descricao' => 'Contas, papel, situação e progresso.',
            'icone'     => 'group',
        ],
        'bans' => [
            'rotulo'    => 'Banimentos',
            'descricao' => 'Histórico de punições, aplicar e revogar.',
            'icone'     => 'gavel',
        ],
        'missions' => [
            'rotulo'    => 'Missões',
            'descricao' => 'Catálogo de missões do mundo.',
            'icone'     => 'flag',
        ],
        'achievements' => [
            'rotulo'    => 'Conquistas',
            'descricao' => 'Catálogo de conquistas.',
            'icone'     => 'military_tech',
        ],
        'maps' => [
            'rotulo'    => 'Mapas',
            'descricao' => 'Mapas e seus marcadores.',
            'icone'     => 'map',
        ],
        'items' => [
            'rotulo'    => 'Itens',
            'descricao' => 'Catálogo de itens do inventário.',
            'icone'     => 'inventory_2',
        ],
        'shop' => [
            'rotulo'    => 'Loja',
            'descricao' => 'Ofertas da loja.',
            'icone'     => 'shopping_cart',
        ],
        'events' => [
            'rotulo'    => 'Eventos',
            'descricao' => 'Desafios, notícias e avisos do sistema.',
            'icone'     => 'event',
        ],
        'servers' => [
            'rotulo'    => 'Servidores',
            'descricao' => 'Regiões, serviços e modo manutenção.',
            'icone'     => 'dns',
        ],
        'economy' => [
            'rotulo'    => 'Economia',
            'descricao' => 'Transações e relatório de receita.',
            'icone'     => 'payments',
        ],
        'settings' => [
            'rotulo'    => 'Configurações',
            'descricao' => 'Parâmetros do jogo (admin_settings).',
            'icone'     => 'settings',
        ],
    ];

    /**
     * Todos os escopos válidos, na ordem do catálogo: ['players:read', 'players:write', ...].
     *
     * @return list<string>
     */
    public static function todos(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $out = [];
        foreach (array_keys(self::RECURSOS) as $recurso) {
            foreach (self::VERBOS as $verbo) {
                $out[] = $recurso . ':' . $verbo;
            }
        }
        return $cache = $out;
    }

    public static function existe(string $escopo): bool
    {
        return in_array($escopo, self::todos(), true);
    }

    /** Rótulo humano para a tela: 'players:read' -> 'Jogadores · ler'. */
    public static function rotulo(string $escopo): string
    {
        [$recurso, $verbo] = array_pad(explode(':', $escopo, 2), 2, '');
        $nome = self::RECURSOS[$recurso]['rotulo'] ?? $recurso;
        return $nome . ' · ' . ($verbo === 'write' ? 'escrever' : 'ler');
    }

    /**
     * O escopo que um método HTTP exige sobre um recurso.
     *
     * Regra única, aplicada pelo despachante: se o método muda estado, é `write`. Nenhum
     * endpoint escolhe isso por conta própria — um GET que escrevesse ficaria protegido por
     * um escopo de leitura, que é exatamente o descuido que este método impede.
     */
    public static function paraMetodo(string $recurso, string $metodo): string
    {
        return $recurso . ':' . (strtoupper($metodo) === 'GET' ? 'read' : 'write');
    }

    /**
     * Agrupado por recurso, pronto para desenhar o formulário de criação de chave.
     *
     * @return list<array{recurso:string, rotulo:string, descricao:string, icone:string,
     *                    escopos:list<array{valor:string, verbo:string}>}>
     */
    public static function agrupados(): array
    {
        $out = [];
        foreach (self::RECURSOS as $recurso => $meta) {
            $escopos = [];
            foreach (self::VERBOS as $verbo) {
                $escopos[] = ['valor' => $recurso . ':' . $verbo, 'verbo' => $verbo];
            }
            $out[] = [
                'recurso'   => $recurso,
                'rotulo'    => $meta['rotulo'],
                'descricao' => $meta['descricao'],
                'icone'     => $meta['icone'],
                'escopos'   => $escopos,
            ];
        }
        return $out;
    }
}
