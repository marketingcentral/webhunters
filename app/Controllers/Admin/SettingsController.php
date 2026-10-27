<?php declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repo\AdminSettingsRepo;

/**
 * Tela Configurações do Jogo do painel: /admin/configuracoes.
 *
 * Governa: `admin_settings` — os parâmetros que o jogo lê em tempo real, agrupados por
 * `grupo` e editados com o widget do seu `kind` (texto, número, interruptor, JSON).
 *
 * DECISÕES QUE EXPLICAM O FORMATO DESTA TELA
 * ------------------------------------------
 * 1. UM FORMULÁRIO POR GRUPO, e não um por campo nem um para a página inteira. Por campo, o
 *    operador que ajusta a lotação de uma região salvaria três vezes e o mundo ficaria
 *    incoerente entre um clique e outro; para a página inteira, um erro de digitação numa
 *    ponta impediria de salvar a outra. O grupo é a menor unidade que se sustenta sozinha.
 *
 * 2. GRAVAÇÃO ATÔMICA DENTRO DO GRUPO. Ou os campos válidos e as regras cruzadas passam
 *    todos, ou nada é escrito. Gravar metade deixaria `region_capacity_total` menor que a
 *    soma das vagas — um estado que o jogo não sabe interpretar e que ninguém pediu.
 *
 * 3. EXCLUIR É UM FLUXO DE DOIS PASSOS NO SERVIDOR (link -> painel de confirmação -> POST), e
 *    não um `confirm()` de JavaScript: a CSP do projeto proíbe manipulador embutido no HTML,
 *    e uma confirmação que só existe com JS ligado não é confirmação. Chave lida pelo código
 *    (AdminSettingsRepo::CHAVES_DO_CODIGO) nem chega a esse painel — o bloqueio aparece com o
 *    motivo escrito.
 *
 * 4. ERROS SOBREVIVEM AO REDIRECT numa bolsa de sessão própria, e não em `flash()`, que só
 *    carrega uma frase. O formulário precisa voltar com a caixa certa em vermelho, a mensagem
 *    do campo e o que o operador digitou — senão ele perde o texto e não sabe onde errou.
 *    POST -> redirect -> GET é mantido para que F5 não regrave nada.
 */
final class SettingsController extends AdminController
{
    /** Grupos por página. A unidade de paginação é o GRUPO: partir um formulário ao meio
     *  faria o botão Salvar enviar metade dos campos que ele mostra. */
    private const GRUPOS_POR_PAGINA = 6;

    /** Bolsas de sessão que atravessam o redirect depois de um POST recusado. */
    private const BOLSA_ERROS   = '_cfg_erros';
    private const BOLSA_VALORES = '_cfg_valores';
    private const BOLSA_NOVA    = '_cfg_nova';

    /** Ordenações aceitas em ?ordem= — fora da lista, cai na primeira. */
    private const ORDENS = ['sugerida', 'chave', 'recentes'];

    /** Filtros de estado aceitos em ?estado=. */
    private const ESTADOS = ['todos', 'alterado', 'padrao', 'sistema', 'personalizada'];

    // =====================================================================  leitura

    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }

        $cromo   = data_mock('admin-config');
        $erros   = $this->tirarBolsa(self::BOLSA_ERROS);
        $digitado = $this->tirarBolsa(self::BOLSA_VALORES);
        $nova    = $this->tirarBolsa(self::BOLSA_NOVA);

        try {
            $linhas    = AdminSettingsRepo::todas();
            $historico = AdminSettingsRepo::ultimasMudancas();
        } catch (\Throwable $e) {
            // Banco fora do ar é um fato a informar, não uma tela branca com stack trace.
            error_log('[extracthunters] admin/configuracoes: ' . $e->getMessage());
            $page = $this->adminPage('config', 'Configurações do Jogo', (string)($cromo['texto']['subtitulo'] ?? ''));
            return $this->render($page['slug'], $page, [
                'cromo'   => $cromo,
                'falha'   => 'Não foi possível ler admin_settings agora. O jogo segue com os valores já em memória.',
                'campos'  => [],
                'grupos'  => [],
                'filtros' => $this->filtrosVazios(),
                'resumo'  => ['total' => 0, 'exibidos' => 0, 'alterados' => 0, 'grupos' => 0, 'ultima' => null],
            ]);
        }

        // Todo campo é montado ANTES de filtrar: os contadores do topo ("12 parâmetros, 3
        // ajustados") descrevem a tabela inteira, não o recorte da busca.
        $campos = [];
        foreach ($linhas as $linha) {
            $campos[] = $this->montarCampo($linha, $cromo, $historico, $erros, $digitado);
        }

        $filtros   = $this->lerFiltros($request, $campos);
        $filtrados = $this->filtrar($campos, $filtros);

        // Um campo recusado tem de ser visto. Se o filtro em vigor o esconderia, ele volta
        // assim mesmo — caso contrário o operador leria "3 campos não foram salvos" e não
        // acharia nenhum deles na tela.
        if ($erros !== []) {
            $filtrados = $this->reintegrarInvalidos($filtrados, $campos);
        }

        $grupos = $this->agrupar($filtrados, $cromo, $filtros['ordem']);
        $pagina = $this->paginar($grupos, $filtros['pagina']);

        $page = $this->adminPage('config', 'Configurações do Jogo', (string)($cromo['texto']['subtitulo'] ?? ''));

        return $this->render($page['slug'], $page, [
            'cromo'      => $cromo,
            'falha'      => null,
            'campos'     => $campos,
            'grupos'     => $pagina['grupos'],
            'paginacao'  => $pagina['info'],
            'filtros'    => $filtros,
            'resumo'     => $this->resumo($campos, $filtrados),
            'nova'       => $nova,
            'excluir'    => $this->confirmacaoDeExclusao($request, $campos),
            'admin_kpis' => $this->kpisDaTopbar($campos),
        ]);
    }

    // =====================================================================  escrita

    /**
     * Salva um grupo inteiro (acao=grupo) ou cria uma chave nova (acao=criar).
     *
     * As duas escritas moram na mesma ação porque a rota POST /admin/configuracoes/salvar já
     * está registrada e é uma só; o campo `acao` é quem decide, e nada além destes dois
     * valores é aceito.
     *
     * @param array<string, string> $params
     */
    public function salvar(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        return match ((string)$request->input('acao', 'grupo')) {
            'criar' => $this->criarChave($request),
            'grupo' => $this->salvarGrupo($request),
            default => $this->voltar('Ação desconhecida.', 'error'),
        };
    }

    /**
     * Remove uma chave criada pelo painel. Chave lida pelo código do jogo é recusada aqui —
     * a proteção não pode viver só no HTML do botão, que qualquer POST direto ignoraria.
     *
     * @param array<string, string> $params
     */
    public function excluir(Request $request, array $params = []): Response
    {
        if ($bloqueio = $this->exigirAdmin()) {
            return $bloqueio;
        }
        $this->csrf($request);

        $chave = (string)$request->input('chave', '');
        $cromo = data_mock('admin-config');

        if ($chave === '') {
            return $this->voltar('Nenhuma chave informada.', 'error');
        }
        if (AdminSettingsRepo::protegida($chave)) {
            return $this->voltar((string)($cromo['texto']['protegida'] ?? 'Esta chave não pode ser removida.'), 'error');
        }

        try {
            $linha = AdminSettingsRepo::porChave($chave);
            if ($linha === null) {
                return $this->voltar('A chave "' . $chave . '" não existe mais.', 'error');
            }
            // Sem esta confirmação explícita, um POST solto apagaria a chave; o painel de
            // confirmação da tela é quem manda o campo.
            if ((string)$request->input('confirmar', '') !== '1') {
                return $this->redirect('admin/configuracoes', ['excluir' => $chave]);
            }

            AdminSettingsRepo::remover($chave);

            // A linha inteira vai para o meta: o log de auditoria é o único lugar de onde a
            // chave pode ser reconstruída depois de apagada.
            $this->auditar(
                AdminSettingsRepo::ACAO_EXCLUIR,
                'config',
                $chave,
                'Removeu a configuração ' . $chave . '.',
                [
                    'grupo' => (string)$linha['grupo'],
                    'kind'  => (string)$linha['kind'],
                    'label' => (string)$linha['label'],
                    'de'    => $linha['value'] !== null ? (string)$linha['value'] : null,
                    'para'  => null,
                ]
            );

            return $this->voltar('Chave "' . $chave . '" removida.', 'ok');
        } catch (\Throwable $e) {
            error_log('[extracthunters] admin/configuracoes/excluir: ' . $e->getMessage());
            return $this->voltar('Não foi possível remover a chave agora.', 'error');
        }
    }

    // =====================================================  escrita — implementação

    private function salvarGrupo(Request $request): Response
    {
        $grupo = strtolower((string)$request->input('grupo', ''));
        if ($grupo === '') {
            return $this->voltar('Formulário sem grupo — nada foi gravado.', 'error');
        }

        $cromo    = data_mock('admin-config');
        $enviados = $this->mapaPost($request, 'campo');

        // A lista `campos[]` diz quais chaves o formulário DESENHOU. É ela, e não as chaves
        // presentes no corpo, que define o que salvar: um interruptor desligado não é enviado
        // pelo navegador, e sem essa lista "desligar" seria indistinguível de "não mexeu".
        $declarados = $request->inputArray('campos');

        try {
            $doGrupo = array_filter(
                AdminSettingsRepo::todas(),
                static fn(array $l): bool => (string)$l['grupo'] === $grupo
            );
        } catch (\Throwable $e) {
            error_log('[extracthunters] admin/configuracoes/salvar: ' . $e->getMessage());
            return $this->voltar('Não foi possível ler as configurações para gravar.', 'error');
        }

        if ($doGrupo === []) {
            return $this->voltar('Grupo "' . $grupo . '" não existe mais.', 'error');
        }

        $erros     = [];
        $digitado  = [];
        $novos     = [];   // chave => valor normalizado
        $atuais    = [];   // chave => linha do banco

        foreach ($doGrupo as $linha) {
            $chave = (string)$linha['key'];
            if (!in_array($chave, $declarados, true)) {
                continue; // não estava no formulário enviado: não é omissão, é fora de escopo
            }
            $atuais[$chave] = $linha;

            $tipo  = (string)$linha['kind'];
            $bruto = (string)($enviados[$chave] ?? '');
            $regra = (array)($cromo['chaves'][$chave] ?? []);

            $digitado[$chave] = $bruto;
            $veredito = AdminSettingsRepo::validar($tipo, $bruto, $regra);
            if (!$veredito['ok']) {
                $erros[$chave] = $veredito['erro'];
                continue;
            }
            $novos[$chave] = $veredito['valor'];
        }

        if ($atuais === []) {
            return $this->voltar('O formulário não trouxe nenhum campo deste grupo.', 'error');
        }

        foreach ($this->regrasCruzadas($grupo, $novos, $atuais) as $chave => $msg) {
            $erros[$chave] = $msg;
        }

        if ($erros !== []) {
            $this->porBolsa(self::BOLSA_ERROS, $erros);
            $this->porBolsa(self::BOLSA_VALORES, $digitado);
            flash('error', count($erros) === 1
                ? 'Um campo não passou na validação — nada foi gravado.'
                : count($erros) . ' campos não passaram na validação — nada foi gravado.');
            return $this->redirect('admin/configuracoes', ['grupo' => $grupo]);
        }

        try {
            $mudadas = (array)Database::transaction(function () use ($novos, $atuais, $grupo): array {
                $feitas = [];
                foreach ($novos as $chave => $valor) {
                    $antes = $atuais[$chave]['value'] !== null ? (string)$atuais[$chave]['value'] : null;
                    if (!AdminSettingsRepo::gravar($chave, $valor, $this->adminId())) {
                        continue;
                    }
                    $feitas[$chave] = ['de' => $antes, 'para' => $valor, 'kind' => (string)$atuais[$chave]['kind'], 'grupo' => $grupo];
                }
                return $feitas;
            });
        } catch (\Throwable $e) {
            error_log('[extracthunters] admin/configuracoes/salvar: ' . $e->getMessage());
            return $this->voltar('A gravação falhou e foi desfeita inteira. Nada mudou.', 'error');
        }

        // Uma linha de auditoria POR CHAVE, e não uma pelo grupo: quem investiga um incidente
        // procura por uma chave, não por uma tela.
        foreach ($mudadas as $chave => $m) {
            $this->auditar(
                AdminSettingsRepo::ACAO_ALTERAR,
                'config',
                $chave,
                'Alterou ' . $chave . ': ' . $this->resumir($m['de']) . ' -> ' . $this->resumir($m['para']) . '.',
                $m
            );
        }

        if ($mudadas === []) {
            flash('info', (string)($cromo['texto']['sem_alteracao'] ?? 'Nada mudou.'));
        } else {
            flash('ok', count($mudadas) === 1
                ? 'Parâmetro atualizado: ' . array_key_first($mudadas) . '.'
                : count($mudadas) . ' parâmetros atualizados em ' . $grupo . '.');
        }
        return $this->redirect('admin/configuracoes', ['grupo' => $grupo]);
    }

    private function criarChave(Request $request): Response
    {
        $bruto = [
            'key'   => (string)$request->input('key', ''),
            'label' => (string)$request->input('label', ''),
            'kind'  => (string)$request->input('kind', 'string'),
            'grupo' => (string)$request->input('grupo_novo', ''),
            'value' => (string)($this->mapaPost($request, 'novo')['value'] ?? $request->input('value', '')),
        ];

        try {
            $exame = AdminSettingsRepo::validarNova($bruto);
        } catch (\Throwable $e) {
            error_log('[extracthunters] admin/configuracoes/criar: ' . $e->getMessage());
            return $this->voltar('Não foi possível validar a chave agora.', 'error');
        }

        if ($exame['erros'] !== []) {
            // O que o operador digitou volta junto com os erros: reescrever um JSON inteiro
            // porque o rótulo ficou vazio é o tipo de castigo que faz ninguém usar a tela.
            $this->porBolsa(self::BOLSA_NOVA, ['erros' => $exame['erros'], 'valores' => $bruto]);
            flash('error', 'A chave não foi criada: confira os campos marcados.');
            return $this->redirect('admin/configuracoes', ['nova' => '1']);
        }

        /** @var array{key:string,value:?string,kind:string,label:string,grupo:string} $linha */
        $linha = $exame['linha'];

        try {
            AdminSettingsRepo::criar($linha, $this->adminId());
        } catch (\Throwable $e) {
            error_log('[extracthunters] admin/configuracoes/criar: ' . $e->getMessage());
            return $this->voltar('Não foi possível criar a chave agora.', 'error');
        }

        $this->auditar(
            AdminSettingsRepo::ACAO_CRIAR,
            'config',
            $linha['key'],
            'Criou a configuração ' . $linha['key'] . ' em ' . $linha['grupo'] . '.',
            ['de' => null, 'para' => $linha['value'], 'kind' => $linha['kind'], 'grupo' => $linha['grupo'], 'label' => $linha['label']]
        );

        flash('ok', 'Chave "' . $linha['key'] . '" criada em ' . $linha['grupo'] . '.');
        return $this->redirect('admin/configuracoes', ['grupo' => $linha['grupo']]);
    }

    /**
     * Regras que um campo sozinho não consegue verificar — a razão de a gravação do grupo ser
     * atômica. Só entram aqui invariantes que o JOGO realmente assume; a tela não inventa
     * política própria.
     *
     * @param array<string,?string> $novos  valores já validados desta submissão
     * @param array<string,array<string,mixed>> $atuais linhas do banco (para as chaves ausentes)
     * @return array<string,string> chave => mensagem no campo culpado
     */
    private function regrasCruzadas(string $grupo, array $novos, array $atuais): array
    {
        if ($grupo !== 'mundo') {
            return [];
        }

        // O valor de referência é o que ESTÁ SENDO GRAVADO quando o campo veio no formulário,
        // e o do banco quando não veio: sem isso, salvar um recorte filtrado da tela compararia
        // o número novo com um vizinho que não está mais lá.
        $ler = static function (string $chave) use ($novos, $atuais): ?int {
            if (array_key_exists($chave, $novos)) {
                return $novos[$chave] !== null ? (int)$novos[$chave] : null;
            }
            $v = $atuais[$chave]['value'] ?? null;
            return $v !== null ? (int)$v : null;
        };

        $total = $ler('region_capacity_total');
        $hun   = $ler('region_capacity_hunters');
        $mer   = $ler('region_capacity_mercenaries');

        if ($total === null || $hun === null || $mer === null || $total >= $hun + $mer) {
            return [];
        }

        $msg = 'A soma das vagas (' . $hun . ' + ' . $mer . ' = ' . ($hun + $mer)
             . ') passa do teto da região (' . $total . ').';

        // O erro é apontado no campo que o operador acabou de mexer; se mexeu no teto, é nele.
        $culpado = array_key_exists('region_capacity_total', $novos)
            ? 'region_capacity_total'
            : (array_key_exists('region_capacity_hunters', $novos) ? 'region_capacity_hunters' : 'region_capacity_mercenaries');

        return [$culpado => $msg];
    }

    // =====================================================  montagem para a view

    /**
     * Uma linha do banco vira o CAMPO que a view desenha: valor pronto para o widget, dica,
     * limites, procedência e o erro do último envio, se houve.
     *
     * @param array<string,mixed> $linha
     * @param array<string,mixed> $cromo
     * @param array<string,array{de:?string,para:?string,quando:string,quem:?string}> $historico
     * @param array<string,string> $erros
     * @param array<string,string> $digitado
     * @return array<string,mixed>
     */
    private function montarCampo(array $linha, array $cromo, array $historico, array $erros, array $digitado): array
    {
        $chave = (string)$linha['key'];
        $tipo  = in_array((string)$linha['kind'], AdminSettingsRepo::TIPOS, true) ? (string)$linha['kind'] : 'string';
        $regra = (array)($cromo['chaves'][$chave] ?? []);
        $valor = $linha['value'] !== null ? (string)$linha['value'] : '';

        // Erro na tela = o operador acabou de enviar isto. O campo tem de voltar com o que ELE
        // escreveu, não com o que está no banco — senão a correção some junto com a mensagem.
        $erro    = $erros[$chave] ?? '';
        $naTela  = $erro !== '' && array_key_exists($chave, $digitado) ? $digitado[$chave] : $valor;
        if ($tipo === 'json' && $erro === '') {
            $naTela = AdminSettingsRepo::jsonBonito($valor);
        }

        $hist = $historico[$chave] ?? null;

        return [
            'chave'      => $chave,
            'label'      => (string)$linha['label'] !== '' ? (string)$linha['label'] : $chave,
            'tipo'       => $tipo,
            'grupo'      => (string)$linha['grupo'],
            'valor'      => $naTela,
            'gravado'    => $valor,
            'ligado'     => $valor === '1',
            'dica'       => (string)($regra['dica'] ?? ''),
            'unidade'    => (string)($regra['unidade'] ?? ''),
            'min'        => isset($regra['min']) ? (int)$regra['min'] : null,
            'max'        => isset($regra['max']) ? (int)$regra['max'] : null,
            'max_len'    => isset($regra['max_len']) ? (int)$regra['max_len'] : null,
            'linhas'     => isset($regra['linhas']) ? max(1, (int)$regra['linhas']) : 1,
            'rot_on'     => (string)($regra['ligado'] ?? 'Ligado'),
            'rot_off'    => (string)($regra['desligado'] ?? 'Desligado'),
            'perigo'     => !empty($regra['perigo']),
            'ordem'      => isset($regra['ordem']) ? (int)$regra['ordem'] : 500,
            'protegida'  => AdminSettingsRepo::protegida($chave),
            'alterada'   => $linha['updated_by'] !== null,
            'quando'     => (string)($linha['updated_at'] ?? ''),
            'quem'       => $linha['updated_por'] !== null ? (string)$linha['updated_por'] : null,
            'anterior'   => $hist['de'] ?? null,
            'erro'       => $erro,
        ];
    }

    /**
     * Filtros da URL, já saneados, mais o catálogo que a barra de ferramentas desenha.
     *
     * @param list<array<string,mixed>> $campos
     * @return array<string,mixed>
     */
    private function lerFiltros(Request $request, array $campos): array
    {
        $grupos = [];
        $tipos  = [];
        foreach ($campos as $c) {
            $grupos[(string)$c['grupo']] = ($grupos[(string)$c['grupo']] ?? 0) + 1;
            $tipos[(string)$c['tipo']]   = ($tipos[(string)$c['tipo']] ?? 0) + 1;
        }
        ksort($grupos);
        ksort($tipos);

        $grupo = (string)$request->query('grupo', '');
        $tipo  = (string)$request->query('tipo', '');

        return [
            'q'          => mb_substr((string)$request->query('q', ''), 0, 60),
            'grupo'      => isset($grupos[$grupo]) ? $grupo : '',
            'tipo'       => isset($tipos[$tipo]) ? $tipo : '',
            'estado'     => in_array((string)$request->query('estado', ''), self::ESTADOS, true) ? (string)$request->query('estado') : 'todos',
            'ordem'      => in_array((string)$request->query('ordem', ''), self::ORDENS, true) ? (string)$request->query('ordem') : self::ORDENS[0],
            'pagina'     => max(1, (int)$request->query('p', '1')),
            'abrir_nova' => (string)$request->query('nova', '') === '1',
            'cat_grupos' => $grupos,
            'cat_tipos'  => $tipos,
        ];
    }

    /** @return array<string,mixed> */
    private function filtrosVazios(): array
    {
        return [
            'q' => '', 'grupo' => '', 'tipo' => '', 'estado' => 'todos', 'ordem' => self::ORDENS[0],
            'pagina' => 1, 'abrir_nova' => false, 'cat_grupos' => [], 'cat_tipos' => [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $campos
     * @param array<string,mixed> $f
     * @return list<array<string,mixed>>
     */
    private function filtrar(array $campos, array $f): array
    {
        $q = mb_strtolower(trim((string)$f['q']));

        return array_values(array_filter($campos, static function (array $c) use ($q, $f): bool {
            if ($f['grupo'] !== '' && $c['grupo'] !== $f['grupo']) {
                return false;
            }
            if ($f['tipo'] !== '' && $c['tipo'] !== $f['tipo']) {
                return false;
            }
            $passaEstado = match ($f['estado']) {
                'alterado'      => (bool)$c['alterada'],
                'padrao'        => !$c['alterada'],
                'sistema'       => (bool)$c['protegida'],
                'personalizada' => !$c['protegida'],
                default         => true,
            };
            if (!$passaEstado) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            // A busca cobre chave, rótulo e VALOR: procurar pela mensagem de manutenção que
            // está no ar é tão legítimo quanto procurar pelo nome da chave.
            $palheiro = mb_strtolower($c['chave'] . ' ' . $c['label'] . ' ' . $c['gravado'] . ' ' . $c['dica']);
            return str_contains($palheiro, $q);
        }));
    }

    /**
     * Devolve para a lista os campos recusados que o filtro atual esconderia.
     *
     * @param list<array<string,mixed>> $filtrados
     * @param list<array<string,mixed>> $todos
     * @return list<array<string,mixed>>
     */
    private function reintegrarInvalidos(array $filtrados, array $todos): array
    {
        $jaTem = array_column($filtrados, 'chave');
        foreach ($todos as $c) {
            if ((string)$c['erro'] !== '' && !in_array($c['chave'], $jaTem, true)) {
                $filtrados[] = $c;
            }
        }
        return $filtrados;
    }

    /**
     * Agrupa por `grupo`, na ordem declarada em app/Data/admin-config.php. Grupo que existe no
     * banco e não no catálogo entra no fim, com o nome cru — some-lo esconderia dado real.
     *
     * @param list<array<string,mixed>> $campos
     * @param array<string,mixed> $cromo
     * @return list<array<string,mixed>>
     */
    private function agrupar(array $campos, array $cromo, string $ordem): array
    {
        $meta   = (array)($cromo['grupos'] ?? []);
        $caixas = [];

        foreach ($campos as $c) {
            $g = (string)$c['grupo'];
            if (!isset($caixas[$g])) {
                $m = (array)($meta[$g] ?? []);
                $caixas[$g] = [
                    'chave' => $g,
                    'label' => (string)($m['label'] ?? ucfirst(str_replace('_', ' ', $g))),
                    'desc'  => (string)($m['desc'] ?? ''),
                    'icon'  => (string)($m['icon'] ?? 'tune'),
                    'ordem' => isset($m['ordem']) ? (int)$m['ordem'] : 900,
                    'itens' => [],
                ];
            }
            $caixas[$g]['itens'][] = $c;
        }

        uasort($caixas, static fn(array $a, array $b): int => [$a['ordem'], $a['chave']] <=> [$b['ordem'], $b['chave']]);

        foreach ($caixas as $g => $caixa) {
            usort($caixas[$g]['itens'], static function (array $a, array $b) use ($ordem): int {
                return match ($ordem) {
                    'chave'    => $a['chave'] <=> $b['chave'],
                    // Mais recente primeiro; quem nunca mudou vai para o fim, não para o topo.
                    'recentes' => [$b['alterada'], (string)$b['quando']] <=> [$a['alterada'], (string)$a['quando']],
                    default    => [$a['ordem'], $a['chave']] <=> [$b['ordem'], $b['chave']],
                };
            });
        }

        return array_values($caixas);
    }

    /**
     * @param list<array<string,mixed>> $grupos
     * @return array{grupos:list<array<string,mixed>>,info:array<string,mixed>}
     */
    private function paginar(array $grupos, int $pagina): array
    {
        $total   = count($grupos);
        $paginas = max(1, (int)ceil($total / self::GRUPOS_POR_PAGINA));
        $pagina  = min(max(1, $pagina), $paginas);

        return [
            'grupos' => array_slice($grupos, ($pagina - 1) * self::GRUPOS_POR_PAGINA, self::GRUPOS_POR_PAGINA),
            'info'   => [
                'pagina'  => $pagina,
                'paginas' => $paginas,
                'total'   => $total,
                'por'     => self::GRUPOS_POR_PAGINA,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $todos
     * @param list<array<string,mixed>> $exibidos
     * @return array<string,mixed>
     */
    private function resumo(array $todos, array $exibidos): array
    {
        $alterados = 0;
        $grupos    = [];
        $ultima    = null;
        foreach ($todos as $c) {
            $grupos[(string)$c['grupo']] = true;
            if (!$c['alterada']) {
                continue;
            }
            $alterados++;
            $quando = (string)$c['quando'];
            if ($quando !== '' && ($ultima === null || $quando > $ultima['quando'])) {
                $ultima = ['quando' => $quando, 'chave' => (string)$c['chave'], 'quem' => $c['quem']];
            }
        }

        return [
            'total'     => count($todos),
            'exibidos'  => count($exibidos),
            'alterados' => $alterados,
            'grupos'    => count($grupos),
            'ultima'    => $ultima,
        ];
    }

    /**
     * O painel de confirmação de exclusão, quando ?excluir= aponta para uma chave real.
     * Chave protegida devolve o motivo do bloqueio em vez do painel.
     *
     * @param list<array<string,mixed>> $campos
     * @return array<string,mixed>|null
     */
    private function confirmacaoDeExclusao(Request $request, array $campos): ?array
    {
        $alvo = (string)$request->query('excluir', '');
        if ($alvo === '') {
            return null;
        }
        foreach ($campos as $c) {
            if ($c['chave'] !== $alvo) {
                continue;
            }
            return [
                'campo'    => $c,
                'bloqueio' => $c['protegida']
                    ? (string)(data_mock('admin-config')['texto']['protegida'] ?? '')
                    : '',
            ];
        }
        return null;
    }

    /**
     * Os três indicadores compactos da topbar. Nenhum é decorativo: todos saem da própria
     * tabela que esta tela edita.
     *
     * @param list<array<string,mixed>> $campos
     * @return list<array<string,mixed>>
     */
    private function kpisDaTopbar(array $campos): array
    {
        $alterados = 0;
        $manutencao = null;
        foreach ($campos as $c) {
            $alterados += $c['alterada'] ? 1 : 0;
            if ($c['chave'] === 'maintenance_enabled') {
                $manutencao = $c['gravado'] === '1';
            }
        }

        return [
            ['icon' => 'tune', 'label' => 'Parâmetros', 'value' => $campos === [] ? null : num(count($campos)), 'tone' => 'lime'],
            ['icon' => 'edit_note', 'label' => 'Ajustados', 'value' => $campos === [] ? null : num($alterados), 'tone' => 'cyan'],
            [
                'icon'  => $manutencao === true ? 'build' : 'rocket_launch',
                'label' => 'Servidor',
                'value' => $manutencao === null ? null : ($manutencao ? 'Manutenção' : 'No ar'),
                'tone'  => $manutencao === true ? 'amber' : 'lime',
            ],
        ];
    }

    // =====================================================================  utilidades

    /**
     * `campo[chave]` do POST como mapa associativo. Request::inputArray() reindexa com
     * array_values e perderia justamente a chave — que aqui é o nome do parâmetro.
     *
     * @return array<string,string>
     */
    private function mapaPost(Request $request, string $nome): array
    {
        $bruto = $request->all()[$nome] ?? [];
        if (!is_array($bruto)) {
            return [];
        }
        $saida = [];
        foreach ($bruto as $k => $v) {
            if (is_scalar($v)) {
                $saida[(string)$k] = (string)$v;
            }
        }
        return $saida;
    }

    /** Valor encurtado para caber no `summary` (255) da auditoria sem virar um romance. */
    private function resumir(?string $v): string
    {
        if ($v === null) {
            return '(vazio)';
        }
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        return mb_strlen($v) > 60 ? mb_substr($v, 0, 57) . '...' : $v;
    }

    /** Flash + volta para a tela. Toda saída de escrita passa por aqui (POST/redirect/GET). */
    private function voltar(string $msg, string $tom): Response
    {
        flash($tom, $msg);
        return $this->redirect('admin/configuracoes');
    }

    /** @param array<string,mixed> $dados */
    private function porBolsa(string $nome, array $dados): void
    {
        $_SESSION[$nome] = $dados;
    }

    /** @return array<string,mixed> Lê E consome — a bolsa não pode sobreviver a um F5. */
    private function tirarBolsa(string $nome): array
    {
        $v = $_SESSION[$nome] ?? [];
        unset($_SESSION[$nome]);
        return is_array($v) ? $v : [];
    }
}
