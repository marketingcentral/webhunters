<?php declare(strict_types=1);

namespace App\Core;

/**
 * FRAGMENTO DE LISTA — como o painel devolve só o pedaço da tela que mudou.
 *
 * O problema: filtrar uma lista do /admin recarregava o documento inteiro (fontes, CSS,
 * sidebar, KPIs da topbar) para trocar vinte linhas de <tbody>. A ideia aqui é a MESMA que
 * assets/js/spa.js já usa no jogo — o mesmo controller, a mesma view, só outro layout —, mas
 * um degrau abaixo: em vez de trocar o miolo da página, troca-se um pedaço do miolo.
 *
 * POR QUE MARCADOR DE COMENTÁRIO, E NÃO UM <div id="lista">:
 *
 *   1. um elemento novo em volta da lista entra no fluxo do CSS. Metade das telas do painel
 *      tem `.adm-content` em grid/flex e `.adm-card` como filho direto — embrulhar mudaria o
 *      espaçamento de 12 telas para resolver um problema de JavaScript;
 *   2. a região raramente é UM elemento. Em /admin/servidores são três <section> seguidas;
 *      em /admin/jogadores é uma só. Um par de comentários delimita QUALQUER quantidade de
 *      irmãos sem inventar um pai para eles;
 *   3. comentário sobrevive ao parser e vira Comment no DOM, então o cliente acha as mesmas
 *      duas bordas que o servidor usou para cortar. Servidor e cliente leem o mesmo par.
 *
 * NA VIEW (é tudo o que uma tela precisa fazer):
 *
 *   <!--eh:lista-->
 *   <section class="adm-card ..."> ... formulário, tabela e paginação ... </section>
 *   <!--/eh:lista-->
 *
 * O formulário de filtros leva `data-adm-preservar="filtros"`: ele é o ÚNICO pedaço que não
 * pode ser substituído, porque é onde está o cursor de quem digita. Ver assets/js/admin/lista.js.
 *
 * SEM JAVASCRIPT nada disto existe: os marcadores são comentários HTML, o formulário continua
 * `method="get"` e a página recarrega como sempre. O cabeçalho abaixo só é enviado por quem
 * tem o script rodando — ou seja, o caminho novo é enriquecimento, nunca requisito.
 */
final class Fragmento
{
    /** Cabeçalho que pede o fragmento. Mesmo espírito do X-EH-Partial do jogo. */
    public const CABECALHO = 'X-EH-Lista';

    /** As duas bordas. Escritas LITERALMENTE nas views — ver o exemplo no topo. */
    public const ABRE  = '<!--eh:lista-->';
    public const FECHA = '<!--/eh:lista-->';

    /** Esta requisição quer só a região da lista? */
    public static function pedido(): bool
    {
        return ($_SERVER['HTTP_X_EH_LISTA'] ?? '') === '1';
    }

    /**
     * O HTML entre as duas bordas, ou null quando a tela renderizada não tem par de
     * marcadores.
     *
     * null é resposta LEGÍTIMA e esperada: /admin/jogadores?id=7 é o dossiê de uma conta,
     * não uma lista, e /admin/mapas?excluir=3 troca a lista por uma confirmação. Nesses
     * casos o cliente desiste do atalho e navega normalmente — que é exatamente o que ele
     * faria sem JavaScript.
     */
    public static function extrair(string $html): ?string
    {
        $inicio = strpos($html, self::ABRE);
        if ($inicio === false) {
            return null;
        }
        $inicio += strlen(self::ABRE);

        $fim = strpos($html, self::FECHA, $inicio);
        if ($fim === false) {
            return null;
        }
        return substr($html, $inicio, $fim - $inicio);
    }
}
