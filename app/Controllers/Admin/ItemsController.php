<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\ItemsRepo;

/**
 * Tela Itens do painel: /admin/itens. Governa a tabela `items`.
 *
 * O controller é a POLÍTICA da tela — o que é um valor aceitável, o que pode ser excluído e
 * o que precisa ser registrado. O SQL está em App\Repo\ItemsRepo, os rótulos em
 * app/Data/admin-itens.php e o desenho em app/Views/pages/admin-itens.php.
 *
 * DUAS DECISÕES QUE EXPLICAM O RESTO DO ARQUIVO:
 *
 * 1. VALIDAÇÃO NO SERVIDOR, SEMPRE, A PARTIR DE UM ESQUEMA FIXO (self::CAMPOS).
 *    O array gravado no banco é montado campo a campo a partir dessa constante, nunca de
 *    $_POST inteiro. Um campo extra injetado no formulário não tem para onde ir, e nenhuma
 *    coluna que a tela não governa (por exemplo um `id` forjado) chega ao UPDATE.
 *
 * 2. EXCLUSÃO PRECISA PROVAR QUE NÃO DESTRÓI NADA.
 *    `player_items.item_id` e `shop_offers.item_id` apagam EM CASCATA. Excluir um item
 *    possuído esvaziaria a mochila de jogadores reais sem uma linha de aviso. Por isso a
 *    exclusão tem duas etapas — uma tela de confirmação que MOSTRA os números medidos, e um
 *    POST que reconfere esses números antes de apagar. A confirmação da primeira etapa não é
 *    aceita como prova: entre ver a tela e clicar, alguém pode ter comprado o item.
 *
 * Erro de formulário não perde o que o operador digitou: os valores e as mensagens por campo
 * vão para a sessão e voltam no GET seguinte (padrão POST/Redirect/GET) — recarregar a página
 * depois de salvar nunca reenvia o formulário.
 */
final class ItemsController extends AdminController
{
    /** Chave de sessão que carrega erros + valores digitados de um POST recusado. */
    private const SESSAO_FORM = 'adm_itens_form';

    /**
     * O contrato de escrita da tela: coluna => tipo. É esta lista, e só ela, que define quais
     * colunas de `items` o painel escreve.
     *
     * texto   = string com limite | inteiro = int com faixa | decimal | bool | json
     * As faixas espelham o tipo da coluna no MySQL (TINYINT UNSIGNED = 255, SMALLINT = 65535):
     * validar aqui é o que transforma "valor fora da faixa" numa mensagem em português em vez
     * de um erro 500 vindo do driver.
     */
    private const CAMPOS = [
        'slug'           => ['tipo' => 'texto',   'max' => 64,  'obrigatorio' => true],
        'name'           => ['tipo' => 'texto',   'max' => 120, 'obrigatorio' => true],
        'short_name'     => ['tipo' => 'texto',   'max' => 32],
        'category'       => ['tipo' => 'texto',   'max' => 24,  'obrigatorio' => true],
        'subtype'        => ['tipo' => 'texto',   'max' => 32],
        'slot_type'      => ['tipo' => 'texto',   'max' => 24],
        'grid_w'         => ['tipo' => 'inteiro', 'min' => 1, 'max' => 20,  'obrigatorio' => true],
        'grid_h'         => ['tipo' => 'inteiro', 'min' => 1, 'max' => 20,  'obrigatorio' => true],
        'weight_kg'      => ['tipo' => 'decimal', 'min' => 0, 'max' => 999.999, 'obrigatorio' => true],
        'rarity'         => ['tipo' => 'texto',   'max' => 16,  'obrigatorio' => true],
        'image'          => ['tipo' => 'texto',   'max' => 160, 'obrigatorio' => true],
        'description'    => ['tipo' => 'texto',   'max' => 400],
        'base_price'     => ['tipo' => 'inteiro', 'min' => 0, 'max' => 2000000000, 'obrigatorio' => true],
        'currency'       => ['tipo' => 'texto',   'max' => 12,  'obrigatorio' => true],
        'stackable'      => ['tipo' => 'bool'],
        'max_stack'      => ['tipo' => 'inteiro', 'min' => 1, 'max' => 65535, 'obrigatorio' => true],
        'durability_max' => ['tipo' => 'inteiro', 'min' => 1, 'max' => 65535],
        'grid_cols'      => ['tipo' => 'inteiro', 'min' => 1, 'max' => 255],
        'grid_rows'      => ['tipo' => 'inteiro', 'min' => 1, 'max' => 255],
        'ammo_caliber'   => ['tipo' => 'texto',   'max' => 24],
        'mag_capacity'   => ['tipo' => 'inteiro', 'min' => 1, 'max' => 65535],
        'attach_slots'   => ['tipo' => 'json'],
        'fits_calibers'  => ['tipo' => 'json'],
        'meta'           => ['tipo' => 'json'],
    ];

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        $cromo   = data_mock('admin-itens');
        $filtros = $this->filtros($request);
        // `p` é o nome curto na URL; o repo pagina por `pagina`. A tradução acontece aqui e em
        // nenhum outro lugar, para o array de filtros continuar servindo direto como query string.
        $lista   = ItemsRepo::listar($filtros + ['pagina' => (int)($filtros['p'] ?? 1)]);

        // Vocabulário oferecido = o que a tela conhece MAIS o que já existe no banco. Um
        // `category` gravado por um seed antigo e ausente do arquivo de dados continua
        // selecionável e filtrável; esconder o valor faria a tela mentir sobre o catálogo.
        $catsBanco = ItemsRepo::categoriasUsadas();
        $rarsBanco = ItemsRepo::raridadesUsadas();

        // Formulário: ?form=novo abre em branco, ?form=<id> abre preenchido. O que voltou de
        // um POST recusado vence o banco — senão a correção apagaria o que foi digitado.
        $devolvido = $this->consumirDevolucao();
        $edicao    = $this->itemDoFormulario($request, $devolvido);

        // Exclusão: ?excluir=<id> é só a tela de confirmação. Quem apaga é o POST.
        $excluir = null;
        $alvoId  = (int)($request->query('excluir') ?? 0);
        if ($alvoId > 0 && ($alvo = ItemsRepo::porId($alvoId)) !== null) {
            $excluir = ['item' => $alvo, 'usos' => ItemsRepo::usos($alvoId)];
        }

        $page = $this->adminPage('itens', 'Itens', 'Catálogo, raridade e atributos');
        $page['actions'] = '<a class="adm-btn adm-btn--primary" href="'
            . e(url('admin/itens', $filtros + ['form' => 'novo'])) . '#adm-form">'
            . icon('add') . '<span>Novo item</span></a>';

        return $this->render($page['slug'], $page, [
            'cromo'       => $cromo,
            'admin_conta' => $this->admin(),

            'resumo'      => ItemsRepo::resumo(),
            'linhas'      => $lista['linhas'],
            'total'       => $lista['total'],
            'pagina'      => $lista['pagina'],
            'paginas'     => $lista['paginas'],
            'por_pagina'  => ItemsRepo::POR_PAGINA,

            'filtros'     => $filtros,
            'tem_filtro'  => ($filtros['q'] ?? '') !== '' || ($filtros['cat'] ?? '') !== '' || ($filtros['rar'] ?? '') !== '',
            'cats_banco'  => $catsBanco,
            'rars_banco'  => $rarsBanco,

            'edicao'      => $edicao,
            'erros'       => (array)($devolvido['erros'] ?? []),
            'excluir'     => $excluir,
        ]);
    }

    /** POST /admin/itens/salvar — cria (id vazio) ou edita (id existente). */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $filtros = $this->filtrosDoPost($request);
        $id      = (int)($request->input('id') ?? 0);
        $atual   = $id > 0 ? ItemsRepo::porId($id) : null;

        if ($id > 0 && $atual === null) {
            // O item sumiu entre abrir o formulário e salvar (outro operador excluiu).
            flash('error', 'Este item não existe mais — ele foi excluído enquanto o formulário estava aberto.');
            return $this->redirect('admin/itens', $filtros);
        }

        [$dados, $erros] = $this->validar($request, $atual);

        if ($erros !== []) {
            $this->devolver($erros, $this->valoresCrus($request), $id);
            flash('error', count($erros) === 1
                ? 'Um campo precisa de correção — a mensagem está abaixo dele.'
                : count($erros) . ' campos precisam de correção — as mensagens estão abaixo deles.');
            return $this->redirect('admin/itens', $filtros + ['form' => $id > 0 ? (string)$id : 'novo']);
        }

        if ($atual === null) {
            $novoId = ItemsRepo::criar($dados);
            $this->auditar('item_criado', 'item', $novoId, "Item criado: {$dados['name']} ({$dados['slug']}).", [
                'slug'     => $dados['slug'],
                'category' => $dados['category'],
                'rarity'   => $dados['rarity'],
                'price'    => $dados['base_price'] . ' ' . $dados['currency'],
            ]);
            flash('ok', "Item “{$dados['name']}” cadastrado.");
            return $this->redirect('admin/itens', $filtros);
        }

        // Só o que MUDOU vai para o UPDATE e para a auditoria: um log que repete 24 colunas
        // iguais a cada salvamento é um log que ninguém lê depois.
        $mudou = $this->diferenca($atual, $dados);
        if ($mudou === []) {
            flash('info', 'Nada foi alterado — os valores enviados são iguais aos que já estavam gravados.');
            return $this->redirect('admin/itens', $filtros);
        }

        ItemsRepo::atualizar($id, array_intersect_key($dados, $mudou));
        $this->auditar('item_editado', 'item', $id, "Item editado: {$dados['name']} ({$dados['slug']}).", [
            'campos'   => array_keys($mudou),
            'anterior' => array_map(static fn(array $d): mixed => $d['de'], $mudou),
            'novo'     => array_map(static fn(array $d): mixed => $d['para'], $mudou),
        ]);
        flash('ok', "Item “{$dados['name']}” atualizado — " . count($mudou) . ' campo(s) alterado(s).');

        return $this->redirect('admin/itens', $filtros);
    }

    /** POST /admin/itens/excluir — só executa quando NADA referencia o item. */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $filtros = $this->filtrosDoPost($request);
        $id      = (int)($request->input('id') ?? 0);
        $item    = ItemsRepo::porId($id);

        if ($item === null) {
            flash('error', 'Item não encontrado — talvez já tenha sido excluído.');
            return $this->redirect('admin/itens', $filtros);
        }

        // Reconferência: os números da tela de confirmação são de ANTES do clique.
        $usos = ItemsRepo::usos($id);
        if ($usos['total'] > 0) {
            $this->auditar('item_exclusao_bloqueada', 'item', $id,
                "Exclusão bloqueada: {$item['name']} ainda é referenciado.", $usos);
            flash('error', 'Não dá para excluir “' . $item['name'] . '”: ' . $this->motivoDoBloqueio($usos));
            return $this->redirect('admin/itens', $filtros + ['excluir' => (string)$id]);
        }

        ItemsRepo::excluir($id);
        // A linha inteira vai para o log: depois do DELETE, a auditoria é a única cópia que resta.
        $this->auditar('item_excluido', 'item', $id, "Item excluído: {$item['name']} ({$item['slug']}).", $item);
        flash('ok', "Item “{$item['name']}” excluído do catálogo.");

        return $this->redirect('admin/itens', $filtros);
    }

    /* ====================================================================== validação */

    /**
     * Converte o POST em linha de banco. Devolve [dados, erros] — dados só vale quando erros
     * está vazio.
     *
     * @param array<string,mixed>|null $atual linha atual, numa edição
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    private function validar(Request $request, ?array $atual): array
    {
        $cromo  = data_mock('admin-itens');
        $dados  = [];
        $erros  = [];

        foreach (self::CAMPOS as $campo => $regra) {
            $cru = trim((string)($request->input($campo) ?? ''));

            // Checkbox não enviado = desmarcado; não é "campo vazio".
            if ($regra['tipo'] === 'bool') {
                $dados[$campo] = $cru !== '' && $cru !== '0' ? 1 : 0;
                continue;
            }

            if ($cru === '') {
                if (!empty($regra['obrigatorio'])) {
                    $erros[$campo] = 'Campo obrigatório.';
                    continue;
                }
                $dados[$campo] = null;   // coluna anulável: vazio é NULL, nunca string vazia
                continue;
            }

            switch ($regra['tipo']) {
                case 'inteiro':
                    if (!preg_match('/^\d{1,10}$/', $cru)) {
                        $erros[$campo] = 'Use apenas números inteiros, sem sinal.';
                        break;
                    }
                    $n = (int)$cru;
                    if ($n < $regra['min'] || $n > $regra['max']) {
                        $erros[$campo] = "Valor fora da faixa aceita ({$regra['min']} a {$regra['max']}).";
                        break;
                    }
                    $dados[$campo] = $n;
                    break;

                case 'decimal':
                    // O teclado brasileiro digita vírgula; a coluna é DECIMAL e quer ponto.
                    $norm = str_replace(',', '.', $cru);
                    if (!preg_match('/^\d{1,4}(\.\d{1,3})?$/', $norm)) {
                        $erros[$campo] = 'Use números com até três casas decimais (ex.: 3,600).';
                        break;
                    }
                    $f = (float)$norm;
                    if ($f < $regra['min'] || $f > $regra['max']) {
                        $erros[$campo] = "Valor fora da faixa aceita ({$regra['min']} a {$regra['max']}).";
                        break;
                    }
                    $dados[$campo] = number_format($f, 3, '.', '');
                    break;

                case 'json':
                    $decodificado = json_decode($cru, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $erros[$campo] = 'JSON inválido: ' . json_last_error_msg() . '.';
                        break;
                    }
                    // Regravado a partir do que foi decodificado: o banco recebe JSON canônico,
                    // e não a formatação (ou o espaço em branco) que o operador colou.
                    $dados[$campo] = json_encode($decodificado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    break;

                default:
                    if (mb_strlen($cru) > $regra['max']) {
                        $erros[$campo] = "Máximo de {$regra['max']} caracteres (enviados: " . mb_strlen($cru) . ').';
                        break;
                    }
                    $dados[$campo] = $cru;
            }
        }

        /* ---- regras que dependem de mais de um campo ---- */

        if (!isset($erros['slug']) && isset($dados['slug'])) {
            $slug = strtolower((string)$dados['slug']);
            if (!preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $slug)) {
                $erros['slug'] = 'Use minúsculas, números e hífen; comece por letra ou número (mínimo 2 caracteres).';
            } elseif (ItemsRepo::slugEmUso($slug, (int)($atual['id'] ?? 0))) {
                $erros['slug'] = 'Já existe outro item com este slug.';
            } else {
                $dados['slug'] = $slug;
            }
        }

        if (!isset($erros['name']) && mb_strlen((string)($dados['name'] ?? '')) < 2) {
            $erros['name'] = 'O nome precisa de pelo menos 2 caracteres.';
        }

        // Categoria/raridade/moeda/slot: o valor precisa ser conhecido OU já existir no banco.
        $this->exigirVocabulario($dados, $erros, 'category', array_keys((array)($cromo['categorias'] ?? [])), array_keys(ItemsRepo::categoriasUsadas()));
        $this->exigirVocabulario($dados, $erros, 'rarity',   array_keys((array)($cromo['raridades'] ?? [])), array_keys(ItemsRepo::raridadesUsadas()));
        $this->exigirVocabulario($dados, $erros, 'currency', array_keys((array)($cromo['moedas'] ?? [])), []);
        $this->exigirVocabulario($dados, $erros, 'slot_type', array_keys((array)($cromo['slots'] ?? [])), []);

        if (!isset($erros['subtype']) && ($dados['subtype'] ?? null) !== null
            && !preg_match('/^[a-z0-9_]+$/', (string)$dados['subtype'])) {
            $erros['subtype'] = 'Use minúsculas, números e sublinhado (ex.: assault_rifle).';
        }

        // O caminho da imagem vira URL em media()/img(). Barra inicial, `..` e espaço abririam
        // caminho para fora da pasta de mídia ou para um link quebrado.
        if (!isset($erros['image']) && isset($dados['image'])
            && !preg_match('#^[a-z0-9][a-z0-9._/-]*\.(png|jpg|jpeg|webp|gif|svg)$#i', (string)$dados['image'])) {
            $erros['image'] = 'Caminho relativo dentro de /media, terminando em .png/.jpg/.webp (ex.: items/ak103.png).';
        }

        // Empilhamento: os dois campos precisam contar a mesma história.
        $empilha = (int)($dados['stackable'] ?? 0) === 1;
        $pilha   = (int)($dados['max_stack'] ?? 1);
        if (!isset($erros['max_stack'])) {
            if ($empilha && $pilha < 2) {
                $erros['max_stack'] = 'Item empilhável precisa de pilha máxima de pelo menos 2.';
            } elseif (!$empilha && $pilha > 1) {
                // Não é erro do operador: é uma correção óbvia, e corrigir calado é pior.
                $dados['max_stack'] = 1;
            }
        }

        // Grade interna de contêiner (rig, mochila): coluna sem linha não descreve grade nenhuma.
        $temCols = ($dados['grid_cols'] ?? null) !== null;
        $temRows = ($dados['grid_rows'] ?? null) !== null;
        if ($temCols !== $temRows) {
            $faltando = $temCols ? 'grid_rows' : 'grid_cols';
            $erros[$faltando] = 'Preencha colunas E linhas da grade interna, ou deixe as duas em branco.';
        }

        // JSON de estrutura: precisa ser lista/objeto, não um número solto gravado como JSON.
        foreach (['attach_slots', 'fits_calibers'] as $campo) {
            if (!isset($erros[$campo]) && ($dados[$campo] ?? null) !== null
                && !is_array(json_decode((string)$dados[$campo], true))) {
                $erros[$campo] = 'Esperado um array JSON (ex.: ["7.62x39"]).';
            }
        }

        return [$dados, $erros];
    }

    /**
     * Um valor de lista fechada. `$extras` traz o que o banco já contém, para uma edição não
     * ser obrigada a reescrever um valor legado só porque ele não está no arquivo de dados.
     *
     * @param array<string,mixed> $dados @param array<string,string> $erros
     * @param list<string> $aceitos @param list<string> $extras
     */
    private function exigirVocabulario(array $dados, array &$erros, string $campo, array $aceitos, array $extras): void
    {
        $valor = $dados[$campo] ?? null;
        if ($valor === null || isset($erros[$campo])) {
            return;
        }
        if (!in_array((string)$valor, array_merge($aceitos, $extras), true)) {
            $erros[$campo] = 'Valor não reconhecido para este campo.';
        }
    }

    /**
     * Colunas realmente alteradas: ['coluna' => ['de' => x, 'para' => y]].
     *
     * A comparação é feita como STRING de propósito. O PDO devolve `weight_kg` como "3.600" e
     * `stackable` como 0/1; o formulário devolve "3,600" já normalizado para "3.600" e 0/1.
     * Comparar frouxo (==) diria que "0" e "" são iguais e esconderia a limpeza de um campo.
     *
     * As colunas JSON são a exceção, e por um motivo medido: o MySQL guarda o valor já
     * normalizado (`{"origem": "qa"}`, com espaço depois dos dois-pontos) enquanto json_encode
     * escreve sem espaço. Comparadas como texto, elas apareceriam alteradas em TODO salvamento
     * — um UPDATE e uma linha de auditoria falsos a cada vez. Por isso o JSON é comparado
     * depois de decodificado, pela estrutura.
     *
     * @param array<string,mixed> $antes @param array<string,mixed> $depois
     * @return array<string,array{de:mixed,para:mixed}>
     */
    private function diferenca(array $antes, array $depois): array
    {
        $mudou = [];
        foreach ($depois as $col => $novo) {
            if (!array_key_exists($col, $antes)) {
                continue;
            }

            if ((self::CAMPOS[$col]['tipo'] ?? '') === 'json') {
                $de   = $antes[$col] === null ? null : json_decode((string)$antes[$col], true);
                $para = $novo === null ? null : json_decode((string)$novo, true);
                if ($de !== $para) {
                    $mudou[$col] = ['de' => $antes[$col], 'para' => $novo];
                }
                continue;
            }

            $de   = $antes[$col] === null ? null : (string)$antes[$col];
            $para = $novo === null ? null : (string)$novo;
            if ($de !== $para) {
                $mudou[$col] = ['de' => $antes[$col], 'para' => $novo];
            }
        }
        return $mudou;
    }

    /** Frase única que explica o bloqueio, na ordem do estrago que a exclusão causaria. */
    private function motivoDoBloqueio(array $usos): string
    {
        $partes = [];
        if ($usos['instancias'] > 0) {
            $partes[] = $usos['instancias'] . ' unidade(s) no inventário de '
                . $usos['jogadores'] . ' jogador(es)';
        }
        if ($usos['ofertas'] > 0)    { $partes[] = $usos['ofertas'] . ' oferta(s) na loja'; }
        if ($usos['missoes'] > 0)    { $partes[] = $usos['missoes'] . ' recompensa(s) de missão'; }
        if ($usos['conquistas'] > 0) { $partes[] = $usos['conquistas'] . ' recompensa(s) de conquista'; }

        return 'ele ainda é referenciado por ' . implode(', ', $partes) . '.';
    }

    /* =================================================================== estado da tela */

    /**
     * Filtros da listagem, saneados. A URL não escolhe consulta: página vira inteiro positivo,
     * ordenação fora da lista vira a padrão, e a busca tem teto de tamanho.
     *
     * @return array<string,string> pronto para voltar como query string em qualquer redirect
     */
    private function filtros(Request $request): array
    {
        $cromo = data_mock('admin-itens');
        $ord   = (string)($request->query('ord') ?? '');
        $q     = mb_substr(trim((string)($request->query('q') ?? '')), 0, 64);

        $f = [];
        if ($q !== '')                                      { $f['q']   = $q; }
        if (($cat = trim((string)($request->query('cat') ?? ''))) !== '') { $f['cat'] = mb_substr($cat, 0, 24); }
        if (($rar = trim((string)($request->query('rar') ?? ''))) !== '') { $f['rar'] = mb_substr($rar, 0, 16); }
        if (isset(((array)($cromo['ordenacoes'] ?? []))[$ord]))           { $f['ord'] = $ord; }

        $p = (int)($request->query('p') ?? 1);
        if ($p > 1) { $f['p'] = (string)$p; }

        return $f;
    }

    /**
     * Os mesmos filtros, mas vindos dos campos ocultos do formulário — é o que faz o operador
     * voltar exatamente para a página e o filtro de onde saiu depois de salvar.
     *
     * @return array<string,string>
     */
    private function filtrosDoPost(Request $request): array
    {
        $f = [];
        foreach (['q', 'cat', 'rar', 'ord', 'p'] as $chave) {
            $v = trim((string)($request->input('f_' . $chave) ?? ''));
            if ($v !== '') {
                $f[$chave] = mb_substr($v, 0, 64);
            }
        }
        return $f;
    }

    /**
     * A linha que o formulário deve mostrar, ou null quando o formulário está fechado.
     *
     * @param array<string,mixed> $devolvido @return array<string,mixed>|null
     */
    private function itemDoFormulario(Request $request, array $devolvido): ?array
    {
        $form = trim((string)($request->query('form') ?? ''));
        if ($form === '') {
            return null;
        }

        $base = ['id' => 0, 'grid_w' => 1, 'grid_h' => 1, 'weight_kg' => '0.000',
                 'rarity' => 'comum', 'currency' => 'credits', 'stackable' => 0,
                 'max_stack' => 1, 'base_price' => 0];

        if ($form !== 'novo') {
            $item = ItemsRepo::porId((int)$form);
            if ($item === null) {
                return null;
            }
            $base = $item;
        }

        // Valores recusados voltam por cima: o operador corrige o que errou, não redigita tudo.
        $valores = (array)($devolvido['valores'] ?? []);
        if ($valores !== [] && (int)($devolvido['id'] ?? 0) === (int)($base['id'] ?? 0)) {
            $base = array_replace($base, $valores);
        }
        return $base;
    }

    /**
     * Guarda erros + o que foi digitado para o GET seguinte.
     *
     * @param array<string,string> $erros @param array<string,string> $valores
     */
    private function devolver(array $erros, array $valores, int $id): void
    {
        $_SESSION[self::SESSAO_FORM] = ['erros' => $erros, 'valores' => $valores, 'id' => $id];
    }

    /** Lê e APAGA a devolução: recarregar a tela depois de corrigir não pode ressuscitar o erro. */
    private function consumirDevolucao(): array
    {
        $d = $_SESSION[self::SESSAO_FORM] ?? null;
        unset($_SESSION[self::SESSAO_FORM]);
        return is_array($d) ? $d : [];
    }

    /**
     * O que o operador digitou, exatamente como digitou — é isso que volta para o formulário
     * recusado. O valor saneado não serve aqui: mostrar "3.600" onde ele escreveu "3,6"
     * esconderia o que ele precisa consertar.
     *
     * @return array<string,string>
     */
    private function valoresCrus(Request $request): array
    {
        $out = [];
        foreach (array_keys(self::CAMPOS) as $campo) {
            $out[$campo] = mb_substr(trim((string)($request->input($campo) ?? '')), 0, 4000);
        }
        $out['id'] = (string)(int)($request->input('id') ?? 0);
        return $out;
    }
}
