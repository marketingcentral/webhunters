<?php declare(strict_types=1);

/**
 * Rótulos e microcópia da tela ADMIN · TRANSAÇÕES (/admin/transacoes).
 *
 * Fica fora da view pelo mesmo motivo do admin-dashboard.php: frase que precisa ser
 * encurtada para caber numa coluna de 1366px é decisão de PRODUTO, e mudar produto não
 * pode significar mexer em HTML. A view lê, escapa e desenha.
 *
 * Uma advertência que vale para toda a tela: `player_purchases` guarda MOEDA DE JOGO
 * (créditos, dinheiro, pontos), não dinheiro de verdade. Nenhum texto daqui pode escrever
 * "R$", e nenhum número de moedas diferentes pode ser somado — 100 créditos + 100 pontos
 * não são 200 de coisa nenhuma. É por isso que "total do período" aparece SEMPRE quebrado
 * por moeda, e só os contadores (transações, unidades, jogadores) somam a tela inteira.
 */

return [
    'titulos' => [
        'filtros'   => 'Filtros',
        'grafico'   => 'Movimento por dia',
        'resumo'    => 'Total por moeda',
        'lista'     => 'Transações',
        'itens'     => 'Itens mais vendidos',
        'jogadores' => 'Quem mais gastou',
    ],

    /* Cabeçalhos da tabela. Curtos de propósito: são oito colunas numa linha só. */
    'colunas' => [
        'id'      => '#',
        'data'    => 'Data',
        'jogador' => 'Jogador',
        'item'    => 'Item',
        'qtd'     => 'Qtd',
        'unit'    => 'Unitário',
        'total'   => 'Total',
        'moeda'   => 'Moeda',
    ],

    'kpis' => [
        'transacoes' => 'Transações',
        'unidades'   => 'Unidades vendidas',
        'jogadores'  => 'Jogadores compradores',
        'ticket'     => 'Ticket médio',
    ],

    'periodos' => [
        ['chave' => '7',    'label' => '7 dias'],
        ['chave' => '30',   'label' => '30 dias'],
        ['chave' => '90',   'label' => '90 dias'],
        ['chave' => 'tudo', 'label' => 'Tudo'],
    ],

    'micro' => [
        // O aviso que impede a leitura errada do painel inteiro. Aparece uma vez, no topo.
        'moeda_de_jogo' => 'Valores em moeda do jogo. Moedas diferentes não são somadas entre si.',
        'ticket_multi'  => 'Ticket médio só existe por moeda — veja o quadro "Total por moeda".',
        'ticket_calc'   => 'Total dividido pelo número de transações da moeda.',
        'delta_base'    => 'Comparado com o período anterior de mesma duração.',
        'delta_sem'     => 'Não há período anterior com movimento para comparar.',
        'semana'        => 'Período longo demais para uma barra por dia: cada barra é uma semana.',
        'granul_dia'    => 'Uma barra por dia.',
        'ordenar'       => 'Ordenar por esta coluna',
        'item_sumido'   => 'Item removido do catálogo depois da compra.',
        'conta_sumida'  => 'Conta removida depois da compra.',
        'export'        => 'Baixa exatamente as linhas deste filtro, na ordem da tela.',
        'somavel'       => 'Contadores somam todas as moedas; valores, não.',
        // Dito na barra do período, não só no campo: os números abaixo são de OUTRA janela.
        'periodo_recusado' => 'Período recusado — mostrando os últimos 30 dias',
    ],

    'vazios' => [
        // Nunca houve compra nenhuma no banco: o operador precisa saber QUEM escreve aqui.
        'ledger'   => 'Nenhuma compra registrada até agora. Esta tabela é escrita pela loja do '
                    . 'jogo: cada compra concluída em /loja grava uma linha aqui, com o preço '
                    . 'praticado no momento.',
        // Existe movimento, mas não no recorte pedido.
        'periodo'  => 'Nenhuma compra no período e nos filtros escolhidos.',
        'grafico'  => 'Sem movimento no período — não há barras para desenhar.',
        'itens'    => 'Nenhum item vendido no período.',
        'jogadores'=> 'Nenhum jogador comprou no período.',
    ],

    'acoes' => [
        'aplicar' => 'Aplicar',
        'limpar'  => 'Limpar filtros',
        'csv'     => 'Exportar CSV',
        'tudo'    => 'Ver todo o período',
    ],

    /* Erros de validação do formulário de filtro, por campo. */
    'erros' => [
        'data'     => 'Use uma data no formato AAAA-MM-DD.',
        'invertido'=> 'A data inicial é posterior à final.',
        'futuro'   => 'A data inicial é posterior a hoje.',
        'moeda'    => 'Moeda desconhecida.',
    ],

    /* A tela é um livro-caixa: ela não escreve. A frase é a resposta a um POST. */
    'somente_leitura' => 'Transações são um registro histórico e não podem ser criadas, '
                       . 'editadas nem apagadas pelo painel. Uma linha aqui é a prova de uma '
                       . 'compra que já aconteceu; corrigi-la à mão apagaria a prova. Para '
                       . 'devolver um item ou um valor, use a tela do jogador.',
];
