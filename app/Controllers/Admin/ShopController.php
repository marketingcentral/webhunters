<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\ShopOfferRepo;

/**
 * Tela Loja do painel: /admin/loja.
 *
 * Governa `shop_offers` — o preço e a vitrine —, sempre conciliada com `items`, que é o
 * catálogo. A distinção é a tela inteira: aqui NÃO se cria item, se põe item à venda. Item
 * sem oferta não aparece na loja do jogo; oferta sem item não existe (o JOIN a esconde).
 *
 * O QUE ESTA TELA MEXE NO JOGO, EM UMA FRASE POR CAMPO
 *   category   -> a ABA da loja em que o produto aparece (app/Views/pages/loja.php)
 *   featured   -> a aba DESTAQUES não tem conteúdo próprio: ela É o filtro deste campo
 *   sort_order -> a posição dentro da aba
 *   price + discount + currency -> o botão COMPRAR e quem consegue apertá-lo
 *
 * Por isso toda escrita aqui é auditada e toda recusa explica o efeito no jogador, não a
 * regra do banco: "a aba EQUIPAMENTO fica vazia" diz mais do que "violaria uma restrição".
 *
 * TRÊS ESCRITAS, UMA ROTA. As rotas registradas são /salvar e /excluir. `salvar` atende o
 * formulário completo e mais duas ações de UMA tecla, que são o trabalho diário de quem
 * arruma vitrine: alternar o destaque e mover a oferta na aba. Abrir um formulário inteiro
 * para trocar um booleano seria pedir seis cliques para uma decisão de um.
 */
final class ShopController extends AdminController
{
    /** Ações que POST /admin/loja/salvar aceita em `acao`. */
    private const ACOES = ['formulario', 'destaque', 'mover'];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        return $this->tela($request);
    }

    /**
     * POST /admin/loja/salvar — cria/edita a oferta, ou aplica uma das ações rápidas.
     *
     * O exigirAdmin() aqui não é estilo: render() protege quem DESENHA, mas esta ação grava
     * antes de desenhar. Sem esta linha, um POST sem sessão escreveria no banco.
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $acao = (string)$request->input('acao', 'formulario');
        if (!in_array($acao, self::ACOES, true)) {
            $acao = 'formulario';
        }

        return match ($acao) {
            'destaque' => $this->alternarDestaque($request),
            'mover'    => $this->mover($request),
            default    => $this->gravarFormulario($request),
        };
    }

    /**
     * POST /admin/loja/excluir — só executa com a confirmação vinda da tela de confirmação,
     * e só quando a loja do jogo sobrevive à remoção.
     */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $txt    = data_mock('admin-loja');
        $id     = (int)$request->input('id', '0');
        $oferta = ShopOfferRepo::porId($id);

        if ($oferta === null) {
            flash('error', (string)($txt['erros']['nao_encontrada'] ?? 'Oferta não encontrada.'));
            return $this->voltar($request);
        }

        // Confirmação é do SERVIDOR, não do navegador: um POST forjado (ou um clique repetido
        // no histórico) sem este campo não apaga nada. A tela de confirmação é quem o envia.
        if ((string)$request->input('confirmar', '') !== 'sim') {
            return $this->voltar($request, ['excluir' => (string)$oferta['id']]);
        }

        if ($motivo = $this->motivoParaNaoExcluir($oferta)) {
            // Recusa também vira linha de auditoria: saber o que o painel IMPEDIU vale tanto
            // quanto saber o que ele fez — é assim que se descobre uma regra errada.
            $this->auditar('loja_excluir_bloqueado', 'shop_offer', $oferta['id'],
                'Exclusão recusada: ' . $oferta['item_nome'] . ' em ' . $oferta['category'] . '.',
                ['motivo' => $motivo]);
            flash('error', $motivo);
            return $this->voltar($request);
        }

        ShopOfferRepo::apagar((int)$oferta['id']);

        $this->auditar('loja_excluir', 'shop_offer', $oferta['id'],
            'Oferta removida: ' . $oferta['item_nome'] . ' (' . $oferta['category'] . ').',
            [
                'item_id'  => $oferta['item_id'],
                'preco'    => $oferta['price'],
                'moeda'    => $oferta['currency'],
                'desconto' => $oferta['discount'],
                'destaque' => $oferta['featured'],
            ]);

        flash('ok', sprintf((string)($txt['ok']['excluida'] ?? '%s removida.'), $oferta['item_nome']));
        return $this->voltar($request);
    }

    // ================================================================== escrita: formulário

    /** Cria ou edita a oferta a partir do formulário completo. */
    private function gravarFormulario(Request $request): Response
    {
        $txt = data_mock('admin-loja');
        $id  = (int)$request->input('id', '0');

        $atual = $id > 0 ? ShopOfferRepo::porId($id) : null;
        if ($id > 0 && $atual === null) {
            flash('error', (string)($txt['erros']['nao_encontrada'] ?? 'Oferta não encontrada.'));
            return $this->voltar($request);
        }

        [$dados, $erros, $bruto] = $this->validar($request, $txt, $atual);

        if ($erros !== []) {
            // 422 e a MESMA tela, com o formulário aberto e o que foi digitado no lugar:
            // redirecionar aqui devolveria uma tela limpa e o operador perderia o trabalho.
            return $this->tela($request, [
                'form'        => $bruto,
                'form_erros'  => $erros,
                'form_aberto' => true,
                'form_id'     => $id,
            ], 422);
        }

        $item = ShopOfferRepo::item((int)$dados['item_id']) ?? [];
        $nome = (string)($item['name'] ?? 'Item');
        $cat  = $this->rotuloCategoria($txt, (string)$dados['category']);

        if ($atual === null) {
            $novoId = ShopOfferRepo::criar($dados);
            $this->auditar('loja_criar', 'shop_offer', $novoId,
                'Oferta publicada: ' . $nome . ' (' . $dados['category'] . ').', $dados);
            flash('ok', sprintf((string)($txt['ok']['criada'] ?? '%s publicada em %s.'), $nome, $cat));
            return $this->voltar($request, ['destaque_id' => (string)$novoId]);
        }

        ShopOfferRepo::atualizar($id, $dados);
        $this->auditar('loja_editar', 'shop_offer', $id,
            'Oferta atualizada: ' . $nome . ' (' . $dados['category'] . ').',
            // O diff, e não só o estado final: é o que responde "quem baixou este preço?".
            ['antes' => $this->instantanea($atual), 'depois' => $dados]);
        flash('ok', sprintf((string)($txt['ok']['atualizada'] ?? '%s atualizada.'), $nome));
        return $this->voltar($request, ['destaque_id' => (string)$id]);
    }

    /**
     * Validação — no SERVIDOR, campo a campo, com a mensagem colada no campo.
     *
     * Devolve [dados prontos para o repo, erros por campo, o que foi digitado].
     * O terceiro item é o que repopula o formulário: o operador não redigita nada.
     *
     * @param array<string,mixed> $txt @param array<string,mixed>|null $atual
     * @return array{0:array<string,mixed>, 1:array<string,string>, 2:array<string,mixed>}
     */
    private function validar(Request $request, array $txt, ?array $atual): array
    {
        $msg  = (array)($txt['erros'] ?? []);
        $erros = [];

        $itemId    = (int)$request->input('item_id', '0');
        $categoria = (string)$request->input('category', '');
        $precoTxt  = (string)$request->input('price', '');
        $moeda     = (string)$request->input('currency', '');
        $descTxt   = (string)$request->input('discount', '');
        $tag       = (string)$request->input('tag', '');
        $ordemTxt  = (string)$request->input('sort_order', '');
        $destaque  = $request->input('featured', '') !== '';

        // ---- item -----------------------------------------------------------------------
        $item = ShopOfferRepo::item($itemId);
        if ($itemId <= 0) {
            $erros['item_id'] = (string)($msg['item_vazio'] ?? 'Escolha o item.');
        } elseif ($item === null) {
            $erros['item_id'] = (string)($msg['item_inexistente'] ?? 'Item inexistente.');
        }

        // ---- categoria ------------------------------------------------------------------
        $abas = array_keys((array)($txt['categorias'] ?? []));
        if ($categoria === 'destaques') {
            // Erro clássico de quem olha a loja pela frente: DESTAQUES é uma aba visível, mas
            // não é um valor de category — é o filtro de `featured`.
            $erros['category'] = (string)($msg['categoria_destaque'] ?? 'Use o campo de destaque.');
        } elseif (!in_array($categoria, $abas, true)) {
            $erros['category'] = (string)($msg['categoria'] ?? 'Categoria inválida.');
        }

        // ---- duplicidade (só quando item e aba são ambos válidos) ------------------------
        if (!isset($erros['item_id'], $erros['category'])
            && $item !== null && in_array($categoria, $abas, true)
            && ShopOfferRepo::categoriaDoDuplicado($itemId, $categoria, $atual !== null ? (int)$atual['id'] : 0)
        ) {
            $erros['item_id'] = sprintf(
                (string)($msg['item_duplicado'] ?? 'Item já à venda em %s.'),
                $this->rotuloCategoria($txt, $categoria)
            );
        }

        // ---- moeda ----------------------------------------------------------------------
        if (!in_array($moeda, ShopOfferRepo::MOEDAS, true)) {
            $erros['currency'] = (string)($msg['moeda'] ?? 'Moeda inválida.');
        }

        // ---- preço ----------------------------------------------------------------------
        // Vazio herda o preço de catálogo do item: é o número que o designer do item já
        // decidiu, e digitar de novo é só chance de errar. Item sem preço base não tem o que
        // herdar, e aí o campo é obrigatório de verdade.
        $preco = 0;
        if ($precoTxt === '') {
            $base = (int)($item['base_price'] ?? 0);
            if ($base > 0) {
                $preco = $base;
            } else {
                $erros['price'] = (string)($msg['preco_vazio'] ?? 'Informe o preço.');
            }
        } elseif (!ctype_digit($precoTxt) || (int)$precoTxt < 1 || (int)$precoTxt > ShopOfferRepo::PRECO_MAX) {
            $erros['price'] = sprintf(
                (string)($msg['preco_invalido'] ?? 'Preço inválido (máx. %s).'),
                num(ShopOfferRepo::PRECO_MAX)
            );
        } else {
            $preco = (int)$precoTxt;
        }

        // ---- desconto -------------------------------------------------------------------
        $desconto = null;
        if ($descTxt !== '' && $descTxt !== '0') {
            if (!ctype_digit($descTxt) || (int)$descTxt > ShopOfferRepo::DESCONTO_MAX) {
                $erros['discount'] = sprintf(
                    (string)($msg['desconto'] ?? 'Desconto de 0 a %s%%.'),
                    (string)ShopOfferRepo::DESCONTO_MAX
                );
            } else {
                $desconto = (int)$descTxt;
            }
        }

        // ---- etiqueta -------------------------------------------------------------------
        // mb_strlen, e não strlen: "ÉPICO" tem 5 caracteres e 6 bytes — cortar por byte
        // recusaria uma etiqueta que cabe e cortaria um acento no meio.
        if (mb_strlen($tag) > ShopOfferRepo::TAG_MAX) {
            $erros['tag'] = sprintf(
                (string)($msg['tag'] ?? 'Máximo de %s caracteres.'),
                (string)ShopOfferRepo::TAG_MAX
            );
        }

        // ---- ordem ----------------------------------------------------------------------
        $ordem = 0;
        if ($ordemTxt === '') {
            // Criar sem dizer a ordem põe no fim da aba; editar sem dizer mantém onde está.
            $ordem = $atual !== null
                ? (int)$atual['sort_order']
                : ShopOfferRepo::proximaOrdem(in_array($categoria, $abas, true) ? $categoria : '');
        } elseif (!ctype_digit($ordemTxt) || (int)$ordemTxt > ShopOfferRepo::ORDEM_MAX) {
            $erros['sort_order'] = sprintf(
                (string)($msg['ordem'] ?? 'Ordem de 0 a %s.'),
                num(ShopOfferRepo::ORDEM_MAX)
            );
        } else {
            $ordem = (int)$ordemTxt;
        }

        $bruto = [
            'id'         => $atual !== null ? (int)$atual['id'] : 0,
            'item_id'    => $itemId,
            'category'   => $categoria,
            'price'      => $precoTxt,
            'currency'   => $moeda,
            'discount'   => $descTxt,
            'tag'        => $tag,
            'sort_order' => $ordemTxt,
            'featured'   => $destaque,
        ];

        return [[
            'item_id'    => $itemId,
            'category'   => $categoria,
            'price'      => $preco,
            'currency'   => $moeda,
            'discount'   => $desconto,
            'tag'        => $tag,
            'featured'   => $destaque,
            'sort_order' => $ordem,
        ], $erros, $bruto];
    }

    // ============================================================== escrita: ações rápidas

    /** Põe ou tira a oferta da aba DESTAQUES, sem abrir formulário. */
    private function alternarDestaque(Request $request): Response
    {
        $txt    = data_mock('admin-loja');
        $oferta = ShopOfferRepo::porId((int)$request->input('id', '0'));

        if ($oferta === null) {
            flash('error', (string)($txt['erros']['nao_encontrada'] ?? 'Oferta não encontrada.'));
            return $this->voltar($request);
        }

        $novo = !$oferta['featured'];
        ShopOfferRepo::definirDestaque((int)$oferta['id'], $novo);

        $this->auditar('loja_destaque', 'shop_offer', $oferta['id'],
            ($novo ? 'Destaque ligado: ' : 'Destaque desligado: ') . $oferta['item_nome'] . '.',
            ['antes' => $oferta['featured'], 'depois' => $novo]);

        flash('ok', sprintf(
            (string)($txt['ok'][$novo ? 'destacada' : 'tirada'] ?? '%s alterada.'),
            $oferta['item_nome']
        ));
        return $this->voltar($request, ['destaque_id' => (string)$oferta['id']]);
    }

    /**
     * Sobe ou desce a oferta uma posição DENTRO da própria aba.
     *
     * A troca é com a vizinha imediata, e não uma renumeração da aba inteira: renumerar
     * reescreveria linhas que ninguém pediu para mexer e encheria a auditoria de ruído.
     */
    private function mover(Request $request): Response
    {
        $txt     = data_mock('admin-loja');
        $oferta  = ShopOfferRepo::porId((int)$request->input('id', '0'));
        $direcao = (string)$request->input('dir', '') === 'cima' ? 'cima' : 'baixo';

        if ($oferta === null) {
            flash('error', (string)($txt['erros']['nao_encontrada'] ?? 'Oferta não encontrada.'));
            return $this->voltar($request);
        }

        $vizinha = ShopOfferRepo::vizinha($oferta, $direcao);
        if ($vizinha === null) {
            // Já é a primeira (ou a última) da aba: nada a fazer e nada a dizer em tom de
            // erro — o botão da ponta vem desabilitado justamente por isso.
            return $this->voltar($request, ['destaque_id' => (string)$oferta['id']]);
        }

        $ordemA = (int)$oferta['sort_order'];
        $ordemB = (int)$vizinha['sort_order'];
        if ($ordemA === $ordemB) {
            // Empate de sort_order: a vitrine desempata por id, então trocar os números não
            // moveria nada. Um dos dois recebe um número real para a ordem virar explícita.
            $ordemA = $direcao === 'cima' ? max(0, $ordemB) : $ordemB;
            $ordemB = $direcao === 'cima' ? $ordemB + 1 : max(0, $ordemB - 1);
        }

        ShopOfferRepo::trocarOrdem((int)$oferta['id'], $ordemA, (int)$vizinha['id'], $ordemB);

        $atualizada = ShopOfferRepo::porId((int)$oferta['id']);
        $posicao    = $atualizada !== null ? ShopOfferRepo::posicaoNaAba($atualizada) : 0;

        $this->auditar('loja_ordenar', 'shop_offer', $oferta['id'],
            'Ordem alterada: ' . $oferta['item_nome'] . ' (' . $oferta['category'] . ').',
            ['direcao' => $direcao, 'trocou_com' => (int)$vizinha['id'], 'posicao' => $posicao]);

        flash('ok', sprintf(
            (string)($txt['ok']['movida'] ?? '%s agora é a %sª de %s.'),
            $oferta['item_nome'],
            (string)$posicao,
            $this->rotuloCategoria($txt, (string)$oferta['category'])
        ));
        return $this->voltar($request, ['destaque_id' => (string)$oferta['id']]);
    }

    // ============================================================================== leitura

    /**
     * Desenha a tela. É um método só porque o GET e o POST-que-falhou mostram exatamente a
     * mesma coisa — o segundo apenas com o formulário aberto e os erros no lugar.
     *
     * @param array<string,mixed> $over
     */
    private function tela(Request $request, array $over = [], int $status = 200): Response
    {
        $txt = data_mock('admin-loja');

        $filtros = $this->filtros($request);
        $lista   = ShopOfferRepo::listar($filtros);
        $resumo  = ShopOfferRepo::resumo();
        $porAba  = ShopOfferRepo::contagemPorCategoria();

        // Qual painel de escrita está aberto. São exclusivos: confirmar uma exclusão com um
        // formulário de edição aberto ao lado é o tipo de tela em que se clica no botão errado.
        $editarId  = (int)($over['form_id'] ?? $request->query('editar', '0'));
        $excluirId = (int)$request->query('excluir', '0');
        $novo      = $request->query('novo') !== null;

        $emEdicao = $editarId > 0 ? ShopOfferRepo::porId($editarId) : null;
        $aExcluir = $excluirId > 0 && $editarId <= 0 ? ShopOfferRepo::porId($excluirId) : null;

        $formAberto = !empty($over['form_aberto']) || $novo || $emEdicao !== null;
        $form       = (array)($over['form'] ?? $this->formDe($emEdicao));

        $data = [
            'txt'          => $txt,
            'filtros'      => $filtros,
            'query'        => $this->queryLimpa($filtros),
            'lista'        => $lista,
            'resumo'       => $resumo,
            'por_aba'      => $porAba,
            'itens'        => ShopOfferRepo::itensDoCatalogo(),

            'form'         => $form,
            'form_erros'   => (array)($over['form_erros'] ?? []),
            'form_aberto'  => $formAberto,
            'form_edicao'  => $emEdicao,

            'excluir'      => $aExcluir,
            'excluir_bloqueio' => $aExcluir !== null ? $this->motivoParaNaoExcluir($aExcluir) : null,
            'excluir_compras'  => $aExcluir !== null ? ShopOfferRepo::comprasDoItem((int)$aExcluir['item_id']) : null,

            // Linha recém-escrita: a tabela a acende por um instante para o operador achar
            // o que ele acabou de mexer sem reler a lista inteira.
            'destaque_id'  => (int)$request->query('destaque_id', '0'),

            'limites'      => [
                'desconto' => ShopOfferRepo::DESCONTO_MAX,
                'tag'      => ShopOfferRepo::TAG_MAX,
                'ordem'    => ShopOfferRepo::ORDEM_MAX,
                'preco'    => ShopOfferRepo::PRECO_MAX,
                'nichos'   => ShopOfferRepo::NICHOS_VITRINE,
            ],

            'admin_kpis'   => $this->kpisDaTopbar($resumo),
        ];

        $page = $this->adminPage('loja', 'Loja', 'Ofertas, preços e destaques da vitrine do jogo');

        return $this->render($page['slug'], $page, $data, 'admin', $status);
    }

    /**
     * Filtros vindos da query string, já normalizados. O que não estiver na lista branca é
     * descartado em silêncio: a URL não define consulta, ela só escolhe entre o que existe.
     *
     * @return array<string,mixed>
     */
    private function filtros(Request $request): array
    {
        $txt  = data_mock('admin-loja');
        $abas = array_keys((array)($txt['categorias'] ?? []));

        $cat = (string)$request->query('cat', '');
        $moe = (string)$request->query('moeda', '');
        $est = (string)$request->query('estado', '');
        $ord = (string)$request->query('ord', '');
        $dir = (string)$request->query('dir', '');

        return [
            // A ordem das abas viaja com o filtro porque é ela que ordena a listagem: a tabela
            // tem de sair na MESMA sequência da fileira de abas logo acima dela.
            'abas'   => $abas,
            'q'      => mb_substr((string)$request->query('q', ''), 0, 60),
            'cat'    => in_array($cat, $abas, true) ? $cat : '',
            'moeda'  => in_array($moe, ShopOfferRepo::MOEDAS, true) ? $moe : '',
            'estado' => isset(((array)($txt['estados'] ?? []))[$est]) ? $est : '',
            'ord'    => $ord !== '' ? $ord : 'ordem',
            'dir'    => strtolower($dir) === 'desc' ? 'desc' : 'asc',
            'pag'    => max(1, (int)$request->query('pag', '1')),
        ];
    }

    /**
     * Os filtros que precisam viajar em cada link e em cada formulário da tela — sem os
     * vazios, para a URL não virar uma tira de `&x=&y=`.
     *
     * @param array<string,mixed> $f @return array<string,string>
     */
    private function queryLimpa(array $f): array
    {
        $q = [];
        foreach (['q', 'cat', 'moeda', 'estado'] as $k) {
            if ((string)$f[$k] !== '') {
                $q[$k] = (string)$f[$k];
            }
        }
        if ($f['ord'] !== 'ordem' || $f['dir'] !== 'asc') {
            $q['ord'] = (string)$f['ord'];
            $q['dir'] = (string)$f['dir'];
        }
        if ((int)$f['pag'] > 1) {
            $q['pag'] = (string)$f['pag'];
        }
        return $q;
    }

    /**
     * A ÚNICA recusa de exclusão desta tela, e o motivo dela ser esta.
     *
     * Nenhuma tabela referencia shop_offers.id: `player_purchases` guarda item_id, então
     * apagar a oferta não deixa órfão nenhum e não apaga histórico de compra. Inventar um
     * bloqueio por "já foi comprado" seria proteger o banco de um perigo que não existe.
     *
     * O que QUEBRA de verdade é a tela do jogador: cada aba de app/Data/loja.php é desenhada
     * mesmo sem conteúdo, e uma aba sem oferta vira uma vitrine vazia com doze nichos
     * trancados. Isso não tem plano B no código do jogo — por isso a última oferta de uma aba
     * não sai por acidente.
     *
     * @param array<string,mixed> $oferta
     */
    private function motivoParaNaoExcluir(array $oferta): ?string
    {
        $txt  = data_mock('admin-loja');
        $abas = (array)($txt['categorias'] ?? []);
        $cat  = (string)$oferta['category'];

        if (!isset($abas[$cat])) {
            // Categoria que não é aba da loja (dado antigo): some sem consequência visível.
            return null;
        }
        if ((ShopOfferRepo::contagemPorCategoria()[$cat] ?? 0) > 1) {
            return null;
        }

        $rotulo = (string)$abas[$cat];
        return sprintf(
            (string)($txt['bloqueios']['ultima_da_aba'] ?? 'Única oferta de %s (%s).'),
            $rotulo,
            mb_strtoupper($rotulo)
        );
    }

    /**
     * Os três indicadores compactos da topbar. Todos saem da MESMA consulta que alimenta a
     * faixa de KPIs da tela — dois números diferentes para "ofertas publicadas" na mesma
     * janela seria o defeito mais caro possível num painel.
     *
     * @param array<string,mixed> $r @return list<array<string,mixed>>
     */
    private function kpisDaTopbar(array $r): array
    {
        return [
            ['icon' => 'sell',      'label' => 'Ofertas publicadas', 'value' => num((int)$r['ofertas']),  'tone' => 'lime'],
            ['icon' => 'star',      'label' => 'Em destaque',        'value' => num((int)$r['destaque']), 'tone' => 'gold'],
            [
                'icon'  => 'percent',
                'label' => 'Com desconto',
                'value' => num((int)$r['promo']),
                'sub'   => (int)$r['desconto_medio'] > 0 ? 'méd. ' . (int)$r['desconto_medio'] . '%' : null,
                'tone'  => (int)$r['promo'] > 0 ? 'amber' : 'cyan',
            ],
        ];
    }

    /**
     * Estado inicial do formulário. Sem oferta em edição, os padrões são os que um operador
     * escolheria de qualquer forma — moeda de crédito e sem desconto.
     *
     * @param array<string,mixed>|null $oferta @return array<string,mixed>
     */
    private function formDe(?array $oferta): array
    {
        if ($oferta === null) {
            return [
                'id' => 0, 'item_id' => 0, 'category' => '', 'price' => '',
                'currency' => 'credits', 'discount' => '', 'tag' => '',
                'sort_order' => '', 'featured' => false,
            ];
        }
        return [
            'id'         => (int)$oferta['id'],
            'item_id'    => (int)$oferta['item_id'],
            'category'   => (string)$oferta['category'],
            'price'      => (string)$oferta['price'],
            'currency'   => (string)$oferta['currency'],
            'discount'   => $oferta['discount'] === null ? '' : (string)$oferta['discount'],
            'tag'        => (string)$oferta['tag'],
            'sort_order' => (string)$oferta['sort_order'],
            'featured'   => (bool)$oferta['featured'],
        ];
    }

    /** O que guardar como "antes" na auditoria de uma edição. @param array<string,mixed> $o */
    private function instantanea(array $o): array
    {
        return [
            'item_id'    => $o['item_id'],
            'category'   => $o['category'],
            'price'      => $o['price'],
            'currency'   => $o['currency'],
            'discount'   => $o['discount'],
            'tag'        => $o['tag'],
            'featured'   => $o['featured'],
            'sort_order' => $o['sort_order'],
        ];
    }

    /** @param array<string,mixed> $txt */
    private function rotuloCategoria(array $txt, string $slug): string
    {
        return (string)(((array)($txt['categorias'] ?? []))[$slug] ?? $slug);
    }

    /**
     * Volta para a listagem com os MESMOS filtros de onde o operador saiu.
     *
     * Sem isto, salvar uma oferta encontrada por busca devolvia a lista inteira na página 1 —
     * e quem estava arrumando a terceira página tinha de refazer o caminho a cada linha.
     *
     * Os filtros viajam na QUERY STRING do `action` de cada formulário, e não em campos
     * ocultos: a tela tem até 37 formulários (três por linha, mais o de busca), e repetir seis
     * <input hidden> em cada um deles são duzentas linhas de HTML que dizem o que a URL já diz.
     * Request lê $_GET em qualquer método, então o POST enxerga os mesmos filtros do GET.
     *
     * @param array<string,string> $extra
     */
    private function voltar(Request $request, array $extra = []): Response
    {
        return $this->redirect('admin/loja', $this->queryLimpa($this->filtros($request)) + $extra);
    }
}
