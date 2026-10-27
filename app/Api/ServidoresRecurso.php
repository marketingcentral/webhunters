<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Maintenance;
use App\Core\Response;
use App\Repo\AdminSettingsRepo;
use App\Repo\ServersRepo;

/**
 * /api/v1/servers — estado da infraestrutura e o interruptor de manutenção.
 *
 * É o recurso que um monitor externo consulta e que um script de janela de manutenção
 * aciona: "vou subir a build às 3h" liga a manutenção, o deploy roda, e o mesmo script
 * desliga no fim. Sem isto, a janela dependeria de alguém acordado com o navegador aberto.
 *
 * ESTADO EFETIVO, NÃO A COLUNA. Uma região com janela de manutenção ativa está em manutenção
 * mesmo com `state = 'ok'` gravado. Quem calcula isso é ServersRepo::regioes(), o MESMO
 * método que o painel usa — se a API respondesse a coluna crua, um monitor diria "tudo certo"
 * enquanto o painel mostra "em manutenção" sobre a mesma região.
 *
 * O INTERRUPTOR É `admin_settings`, e não uma coluna nova: é a chave `maintenance_enabled`
 * que App\Core\Maintenance lê a cada requisição do jogo. Escrever em outro lugar criaria um
 * segundo interruptor que não desliga nada — que é exatamente o defeito que a classe
 * Maintenance nasceu para corrigir.
 */
final class ServidoresRecurso extends Recurso
{
    public function nome(): string
    {
        return 'servers';
    }

    /** @return list<array{0:string, 1:string, 2:string, 3:string, 4?:string}> */
    public function rotas(): array
    {
        return [
            ['GET',   '',            'estado',      'Regiões, serviços, janelas e modo manutenção.', ''],
            ['PATCH', 'maintenance', 'manutencao',  'Liga/desliga o modo manutenção do jogo.',       ''],
            ['PATCH', 'regions/{id}', 'regiao',     'Troca o estado de uma região (ok|warn|down).',  ''],
            ['PATCH', 'services/{id}', 'servico',   'Troca o estado de um serviço (ok|warn|down).',  ''],
        ];
    }

    protected function estado(Requisicao $req, Contexto $ctx, array $params): Response
    {
        [$ligada, $mensagem] = Maintenance::estado();

        return Resposta::ok([
            'maintenance' => [
                'enabled' => $ligada,
                'message' => $mensagem,
            ],
            'summary'  => ServersRepo::resumo(),
            'regions'  => array_map(
                static fn(array $r): array => [
                    'id'         => $r['id'],
                    'slug'       => $r['slug'],
                    'label'      => $r['label'],
                    // `state` é o que está GRAVADO; `effective_state` é o que vale agora,
                    // com as janelas de manutenção consideradas. Os dois viajam porque um
                    // monitor precisa do efetivo e um script de correção precisa do gravado.
                    'state'      => $r['state'],
                    'effective_state' => $r['estado'],
                    'ping_ms'    => $r['ping'],
                    'players'    => $r['jogadores'],
                    'note'       => $r['nota'],
                ],
                ServersRepo::regioes()
            ),
            'services' => array_map(
                static fn(array $s): array => [
                    'id'          => $s['id'],
                    'slug'        => $s['slug'],
                    'label'       => $s['label'],
                    'state'       => $s['state'],
                    'status_label'=> $s['rotulo'],
                    'maint_scope' => $s['escopo'],
                    'maint_starts_at' => $s['inicio'] > 0 ? date('Y-m-d H:i:s', $s['inicio']) : null,
                    'maint_minutes'   => $s['minutos'],
                ],
                ServersRepo::servicos()
            ),
            // As janelas vêm da PRIMEIRA página de ServersRepo::janelas() (8 por página, mais
            // recentes primeiro). É de propósito: este endpoint responde "como está o jogo
            // agora", e a agenda inteira — que cresce para sempre, porque manutenção é
            // recorrente — não cabe numa resposta de estado. O que vale para um monitor é a
            // janela ativa e as próximas, e essas estão aqui.
            'windows' => array_map(
                static fn(array $j): array => [
                    'id'        => $j['id'],
                    'region'    => $j['regiao'],
                    'starts_at' => date('Y-m-d H:i:s', $j['inicio']),
                    'ends_at'   => date('Y-m-d H:i:s', $j['fim']),
                    'minutes'   => $j['minutos'],
                    'state'     => $j['situacao'],
                    'note'      => $j['nota'],
                ],
                ServersRepo::janelas()['linhas']
            ),
        ]);
    }

    /**
     * Liga ou desliga a manutenção. Corpo: {"enabled": true, "message": "..."}.
     *
     * A mensagem é opcional e, quando vem, é gravada ANTES do interruptor. A ordem importa:
     * ligar primeiro exibiria, por alguns instantes, a manutenção anterior — e a mensagem
     * velha durante a janela nova é o tipo de detalhe que gera ticket de suporte.
     */
    protected function manutencao(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $corpo = $req->corpo();
        if (!array_key_exists('enabled', $corpo)) {
            return Resposta::erro('entrada_invalida', '', ['enabled' => 'Campo obrigatório (true ou false).']);
        }

        $r = Campo::validar($corpo['enabled'], ['tipo' => 'bool']);
        if (!$r['ok']) {
            return Resposta::erro('entrada_invalida', '', ['enabled' => $r['erro']]);
        }
        $ligar = (int)$r['valor'];

        if (array_key_exists('message', $corpo)) {
            $msg = trim((string)$corpo['message']);
            if (mb_strlen($msg) > 240) {
                return Resposta::erro('entrada_invalida', '', ['message' => 'A mensagem não pode passar de 240 caracteres.']);
            }
            AdminSettingsRepo::gravar('maintenance_message', $msg, 0);
        }

        $mudou = AdminSettingsRepo::gravar('maintenance_enabled', (string)$ligar, 0);

        if ($mudou) {
            $ctx->auditar(
                'manutencao',
                'admin_settings',
                'maintenance_enabled',
                ($ligar === 1 ? 'Modo manutenção LIGADO' : 'Modo manutenção DESLIGADO') . ' pela API.',
                ['enabled' => $ligar === 1]
            );
        }

        // O carimbo de sessão de Maintenance vale só para navegação com sessão; a API não tem
        // sessão, então o estado devolvido aqui é lido do banco e é o atual.
        [$agora, $mensagem] = Maintenance::estado();
        return Resposta::ok(['maintenance' => ['enabled' => $agora, 'message' => $mensagem], 'changed' => $mudou]);
    }

    protected function regiao(Requisicao $req, Contexto $ctx, array $params): Response
    {
        return $this->trocar($req, $ctx, $params, 'regiao', 'server_regions');
    }

    protected function servico(Requisicao $req, Contexto $ctx, array $params): Response
    {
        return $this->trocar($req, $ctx, $params, 'servico', 'server_services');
    }

    /**
     * O corpo dos dois PATCH de estado é o mesmo: {"state": "ok|warn|down"}.
     *
     * @param array<string,string> $params
     */
    private function trocar(Requisicao $req, Contexto $ctx, array $params, string $tipo, string $tabela): Response
    {
        $id = $this->id($params);
        $atual = $tipo === 'regiao' ? ServersRepo::regiao($id) : ServersRepo::servico($id);
        if ($atual === null) {
            return $this->naoEncontrado($tipo === 'regiao' ? 'Região não encontrada.' : 'Serviço não encontrado.');
        }

        $novo = strtolower(trim((string)($req->corpo()['state'] ?? '')));
        if (!isset(ServersRepo::ESTADOS[$novo])) {
            return Resposta::erro('entrada_invalida', '', [
                'state' => 'Estado aceito: ' . implode(', ', array_keys(ServersRepo::ESTADOS)) . '.',
            ]);
        }

        if ((string)$atual['state'] === $novo) {
            return Resposta::ok(['id' => $id, 'state' => $novo, 'changed' => false]);
        }

        ServersRepo::trocarEstado($tipo, $id, $novo);
        $ctx->auditar(
            'estado_servidor',
            $tabela,
            $id,
            sprintf('%s "%s": estado %s -> %s, pela API.', ucfirst($tipo), (string)$atual['label'], (string)$atual['state'], $novo),
            ['de' => $atual['state'], 'para' => $novo]
        );

        return Resposta::ok(['id' => $id, 'state' => $novo, 'changed' => true]);
    }
}
