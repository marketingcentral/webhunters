<?php declare(strict_types=1);

namespace App\Core;

/**
 * Regras e vocabulário canônicos do jogo, num único lugar.
 *
 * Tudo que o PRD v3 manda "não hardcodar fundo nos componentes" mora aqui: tamanho da unidade
 * Hunter, patentes, as quatro funções, as duas facções, faixas de reputação, escalas de
 * infestação e de atividade, tipos de extração, castas Veyra e eventos de ciclo de mundo.
 * Views e controllers consultam estas constantes; nenhuma tela inventa a sua própria lista.
 *
 * Capacidade por Base Governamental NÃO está aqui de propósito: é dado da tabela
 * `government_bases` (capacity), para poder mudar sem deploy.
 *
 * ---------------------------------------------------------------------------------------
 * GLOSSÁRIO (Bíblia de Universo v5.1)
 *   Hunter          soldado de elite do governo, membro do Hunter Command.
 *   Hunter Command  Comando de Operações de Reconquista da Autoridade de Reconquista.
 *   Mercenário      o MESMO personagem sob contrato exclusivo com Aegis ou Frontier.
 *   Sobrevivente    operador sem bandeira: faixas brancas no braço e na perna, sem extração
 *                   oficial nem de facção, permanece no mundo até morrer.
 *   Aegis           facção militarizada — força, blindagem, veículos, postos fortificados.
 *   Frontier        facção de rotas ocultas, túneis, contrabando e medicina de campo.
 *   Veyra           ecossistema extraterrestre adaptativo (terraformação), não uma raça.
 *   Coro            rede de comunicação biológica distribuída da Veyra.
 *   Núcleo Negro    estrutura que armazena e redistribui a memória ecológica da Veyra.
 *   Icarus          instituto científico que decodifica o Coro; origem das missões de Ciência.
 *   Licença Cinza   política que permite ao Hunter suspender o IFF do governo e assinar contrato.
 *   Sanção Faixa Branca  revogação da autoridade Hunter após quebra grave -> Sobrevivente.
 *   Operação        incursão com objetivo definido no mundo persistente.
 *   Extração        saída logística autorizada do mapa com os recursos carregados.
 *   Base Governamental   fortaleza de mobilização Hunter (Alpha, Bravo, Charlie).
 *   Base de Campo   estrutura persistente construída pelo jogador.
 *   Esquadrão       unidade Hunter de até 4 operadores.
 * ---------------------------------------------------------------------------------------
 */
final class GameRules
{
    /** Unidade Hunter: 1 jogador + até 3. Nunca 5. */
    public const SQUAD_MAX = 4;

    /** Teto humano de uma região e como ele se divide (Bíblia §3; PRD §3.1). */
    public const REGION_CAPACITY = ['total' => 20, 'hunters' => 12, 'mercenaries' => 8];

    // ------------------------------------------------------------------ estados operacionais

    public const STATE_HUNTER    = 'HUNTER';
    public const STATE_MERCENARY = 'MERCENARY';
    public const STATE_SURVIVOR  = 'SURVIVOR';

    public const STATES = [self::STATE_HUNTER, self::STATE_MERCENARY, self::STATE_SURVIVOR];

    // -------------------------------------------------------------------- hierarquia Hunter

    /** Da menor para a maior. O índice é a ordem de precedência (líder = maior índice). */
    public const RANKS = ['RECRUTA', 'SOLDADO', 'CABO', 'SARGENTO', 'TENENTE', 'CAPITÃO'];

    /**
     * As quatro funções oficiais. Rótulo exatamente como o PRD §18.3 exige; a descrição é a
     * da Bíblia (§3 "Quatro classes de campo") e vai em tooltip/subtexto.
     * `legacy` mapeia as grafias antigas dos mocks para a função oficial.
     */
    public const ROLES = [
        'ASSAULT'    => ['label' => 'ASSAULT',    'icon' => 'class-assault', 'ms' => 'swords',
                         'desc'  => 'Abre espaço, sustenta contato direto e leva a maior capacidade ofensiva da equipe.',
                         'legacy' => ['ASSALTO']],
        'SNIPER'     => ['label' => 'SNIPER',     'icon' => 'class-sniper',  'ms' => 'my_location',
                         'desc'  => 'Reconhecimento de longo alcance, cobertura, marcação e eliminação de ameaças prioritárias.',
                         'legacy' => ['FRANCO-ATIRADOR', 'FRANCO ATIRADOR']],
        'MEDICAL'    => ['label' => 'MEDICAL',    'icon' => 'class-support', 'ms' => 'medical_services',
                         'desc'  => 'Estabiliza feridos, administra recursos médicos e reduz a chance de uma baixa virar morte definitiva.',
                         'legacy' => ['MÉDICO', 'MEDICO', 'SUPORTE']],
        'RASTREADOR' => ['label' => 'RASTREADOR', 'icon' => 'class-scout',   'ms' => 'visibility',
                         'desc'  => 'Lê sinais da Veyra, atividade humana, pegadas, ruído e rotas recentes.',
                         'legacy' => ['BATEDOR']],
    ];

    // ---------------------------------------------------------------------------- facções

    /** Cores só as que já existem em tokens.css (PRD §13). */
    public const FACTIONS = [
        'aegis'    => ['label' => 'AEGIS',    'color' => 'cyan',
                       'tagline'  => 'Território só pertence a quem consegue mantê-lo.',
                       'identity' => 'Força, blindagem, veículos e postos fortificados.',
                       'economy'  => 'Peças militares, munição, componentes de veículo e logística.',
                       'missions' => 'Escoltas, defesas, ataques, recuperação de armas e controle de instalações.',
                       'npc'      => 'Patrulhas armadas, fogo de apoio e defesa de posições Aegis.'],
        'frontier' => ['label' => 'FRONTIER', 'color' => 'amber',
                       'tagline'  => 'Conhecer o que existe entre os territórios.',
                       'identity' => 'Rotas ocultas, túneis, esconderijos e caminhos fora da vigilância militar.',
                       'economy'  => 'Itens raros, medicina de campo, materiais científicos e mercado clandestino.',
                       'missions' => 'Infiltração, reconhecimento, contrabando, resgate e recuperação discreta.',
                       'npc'      => 'Guias, alertas de perigo, apoio local e acessos alternativos.'],
    ];

    /**
     * Faixas de reputação com uma facção. Ordenadas; o rótulo vale a partir de `min`.
     * "CONFIÁVEL" em 1.500 é o valor que o PRD usa como exemplo de requisito (§24.5).
     */
    public const REP_TIERS = [
        ['min' => 0,    'label' => 'RECRUTADO'],
        ['min' => 500,  'label' => 'OPERADOR'],
        ['min' => 1500, 'label' => 'CONFIÁVEL'],
        ['min' => 3000, 'label' => 'VETERANO'],
    ];

    // --------------------------------------------------------------------------- origens

    /** De quem vem uma missão/contrato. Cor = quem manda; Icarus roxo (ciência), mundo vermelho. */
    public const ORIGINS = [
        'hunter_command' => ['label' => 'HUNTER COMMAND',  'color' => 'lime',   'badge' => 'OPERAÇÃO HUNTER'],
        'aegis'          => ['label' => 'AEGIS',           'color' => 'cyan',   'badge' => 'CONTRATO MERCENÁRIO'],
        'frontier'       => ['label' => 'FRONTIER',        'color' => 'amber',  'badge' => 'CONTRATO MERCENÁRIO'],
        'icarus'         => ['label' => 'ICARUS',          'color' => 'purple', 'badge' => 'PEDIDO CIENTÍFICO'],
        'world'          => ['label' => 'EVENTO DE MUNDO', 'color' => 'red',    'badge' => 'EVENTO DE MUNDO'],
    ];

    // ----------------------------------------------------------------------------- escalas

    /** Infestação Veyra 0–100 -> rótulo. */
    public const INFESTATION = [
        ['max' => 20,  'label' => 'BAIXA',      'color' => 'lime'],
        ['max' => 40,  'label' => 'CONTROLADA', 'color' => 'lime'],
        ['max' => 60,  'label' => 'CRESCENTE',  'color' => 'amber'],
        ['max' => 80,  'label' => 'ALTA',       'color' => 'red'],
        ['max' => 100, 'label' => 'CRÍTICA',    'color' => 'purple'],
    ];

    /** Atividade (Veyra ou humana) em quatro degraus (PRD §17.9). */
    public const ACTIVITY = [
        'BAIXA'    => 'lime',
        'MODERADA' => 'amber',
        'ELEVADA'  => 'red',
        'CRÍTICA'  => 'purple',
    ];

    /** Estados de uma Base Governamental ou de Campo (PRD §23.3, §37). */
    public const BASE_STATUS = [
        'OPERACIONAL' => 'lime',
        'LOTADA'      => 'amber',
        'EM RISCO'    => 'amber',
        'ISOLADA'     => 'red',
        'SOB ATAQUE'  => 'red',
        'COMPROMETIDA'=> 'red',
    ];

    /** Relação de uma instalação de facção com o jogador (PRD §23.4). */
    public const FACTION_SITE_STATUS = [
        'ALIADA'    => 'lime',
        'NEUTRA'    => 'muted',
        'HOSTIL'    => 'red',
        'BLOQUEADA' => 'muted',
    ];

    // ----------------------------------------------------------------------------- extração

    /**
     * Tipos de extração. Slug = PRD §23.9; rótulo = nome canônico da Bíblia (§7 "Exemplos").
     * Sobrevivente não tem direito a nenhum deles.
     */
    public const EXTRACTION_TYPES = [
        'governamental' => ['label' => 'EVAC HUNTER',      'color' => 'lime',   'who' => 'Requer autorização Hunter ativa.'],
        'publica'       => ['label' => 'PÚBLICA',          'color' => 'muted',  'who' => 'Gratuita, previsível e exposta. Todo operador conhece a rota.'],
        'aegis'         => ['label' => 'TRANSPORTE AEGIS', 'color' => 'cyan',   'who' => 'Blindado ou comboio da infraestrutura Aegis.'],
        'frontier'      => ['label' => 'ROTA FRONTIER',    'color' => 'amber',  'who' => 'Saída discreta por trilhas, túneis, rios ou pontos clandestinos.'],
        'clandestina'   => ['label' => 'CLANDESTINA',      'color' => 'purple', 'who' => 'Sem dono declarado. Ninguém garante que ela ainda existe.'],
    ];

    /** Estados de um ponto de extração. `blocked` é o que o Sobrevivente vê em todos. */
    public const EXTRACTION_STATES = [
        'active'      => ['label' => 'ATIVA',        'color' => 'lime'],
        'conditional' => ['label' => 'CONDICIONAL',  'color' => 'amber'],
        'inactive'    => ['label' => 'INATIVA',      'color' => 'muted'],
        'blocked'     => ['label' => 'BLOQUEADA',    'color' => 'muted'],
    ];

    public const SURVIVOR_EXTRACTION_TIP = 'INDISPONÍVEL NO ESTADO SOBREVIVENTE.';

    // ---------------------------------------------------------------------------------- Veyra

    /** Castas conhecidas (Bíblia §2). Usadas em marcadores (meta.caste), conquistas e boletins. */
    public const VEYRA_CASTES = [
        'runners'      => 'Predadores rápidos. Pressionam flancos e perseguem operadores isolados.',
        'burrowers'    => 'Escavam túneis, rompem perímetros e criam emboscadas subterrâneas.',
        'sporecasters' => 'Negam área com esporos e contaminação biológica.',
        'bulwarks'     => 'Organismos pesados destinados a romper defesas e veículos.',
        'harvesters'   => 'Movem biomassa e recursos entre estruturas; revelam rotas de ninho.',
        'listeners'    => 'Detectam vibração e atividade eletrônica, aumentando a pressão do enxame.',
        'mimics'       => 'Camuflagem e adaptação ao ambiente; provocam dúvida e paranoia.',
        'colossi'      => 'A resposta extrema da Veyra quando uma região entra em guerra aberta.',
    ];

    /** Contaminação Veyra 0–100 -> estado (PRD §20.1). */
    public const CONTAMINATION = [
        ['max' => 0,   'label' => 'LIMPO',              'color' => 'lime'],
        ['max' => 25,  'label' => 'EXPOSIÇÃO BAIXA',    'color' => 'lime'],
        ['max' => 50,  'label' => 'EXPOSIÇÃO MODERADA', 'color' => 'amber'],
        ['max' => 75,  'label' => 'CONTAMINADO',        'color' => 'red'],
        ['max' => 100, 'label' => 'CRÍTICO',            'color' => 'purple'],
    ];

    // ------------------------------------------------------------------------- ciclo de mundo

    /**
     * Eventos que justificam recursos voltando ao mapa (Bíblia §7). A UI nunca diz "respawn";
     * diz o que aconteceu. `logistic` = entra no rótulo "PRÓXIMA JANELA LOGÍSTICA".
     */
    public const WORLD_EVENTS = [
        'convoy'          => ['label' => 'COMBOIO',              'logistic' => true],
        'supply_drop'     => ['label' => 'QUEDA DE SUPRIMENTOS', 'logistic' => true],
        'resupply'        => ['label' => 'REABASTECIMENTO',      'logistic' => true],
        'abandoned_cargo' => ['label' => 'CARGA ABANDONADA',     'logistic' => true],
        'faction_move'    => ['label' => 'MOVIMENTAÇÃO DE FACÇÃO','logistic' => false],
        'veyra_bloom'     => ['label' => 'NOVA FORMAÇÃO VEYRA',  'logistic' => false],
        'storm'           => ['label' => 'TEMPESTADE',           'logistic' => false],
        'bio_regrowth'    => ['label' => 'REBROTA BIOLÓGICA',    'logistic' => false],
        'surge'           => ['label' => 'SURTO',                'logistic' => false],
        'migration'       => ['label' => 'MIGRAÇÃO',             'logistic' => false],
        'nest_found'      => ['label' => 'NINHO DESCOBERTO',     'logistic' => false],
        'base_attack'     => ['label' => 'BASE SOB ATAQUE',      'logistic' => false],
        'distress'        => ['label' => 'SINAL DE SOCORRO',     'logistic' => false],
    ];

    /** Marcadores temporários de atividade humana (PRD §23.6): aproximados e com expiração. */
    public const HUMAN_SIGNALS = [
        'shots'        => 'TIROS DETECTADOS',
        'transmission' => 'TRANSMISSÃO INTERCEPTADA',
        'movement'     => 'MOVIMENTO HUMANO',
        'combat'       => 'COMBATE ATIVO',
        'extraction'   => 'SINAL DE EXTRAÇÃO',
    ];

    // ----------------------------------------------------------------------------- helpers

    /** Posição da patente na cadeia (−1 se desconhecida). Maior = mais alto. */
    public static function rankIndex(string $rank): int
    {
        $i = array_search(mb_strtoupper(trim($rank)), self::RANKS, true);
        return $i === false ? -1 : (int)$i;
    }

    /** Normaliza qualquer grafia antiga (ASSALTO, BATEDOR, SUPORTE…) para a função oficial. */
    public static function role(?string $any): ?string
    {
        if ($any === null || trim($any) === '') {
            return null;
        }
        $up = mb_strtoupper(trim($any));
        foreach (self::ROLES as $key => $r) {
            if ($up === $key || in_array($up, $r['legacy'], true)) {
                return $key;
            }
        }
        return null;
    }

    /** Faixa de reputação para um valor. */
    public static function repTier(int $rep): string
    {
        $label = self::REP_TIERS[0]['label'];
        foreach (self::REP_TIERS as $t) {
            if ($rep >= $t['min']) { $label = $t['label']; }
        }
        return $label;
    }

    /** A faixa `$have` satisfaz o requisito `$need`? */
    public static function tierMeets(string $have, string $need): bool
    {
        $order = array_column(self::REP_TIERS, 'label');
        $h = array_search(mb_strtoupper($have), $order, true);
        $n = array_search(mb_strtoupper($need), $order, true);
        return $h !== false && $n !== false && $h >= $n;
    }

    /** @return array{label:string,color:string} */
    public static function infestation(int $pct): array
    {
        $pct = max(0, min(100, $pct));
        foreach (self::INFESTATION as $s) {
            if ($pct <= $s['max']) { return ['label' => $s['label'], 'color' => $s['color']]; }
        }
        return ['label' => 'CRÍTICA', 'color' => 'purple'];
    }

    /** @return array{label:string,color:string} */
    public static function contamination(int $value): array
    {
        $value = max(0, min(100, $value));
        foreach (self::CONTAMINATION as $s) {
            if ($value <= $s['max']) { return ['label' => $s['label'], 'color' => $s['color']]; }
        }
        return ['label' => 'CRÍTICO', 'color' => 'purple'];
    }

    /** Cor de um degrau de atividade; desconhecido cai em muted. */
    public static function activityColor(string $level): string
    {
        return self::ACTIVITY[mb_strtoupper(trim($level))] ?? 'muted';
    }

    public static function faction(?string $slug): ?array
    {
        $slug = strtolower((string)$slug);
        return self::FACTIONS[$slug] ?? null;
    }

    public static function origin(?string $slug): array
    {
        return self::ORIGINS[strtolower((string)$slug)] ?? self::ORIGINS['hunter_command'];
    }
    // ------------------------------------------------------------------------- SAFE ZONE

    /**
     * SAFE ZONE — perímetro protegido junto às Bases Governamentais, onde a economia humana
     * acontece entre operações: armas guardadas, sem PvP, sem Veyra. Aqui o mesmo personagem
     * assume um PAPEL CIVIL e presta serviço aos outros operadores por um turno.
     *
     * Não é um segundo personagem nem um modo separado: continua valendo o estado operacional
     * (Hunter / Mercenário / Sobrevivente) — ele é justamente quem decide onde você entra e
     * qual papel pode assumir.
     */
    public const SAFE_SHIFT_MIN = 45;             // duração de um turno, em minutos
    public const SAFE_ZONE_GOV  = 'gov';          // perímetro do governo (Hunter Command)
    public const SAFE_ZONE_FREE = 'free';         // zona franca tolerada (Frontier)

    /** Estado de uma zona -> cor do badge. */
    public const SAFE_ZONE_STATUS = [
        'ABERTA'      => 'lime',
        'MOVIMENTADA' => 'amber',
        'EM ALERTA'   => 'amber',
        'FECHADA'     => 'red',
        'TOLERADA'    => 'muted',
    ];

    /** Ocupação civil da zona — faixa, nunca contagem exata de quem está lá. */
    public const SAFE_OCCUPANCY = [
        'BAIXA'    => 'lime',
        'MODERADA' => 'lime',
        'ALTA'     => 'amber',
        'LOTADA'   => 'red',
    ];

    /**
     * Papéis civis. `req` é o que a HUD confere antes de deixar assumir:
     *   level   nível mínimo do personagem
     *   rank    patente mínima (só faz sentido para Hunter)
     *   state   estado operacional exigido (HUNTER / MERCENARY / SURVIVOR) ou null
     *   faction true = exige contrato mercenário ativo (qualquer facção)
     *   role    função Hunter exigida ou null
     */
    public const SAFE_ROLES = [
        'comerciante' => [
            'label' => 'COMERCIANTE', 'ms' => 'storefront', 'color' => 'gold',
            'summary' => 'Abre banca no mercado da zona.',
            'desc'    => 'Compra o que os outros trouxeram do campo e revende a quem vai entrar. Define o próprio preço e responde por ele.',
            'gain'    => 'Comissão em créditos sobre cada negócio fechado na sua banca.',
            'req'     => ['level' => 0,  'rank' => null,       'state' => null, 'faction' => false, 'role' => null],
        ],
        'ferramenteiro' => [
            'label' => 'FERRAMENTEIRO', 'ms' => 'build', 'color' => 'cyan',
            'summary' => 'Repara e modifica equipamento alheio.',
            'desc'    => 'Recupera durabilidade, instala acessórios e devolve arma boa a quem não tem bancada própria.',
            'gain'    => 'Pagamento por serviço e peças recuperadas do que passa pela oficina.',
            'req'     => ['level' => 5,  'rank' => null,       'state' => null, 'faction' => false, 'role' => null],
        ],
        'instrutor' => [
            'label' => 'INSTRUTOR', 'ms' => 'school', 'color' => 'lime',
            'summary' => 'Treina recrutas do Hunter Command.',
            'desc'    => 'Ensina leitura de terreno, disciplina de fogo e protocolo de extração a quem ainda vai à primeira operação.',
            'gain'    => 'Experiência por recruta treinado e peso na sua folha de conduta.',
            'req'     => ['level' => 0,  'rank' => 'SARGENTO', 'state' => self::STATE_HUNTER, 'faction' => false, 'role' => null],
        ],
        'medico' => [
            'label' => 'MÉDICO DA ZONA', 'ms' => 'medical_services', 'color' => 'red',
            'summary' => 'Atende ferimentos e contaminação.',
            'desc'    => 'Trata quem voltou inteiro por pouco: hemorragia, fratura, infecção e exposição a esporos Veyra.',
            'gain'    => 'Pagamento por atendimento e acesso ao estoque médico da enfermaria.',
            'req'     => ['level' => 8,  'rank' => null,       'state' => null, 'faction' => false, 'role' => 'MEDICAL'],
        ],
        'transportador' => [
            'label' => 'TRANSPORTADOR', 'ms' => 'local_shipping', 'color' => 'amber',
            'summary' => 'Move carga entre zonas na janela logística.',
            'desc'    => 'Organiza o comboio, escolhe a rota e responde pela carga de outros operadores até a próxima zona.',
            'gain'    => 'Frete em créditos e reputação com a facção que assina a carga.',
            'req'     => ['level' => 0,  'rank' => null,       'state' => null, 'faction' => true,  'role' => null],
        ],
        'analista' => [
            'label' => 'ANALISTA ICARUS', 'ms' => 'biotech', 'color' => 'purple',
            'summary' => 'Decodifica amostras, sinais e intel.',
            'desc'    => 'Cruza gravações do Coro, amostras biológicas e registros recuperados em campo para o Instituto Icarus.',
            'gain'    => 'Intel utilizável em operação e crédito científico com o Icarus.',
            'req'     => ['level' => 12, 'rank' => 'SARGENTO', 'state' => self::STATE_HUNTER, 'faction' => false, 'role' => null],
        ],
    ];

    /** Chave canônica de um papel civil (aceita o rótulo). */
    public static function safeRoleKey(?string $any): ?string
    {
        $k = strtolower(trim((string)$any));
        if (isset(self::SAFE_ROLES[$k])) { return $k; }
        foreach (self::SAFE_ROLES as $key => $r) {
            if (mb_strtoupper((string)$any) === $r['label']) { return $key; }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function safeRole(?string $any): ?array
    {
        $k = self::safeRoleKey($any);
        return $k !== null ? self::SAFE_ROLES[$k] + ['key' => $k] : null;
    }

    /** Faixa de ocupação -> cor. */
    public static function occupancyColor(string $level): string
    {
        return self::SAFE_OCCUPANCY[mb_strtoupper(trim($level))] ?? 'muted';
    }
}