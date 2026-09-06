<?php declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\GameRules;
use App\Core\Request;
use App\Core\Response;

/**
 * BASE DE CAMPO (/base) — PRD v3 §28.
 *
 * P0: a página existe, explica o que uma base é e mostra o estado real do jogador — que hoje
 * é "nenhuma base ativa", porque a tabela player_bases só entra no P1. Quando ela existir,
 * este controller lê a base e a view já sabe pintar os módulos.
 */
final class BaseController extends Controller
{
    /** @param array<string, string> $params */
    public function index(Request $request, array $params = []): Response
    {
        $data = data_mock('base');
        $op   = $this->operational();
        $data['operational'] = $op;
        $data['base']        = $this->baseDoJogador($this->userId());
        $data['is_survivor'] = $op['state'] === GameRules::STATE_SURVIVOR;

        $page = $this->page('base', 'Base de Campo', ['back' => ['label' => 'VOLTAR', 'href' => url('inicio')]]);
        return $this->render('base', $page, $data);
    }

    /**
     * Base de campo do jogador, se a tabela existir e houver uma. null = nenhuma ativa.
     * @return array<string,mixed>|null
     */
    private function baseDoJogador(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        try {
            $b = Database::one(
                'SELECT b.*, m.name AS region_name FROM player_bases b LEFT JOIN maps m ON m.id = b.map_id
                  WHERE b.user_id = :u ORDER BY b.id DESC LIMIT 1',
                ['u' => $uid]
            );
        } catch (\Throwable) {
            return null;   // player_bases ainda não existe (P1)
        }
        if ($b === null) {
            return null;
        }
        $b['modules'] = json_decode((string)($b['modules'] ?? '[]'), true) ?: [];
        return $b;
    }
}
