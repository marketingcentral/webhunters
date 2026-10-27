<?php declare(strict_types=1);

/**
 * PÁGINA DE DIVULGAÇÃO (/) — cromo e conteúdo.
 *
 * Tudo aqui sai de docs/Extract_Hunters_Biblia_Canon_v6_0.md: a Queda do meteoro Vesper, a
 * Veyra como fungo, o ritmo de survival estratégico, a contaminação, os três estados, as duas
 * facções e as quatro extrações. Nada foi inventado para soar bem — a página promete o que o
 * jogo tem. O que é DADO (regiões, marcadores, capacidade, infestação) não está aqui: vem do
 * banco, por App\Core\WorldMap, montado no LandingController. Este arquivo é só o que se lê.
 *
 * AS CRIATURAS NÃO ESTÃO AQUI. A fonte única é app/Data/criaturas.php (grupos, lista,
 * abordagens), a mesma que o Bestiário da área logada e o mapa leem. Duplicar a
 * lista nesta página foi o que deixou a landing presa no enredo antigo quando o canon mudou.
 *
 * Por que arrays e não markup: dá para reler a página inteira sem caçar texto entre <div>s.
 */
return [
    'title'       => 'EXTRACT HUNTERS — survival tático de extração num mundo tomado por um fungo que caiu do céu',
    'description' => 'Em 2058 um meteoro caiu na ilha trazendo a Veyra, um fungo de outro mundo que toma pessoas, animais e a própria terra. Cinco anos depois, o governo manda equipes pequenas de volta. Pense antes de atirar: o barulho chama, a munição é pouca e o seu corpo pode levantar do outro lado.',

    'nav' => [
        ['#queda',      'A QUEDA'],
        ['#criaturas',  'CRIATURAS'],
        ['#sobreviver', 'SOBREVIVÊNCIA'],
        ['#estados',    'ESTADOS'],
        ['#mundo',      'A ILHA'],
        ['#faccoes',    'FACÇÕES'],
    ],

    'hero' => [
        'kicker'  => 'CAIU DO CÉU EM 2058. AINDA ESTÁ CRESCENDO.',
        'boot'    => 'ENLACE ESTABELECIDO · ZONA DE QUEDA · ANO 2063 · MUNDO PERSISTENTE',
        'tagline' => 'SOBREVIVA. EXTRAIA. EVOLUA.',
        'lead'    => 'Survival tático de extração num mundo tomado por um fungo que caiu do céu. A munição é pouca, o barulho chama e toda criatura dá sinal antes do contato. Aqui não sobrevive quem atira primeiro: sobrevive quem pensa na jogada.',
        'cta'     => 'ENTRAR NA OPERAÇÃO',
        'cta2'    => 'RECONHECER O SETOR',
        'setor'   => 'ZONA DE OPERAÇÃO · SETOR A7',
        'coords'  => ['41.6723° S', '171.2913° E'],
        'verticais' => ['OBSERVAR', 'PLANEJAR', 'SOBREVIVER', 'EXTRAIR'],
        'retrato' => 'portraits/op-assault',
        'telemetria_titulo' => 'TELEMETRIA DO SETOR',
    ],

    /** A faixa logo abaixo do herói: o jogo em cinco frases (§01 e §04 da Bíblia). */
    'pilares' => [
        ['public',      'MUNDO PERSISTENTE',   'Nada reinicia. O que você deixa no setor continua lá.'],
        ['hearing',     'RUÍDO É O INIMIGO',   'Cada tiro tem raio. O fuzil resolve um problema e chama três.'],
        ['person',      'SOLO É POSSÍVEL',     'Tudo o que é comum dá para fazer sozinho. Equipe muda a conta.'],
        ['coronavirus', 'CONTAMINAÇÃO',        'Mordida, arranhão ou esporo. Em 100, o seu corpo levanta.'],
        ['shield',      'FACÇÕES EM CONFLITO', 'Aegis e Frontier disputam os fragmentos — e você.'],
    ],

    /**
     * A QUEDA (§02). A arte é `creature_img('a-queda')` e `creature_img('cratera')`; enquanto
     * os arquivos não existem, a view desenha o quadro vazio com a legenda abaixo.
     */
    'queda' => [
        'kicker'  => 'ORIGEM',
        'titulo'  => 'A QUEDA',
        'citacao' => 'Caiu do céu numa noite de outubro. Cinco anos depois, ainda estamos tentando entender o que veio dentro.',
        'texto'   => 'Por fora, o Vesper parecia um condrito comum: rocha escura, porosa e pesada. Por dentro, os poros guardavam esporos dormentes presos em gelo e numa resina negra que nenhum laboratório conseguiu classificar. O calor da entrada na atmosfera não os matou. O impacto os acordou.',
        'arte'    => ['slug' => 'a-queda', 'legenda' => 'VESPER · 14 OUT 2058 · ENTRADA NA ATMOSFERA', 'icone' => 'flare'],
        /** [quando, título, texto, destaque?] — `agora` marca onde o jogador entra. */
        'linha' => [
            ['2057',           'RASTREIO',          'O Projeto Vesper cataloga um corpo escuro e poroso em rota de colisão. A previsão oficial: queda no oceano.', ''],
            ['14 OUT 2058',    'A QUEDA',           'O Vesper se fragmenta sobre a ilha. O corpo principal abre uma cratera ao lado do complexo industrial da costa.', 'queda'],
            ['OUT–NOV 2058',   'FEBRE CINZA',       'A Axiom recolhe os fragmentos. Quem manuseou a rocha tem febre alta, tosse seca e, depois, agressividade.', ''],
            ['DEZ 2058',       'PRIMEIRO ATAQUE',   'Um operário da Axiom morde um enfermeiro no hospital da costa. A quarentena chega tarde demais.', ''],
            ['2059',           'O ESPALHAMENTO',    'Infectados em incubação embarcam em navios e aviões; aves levam esporo ao continente. Em dezoito meses, todos os continentes.', ''],
            ['2060–2062',      'CIDADES SELADAS',   'Os governos recuam para cidades seladas e corredores militares. Fora deles, o fungo refaz florestas, rios e ruínas.', ''],
            ['2063',           'RECONQUISTA',       'Nasce o Hunter Command. A ilha onde tudo começou vira o primeiro teatro de operações.', 'agora'],
        ],
        'agora' => 'VOCÊ ENTRA AQUI',

        /** Como o fungo toma o que encontra (§02 · Veyra). */
        'fungo_titulo' => 'VEYRA — O FUNGO QUE VEIO DO CÉU',
        'fungo_texto'  => 'Não é vírus nem gás: é uma rede de micélio que cresce em qualquer matéria orgânica. Os infectados não são mortos que levantam. Estão vivos, com o sistema nervoso tomado, e morrem como qualquer corpo.',
        'fungo' => [
            ['person',     'EM PESSOAS', 'Entra por mordida, arranhão ou pulmão e sobe pelo sistema nervoso. O hospedeiro continua vivo: o fungo só precisa que ele ande, coma e morda.'],
            ['pets',       'EM ANIMAIS', 'Os bichos que comeram o que caiu da cratera foram os primeiros hospedeiros. Mantêm o instinto e perderam o medo.'],
            ['psychiatry', 'NO SOLO',    'Onde o micélio cobre o chão nasce um ecossistema alienígena: plantas e estruturas que vieram dentro da rocha.'],
            ['air',        'NO AR',      'Colônias maduras soltam nuvens densas de esporo, a névoa amarela. O ar comum não infecta. Máscara e filtro funcionam.'],
        ],

        /** O mistério central, no lugar da antiga seção do Coro. O terceiro arquivo abre num <details>. */
        'misterio' => [
            'kicker' => 'MISTÉRIO CENTRAL',
            'titulo' => 'O QUE VEIO DENTRO',
            'arte'   => ['slug' => 'cratera', 'legenda' => 'CRATERA VESPER · ZONA INDUSTRIAL · ACESSO RESTRITO', 'icone' => 'crisis_alert'],
            'quote'  => 'A Veyra não pensa como uma pessoa. Mas a ilha inteira parece lembrar do que fizemos com ela.',
            'arquivos' => [
                ['graphic_eq', 'O CORO',        'ARQUIVO ICARUS · 01',
                 'Sensores do Instituto Icarus registram pulsos elétricos correndo pelo micélio entre colônias a dezenas de quilômetros. Cruzadas as frequências, aparece padrão onde devia haver ruído — e todas as linhas convergem para a cratera.'],
                ['radio_button_checked', 'O NÚCLEO NEGRO', 'ARQUIVO ICARUS · 02',
                 'O corpo principal do meteoro está enterrado sob a Zona Industrial. É o centro do Coro e o fim da campanha. Os fragmentos que saíram dele são o recurso mais valioso da ilha — e o motivo de nenhuma facção aceitar sair dela.'],
            ],
            'classificado' => [
                'rotulo' => 'ARQUIVO ICARUS · 03 · CLASSIFICADO',
                'abrir'  => 'ABRIR ARQUIVO',
                'titulo' => 'A ROCHA É UMA CÁPSULA',
                'texto'  => 'O Vesper não caiu por acaso. É uma semente lançada de algum lugar para encontrar um planeta, tomar o que houver de vida nele e transformá-lo em algo que sirva a outro padrão de vida. A Veyra não odeia a humanidade. Para ela, nós somos matéria disponível.',
            ],
            'pergunta' => 'Arquivos recuperados sugerem que a Axiom sabia que a rocha estava viva semanas antes do primeiro ataque. Quem sabia o que tinha caído — e por que deixou o mundo inteiro pagar por isso?',
        ],
    ],

    /**
     * CRIATURAS (§03). Só o cromo da seção: a lista, os grupos e as abordagens (como lidar)
     * vêm de app/Data/criaturas.php.
     */
    'bestiario' => [
        'kicker'  => 'BESTIÁRIO',
        'titulo'  => 'O QUE VIVE NAS ZONAS',
        'citacao' => 'Toda criatura tem um sinal que se lê antes do contato.',
        'texto'   => 'Pessoas tomadas pelo micélio, animais que perderam o medo e plantas que não são da Terra. Quase tudo aqui tem escala humana e um jeito de passar que não depende de muita munição: silêncio, lâmina, porta, fogo, distância, rádio. Só uma coisa não se enfrenta.',
        'todos'   => 'TODAS',
        'legenda' => 'COMO LIDAR',
        'anterior' => 'Criaturas anteriores',
        'proxima'  => 'Próximas criaturas',
        'ficha_nota' => 'Fraqueza, habitat e o registro de avistamentos ficam no Bestiário, dentro do jogo.',
        'sem_arte'   => 'REGISTRO VISUAL PENDENTE',
    ],

    /** SOZINHO OU EM EQUIPE + CONTAMINAÇÃO (§04). */
    'sobreviver' => [
        'kicker' => 'RITMO',
        'titulo' => 'SOZINHO OU EM EQUIPE',
        'citacao' => 'Não é um jogo para jogar rápido. É um jogo para pensar na jogada.',
        'unidade' => 'UNIDADES DE 1 A 4',
        'solo' => [
            'icone'  => 'person',
            'titulo' => 'SOZINHO',
            'texto'  => 'Tudo o que é comum dá para fazer sozinho: lootear, cumprir a maioria dos contratos, atravessar uma região, extrair. O jogo nunca exige que o solo tenha uma arma automática.',
            'itens'  => [
                ['INFORMAÇÃO',  'Binóculo, rastro, céu e mapa de infestação. Um minuto observando custa menos que uma entrada atirando.'],
                ['SILÊNCIO',    'Abate por trás, lâmina, porta fechada. A briga que não precisa acontecer não acontece.'],
                ['O BARULHO A SEU FAVOR', 'Uma garrafa jogada longe tira um grupo inteiro do caminho.'],
                ['O CUSTO',     'Sozinho, cair é morrer. Ninguém levanta você.'],
            ],
        ],
        'equipe' => [
            'icone'  => 'groups',
            'titulo' => 'EM EQUIPE',
            'texto'  => 'O jogo recompensa equipe sem punir quem joga sozinho. Algumas respostas só existem em dois — e o conteúdo extremo exige esquadrão.',
            'itens'  => [
                ['REANIMAR E CARREGAR', 'Um operador derrubado pode ser levantado ou arrastado para fora.'],
                ['FUNÇÕES QUE SE COMPLETAM', 'RASTREADOR lê o setor, SNIPER marca Vigias, MÉDICO segura a contaminação, ASSAULT segura a linha.'],
                ['RESPOSTAS DE DOIS', 'Atrair e flanquear o Couraçado, cobrir o vão do Rastejante, confirmar o Eco pelo rádio.'],
                ['BÔNUS DE ESQUADRÃO', 'Contratos de contenção de Matriz e extração conjunta pagam mais.'],
            ],
        ],
        'regras' => [
            ['volume_up',      'RUÍDO',       'Tiro sem supressor, motor e porta arrombada têm raio de atração.'],
            ['inventory_2',    'ESCASSEZ',    'Munição, filtro de máscara e antifúngico são raros.'],
            ['visibility',     'LEITURA',     'Quem observa antes de entrar perde menos.'],
            ['dark_mode',      'DIA E NOITE', 'À noite os Faróis acendem, os Febris caçam e os Ecos chamam.'],
        ],

        /** Medidor 0–100 (§04 · Contaminação). [de, até, estado, o que acontece, como tratar, cor] */
        'contaminacao' => [
            'kicker' => 'O CENTRO DO SURVIVAL',
            'titulo' => 'CONTAMINAÇÃO',
            'texto'  => 'Mordida, arranhão e nuvem de esporo enchem um medidor de 0 a 100 que vale a operação inteira. Arraste e veja o que acontece com o corpo.',
            'rotulo' => 'NÍVEL DE CONTAMINAÇÃO',
            'inicial' => 37,
            'faixas' => [
                [0,   0,  'LIMPO',              'Nada no sangue.',                                         'Manter o filtro em dia.',                       'lime'],
                [1,   25, 'EXPOSIÇÃO BAIXA',    'Sem sintomas.',                                           'Tempo fora da nuvem; filtro novo.',             'lime'],
                [26,  50, 'EXPOSIÇÃO MODERADA', 'Tosse ocasional — e tosse faz barulho.',                  'Antifúngico de campo.',                         'amber'],
                [51,  75, 'CONTAMINADO',        'Febre: mira tremida, fôlego curto.',                      'Antifúngico e o MÉDICO do esquadrão.',          'red'],
                [76,  99, 'CRÍTICO',            'Visão turva, ecos na cabeça, o mapa falhando.',           'Soro Vesper (raro) ou extração médica.',        'red'],
                [100, 100, 'O CORPO LEVANTA',   'O operador morre e o corpo levanta como ERRANTE no mundo persistente, com o equipamento que carregava.', 'O esquadrão pode voltar, abater o que sobrou e recuperar a carga.', 'purple'],
            ],
        ],
    ],

    /** Os três estados do MESMO personagem (§01, §06). O `cor` retinge a página inteira. */
    'estados' => [
        'hunter' => [
            'nome'   => 'HUNTER', 'num' => '01',
            'titulo' => 'OBSERVA. PLANEJA. EXECUTA.',
            'cor'    => '155,224,74',
            'img'    => 'portraits/op-sniper',
            'linha'  => 'Soldado do Hunter Command. Patente, função de campo e cadeia de comando.',
            'texto'  => 'O governo não manda exércitos para as zonas infectadas: manda unidades de um a quatro operadores que aguentam dias lá dentro. Você tem extração oficial, missões de campanha e um Relatório de Conduta que registra o que você faz com isso.',
            'regras' => [
                ['PATENTE E FUNÇÃO', 'De Recruta a Capitão, em ASSAULT, SNIPER, MEDICAL ou RASTREADOR.'],
                ['EXTRAÇÃO',         'EVAC HUNTER liberada — autorização ativa do governo.'],
                ['DISCIPLINA',       'Fogo amigo deliberado e confirmado revoga o status. Você vira Sobrevivente.'],
            ],
            'selo'      => 'RECRUTA',
            'extracoes' => ['evac' => true, 'publica' => true, 'aegis' => false, 'frontier' => false],
        ],
        'mercenario' => [
            'nome'   => 'MERCENÁRIO', 'num' => '02',
            'titulo' => 'LUTA. CONQUISTA. LUCRA.',
            'cor'    => '245,166,35',
            'img'    => 'portraits/op-assault',
            'linha'  => 'O mesmo personagem, sob contrato exclusivo com Aegis ou Frontier.',
            'texto'  => 'A Licença Cinza permite suspender o IFF do governo e ligar o transponder de uma facção. O inventário é o mesmo, as cicatrizes e a contaminação no sangue são as mesmas — o que muda é quem reconhece sua autoridade, quem te protege e quem passa a te caçar.',
            'regras' => [
                ['EXCLUSIVO',   'Aegis OU Frontier. Trocar de lado zera a reputação de quem você abandonou.'],
                ['EXTRAÇÃO',    'A rota da sua facção abre; a da rival fecha.'],
                ['SEM RESGATE', 'Durante a licença, o Hunter Command não garante a sua volta.'],
            ],
            'selo'      => 'SEM CONTRATO',
            'extracoes' => ['evac' => false, 'publica' => true, 'aegis' => true, 'frontier' => true],
        ],
        'sobrevivente' => [
            'nome'   => 'SOBREVIVENTE', 'num' => '03',
            'titulo' => 'ADAPTA. IMPROVISA. PERSISTE.',
            'cor'    => '232,237,230',
            'img'    => 'portraits/op-scout',
            'linha'  => 'Faixas brancas no braço e na perna: ninguém responde por este operador.',
            'texto'  => 'Não há extração de nenhum tipo. Você permanece no mundo até morrer, e a única forma de transformar tempo vivo em progresso é uma base de campo que você mesmo construiu — e que outro jogador pode encontrar.',
            'regras' => [
                ['SEM HIERARQUIA', 'Nenhuma ordem, nenhuma proteção, nenhuma regra de engajamento.'],
                ['EXTRAÇÃO',       'Nenhuma. Nem a pública.'],
                ['PERSISTÊNCIA',   'Você fica no mundo. O mundo continua sem você quando acabar.'],
            ],
            'selo'      => 'SEM EXTRAÇÃO',
            'extracoes' => ['evac' => false, 'publica' => false, 'aegis' => false, 'frontier' => false],
        ],
    ],

    /** Os quatro tipos de extração. A chave casa com `extracoes` de cada estado. */
    'extracoes' => [
        ['id' => 'evac',     'nome' => 'EVAC HUNTER',      'sub' => 'Extração oficial do governo. Requer autorização ativa Hunter.'],
        ['id' => 'aegis',    'nome' => 'TRANSPORTE AEGIS', 'sub' => 'Blindado ou comboio ligado à infraestrutura Aegis.'],
        ['id' => 'frontier', 'nome' => 'ROTA FRONTIER',    'sub' => 'Saída discreta por trilhas, túneis, rios ou pontos clandestinos.'],
        ['id' => 'publica',  'nome' => 'PÚBLICA',          'sub' => 'Gratuita, previsível e mais exposta. Qualquer operador conhece a rota.'],
    ],

    'mundo' => [
        'kicker' => 'EXPLORE',
        'titulo' => 'A ILHA',
        'texto'  => 'Onde tudo começou. Da floresta à Zona Industrial, onde o corpo do meteoro está enterrado, cada região tem a sua infestação, as suas criaturas e as suas rotas de saída — e este é o MESMO mapa que o operador lê dentro do jogo, com os mesmos pontos.',
        'cta'    => 'EXPLORAR O MAPA',
        'dica'   => 'Arraste para mover · roda para aproximar · as camadas ligam e desligam',
    ],

    'sistemas' => [
        'kicker' => 'SISTEMAS DE GAMEPLAY',
        'titulo' => 'MAIS DO QUE UMA EXTRAÇÃO',
        'texto'  => 'Sistemas que se cruzam: o tiro que você dá num contrato muda a infestação do setor, e a infestação muda o que aparece na próxima operação.',
        'itens'  => [
            ['explore',        'EXPLORAÇÃO',           'Leia o setor antes de entrar nele.',
             'Fora das Cidades Seladas, o fungo refez florestas, rios e ruínas. Rota pisada demais vira rastro — e onde há rastro, Vigias e Rastejantes se instalam.', 'maps/vale-do-eco'],
            ['assignment',     'CONTRATOS',            'Missões dinâmicas, recompensas reais.',
             'Operações do Hunter Command, contratos Aegis e Frontier, Fragmentos Vesper e amostras para o Instituto Icarus. Destruir uma Matriz devolve território de verdade.', 'maps/complexo-militar'],
            ['home_work',      'BASES',                'Construa, melhore, prepare-se.',
             'Três Bases Governamentais lançam esquadras. Bases de campo transformam tempo vivo em progresso — e podem ser encontradas por outro jogador.', 'maps/base-artica'],
            ['flight_takeoff', 'EXTRAÇÃO',             'Chegue vivo. Leve o que é seu.',
             'EVAC HUNTER, TRANSPORTE AEGIS, ROTA FRONTIER ou PÚBLICA. Cada rota tem dono, custo e ruído — e o Sobrevivente não tem nenhuma.', 'maps/porto-industrial'],
            ['military_tech',  'PROGRESSÃO',           'Equipe, habilidades, novas possibilidades.',
             'Patente de Recruta a Capitão, quatro funções de campo, reputação com cada facção. Nenhuma delas é uma conta nova: é o mesmo operador.', 'maps/cidade-em-ruinas'],
            ['groups',         'COOPERAÇÃO / CONFLITO', 'Alie-se ou enfrente. A escolha é sua.',
             'Unidades de um a quatro. Vinte humanos por região. O PvP não é um modo: é o que acontece quando dois esquadrões querem a mesma extração.', 'maps/fabrica-abandonada'],
        ],
    ],

    /** As duas facções. Assinar com uma apaga a outra — é a interação da seção. */
    'faccoes' => [
        ['id' => 'aegis', 'nome' => 'AEGIS', 'cor' => '62,201,240', 'img' => 'maps/complexo-militar',
         'lema'   => 'ORDEM. PESQUISA. CONTROLE.',
         'frase'  => 'Território é de quem consegue mantê-lo.',
         'origem' => 'Empresas militares privadas, seguranças corporativos e unidades isoladas que sobreviveram fora da proteção do governo.',
         'itens'  => [
            ['FORÇA',    'Armamento pesado, veículos, postos fortificados, combate frontal.'],
            ['ECONOMIA', 'Peças militares, blindagem, munição, componentes de veículo.'],
            ['MISSÕES',  'Escolta, defesa, ataque, recuperação de armas, controle de instalação.'],
            ['APOIO',    'Patrulhas armadas e fogo de apoio em posições Aegis.'],
         ]],
        ['id' => 'frontier', 'nome' => 'FRONTIER', 'cor' => '245,166,35', 'img' => 'maps/vale-do-eco',
         'lema'   => 'LIBERDADE. RECURSOS. SOBREVIVÊNCIA.',
         'frase'  => 'Conhecemos o que existe entre os territórios.',
         'origem' => 'Comunidades isoladas, guias, catadores, contrabandistas e engenheiros que navegavam zonas abandonadas antes de existir mapa oficial.',
         'itens'  => [
            ['CONHECIMENTO', 'Rotas ocultas, túneis, esconderijos fora da vigilância militar.'],
            ['ECONOMIA',     'Itens raros, medicina de campo, material científico, mercado clandestino.'],
            ['MISSÕES',      'Infiltração, reconhecimento, contrabando, resgate, recuperação discreta.'],
            ['APOIO',        'Guias, alertas de perigo e acessos alternativos.'],
         ]],
    ],
    'faccoes_txt' => [
        'kicker'   => 'CONTRATO EXCLUSIVO',
        'titulo'   => 'FACÇÕES EM CONFLITO',
        'sub'      => 'Ideais diferentes. O mesmo território.',
        'texto'    => 'Aegis e Frontier querem os Fragmentos Vesper e as zonas onde eles caíram. Os contratos são mutuamente exclusivos: trocar de lado zera a reputação de quem você abandonou. Assine com uma e veja o que acontece com a outra.',
        'assinar'  => 'ASSINAR CONTRATO',
        'assinado' => 'CONTRATO ATIVO',
        'romper'   => 'ROMPER E VOLTAR AO HUNTER COMMAND',
        'aviso'    => 'Ao assinar, o transponder do governo é suspenso e a rota EVAC HUNTER fecha. A reputação com a facção rival vai a zero.',
    ],

    'galeria' => [
        'kicker' => 'GALERIA',
        'titulo' => 'UM MUNDO REAL. HISTÓRIAS VERDADEIRAS.',
        'itens'  => [
            ['regions/region-floresta-verde',     'FLORESTA VERDE — ATO I · SINAL PERDIDO'],
            ['regions/region-pantano-cinzento',   'PÂNTANO CINZENTO — névoa amarela e Semeadores'],
            ['regions/region-montanhas-gelidas',  'MONTANHAS GÉLIDAS — ATO II · LICENÇA CINZA'],
            ['regions/region-deserto-abandonado', 'DESERTO ABANDONADO — ATO III · guerra econômica'],
            ['regions/region-zona-industrial',    'ZONA INDUSTRIAL — ATO IV · a cratera e o Núcleo Negro'],
            ['maps/porto-industrial',             'COSTA / PORTO — extração é liberdade'],
        ],
    ],

    'final' => [
        'kicker'  => 'A RECONQUISTA COMEÇOU',
        'titulo'  => 'FAÇA PARTE DA OPERAÇÃO',
        'texto'   => 'O setor não espera. Entre como Hunter, sozinho ou com mais três, assine com uma facção quando a campanha te obrigar a escolher, perca a farda se for longe demais. O mundo vai continuar lá — com ou sem você. E, se você não voltar, o seu corpo também.',
        'cta'     => 'ENTRAR NA OPERAÇÃO',
        'cta2'    => 'JÁ TENHO CONTA',
        'lateral' => 'SOBREVIVENTES HOJE. LENDAS AMANHÃ.',
    ],
    'rodape' => [
        'tagline' => 'SOBREVIVA. EXTRAIA. EVOLUA.',
        'canon'   => 'Conteúdo canônico: Bíblia de Universo v6.0',
    ],
];
