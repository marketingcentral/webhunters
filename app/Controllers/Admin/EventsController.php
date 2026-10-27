<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Repo\EventsRepo;

/**
 * Tela EVENTOS do painel: /admin/eventos.
 *
 * Governa três catálogos numa tela só, em abas: `challenges` (+ `challenge_bonuses`), `news`
 * e `system_notices`. Uma tela e não três porque são a mesma pergunta do operador — "o que
 * está acontecendo no jogo esta semana?" — e porque as três listas juntas ainda cabem numa
 * navegação lateral já com dezoito itens.
 *
 * TRÊS ROTAS, QUATRO FORMULÁRIOS
 * O painel registra só GET /admin/eventos, POST /admin/eventos/salvar e .../excluir. Então
 * criar, editar e confirmar exclusão são ESTADOS DA MESMA URL, escolhidos por query string
 * (`?aba=`, `?form=`, `?id=`, `?excluir=`), e o tipo do registro viaja num campo escondido
 * (`tipo`) do POST. O efeito colateral é bom: cada estado da tela tem um endereço, dá para
 * mandar por link e o botão "voltar" do navegador funciona.
 *
 * VALIDAÇÃO QUE NÃO PERDE O QUE FOI DIGITADO
 * Quando o POST não passa, a resposta NÃO é um redirect: é a própria tela, renderizada
 * outra vez com os erros por campo e com os valores que o operador digitou. Um redirect com
 * flash faria a mensagem sobreviver e o texto não — e formulário longo (o corpo de uma
 * notícia) apagado por um erro de data é motivo de o operador parar de usar o painel.
 *
 * O QUE ESTA TELA SE RECUSA A FAZER
 * Excluir um desafio que já tem progresso de jogador em `player_challenges` e excluir uma
 * notícia citada por `maintenance_windows`. Nos dois casos a FK é ON DELETE CASCADE ou o
 * dado ficaria órfão: o banco obedeceria em silêncio e o estrago só apareceria dias depois,
 * na tela do jogador. O bloqueio é da aplicação, com o motivo escrito e o número na frente.
 */
final class EventsController extends AdminController
{
    /** Abas da tela, na ordem em que aparecem. A primeira é o padrão. */
    private const ABAS = ['desafios', 'noticias', 'avisos'];

    /** Formulário -> aba onde ele mora. É também a lista fechada de `?form=` e de `tipo`. */
    private const FORMULARIOS = [
        'desafio' => 'desafios',
        'bonus'   => 'desafios',
        'noticia' => 'noticias',
        'aviso'   => 'avisos',
    ];

    /** Ícones do Material Symbols aceitos em desafio/aviso: minúsculas, dígitos e `_`. */
    private const RE_ICONE = '/^[a-z0-9_]{1,48}$/';

    /** Identificador estável usado pelo jogo nas URLs e nas junções (`news_slug`). */
    private const RE_SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    // =====================================================================================
    // LEITURA
    // =====================================================================================

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        return $this->tela($request);
    }

    // =====================================================================================
    // ESCRITA
    // =====================================================================================

    /** @param array<string, string> $params */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $tipo = (string)($request->input('tipo') ?? '');
        if (!isset(self::FORMULARIOS[$tipo])) {
            flash('error', 'Formulário desconhecido — nada foi gravado.');
            return $this->redirect('admin/eventos');
        }

        return match ($tipo) {
            'desafio' => $this->salvarDesafio($request),
            'bonus'   => $this->salvarBonus($request),
            'noticia' => $this->salvarNoticia($request),
            default   => $this->salvarAviso($request),
        };
    }

    /** @param array<string, string> $params */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $tipo  = (string)($request->input('tipo') ?? '');
        $id    = (string)($request->input('id') ?? '');
        $volta = $this->voltar($request->input('_volta'), self::FORMULARIOS[$tipo] ?? 'desafios');

        if (!isset(self::FORMULARIOS[$tipo]) || $id === '') {
            flash('error', 'Pedido de exclusão incompleto — nada foi apagado.');
            return Response::redirect($volta);
        }

        // O impedimento é conferido DE NOVO aqui. A tela de confirmação já mostrou o bloqueio,
        // mas entre olhar e confirmar cabe um jogador começando o desafio noutra aba.
        $impedimento = $this->impedimento($tipo, $id);
        if ($impedimento !== null) {
            flash('error', $impedimento);
            return Response::redirect($volta);
        }

        switch ($tipo) {
            case 'desafio':
                $linha = EventsRepo::desafio((int)$id);
                if ($linha === null) {
                    flash('error', 'Esse desafio já não existe.');
                    return Response::redirect($volta);
                }
                EventsRepo::excluirDesafio((int)$id);
                $this->auditar('evento_desafio_excluir', 'challenge', $id,
                    'Desafio removido: ' . $linha['label'], ['slug' => $linha['slug'], 'escopo' => $linha['scope']]);
                flash('ok', 'Desafio “' . $linha['label'] . '” removido.');
                break;

            case 'bonus':
                $linha = EventsRepo::bonusDoEscopo($id);
                if ($linha === null) {
                    flash('error', 'Esse bônus já não existe.');
                    return Response::redirect($volta);
                }
                EventsRepo::excluirBonus($id);
                $this->auditar('evento_bonus_excluir', 'challenge_bonus', $id,
                    'Bônus removido do escopo ' . $id, ['titulo' => $linha['title']]);
                flash('ok', 'Bônus do escopo “' . $id . '” removido.');
                break;

            case 'noticia':
                $linha = EventsRepo::noticia((int)$id);
                if ($linha === null) {
                    flash('error', 'Essa notícia já não existe.');
                    return Response::redirect($volta);
                }
                EventsRepo::excluirNoticia((int)$id);
                $this->auditar('evento_noticia_excluir', 'news', $id,
                    'Notícia removida: ' . $linha['title'], ['slug' => $linha['slug']]);
                flash('ok', 'Notícia “' . $linha['title'] . '” removida.');
                break;

            default:
                $linha = EventsRepo::aviso((int)$id);
                if ($linha === null) {
                    flash('error', 'Esse aviso já não existe.');
                    return Response::redirect($volta);
                }
                EventsRepo::excluirAviso((int)$id);
                $this->auditar('evento_aviso_excluir', 'system_notice', $id,
                    'Aviso removido: ' . $linha['title'],
                    ['destino' => $linha['user_id'] === null ? 'global' : (string)$linha['username']]);
                flash('ok', 'Aviso “' . $linha['title'] . '” removido.');
        }

        return Response::redirect($volta);
    }

    // =====================================================================================
    // GRAVAÇÃO POR TIPO
    // =====================================================================================

    private function salvarDesafio(Request $request): Response
    {
        $id  = max(0, (int)($request->input('id') ?? 0));
        $ant = $id > 0 ? EventsRepo::desafio($id) : null;
        if ($id > 0 && $ant === null) {
            flash('error', 'Esse desafio já não existe — nada foi gravado.');
            return Response::redirect($this->voltar($request->input('_volta'), 'desafios'));
        }

        $erros = [];
        $slug  = $this->campoSlug($request, 'slug', 64, $erros);
        if ($slug !== '' && EventsRepo::slugDesafioEmUso($slug, $id)) {
            $erros['slug'] = 'Já existe outro desafio com este identificador.';
        }

        $escopo = (string)($request->input('scope') ?? '');
        if (!in_array($escopo, EventsRepo::ESCOPOS, true)) {
            $erros['scope'] = 'Escolha um escopo.';
            $escopo = EventsRepo::ESCOPOS[0];
        }

        $label = $this->campoTexto($request, 'label', 160, true, $erros, 'O título do desafio');
        $desc  = $this->campoTexto($request, 'description', 400, false, $erros, 'A descrição');
        $icone = $this->campoIcone($request, 'icon', $erros);

        $alvo    = $this->campoInteiro($request, 'target', 1, 1_000_000, $erros, 'O alvo');
        $xp      = $this->campoInteiro($request, 'xp', 0, 100_000_000, $erros, 'O XP');
        $creditos= $this->campoInteiro($request, 'credits', 0, 100_000_000, $erros, 'Os créditos');
        $ordem   = $this->campoInteiro($request, 'sort_order', 0, 65_535, $erros, 'A ordem');

        // season_id só faz sentido em desafio de temporada — o próprio schema diz isso. Fora
        // desse escopo o valor é ZERADO, e não recusado: trocar o escopo de um desafio antigo
        // não pode virar um erro de formulário sobre um campo que a tela nem mostra mais.
        $temporada = null;
        if ($escopo === 'temporada') {
            $temporada = max(0, (int)($request->input('season_id') ?? 0));
            if ($temporada <= 0) {
                $erros['season_id'] = 'Desafio de temporada precisa dizer de qual temporada é.';
            } elseif (!EventsRepo::temporadaExiste($temporada)) {
                $erros['season_id'] = 'Essa temporada não existe.';
                $temporada = null;
            }
        }

        if ($erros !== []) {
            return $this->tela($request, $erros, 'desafio');
        }

        $dados = [
            'slug'        => $slug,
            'scope'       => $escopo,
            'season_id'   => $temporada !== null && $temporada > 0 ? $temporada : null,
            'label'       => $label,
            'description' => $desc !== '' ? $desc : null,
            'icon'        => $icone !== '' ? $icone : null,
            'target'      => $alvo,
            'xp'          => $xp,
            'credits'     => $creditos,
            'highlight'   => $request->input('highlight') !== null ? 1 : 0,
            'sort_order'  => $ordem,
        ];

        $novoId = EventsRepo::salvarDesafio($dados, $id);
        $this->auditar(
            $id > 0 ? 'evento_desafio_editar' : 'evento_desafio_criar',
            'challenge',
            $novoId,
            ($id > 0 ? 'Desafio atualizado: ' : 'Desafio criado: ') . $label,
            $id > 0 ? ['antes' => $this->diff($ant ?? [], $dados)] : $dados
        );

        flash('ok', $id > 0 ? 'Desafio “' . $label . '” atualizado.' : 'Desafio “' . $label . '” criado.');
        return Response::redirect($this->voltar($request->input('_volta'), 'desafios'));
    }

    private function salvarBonus(Request $request): Response
    {
        // A chave primária de `challenge_bonuses` é o escopo: existe UM bônus por aba do jogo.
        // Por isso editar não troca o escopo — trocar seria criar outro e apagar este.
        $escopoOriginal = (string)($request->input('id') ?? '');
        $editando       = $escopoOriginal !== '' && EventsRepo::bonusDoEscopo($escopoOriginal) !== null;

        $erros  = [];
        $escopo = $editando ? $escopoOriginal : (string)($request->input('scope') ?? '');
        if (!in_array($escopo, EventsRepo::ESCOPOS, true)) {
            $erros['scope'] = 'Escolha um escopo.';
        } elseif (!$editando && EventsRepo::bonusDoEscopo($escopo) !== null) {
            $erros['scope'] = 'Este escopo já tem bônus. Edite o que existe.';
        }

        $rotulo = $this->campoTexto($request, 'label', 40, true, $erros, 'A tarja');
        $titulo = $this->campoTexto($request, 'title', 80, true, $erros, 'O título');
        $desc   = $this->campoTexto($request, 'description', 400, false, $erros, 'A descrição');
        $imagem = $this->campoCaminho($request, 'image', 160, $erros);
        $xp     = $this->campoInteiro($request, 'xp', 0, 100_000_000, $erros, 'O XP');
        $creds  = $this->campoInteiro($request, 'credits', 0, 100_000_000, $erros, 'Os créditos');

        if ($erros !== []) {
            return $this->tela($request, $erros, 'bonus');
        }

        $dados = [
            'label'       => $rotulo,
            'title'       => $titulo,
            'description' => $desc !== '' ? $desc : null,
            'image'       => $imagem !== '' ? $imagem : null,
            'xp'          => $xp,
            'credits'     => $creds,
        ];
        EventsRepo::salvarBonus($editando ? $dados : $dados + ['scope' => $escopo], $editando ? $escopo : '');

        $this->auditar(
            $editando ? 'evento_bonus_editar' : 'evento_bonus_criar',
            'challenge_bonus',
            $escopo,
            ($editando ? 'Bônus atualizado: ' : 'Bônus criado: ') . $titulo,
            $dados
        );
        flash('ok', 'Bônus do escopo “' . $escopo . '” salvo.');
        return Response::redirect($this->voltar($request->input('_volta'), 'desafios'));
    }

    private function salvarNoticia(Request $request): Response
    {
        $id  = max(0, (int)($request->input('id') ?? 0));
        $ant = $id > 0 ? EventsRepo::noticia($id) : null;
        if ($id > 0 && $ant === null) {
            flash('error', 'Essa notícia já não existe — nada foi gravado.');
            return Response::redirect($this->voltar($request->input('_volta'), 'noticias'));
        }

        $erros = [];
        $slug  = $this->campoSlug($request, 'slug', 80, $erros);
        if ($slug !== '' && EventsRepo::slugNoticiaEmUso($slug, $id)) {
            $erros['slug'] = 'Já existe outra notícia com este identificador.';
        }
        // Renomear o slug de uma notícia citada por `maintenance_windows` quebraria a junção
        // (a chave é o texto, não o id) e a janela ficaria sem imagem, sem erro nenhum.
        if ($ant !== null && $slug !== '' && $slug !== (string)$ant['slug']) {
            $janelas = EventsRepo::janelasDaNoticia((string)$ant['slug']);
            if ($janelas > 0) {
                $erros['slug'] = $janelas . ' janela(s) de manutenção apontam para “' . $ant['slug']
                    . '”. Trocar o identificador desligaria essa ligação.';
            }
        }

        $titulo   = $this->campoTexto($request, 'title', 160, true, $erros, 'O título');
        $categoria= $this->campoTexto($request, 'category', 24, false, $erros, 'A categoria');
        $resumo   = $this->campoTexto($request, 'excerpt', 400, false, $erros, 'O resumo');
        $imagem   = $this->campoCaminho($request, 'image', 160, $erros);
        $ordem    = $this->campoInteiro($request, 'sort_order', 0, 65_535, $erros, 'A ordem');
        $corpo    = $this->corpoDaNoticia((string)($request->input('body') ?? ''), $erros);
        $data     = $this->campoData($request, 'published', $erros);

        if ($erros !== []) {
            return $this->tela($request, $erros, 'noticia');
        }

        $dados = [
            'slug'       => $slug,
            'title'      => $titulo,
            'category'   => $categoria !== '' ? $categoria : null,
            'excerpt'    => $resumo !== '' ? $resumo : null,
            'body'       => $corpo,
            'image'      => $imagem !== '' ? $imagem : null,
            'published'  => $data,
            'sort_order' => $ordem,
        ];

        $novoId = EventsRepo::salvarNoticia($dados, $id);
        $estado = $data === null ? 'rascunho' : 'publicada em ' . $data;
        $this->auditar(
            $id > 0 ? 'evento_noticia_editar' : 'evento_noticia_criar',
            'news',
            $novoId,
            ($id > 0 ? 'Notícia atualizada: ' : 'Notícia criada: ') . $titulo . ' (' . $estado . ')',
            ['slug' => $slug, 'published' => $data, 'categoria' => $categoria]
        );

        flash('ok', 'Notícia “' . $titulo . '” salva como ' . $estado . '.');
        return Response::redirect($this->voltar($request->input('_volta'), 'noticias'));
    }

    private function salvarAviso(Request $request): Response
    {
        $id  = max(0, (int)($request->input('id') ?? 0));
        $ant = $id > 0 ? EventsRepo::aviso($id) : null;
        if ($id > 0 && $ant === null) {
            flash('error', 'Esse aviso já não existe — nada foi gravado.');
            return Response::redirect($this->voltar($request->input('_volta'), 'avisos'));
        }

        $erros = [];

        // Destino: global (user_id NULL) ou um jogador, resolvido pelo NOME digitado. O painel
        // pede o nome e não o id porque é o nome que o operador tem na mão quando alguém abre
        // um chamado; a existência é conferida no banco antes de gravar.
        $destino = (string)($request->input('destino') ?? 'global');
        $userId  = null;
        $paraQuem= 'global';
        if ($destino === 'jogador') {
            $nome = trim((string)($request->input('destinatario') ?? ''));
            if ($nome === '') {
                $erros['destinatario'] = 'Diga para qual jogador é este aviso.';
            } else {
                $jogador = EventsRepo::jogadorPorNome($nome);
                if ($jogador === null) {
                    $erros['destinatario'] = 'Não existe jogador com o nome “' . $nome . '”.';
                } else {
                    $userId   = (int)$jogador['id'];
                    $paraQuem = (string)$jogador['username'];
                }
            }
        } elseif ($destino !== 'global') {
            $erros['destino'] = 'Escolha entre aviso global e aviso de um jogador.';
        }

        $icone  = $this->campoIcone($request, 'icon', $erros);
        if ($icone === '') {
            $erros['icon'] = 'O ícone é obrigatório (ex.: info, warning, redeem).';
        }

        $cor = (string)($request->input('color') ?? '');
        if (!in_array($cor, EventsRepo::CORES_AVISO, true)) {
            $erros['color'] = 'Escolha uma das cores que o jogo sabe desenhar.';
            $cor = 'lime';
        }

        $titulo = $this->campoTexto($request, 'title', 120, true, $erros, 'O título');
        $texto  = $this->campoTexto($request, 'body', 400, true, $erros, 'O texto do aviso');
        $ordem  = $this->campoInteiro($request, 'sort_order', 0, 65_535, $erros, 'A ordem');

        // Botão: rótulo e destino andam juntos. Um sem o outro rende um botão sem texto ou um
        // texto que não leva a lugar nenhum — os dois viram defeito visível na HUD.
        $ctaLabel = $this->campoTexto($request, 'cta_label', 40, false, $erros, 'O rótulo do botão');
        $ctaHref  = trim((string)($request->input('cta_href') ?? ''));
        if (mb_strlen($ctaHref) > 120) {
            $erros['cta_href'] = 'O destino do botão passa de 120 caracteres.';
        } elseif ($ctaHref !== '' && !preg_match('#^(https?://[^\s]+|[a-z0-9][a-z0-9/_-]*)$#i', $ctaHref)) {
            $erros['cta_href'] = 'Use um caminho do jogo (ex.: loja) ou um endereço http(s) completo.';
        }
        if ($ctaLabel !== '' && $ctaHref === '') {
            $erros['cta_href'] = 'O botão tem rótulo mas não tem para onde ir.';
        }
        if ($ctaHref !== '' && $ctaLabel === '') {
            $erros['cta_label'] = 'O botão tem destino mas não tem rótulo.';
        }

        if ($erros !== []) {
            return $this->tela($request, $erros, 'aviso');
        }

        $dados = [
            'user_id'    => $userId,
            'icon'       => $icone,
            'color'      => $cor,
            'title'      => $titulo,
            'body'       => $texto,
            'cta_label'  => $ctaLabel !== '' ? $ctaLabel : null,
            'cta_href'   => $ctaHref !== '' ? $ctaHref : null,
            'fresh'      => $request->input('fresh') !== null ? 1 : 0,
            'sort_order' => $ordem,
        ];

        $novoId = EventsRepo::salvarAviso($dados, $id);
        $this->auditar(
            $id > 0 ? 'evento_aviso_editar' : 'evento_aviso_criar',
            'system_notice',
            $novoId,
            ($id > 0 ? 'Aviso atualizado: ' : 'Aviso criado: ') . $titulo . ' (' . $paraQuem . ')',
            ['destino' => $paraQuem, 'cor' => $cor, 'icone' => $icone]
        );

        flash('ok', 'Aviso “' . $titulo . '” salvo para ' . ($userId === null ? 'todos os jogadores' : $paraQuem) . '.');
        return Response::redirect($this->voltar($request->input('_volta'), 'avisos'));
    }

    // =====================================================================================
    // MONTAGEM DA TELA
    // =====================================================================================

    /**
     * Desenha a tela inteira. É o único caminho de renderização: o GET normal e o POST que
     * não passou na validação chegam aqui pela mesma porta, então a lista, os filtros e os
     * contadores nunca ficam diferentes entre um caso e o outro.
     *
     * @param array<string,string> $erros    campo => mensagem
     * @param string|null          $formErro formulário que falhou (força a aba e reabre o form)
     */
    private function tela(Request $request, array $erros = [], ?string $formErro = null): Response
    {
        $filtros = $this->filtros($request);
        $aba     = $formErro !== null ? self::FORMULARIOS[$formErro] : $filtros['aba'];

        // Depois de um POST inválido os valores exibidos são os DIGITADOS; num GET, são os do
        // banco. Um só array, para a view não precisar saber de onde veio.
        $form = $formErro !== null
            ? ['tipo' => $formErro, 'id' => (string)($request->input('id') ?? ''), 'valores' => $request->all()]
            : $this->formularioPedido($request, $aba);

        $excluir = $formErro === null ? $this->exclusaoPedida($request, $aba) : null;

        $dados = [
            'aba'       => $aba,
            'filtros'   => $filtros,
            'resumo'    => EventsRepo::resumo(),
            'cromo'     => data_mock('admin-eventos'),
            'form'      => $form,
            'erros'     => $erros,
            'excluir'   => $excluir,
            'volta'     => $this->urlDaLista($filtros, $aba),
            'temporadas'=> [],
            'jogadores' => [],
        ];

        // Cada aba paga só a consulta dela. O bônus vem junto dos desafios porque é o mesmo
        // assunto e são três linhas; as temporadas e os nomes de jogador só são carregados
        // quando existe um formulário aberto que precisa deles.
        if ($aba === 'desafios') {
            $dados['lista'] = EventsRepo::desafios($filtros);
            $dados['bonus'] = EventsRepo::bonus();
            $dados['escopos_livres'] = EventsRepo::escoposSemBonus();
        } elseif ($aba === 'noticias') {
            $dados['lista'] = EventsRepo::noticias($filtros);
            $dados['categorias'] = EventsRepo::categoriasDeNoticia();
        } else {
            $dados['lista'] = EventsRepo::avisos($filtros);
        }

        if (($form['tipo'] ?? '') === 'desafio') {
            $dados['temporadas'] = EventsRepo::temporadas();
        }
        if (($form['tipo'] ?? '') === 'aviso') {
            $dados['jogadores'] = EventsRepo::sugestoesDeJogador();
        }

        $rotuloAba = (string)(data_mock('admin-eventos')['abas'][$aba]['label'] ?? 'Eventos');
        $page = $this->adminPage(
            'eventos',
            'Eventos',
            'Desafios, notícias e avisos do sistema',
            ['actions' => $this->botaoNovo($aba, $rotuloAba, $dados['volta'])]
        );

        return $this->render($page['slug'], $page, $dados + ['admin_conta' => $this->admin()]);
    }

    /**
     * Estado da listagem lido da URL, já domado: aba conhecida, ordenação de dentro do mapa
     * do repositório, página positiva. É o mesmo array que volta para a view desenhar os
     * campos e para montar todos os links da tela.
     *
     * @return array<string,mixed>
     */
    private function filtros(Request $request): array
    {
        $aba = (string)($request->query('aba') ?? '');
        if (!in_array($aba, self::ABAS, true)) {
            $aba = self::ABAS[0];
        }

        return [
            'aba'     => $aba,
            'q'       => trim(mb_substr((string)($request->query('q') ?? ''), 0, 80)),
            'escopo'  => (string)($request->query('escopo') ?? ''),
            'estado'  => (string)($request->query('estado') ?? ''),
            'cat'     => (string)($request->query('cat') ?? ''),
            'destino' => (string)($request->query('destino') ?? ''),
            'ord'     => (string)($request->query('ord') ?? ''),
            'dir'     => strtoupper((string)($request->query('dir') ?? '')) === 'DESC' ? 'DESC' : 'ASC',
            'pagina'  => max(1, (int)($request->query('p') ?? 1)),
        ];
    }

    /**
     * `?form=noticia&id=3` -> formulário de edição preenchido do banco; `?form=noticia` sem id
     * -> formulário vazio. Um id que não existe mais não vira erro 404: vira o formulário de
     * criação, porque foi isso que sobrou do link antigo.
     *
     * @return array{tipo:string,id:string,valores:array<string,mixed>}|null
     */
    private function formularioPedido(Request $request, string $aba): ?array
    {
        $tipo = (string)($request->query('form') ?? '');
        if (!isset(self::FORMULARIOS[$tipo]) || self::FORMULARIOS[$tipo] !== $aba) {
            return null;
        }
        $id = trim((string)($request->query('id') ?? ''));

        $valores = [];
        if ($id !== '') {
            $linha = match ($tipo) {
                'desafio' => EventsRepo::desafio((int)$id),
                'bonus'   => EventsRepo::bonusDoEscopo($id),
                'noticia' => EventsRepo::noticia((int)$id),
                default   => EventsRepo::aviso((int)$id),
            };
            if ($linha === null) {
                return ['tipo' => $tipo, 'id' => '', 'valores' => []];
            }
            $valores = $linha;

            if ($tipo === 'noticia') {
                // O corpo é gravado como lista JSON (é o que CatalogRepo::news lê). No campo
                // de texto ele volta a ser parágrafo separado por linha em branco.
                $valores['body'] = $this->corpoParaTexto((string)($linha['body'] ?? ''));
            }
            if ($tipo === 'aviso') {
                $valores['destino']      = $linha['user_id'] === null ? 'global' : 'jogador';
                $valores['destinatario'] = (string)($linha['username'] ?? '');
            }
        }

        return ['tipo' => $tipo, 'id' => $id, 'valores' => $valores];
    }

    /**
     * `?excluir=<id>&tipo=<t>` -> o cartão de confirmação, já com o que será apagado e com o
     * impedimento, quando existe. Nada é apagado aqui: este é o passo de LER antes de agir.
     *
     * @return array{tipo:string,id:string,titulo:string,detalhe:string,impedimento:?string}|null
     */
    private function exclusaoPedida(Request $request, string $aba): ?array
    {
        $id = trim((string)($request->query('excluir') ?? ''));
        $tipo = (string)($request->query('tipo') ?? '');
        if ($id === '' || !isset(self::FORMULARIOS[$tipo]) || self::FORMULARIOS[$tipo] !== $aba) {
            return null;
        }

        [$titulo, $detalhe] = match ($tipo) {
            'desafio' => (function () use ($id): array {
                $l = EventsRepo::desafio((int)$id);
                return $l === null ? ['', ''] : [(string)$l['label'], 'Desafio ' . $l['scope'] . ' · ' . $l['slug']];
            })(),
            'bonus' => (function () use ($id): array {
                $l = EventsRepo::bonusDoEscopo($id);
                return $l === null ? ['', ''] : [(string)$l['title'], 'Bônus do escopo ' . $id];
            })(),
            'noticia' => (function () use ($id): array {
                $l = EventsRepo::noticia((int)$id);
                return $l === null ? ['', ''] : [(string)$l['title'], 'Notícia · ' . $l['slug']];
            })(),
            default => (function () use ($id): array {
                $l = EventsRepo::aviso((int)$id);
                return $l === null
                    ? ['', '']
                    : [(string)$l['title'], 'Aviso · ' . ($l['user_id'] === null ? 'global' : (string)$l['username'])];
            })(),
        };

        if ($titulo === '') {
            return null;
        }

        return [
            'tipo'        => $tipo,
            'id'          => $id,
            'titulo'      => $titulo,
            'detalhe'     => $detalhe,
            'impedimento' => $this->impedimento($tipo, $id),
        ];
    }

    /**
     * Por que este registro NÃO pode ser apagado — ou null quando pode.
     *
     * Os dois casos existem porque o banco obedeceria calado: `player_challenges` tem FK com
     * ON DELETE CASCADE (apagar o desafio apagaria o progresso de quem já jogou) e
     * `maintenance_windows.news_slug` é junção por TEXTO, sem FK (a janela continuaria de pé,
     * só que sem imagem e sem link, e ninguém saberia por quê).
     */
    private function impedimento(string $tipo, string $id): ?string
    {
        if ($tipo === 'desafio') {
            $n = EventsRepo::progressoDoDesafio((int)$id);
            return $n === 0 ? null
                : 'Este desafio já tem progresso gravado de ' . $n . ' jogador(es). Apagá-lo apagaria '
                . 'junto esse progresso (player_challenges cai por ON DELETE CASCADE). Zere o alvo ou '
                . 'tire o desafio do escopo em vez de excluir.';
        }
        if ($tipo === 'noticia') {
            $linha = EventsRepo::noticia((int)$id);
            if ($linha === null) {
                return null;
            }
            $n = EventsRepo::janelasDaNoticia((string)$linha['slug']);
            return $n === 0 ? null
                : $n . ' janela(s) de manutenção apontam para “' . $linha['slug'] . '”. Sem a notícia, '
                . 'essas janelas ficam sem imagem e sem link na HUD. Desligue a referência em '
                . 'maintenance_windows antes de excluir.';
        }
        return null;
    }

    // =====================================================================================
    // CAMPOS — validação no servidor, uma regra por método
    // =====================================================================================

    /** @param array<string,string> $erros */
    private function campoTexto(Request $r, string $campo, int $max, bool $obrigatorio, array &$erros, string $rotulo): string
    {
        $v = trim((string)($r->input($campo) ?? ''));
        if ($v === '') {
            if ($obrigatorio) {
                $erros[$campo] = $rotulo . ' é obrigatório.';
            }
            return '';
        }
        if (mb_strlen($v) > $max) {
            $erros[$campo] = $rotulo . ' passa de ' . $max . ' caracteres (tem ' . mb_strlen($v) . ').';
        }
        return $v;
    }

    /** @param array<string,string> $erros */
    private function campoSlug(Request $r, string $campo, int $max, array &$erros): string
    {
        $v = strtolower(trim((string)($r->input($campo) ?? '')));
        if ($v === '') {
            $erros[$campo] = 'O identificador é obrigatório — é por ele que o jogo encontra o registro.';
            return '';
        }
        if (mb_strlen($v) > $max) {
            $erros[$campo] = 'O identificador passa de ' . $max . ' caracteres.';
            return $v;
        }
        if (!preg_match(self::RE_SLUG, $v)) {
            $erros[$campo] = 'Use letras minúsculas, números e hífen entre palavras (ex.: evento-caca-na-floresta).';
        }
        return $v;
    }

    /** @param array<string,string> $erros */
    private function campoInteiro(Request $r, string $campo, int $min, int $max, array &$erros, string $rotulo): int
    {
        $bruto = trim((string)($r->input($campo) ?? ''));
        if ($bruto === '') {
            return $min;
        }
        if (!preg_match('/^\d{1,12}$/', $bruto)) {
            $erros[$campo] = $rotulo . ' precisa ser um número inteiro.';
            return $min;
        }
        $v = (int)$bruto;
        if ($v < $min || $v > $max) {
            $erros[$campo] = $rotulo . ' precisa ficar entre ' . num($min) . ' e ' . num($max) . '.';
            return max($min, min($max, $v));
        }
        return $v;
    }

    /** @param array<string,string> $erros */
    private function campoIcone(Request $r, string $campo, array &$erros): string
    {
        $v = strtolower(trim((string)($r->input($campo) ?? '')));
        if ($v === '') {
            return '';
        }
        if (!preg_match(self::RE_ICONE, $v)) {
            $erros[$campo] = 'Nome de ícone do Material Symbols: minúsculas, números e sublinhado (ex.: military_tech).';
        }
        return $v;
    }

    /**
     * Caminho de mídia relativo (`items/supply-crate.png`), do jeito que media() resolve.
     * Barra inicial, `..` e endereço absoluto são recusados: o campo aponta para /media do
     * próprio projeto, e não para um arquivo qualquer da internet.
     *
     * @param array<string,string> $erros
     */
    private function campoCaminho(Request $r, string $campo, int $max, array &$erros): string
    {
        $v = trim((string)($r->input($campo) ?? ''));
        if ($v === '') {
            return '';
        }
        if (mb_strlen($v) > $max) {
            $erros[$campo] = 'O caminho passa de ' . $max . ' caracteres.';
            return $v;
        }
        if (!preg_match('#^[a-z0-9][a-z0-9._/-]*\.(png|jpg|jpeg|webp|avif)$#i', $v) || str_contains($v, '..')) {
            $erros[$campo] = 'Caminho relativo dentro de /media, terminando em .png/.jpg/.webp (ex.: items/supply-crate.png).';
        }
        return $v;
    }

    /**
     * Data de publicação. Vazio é RASCUNHO, e isso é um estado legítimo — não um erro.
     * @param array<string,string> $erros
     */
    private function campoData(Request $r, string $campo, array &$erros): ?string
    {
        $v = trim((string)($r->input($campo) ?? ''));
        if ($v === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            $erros[$campo] = 'Data inválida. Use o formato AAAA-MM-DD.';
            return null;
        }
        return $v;
    }

    /**
     * O corpo digitado vira LISTA JSON de parágrafos — o formato que
     * App\Repo\CatalogRepo::news() decodifica para a HUD. Guardar texto corrido faria a HUD
     * cair no ramo de compatibilidade e imprimir a notícia inteira num parágrafo só.
     *
     * @param array<string,string> $erros
     */
    private function corpoDaNoticia(string $bruto, array &$erros): ?string
    {
        $bruto = trim(str_replace(["\r\n", "\r"], "\n", $bruto));
        if ($bruto === '') {
            return null;
        }
        $paragrafos = array_values(array_filter(
            array_map('trim', preg_split('/\n{2,}/', $bruto) ?: []),
            static fn(string $p): bool => $p !== ''
        ));
        $json = json_encode($paragrafos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $erros['body'] = 'Não foi possível gravar este texto. Retire caracteres de controle.';
            return null;
        }
        if (strlen($json) > 60000) {
            $erros['body'] = 'O corpo da notícia é grande demais.';
            return null;
        }
        return $json;
    }

    /** Caminho inverso: a lista JSON do banco volta a ser texto editável. */
    private function corpoParaTexto(string $guardado): string
    {
        if (trim($guardado) === '') {
            return '';
        }
        $lista = json_decode($guardado, true);
        return is_array($lista) ? implode("\n\n", array_map('strval', $lista)) : $guardado;
    }

    // =====================================================================================
    // NAVEGAÇÃO
    // =====================================================================================

    /**
     * URL da listagem com os filtros atuais. É o "voltar" de todo formulário: salvar uma
     * notícia na página 3 filtrada por rascunho devolve o operador à página 3 filtrada por
     * rascunho, e não ao topo de uma lista que ele não estava olhando.
     *
     * @param array<string,mixed> $f
     */
    private function urlDaLista(array $f, string $aba): string
    {
        $q = ['aba' => $aba];
        foreach (['q', 'escopo', 'estado', 'cat', 'destino', 'ord'] as $k) {
            if (($f[$k] ?? '') !== '') {
                $q[$k] = (string)$f[$k];
            }
        }
        if (($f['ord'] ?? '') !== '' && ($f['dir'] ?? 'ASC') === 'DESC') {
            $q['dir'] = 'DESC';
        }
        if ((int)($f['pagina'] ?? 1) > 1) {
            $q['p'] = (string)(int)$f['pagina'];
        }
        return url('admin/eventos', $q);
    }

    /**
     * O `_volta` chega escondido no POST, então é entrada de usuário: nunca é usado como URL.
     * A query dele é relida pelo MESMO normalizador dos filtros e a URL é remontada aqui —
     * um `_volta` adulterado não passa de filtros esquisitos na própria tela.
     */
    private function voltar(?string $bruto, string $abaPadrao): string
    {
        $bruto = (string)$bruto;
        $qs    = (string)(parse_url($bruto, PHP_URL_QUERY) ?? '');
        if ($qs === '') {
            return url('admin/eventos', ['aba' => $abaPadrao]);
        }
        parse_str($qs, $partes);
        $f = [
            'q'       => trim(mb_substr((string)($partes['q'] ?? ''), 0, 80)),
            'escopo'  => (string)($partes['escopo'] ?? ''),
            'estado'  => (string)($partes['estado'] ?? ''),
            'cat'     => (string)($partes['cat'] ?? ''),
            'destino' => (string)($partes['destino'] ?? ''),
            'ord'     => (string)($partes['ord'] ?? ''),
            'dir'     => strtoupper((string)($partes['dir'] ?? '')) === 'DESC' ? 'DESC' : 'ASC',
            'pagina'  => max(1, (int)($partes['p'] ?? 1)),
        ];
        $aba = (string)($partes['aba'] ?? '');
        return $this->urlDaLista($f, in_array($aba, self::ABAS, true) ? $aba : $abaPadrao);
    }

    /** O botão "novo" da topbar muda de nome com a aba — é o verbo do que está sendo olhado. */
    private function botaoNovo(string $aba, string $rotuloAba, string $volta): string
    {
        $tipo = match ($aba) {
            'noticias' => 'noticia',
            'avisos'   => 'aviso',
            default    => 'desafio',
        };
        $rotulo = match ($aba) {
            'noticias' => 'Nova notícia',
            'avisos'   => 'Novo aviso',
            default    => 'Novo desafio',
        };
        $href = url('admin/eventos', ['aba' => $aba, 'form' => $tipo]);

        return '<a class="adm-btn adm-btn--primary" href="' . e($href) . '" title="'
            . e($rotuloAba . ' — criar registro') . '">' . icon('add') . e($rotulo) . '</a>';
    }

    /**
     * O que mudou entre o registro antigo e o novo — é isto que vai para a auditoria, e não a
     * linha inteira: a tela "Logs do Sistema" precisa mostrar A MUDANÇA, não repetir o cadastro.
     *
     * @param array<string,mixed> $antes @param array<string,mixed> $depois
     * @return array<string,mixed>
     */
    private function diff(array $antes, array $depois): array
    {
        $mudou = [];
        foreach ($depois as $campo => $novo) {
            if (!array_key_exists($campo, $antes)) {
                continue;
            }
            $velho = $antes[$campo];
            if ((string)$velho !== (string)$novo) {
                $mudou[$campo] = ['de' => $velho, 'para' => $novo];
            }
        }
        return $mudou;
    }
}
