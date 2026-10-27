<?php declare(strict_types=1);

namespace App\Api;

/**
 * A coleção filha em si. Existe como classe separada (e não como declaração solta) para
 * herdar as cinco ações, a validação e a auditoria de TabelaRecurso sem uma linha nova.
 *
 * Nunca é registrada no despachante: o único caminho até ela é MapasRecurso::marcadores().
 */
final class MarcadoresRecurso extends TabelaRecurso
{
    public function __construct()
    {
        parent::__construct('maps', Catalogo::marcadores(), 'marcadores', 'marcador');
    }

    /** @param array<string,string> $params */
    protected function paiId(array $params): ?int
    {
        $pai = (int)($params['pai'] ?? 0);
        return $pai > 0 ? $pai : null;
    }

    /** @param array<string,string> $params */
    protected function colecaoUrl(array $params): string
    {
        return 'maps/' . (int)($params['pai'] ?? 0) . '/markers';
    }
}
