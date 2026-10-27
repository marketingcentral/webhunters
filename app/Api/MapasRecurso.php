<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Response;

/**
 * /api/v1/maps — a coleção de mapas MAIS os marcadores de cada mapa.
 *
 * Os marcadores são uma coleção ANINHADA (/maps/{id}/markers) e não um recurso de primeiro
 * nível, porque um marcador não existe sozinho: ele é uma coordenada dentro de um mapa. Com o
 * mapa na URL, o pai entra no WHERE de toda consulta — pedir /maps/3/markers/99 quando o 99 é
 * do mapa 7 devolve 404, e não o marcador do mapa errado. Numa rota /markers/99 essa
 * conferência dependeria de o programador lembrar de fazê-la.
 *
 * O escopo continua sendo `maps:read` / `maps:write`: quem governa o mapa governa o que está
 * desenhado nele. Um escopo separado só para marcadores daria a impressão de uma fronteira
 * que não existe — quem pode apagar o mapa já leva os marcadores junto.
 */
final class MapasRecurso extends TabelaRecurso
{
    private TabelaRecurso $marcadores;

    public function __construct()
    {
        parent::__construct('maps', Catalogo::mapas(), 'mapas', 'mapa');
        $this->marcadores = new MarcadoresRecurso();
    }

    /** @return list<array{0:string, 1:string, 2:string, 3:string, 4?:string}> */
    public function rotas(): array
    {
        return array_merge(parent::rotas(), [
            ['GET',    '{id}/markers',             'marcadores', 'Marcadores do mapa.',        '?limit=200'],
            ['POST',   '{id}/markers',             'marcadores', 'Cria um marcador no mapa.',  ''],
            ['GET',    '{id}/markers/{marcador}',  'marcadores', 'Um marcador pelo id.',       ''],
            ['PATCH',  '{id}/markers/{marcador}',  'marcadores', 'Altera um marcador.',        ''],
            ['DELETE', '{id}/markers/{marcador}',  'marcadores', 'Apaga um marcador.',         ''],
        ]);
    }

    /**
     * Toda rota de marcador cai aqui; a ação da coleção filha sai do método HTTP e da
     * presença (ou não) do id do marcador.
     *
     * @param array<string,string> $params
     */
    protected function marcadores(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $mapa = $this->id($params);
        // O mapa é conferido ANTES de qualquer coisa: sem isso, listar os marcadores de um
        // mapa inexistente devolveria uma lista vazia — que se lê como "este mapa não tem
        // marcadores", e não como "este mapa não existe".
        if ($mapa <= 0 || Tabela::obter($this->decl, $mapa) === null) {
            return $this->naoEncontrado('Mapa não encontrado.');
        }

        $temMarcador = ($params['marcador'] ?? '') !== '';
        $acao = match (true) {
            $req->metodo() === 'GET'    => $temMarcador ? 'obter' : 'listar',
            $req->metodo() === 'POST'   => 'criar',
            $req->metodo() === 'PATCH'  => 'atualizar',
            $req->metodo() === 'DELETE' => 'excluir',
            default                     => '',
        };
        if ($acao === '') {
            return Resposta::erro('metodo_invalido');
        }

        // O id do marcador viaja como 'id' para a coleção filha, e o do mapa como 'pai' —
        // é o vocabulário que TabelaRecurso entende.
        return $this->marcadores->operar($acao, $req, $ctx, [
            'id'  => (string)($params['marcador'] ?? ''),
            'pai' => (string)$mapa,
        ]);
    }
}
