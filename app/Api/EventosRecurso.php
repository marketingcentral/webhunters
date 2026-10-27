<?php declare(strict_types=1);

namespace App\Api;

use App\Core\Api\Requisicao;
use App\Core\Api\Resposta;
use App\Core\Response;

/**
 * /api/v1/events — três coleções sob um recurso só: desafios, notícias e avisos.
 *
 * POR QUE JUNTAS
 * Não é economia de rota: é que elas respondem à mesma pergunta operacional — "o que está
 * acontecendo no jogo esta semana" — e são governadas pela mesma tela do painel
 * (/admin/eventos, com as três abas). Uma chave que cuida da agenda do jogo precisa das três;
 * separá-las em três recursos criaria três escopos que ninguém concede em separado.
 *
 * POR QUE AS ROTAS SÃO LITERAIS, E NÃO /events/{grupo}
 * Um `{grupo}` casaria com qualquer palavra e mandaria /events/qualquercoisa para dentro do
 * recurso, que devolveria 404 vindo de um lugar mais fundo. Mais importante: a referência da
 * tela /admin/api é gerada desta mesma lista, e com um `{grupo}` o operador leria
 * "/api/v1/events/{grupo}" sem descobrir, em lugar nenhum, quais grupos existem. Declaradas
 * uma a uma, as três aparecem escritas na tela — e é com esse texto que ele monta o curl.
 */
final class EventosRecurso extends Recurso
{
    /** @var array<string, TabelaRecurso> grupo => coleção */
    private array $grupos = [];

    /** Rótulos das três coleções: [plural, singular] — o singular é o que a auditoria escreve. */
    private const ROTULOS = [
        'challenges' => ['desafios', 'desafio'],
        'news'       => ['notícias', 'notícia'],
        'notices'    => ['avisos do sistema', 'aviso'],
    ];

    public function __construct()
    {
        foreach (Catalogo::eventos() as $grupo => $decl) {
            [$plural, $singular] = self::ROTULOS[$grupo] ?? [$grupo, $grupo];
            // O nome da coleção filha inclui o grupo ('events/challenges') porque é dele que
            // sai o cabeçalho Location de um 201: só 'events' mandaria o cliente para
            // /api/v1/events/12, que não é onde o registro está. Estas filhas nunca são
            // registradas no despachante, então este nome composto não vira rota.
            $this->grupos[$grupo] = new TabelaRecurso('events/' . $grupo, $decl, $plural, $singular);
        }
    }

    public function nome(): string
    {
        return 'events';
    }

    /** @return list<array{0:string, 1:string, 2:string, 3:string, 4?:string}> */
    public function rotas(): array
    {
        $out = [];
        foreach (array_keys($this->grupos) as $grupo) {
            [$plural] = self::ROTULOS[$grupo] ?? [$grupo];
            $out[] = ['GET',    $grupo,        'grupo', 'Lista ' . $plural . '.',                          '?q=&limit=50'];
            $out[] = ['POST',   $grupo,        'grupo', 'Cria um registro em ' . $plural . '.',            ''];
            $out[] = ['GET',    $grupo . '/{id}', 'grupo', 'Um registro de ' . $plural . ' pelo id.',      ''];
            $out[] = ['PATCH',  $grupo . '/{id}', 'grupo', 'Altera um registro de ' . $plural . '.',       ''];
            $out[] = ['DELETE', $grupo . '/{id}', 'grupo', 'Apaga um registro de ' . $plural . '.',        ''];
        }
        return $out;
    }

    /**
     * Toda rota de evento cai aqui. O grupo é o primeiro segmento depois de /events — e ele
     * é LITERAL na rota, então chegar aqui já prova que é um dos três.
     *
     * @param array<string,string> $params
     */
    protected function grupo(Requisicao $req, Contexto $ctx, array $params): Response
    {
        $grupo = $req->segmento(1);
        if (!isset($this->grupos[$grupo])) {
            // Inalcançável pelas rotas declaradas; fica como rede para o dia em que alguém
            // acrescentar um grupo em rotas() e esquecer do Catalogo.
            return Resposta::erro('rota_ausente');
        }

        $temId = ($params['id'] ?? '') !== '';
        $acao = match ($req->metodo()) {
            'GET'    => $temId ? 'obter' : 'listar',
            'POST'   => 'criar',
            'PATCH'  => 'atualizar',
            'DELETE' => 'excluir',
            default  => '',
        };
        if ($acao === '') {
            return Resposta::erro('metodo_invalido');
        }

        return $this->grupos[$grupo]->operar($acao, $req, $ctx, $params);
    }
}
